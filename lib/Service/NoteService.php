<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCA\Kladory\Db\MemberMapper;
use OCA\Kladory\Db\Notebook;
use OCA\Kladory\Db\NotebookMapper;
use OCA\Kladory\Db\Page;
use OCA\Kladory\Db\PageMapper;
use OCA\Kladory\Db\Section;
use OCA\Kladory\Db\SectionMapper;
use OCA\Kladory\Db\TagMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Notizbücher, Abschnitte, Seiten, Tags, Suche – jeweils mit Rechteprüfung über den AccessService.
 * Löschen verschiebt in den Papierkorb (siehe TrashService).
 */
class NoteService {
	private const MAX_NAME = 200;
	private const MAX_TITLE = 255;
	private const MAX_CONTENT = 5 * 1024 * 1024; // 5 MB JSON pro Seite
	public const MAX_REQUEST = self::MAX_CONTENT + 64 * 1024;
	private const DEFAULT_COLOR = '#4f6bed';

	public function __construct(
		private NotebookMapper $notebooks,
		private SectionMapper $sections,
		private PageMapper $pages,
		private IDBConnection $db,
		private ITimeFactory $time,
		private ContentSanitizer $sanitizer,
		private TagMapper $tags,
		private AccessService $access,
		private MemberMapper $members,
	) {
	}

	// =================================================================== Baum

	/**
	 * Alle Notizbücher, die der Benutzer sehen darf, mit den für ihn sichtbaren Abschnitten.
	 */
	public function getTree(string $userId): array {
		$notebooks = $this->visibleNotebooks($userId);
		// alle Abschnitte mit einer einzigen Abfrage statt einer pro Notizbuch
		$sections = $this->sectionsByNotebook($notebooks);
		$out = [];
		foreach ($notebooks as $nb) {
			$role = $this->access->role($userId, $nb);
			$mine = $sections[$nb->getId()] ?? [];
			if ($nb->isClass()
				&& $this->ensureStudentSections($nb, $role === 'student' ? [$userId => ''] : null, $mine)) {
				$mine = $this->sections->findByNotebooks([$nb->getId()]);
			}
			$out[] = $this->notebookJson($userId, $nb, $role, $mine);
		}
		return $out;
	}

	/**
	 * @param Notebook[] $notebooks
	 * @return array<int, Section[]> notebookId => Abschnitte (ohne Papierkorb)
	 */
	private function sectionsByNotebook(array $notebooks): array {
		$out = [];
		$ids = array_map(static fn (Notebook $n) => $n->getId(), $notebooks);
		foreach ($this->sections->findByNotebooks($ids) as $s) {
			$out[$s->getNotebookId()][] = $s;
		}
		return $out;
	}

	/** @return Notebook[] eigene + Kursnotizbücher, in denen der Benutzer Mitglied ist */
	public function visibleNotebooks(string $userId): array {
		$own = $this->notebooks->findAllForUser($userId);
		$memberIds = $this->members->findNotebookIdsFor($userId, $this->access->groupIdsOf($userId));
		$ownIds = array_map(static fn (Notebook $n) => $n->getId(), $own);
		$others = $this->notebooks->findLiveByIds(array_diff($memberIds, $ownIds));
		$this->access->primeMembers(array_merge(
			array_map(static fn (Notebook $n) => $n->getId(), $others),
			array_map(static fn (Notebook $n) => $n->getId(), array_filter($own, static fn (Notebook $n) => $n->isClass())),
		));
		$others = array_filter($others, fn (Notebook $n) => $n->isClass() && $this->access->role($userId, $n) !== null);
		usort($others, static fn (Notebook $a, Notebook $b) => strnatcasecmp($a->getName(), $b->getName()));
		return array_merge($own, array_values($others));
	}

	/** @return int[] IDs aller Abschnitte, die der Benutzer lesen darf */
	public function readableSectionIds(string $userId): array {
		$ids = [];
		$notebooks = $this->visibleNotebooks($userId);
		$sections = $this->sectionsByNotebook($notebooks);
		foreach ($notebooks as $nb) {
			foreach ($sections[$nb->getId()] ?? [] as $s) {
				if ($this->access->canReadSection($userId, $nb, $s)) {
					$ids[] = $s->getId();
				}
			}
		}
		return $ids;
	}

