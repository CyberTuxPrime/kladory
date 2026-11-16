<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCA\Kladory\AppInfo\Application;
use OCA\Kladory\Db\MemberMapper;
use OCA\Kladory\Db\Notebook;
use OCA\Kladory\Db\NotebookMapper;
use OCA\Kladory\Db\SectionMapper;
use OCA\Kladory\Db\TagMapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Kursnotizbücher (wie OneNote Class Notebook):
 * anlegen, Mitglieder verwalten, Schülerabschnitte, Seiten an alle Schüler verteilen.
 */
class ClassService {
	private const MAX_MEMBERS = 300;
	private const DEFAULT_STUDENT_SECTIONS = ['Notizen', 'Hausaufgaben'];

	public function __construct(
		private NoteService $notes,
		private AccessService $access,
		private MemberMapper $members,
		private NotebookMapper $notebooks,
		private SectionMapper $sections,
		private TagMapper $tags,
		private ImageStore $images,
		private ContentSanitizer $sanitizer,
		private IUserManager $users,
		private IGroupManager $groups,
		private IAppConfig $appConfig,
		private IDBConnection $db,
	) {
	}

	/**
	 * Darf der Benutzer Kursnotizbücher anlegen?
	 * Einstellbar über: occ config:app:set kladory class_creator_groups --value="Lehrer,Admin"
	 * (leer = alle dürfen)
	 */
	public function canCreate(string $userId): bool {
		$groups = array_filter(array_map('trim', explode(',', $this->appConfig->getValueString(Application::APP_ID, 'class_creator_groups', ''))));
		if ($groups === []) {
			return true;
		}
		return array_intersect($groups, $this->access->groupIdsOf($userId)) !== [];
	}