	/** @param Section[]|null $preloaded bereits geladene Abschnitte dieses Notizbuchs */
	public function notebookJson(string $userId, Notebook $nb, ?string $role = null, ?array $preloaded = null): array {
		$role ??= $this->access->role($userId, $nb);
		$teacher = AccessService::isTeacherRole($role);
		$sections = [];
		$studentNames = $nb->isClass() && $teacher ? $this->access->students($nb) : [];
		foreach ($preloaded ?? $this->sections->findByNotebooks([$nb->getId()]) as $s) {
			if (!$this->access->canReadSection($userId, $nb, $s)) {
				continue;
			}
			$json = $s->jsonSerialize();
			$json['readOnly'] = !$this->access->canWriteSection($userId, $nb, $s);
			if ($s->getArea() === 'student' && $s->getStudentId() !== null) {
				$json['studentName'] = $studentNames[$s->getStudentId()] ?? $this->access->displayName($s->getStudentId());
			}
			$sections[] = $json;
		}
		$json = $nb->jsonSerialize() + [
			'role' => $role,
			'canManage' => $teacher,
			'isOwner' => $role === 'owner',
			'sections' => $sections,
		];
		if ($nb->isClass()) {
			$json['ownerName'] = $this->access->displayName($nb->getUserId());
			$json['studentSections'] = $this->studentSectionNames($nb);
			if ($teacher) {
				$json['students'] = array_map(
					static fn ($uid, $name) => ['id' => (string)$uid, 'name' => $name],
					array_keys($studentNames), array_values($studentNames));
			}
		}
		return $json;
	}

	// =================================================================== Notizbücher

	public function createNotebook(string $userId, string $name, ?string $color): array {
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$nb = $this->insertNotebook($userId, $name, $color, 'personal', null);
			$this->insertSection($nb->getId(), 'Allgemein', $nb->getColor(), 'normal', null, $now);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->notebookJson($userId, $nb, 'owner');
	}

	public function insertNotebook(string $userId, string $name, ?string $color, string $kind, ?array $settings): Notebook {
		$now = $this->time->getTime();
		$nb = new Notebook();
		$nb->setUserId($userId);
		$nb->setName($this->cleanName($name));
		$nb->setColor($this->cleanColor($color));
		$nb->setSortOrder($this->notebooks->maxSortOrder($userId) + 1);
		$nb->setCreatedAt($now);
		$nb->setUpdatedAt($now);
		$nb->setKind($kind);
		$nb->setSettings($settings === null ? null : json_encode($settings, JSON_UNESCAPED_UNICODE));
		return $this->notebooks->insert($nb);
	}

	public function updateNotebook(string $userId, int $id, ?string $name, ?string $color): Notebook {
		$nb = $this->requireNotebook($userId, $id, 'manage');
		if ($name !== null) {
			$nb->setName($this->cleanName($name));
		}
		if ($color !== null) {
			$nb->setColor($this->cleanColor($color));
		}
		$nb->setUpdatedAt($this->time->getTime());
		return $this->notebooks->update($nb);
	}

	/** In den Papierkorb (nur der Besitzer; Abschnitte und Seiten bleiben unverändert darin) */
	public function deleteNotebook(string $userId, int $id): void {
		$nb = $this->requireNotebook($userId, $id, 'own');
		$nb->setDeletedAt($this->time->getTime());
		$this->notebooks->update($nb);
	}

	// =================================================================== Abschnitte

	public function createSection(string $userId, int $notebookId, string $name, ?string $color, ?string $area = null, ?string $studentId = null): Section {
		$nb = $this->requireNotebook($userId, $notebookId, 'manage');
		if (!$nb->isClass()) {
			$area = 'normal';
			$studentId = null;
		} else {
			$area = in_array($area, AccessService::AREAS, true) ? $area : 'collab';
			if ($area === 'student') {
				if ($studentId === null || !isset($this->access->students($nb)[$studentId])) {
					throw new ValidationException('Unbekannter Schüler.');
				}
			} else {
				$studentId = null;
			}
		}
		return $this->insertSection($nb->getId(), $name, $color, $area, $studentId, $this->time->getTime());
	}

	public function insertSection(int $notebookId, string $name, ?string $color, string $area, ?string $studentId, int $now): Section {
		$s = new Section();
		$s->setNotebookId($notebookId);
		$s->setName($this->cleanName($name));
		$s->setColor($this->cleanColor($color));
		$s->setSortOrder($this->sections->maxSortOrder($notebookId) + 1);
		$s->setCreatedAt($now);
		$s->setUpdatedAt($now);
		$s->setArea($area);
		$s->setStudentId($studentId);
		return $this->sections->insert($s);
	}

	public function updateSection(string $userId, int $id, ?string $name, ?string $color): Section {
		[$s] = $this->requireSection($userId, $id, 'manage');
		if ($name !== null) {
			$s->setName($this->cleanName($name));
		}
		if ($color !== null) {
			$s->setColor($this->cleanColor($color));
		}
		$s->setUpdatedAt($this->time->getTime());
		return $this->sections->update($s);
	}

	/** In den Papierkorb (nur Besitzer/Lehrkräfte) */
	public function deleteSection(string $userId, int $id): void {
		[$s] = $this->requireSection($userId, $id, 'manage');
		$s->setDeletedAt($this->time->getTime());
		$s->setDeletedBy($userId);
		$this->sections->update($s);
	}

	// =================================================================== Kursnotizbuch: Schülerabschnitte

	/** @return string[] Standard-Abschnitte für jeden Schüler */
	public function studentSectionNames(Notebook $nb): array {
		$names = $nb->getSettingsArray()['studentSections'] ?? [];
		return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
	}

	/**
	 * Legt für Schüler ohne eigenen Abschnitt die Standard-Abschnitte an.
	 * @param array<string,string>|null $only nur diese Schüler (null = alle)
	 * @param Section[]|null $preloaded bereits geladene Abschnitte (spart Abfragen)
	 * @return bool true, wenn Abschnitte angelegt wurden
	 */
	public function ensureStudentSections(Notebook $nb, ?array $only = null, ?array $preloaded = null): bool {
		$names = $this->studentSectionNames($nb);
		if ($names === []) {
			return false;
		}
		$have = [];
		foreach ($preloaded ?? $this->sections->findByNotebooks([$nb->getId()]) as $s) {
			if ($s->getArea() === 'student' && $s->getStudentId() !== null) {
				$have[$s->getStudentId()] = true;
			}
		}
		// Schnellweg für Schüler: eigener Abschnitt vorhanden → keine Gruppenabfrage nötig
		if ($only !== null && array_diff_key($only, $have) === []) {
			return false;
		}
		$students = $this->access->students($nb);
		if ($only !== null) {
			$students = array_intersect_key($students, $only);
		}
		$missing = array_diff_key($students, $have);
		if ($missing === []) {
			return false;
		}
		// auch Abschnitte im Papierkorb zählen: sonst würde ein gelöschter Abschnitt sofort neu angelegt
		foreach ($this->sections->findStudentIdsWithDeleted($nb->getId()) as $uid) {
			unset($missing[$uid]);
		}
		if ($missing === []) {
			return false;
		}
		$now = $this->time->getTime();
		foreach (array_keys($missing) as $uid) {
			foreach ($names as $i => $name) {
				$this->insertSection($nb->getId(), $name, self::areaColor($i), 'student', (string)$uid, $now);
			}
		}
		return true;
	}

	public static function areaColor(int $i): string {
		$colors = ['#1a73e8', '#0f9d58', '#e8710a', '#9334e6', '#d93025', '#00897b'];
		return $colors[$i % count($colors)];
	}

	// =================================================================== Seiten

	/** @return array[] */
	public function listPages(string $userId, int $sectionId): array {
		[$s] = $this->requireSection($userId, $sectionId, 'read');
		return array_map(static fn (Page $p) => $p->toSummary(), $this->pages->findBySection($s->getId()));
	}

	/** Seite lesen (Rechte: lesen) */
	public function getPage(string $userId, int $id): Page {
		return $this->requirePage($userId, $id, 'read')[0];
	}

	/** Seite inkl. Tags und Schreibrecht für die Oberfläche */
	public function getPageData(string $userId, int $id): array {
		[$page, $s, $nb] = $this->requirePage($userId, $id, 'read');
		return $page->jsonSerialize() + [
			'tags' => $this->tags->getForPage($page->getId()),
			'readOnly' => !$this->access->canWriteSection($userId, $nb, $s),
		];
	}

	/** Prüft Schreibrecht auf die Seite */
	public function requireWritablePage(string $userId, int $id): Page {
		return $this->requirePage($userId, $id, 'write')[0];
	}

	public function createPage(string $userId, int $sectionId, ?string $title): Page {
		[$s] = $this->requireSection($userId, $sectionId, 'write');
		return $this->insertPage($s->getId(), $title ?? '', null, $userId);
	}

	public function insertPage(int $sectionId, string $title, ?string $content, string $userId): Page {
		$now = $this->time->getTime();
		$p = new Page();
		$p->setSectionId($sectionId);
		$p->setTitle($this->cleanTitle($title));
		$p->setContent($content);
		$p->setSortOrder($this->pages->maxSortOrder($sectionId) + 1);
		$p->setCreatedAt($now);
		$p->setUpdatedAt($now);
		$p->setUpdatedBy($userId);
		$p->setRevision(0);
		$p->setSearchText($this->sanitizer->buildSearchText((string)$p->getTitle(), $content));
		return $this->pages->insert($p);
	}