	public function create(string $userId, string $name, ?string $color, mixed $members, mixed $studentSections): array {
		if (!$this->canCreate($userId)) {
			throw new ForbiddenException('Du darfst keine Kursnotizbücher anlegen.');
		}
		$members = $this->cleanMembers($members, $userId);
		$studentSections = $this->cleanSectionNames($studentSections);
		$now = time();

		$this->db->beginTransaction();
		try {
			$nb = $this->notes->insertNotebook($userId, $name, $color, 'class', ['studentSections' => $studentSections]);
			$this->notes->insertSection($nb->getId(), 'Arbeitsblätter', '#1a73e8', 'library', null, $now);
			$this->notes->insertSection($nb->getId(), 'Gemeinsam', '#0f9d58', 'collab', null, $now);
			$this->notes->insertSection($nb->getId(), 'Unterrichtsplanung', '#5f6368', 'teacher', null, $now);
			$this->members->setForNotebook($nb->getId(), $members);
			$this->access->forget($nb->getId());
			$this->notes->ensureStudentSections($nb);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->notes->notebookJson($userId, $nb, 'owner');
	}

	public function getMembers(string $userId, int $notebookId): array {
		$nb = $this->classNotebook($userId, $notebookId);
		$list = [];
		foreach ($this->access->membersOf($nb->getId()) as $m) {
			$list[] = $m + ['name' => $this->principalName($m['type'], $m['principal'])];
		}
		return [
			'owner' => ['id' => $nb->getUserId(), 'name' => $this->access->displayName($nb->getUserId())],
			'members' => $list,
			'studentCount' => count($this->access->students($nb)),
			'studentSections' => $this->notes->studentSectionNames($nb),
		];
	}

	public function setMembers(string $userId, int $notebookId, mixed $members): array {
		$nb = $this->classNotebook($userId, $notebookId);
		$clean = $this->cleanMembers($members, $nb->getUserId());
		$this->db->beginTransaction();
		try {
			$this->members->setForNotebook($nb->getId(), $clean);
			$this->access->forget($nb->getId());
			$this->notes->ensureStudentSections($nb);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->getMembers($userId, $notebookId);
	}

	/** Neuen Abschnitt für alle Schüler anlegen (und als Standard für neue Schüler merken) */
	public function addStudentSection(string $userId, int $notebookId, string $name): array {
		$nb = $this->classNotebook($userId, $notebookId);
		$name = $this->cleanSectionNames([$name])[0];
		$names = $this->notes->studentSectionNames($nb);
		if (!in_array($name, $names, true)) {
			$names[] = $name;
		}
		$settings = $nb->getSettingsArray();
		$settings['studentSections'] = $names;
		$nb->setSettings(json_encode($settings, JSON_UNESCAPED_UNICODE));
		$this->notebooks->update($nb);

		$have = [];
		foreach ($this->sections->findByNotebooks([$nb->getId()]) as $s) {
			if ($s->getArea() === 'student' && mb_strtolower($s->getName()) === mb_strtolower($name)) {
				$have[$s->getStudentId()] = true;
			}
		}
		$count = 0;
		$color = NoteService::areaColor(count($names) - 1);
		foreach (array_keys($this->access->students($nb)) as $uid) {
			if (!isset($have[$uid])) {
				$this->notes->insertSection($nb->getId(), $name, $color, 'student', (string)$uid, time());
				$count++;
			}
		}
		return ['created' => $count, 'studentSections' => $names];
	}

	/**
	 * Seite an alle Schüler verteilen: Kopie in den Schülerabschnitt mit dem Namen $sectionName.
	 * Fehlt der Abschnitt bei einem Schüler, wird er angelegt.
	 */
	public function distribute(string $userId, int $pageId, string $sectionName): array {
		[$page, , $nb] = $this->notes->requirePage($userId, $pageId, 'read');
		if (!$nb->isClass() || !$this->access->isTeacher($userId, $nb)) {
			throw new ForbiddenException('Nur Lehrkräfte können Seiten verteilen.');
		}
		$sectionName = $this->cleanSectionNames([$sectionName])[0];
		$students = $this->access->students($nb);
		if ($students === []) {
			throw new ValidationException('Dieses Kursnotizbuch hat noch keine Schüler.');
		}

		$target = [];
		foreach ($this->sections->findByNotebooks([$nb->getId()]) as $s) {
			if ($s->getArea() === 'student' && mb_strtolower($s->getName()) === mb_strtolower($sectionName)) {
				$target[$s->getStudentId()] ??= $s->getId();
			}
		}
		$tags = $this->tags->getForPage($page->getId());
		$imageRefs = $this->sanitizer->extractImageRefs($page->getContent());
		$now = time();
		$count = 0;
		foreach (array_keys($students) as $uid) {
			$uid = (string)$uid;
			$sectionId = $target[$uid] ?? $this->notes->insertSection($nb->getId(), $sectionName, '#1a73e8', 'student', $uid, $now)->getId();
			$copy = $this->notes->insertPage($sectionId, (string)$page->getTitle(), $page->getContent(), $userId);
			if ($tags !== []) {
				$this->tags->setForPage($copy->getId(), $tags);
			}
			if ($imageRefs !== []) {
				$this->images->copy($page->getId(), $copy->getId(), $imageRefs);
			}
			$count++;
		}
		return ['distributed' => $count, 'sectionName' => $sectionName];
	}

	/**
	 * Benutzer/Gruppen für die Mitgliederauswahl suchen.
	 * @return array<int, array{type:string, id:string, name:string}>
	 */
	public function searchPrincipals(string $userId, string $term): array {
		if (!$this->canCreate($userId)) {
			throw new ForbiddenException('Keine Berechtigung.');
		}
		$term = trim(mb_substr($term, 0, 64));
		if (mb_strlen($term) < 2) {
			return [];
		}
		$out = [];
		foreach ($this->groups->search($term, 10, 0) as $g) {
			$out[] = ['type' => 'group', 'id' => $g->getGID(), 'name' => $g->getDisplayName()];
		}
		foreach ($this->users->searchDisplayName($term, 15, 0) as $u) {
			$out[] = ['type' => 'user', 'id' => $u->getUID(), 'name' => $u->getDisplayName()];
		}
		return $out;
	}

	// ---------- intern ----------

	private function classNotebook(string $userId, int $notebookId): Notebook {
		$nb = $this->notes->requireNotebook($userId, $notebookId, 'manage');
		if (!$nb->isClass()) {
			throw new ValidationException('Das ist kein Kursnotizbuch.');
		}
		return $nb;
	}

	private function principalName(string $type, string $id): string {
		if ($type === 'group') {
			$g = $this->groups->get($id);
			return $g ? $g->getDisplayName() : $id . ' (gelöscht)';
		}
		return $this->users->userExists($id) ? $this->access->displayName($id) : $id . ' (gelöscht)';
	}

	/** @return array<int, array{type:string, principal:string, role:string}> */
	private function cleanMembers(mixed $members, string $ownerId): array {
		if (!is_array($members)) {
			throw new ValidationException('Ungültige Mitgliederliste.');
		}
		if (count($members) > self::MAX_MEMBERS) {
			throw new ValidationException('Zu viele Einträge.');
		}
		$out = [];
		foreach ($members as $m) {
			if (!is_array($m)) {
				continue;
			}
			$type = $m['type'] ?? '';
			$id = is_string($m['principal'] ?? null) ? $m['principal'] : (is_string($m['id'] ?? null) ? $m['id'] : '');
			$role = $m['role'] ?? '';
			if (!in_array($type, ['user', 'group'], true) || !in_array($role, ['teacher', 'student'], true) || $id === '') {
				throw new ValidationException('Ungültiger Eintrag in der Mitgliederliste.');
			}
			if ($type === 'user' && $id === $ownerId) {
				continue; // Besitzer ist immer Lehrkraft
			}
			$exists = $type === 'user' ? $this->users->userExists($id) : $this->groups->groupExists($id);
			if (!$exists) {
				throw new ValidationException(($type === 'user' ? 'Benutzer' : 'Gruppe') . ' „' . $id . '“ gibt es nicht.');
			}
			$key = $type . ':' . $id;
			// doppelte Einträge: Lehrkraft gewinnt
			if (!isset($out[$key]) || $role === 'teacher') {
				$out[$key] = ['type' => $type, 'principal' => $id, 'role' => $role];
			}
		}
		return array_values($out);
	}

	/** @return string[] */
	private function cleanSectionNames(mixed $names): array {
		if (!is_array($names) || $names === []) {
			return self::DEFAULT_STUDENT_SECTIONS;
		}
		$out = [];
		foreach ($names as $n) {
			if (!is_string($n)) {
				continue;
			}
			$n = mb_substr(trim(preg_replace('/\s+/u', ' ', $n) ?? ''), 0, 60);
			if ($n !== '' && !in_array($n, $out, true)) {
				$out[] = $n;
			}
		}
		if (count($out) > 8) {
			throw new ValidationException('Höchstens 8 Schülerabschnitte.');
		}
		return $out === [] ? self::DEFAULT_STUDENT_SECTIONS : $out;
	}
}