	/**
	 * @param int|null $revision Stand, auf dem die Änderung beruht. null = ohne Prüfung überschreiben.
	 * @throws ConflictException wenn die Seite inzwischen anderswo geändert wurde
	 */
	public function updatePage(string $userId, int $id, ?string $title, ?string $content, ?int $revision = null): Page {
		[$p] = $this->requirePage($userId, $id, 'write');
		if ($revision !== null && $revision !== (int)$p->getRevision()) {
			throw new ConflictException($p);
		}
		if ($title !== null) {
			$p->setTitle($this->cleanTitle($title));
		}
		if ($content !== null) {
			if (strlen($content) > self::MAX_CONTENT) {
				throw new ValidationException('Seiteninhalt ist zu groß.');
			}
			$p->setContent($this->sanitizer->sanitizeContent($content));
		}
		if ($title !== null || $content !== null) {
			$p->setSearchText($this->sanitizer->buildSearchText((string)$p->getTitle(), $p->getContent()));
		}
		$p->setRevision((int)$p->getRevision() + 1);
		$p->setUpdatedAt($this->time->getTime());
		$p->setUpdatedBy($userId);
		return $this->pages->update($p);
	}

	/**
	 * Körper von POST /api/pages/{id}/save: JSON {title, content, revision}, optional gzip-komprimiert.
	 * @return array{title: ?string, content: ?string, revision: ?int}
	 */
	public static function decodeSaveBody(string $raw, bool $gzip): array {
		if (strlen($raw) > self::MAX_REQUEST) {
			throw new ValidationException('Seiteninhalt ist zu groß.');
		}
		if ($gzip) {
			// Obergrenze schützt vor „Zip-Bomben“
			$raw = @gzdecode($raw, self::MAX_REQUEST + 1);
			if ($raw === false || strlen($raw) > self::MAX_REQUEST) {
				throw new ValidationException('Seiteninhalt ist ungültig oder zu groß.');
			}
		}
		$in = json_decode($raw, true);
		if (!is_array($in)) {
			throw new ValidationException('Seiteninhalt ist ungültig.');
		}
		$str = static fn ($v) => is_string($v) ? $v : null;
		return [
			'title' => $str($in['title'] ?? null),
			'content' => $str($in['content'] ?? null),
			'revision' => is_int($in['revision'] ?? null) ? $in['revision'] : null,
		];
	}

	/** In den Papierkorb */
	public function deletePage(string $userId, int $id): void {
		[$p] = $this->requirePage($userId, $id, 'write');
		$p->setDeletedAt($this->time->getTime());
		$p->setDeletedBy($userId);
		$this->pages->update($p);
	}

	// =================================================================== Tags

	/**
	 * @param mixed $tags Liste von Strings
	 * @return string[] gespeicherte (bereinigte) Tags
	 */
	public function setTags(string $userId, int $pageId, mixed $tags): array {
		[$page] = $this->requirePage($userId, $pageId, 'write');
		if (!is_array($tags)) {
			throw new ValidationException('Tags müssen eine Liste sein.');
		}
		$clean = [];
		foreach ($tags as $tag) {
			if (!is_string($tag)) {
				continue;
			}
			$tag = self::normalizeTag($tag);
			if ($tag !== '') {
				$clean[$tag] = true;
			}
		}
		$clean = array_keys($clean);
		if (count($clean) > 20) {
			throw new ValidationException('Höchstens 20 Tags pro Seite.');
		}
		sort($clean);
		$this->tags->setForPage($page->getId(), $clean);
		return $clean;
	}

	public function listTags(string $userId): array {
		return $this->tags->listForSections($this->readableSectionIds($userId));
	}

	public static function normalizeTag(string $tag): string {
		$tag = mb_strtolower(trim($tag));
		$tag = ltrim($tag, '#');
		$tag = preg_replace('/\s+/u', '-', $tag) ?? '';
		$tag = preg_replace('/[^\p{L}\p{N}_-]/u', '', $tag) ?? '';
		return mb_substr($tag, 0, 40);
	}

	// =================================================================== Suche

	public function search(string $userId, string $query, ?string $tag): array {
		$query = trim(mb_substr($query, 0, 100));
		$tag = $tag !== null ? self::normalizeTag($tag) : null;
		if ($query === '' && ($tag === null || $tag === '')) {
			return [];
		}
		$sectionIds = $this->readableSectionIds($userId);
		if ($sectionIds === []) {
			return [];
		}
		$out = [];
		foreach ($this->pages->search($sectionIds, $query, $tag) as $r) {
			$out[] = [
				'id' => (int)$r['id'],
				'title' => (string)($r['title'] ?? ''),
				'updatedAt' => (int)$r['updated_at'],
				'sectionId' => (int)$r['sec_id'],
				'sectionName' => (string)$r['sec_name'],
				'sectionColor' => (string)$r['sec_color'],
				'notebookId' => (int)$r['nb_id'],
				'notebookName' => (string)$r['nb_name'],
				'snippet' => self::snippet((string)($r['search_text'] ?? ''), $query),
			];
		}
		return $out;
	}

	private static function snippet(string $text, string $query): string {
		// Titel steht in der ersten Zeile – für den Ausschnitt nur den Inhalt verwenden
		$nl = strpos($text, "\n");
		$body = $nl === false ? '' : substr($text, $nl + 1);
		if ($body === '') {
			return '';
		}
		$pos = $query !== '' ? mb_stripos($body, $query) : false;
		if ($pos === false) {
			return mb_substr($body, 0, 140) . (mb_strlen($body) > 140 ? ' …' : '');
		}
		$start = max(0, $pos - 50);
		$snip = mb_substr($body, $start, 160);
		return ($start > 0 ? '… ' : '') . $snip . ($start + 160 < mb_strlen($body) ? ' …' : '');
	}

	// =================================================================== Rechte-Helfer

	/**
	 * @param string $need read | manage (Besitzer/Lehrkraft) | own (nur Besitzer)
	 */
	public function requireNotebook(string $userId, int $id, string $need): Notebook {
		try {
			$nb = $this->notebooks->findLive($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Notizbuch nicht gefunden.');
		}
		$role = $this->access->role($userId, $nb);
		$ok = match ($need) {
			'own' => $role === 'owner',
			'manage' => AccessService::isTeacherRole($role),
			default => $role !== null,
		};
		if (!$ok) {
			// kein Hinweis darauf, ob es das Notizbuch gibt
			throw new NotFoundException('Notizbuch nicht gefunden.');
		}
		return $nb;
	}

	/**
	 * @param string $need read | write | manage
	 * @return array{0: Section, 1: Notebook}
	 */
	public function requireSection(string $userId, int $id, string $need): array {
		try {
			$s = $this->sections->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Abschnitt nicht gefunden.');
		}
		if ((int)$s->getDeletedAt() > 0) {
			throw new NotFoundException('Abschnitt nicht gefunden.');
		}
		try {
			$nb = $this->notebooks->findLive($s->getNotebookId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Abschnitt nicht gefunden.');
		}
		if (!$this->access->canReadSection($userId, $nb, $s)) {
			throw new NotFoundException('Abschnitt nicht gefunden.');
		}
		if ($need === 'write' && !$this->access->canWriteSection($userId, $nb, $s)) {
			throw new ForbiddenException('Hier hast du nur Leserechte.');
		}
		if ($need === 'manage' && !$this->access->isTeacher($userId, $nb)) {
			throw new ForbiddenException('Nur Lehrkräfte können Abschnitte verwalten.');
		}
		return [$s, $nb];
	}

	/**
	 * @param string $need read | write
	 * @return array{0: Page, 1: Section, 2: Notebook}
	 */
	public function requirePage(string $userId, int $id, string $need): array {
		try {
			$page = $this->pages->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Seite nicht gefunden.');
		}
		if ((int)$page->getDeletedAt() > 0) {
			throw new NotFoundException('Seite nicht gefunden.');
		}
		[$s, $nb] = $this->requireSection($userId, $page->getSectionId(), $need);
		return [$page, $s, $nb];
	}

	// =================================================================== Bereinigung

	private function cleanName(string $name): string {
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
		if ($name === '') {
			throw new ValidationException('Der Name darf nicht leer sein.');
		}
		return mb_substr($name, 0, self::MAX_NAME);
	}

	private function cleanTitle(string $title): string {
		$title = trim(preg_replace('/[\r\n\t]+/u', ' ', $title) ?? '');
		return mb_substr($title, 0, self::MAX_TITLE);
	}

	private function cleanColor(?string $color): string {
		if ($color === null || $color === '') {
			return self::DEFAULT_COLOR;
		}
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
			throw new ValidationException('Ungültige Farbe.');
		}
		return strtolower($color);
	}
}
