<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCA\Kladory\Db\MemberMapper;
use OCA\Kladory\Db\Notebook;
use OCA\Kladory\Db\Section;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Zentrale Rechteprüfung.
 *
 * Rollen in einem Notizbuch:
 *   owner   – hat es angelegt (persönliches Notizbuch oder Kursnotizbuch)
 *   teacher – Mitlehrkraft im Kursnotizbuch
 *   student – Schüler im Kursnotizbuch
 *
 * Bereiche (sections.area) im Kursnotizbuch:
 *   library – Inhaltsbibliothek: Lehrkräfte schreiben, Schüler lesen
 *   collab  – Gemeinsamer Bereich: alle schreiben
 *   teacher – Nur Lehrkräfte: für Schüler unsichtbar
 *   student – Schülerbereich: nur der jeweilige Schüler + Lehrkräfte
 */
class AccessService {
	public const AREAS = ['library', 'collab', 'teacher', 'student'];

	/** @var array<int, array> */
	private array $memberCache = [];
	/** @var array<string, ?string> */
	private array $roleCache = [];
	/** @var array<string, string[]> Gruppen je Benutzer (pro Anfrage) */
	private array $userGroups = [];
	/** @var array<string, array<string,string>> Mitglieder je Gruppe (pro Anfrage) */
	private array $groupMembers = [];

	/**
	 * Gruppen kommen bei Schulen meist aus LDAP. Jede Abfrage dort kostet Zeit,
	 * deshalb werden Gruppenzugehörigkeit und Mitgliederlisten kurz zwischengespeichert
	 * (Nextcloud-Memcache, z. B. Redis/APCu; ohne Memcache nur innerhalb einer Anfrage).
	 * Änderungen in LDAP wirken dadurch spätestens nach CACHE_TTL Sekunden.
	 */
	public const CACHE_TTL = 300;
	private ICache $cache;

	public function __construct(
		private MemberMapper $members,
		private IGroupManager $groups,
		private IUserManager $users,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('kladory-access');
	}

	public function role(string $userId, Notebook $nb): ?string {
		if ($nb->getUserId() === $userId) {
			return 'owner';
		}
		if (!$nb->isClass()) {
			return null;
		}
		$key = $nb->getId() . '|' . $userId;
		if (array_key_exists($key, $this->roleCache)) {
			return $this->roleCache[$key];
		}
		$role = null;
		$members = $this->membersOf($nb->getId());
		$myGroups = [];
		foreach ($members as $m) {
			if ($m['type'] === 'group') {
				$myGroups = array_flip($this->groupIdsOf($userId));
				break;
			}
		}
		foreach ($members as $m) {
			$match = $m['type'] === 'user'
				? $m['principal'] === $userId
				: isset($myGroups[$m['principal']]);
			if ($match) {
				if ($m['role'] === 'teacher') {
					$role = 'teacher';
					break; // Lehrkraft-Rolle hat Vorrang
				}
				$role = 'student';
			}
		}
		return $this->roleCache[$key] = $role;
	}

	public static function isTeacherRole(?string $role): bool {
		return $role === 'owner' || $role === 'teacher';
	}

	public function isTeacher(string $userId, Notebook $nb): bool {
		return self::isTeacherRole($this->role($userId, $nb));
	}

	public function canReadSection(string $userId, Notebook $nb, Section $s): bool {
		$role = $this->role($userId, $nb);
		if ($role === null) {
			return false;
		}
		if (!$nb->isClass() || self::isTeacherRole($role)) {
			return true;
		}
		return match ($s->getArea()) {
			'library', 'collab' => true,
			'student' => $s->getStudentId() === $userId,
			default => false,
		};
	}

	public function canWriteSection(string $userId, Notebook $nb, Section $s): bool {
		return $this->canWriteArea($userId, $nb, (string)$s->getArea(), $s->getStudentId());
	}

	public function canWriteArea(string $userId, Notebook $nb, string $area, ?string $studentId): bool {
		$role = $this->role($userId, $nb);
		if ($role === null) {
			return false;
		}
		if (!$nb->isClass() || self::isTeacherRole($role)) {
			return true;
		}
		return match ($area) {
			'collab' => true,
			'student' => $studentId === $userId,
			default => false,
		};
	}

	/** @return array<int, array{type:string, principal:string, role:string}> */
	public function membersOf(int $notebookId): array {
		return $this->memberCache[$notebookId] ??= $this->members->findByNotebook($notebookId);
	}

	/**
	 * Mitglieder mehrerer Notizbücher auf einmal laden (eine Abfrage statt einer pro Notizbuch).
	 * @param int[] $notebookIds
	 */
	public function primeMembers(array $notebookIds): void {
		$missing = array_values(array_filter($notebookIds, fn ($id) => !isset($this->memberCache[$id])));
		if ($missing === []) {
			return;
		}
		$rows = $this->members->findByNotebooks($missing);
		foreach ($missing as $id) {
			$this->memberCache[$id] = $rows[$id] ?? [];
		}
	}

	public function forget(int $notebookId): void {
		unset($this->memberCache[$notebookId]);
		foreach (array_keys($this->roleCache) as $key) {
			if (str_starts_with($key, $notebookId . '|')) {
				unset($this->roleCache[$key]);
			}
		}
	}

	/**
	 * Alle Lehrkräfte (Benutzer-IDs) inkl. Besitzer.
	 * @return array<string, string> uid => Anzeigename
	 */
	public function teachers(Notebook $nb): array {
		$out = [$nb->getUserId() => $this->displayName($nb->getUserId())];
		foreach ($this->membersOf($nb->getId()) as $m) {
			if ($m['role'] === 'teacher') {
				$out += $this->expand($m);
			}
		}
		return $out;
	}

	/**
	 * Alle Schüler (ohne Lehrkräfte), sortiert nach Anzeigename.
	 * @return array<string, string> uid => Anzeigename
	 */
	public function students(Notebook $nb): array {
		if (!$nb->isClass()) {
			return [];
		}
		$teachers = $this->teachers($nb);
		$out = [];
		foreach ($this->membersOf($nb->getId()) as $m) {
			if ($m['role'] === 'student') {
				$out += $this->expand($m);
			}
		}
		$out = array_diff_key($out, $teachers);
		asort($out, SORT_NATURAL | SORT_FLAG_CASE);
		return $out;
	}

	public function displayName(string $userId): string {
		return $this->users->getDisplayName($userId) ?? $userId;
	}

	/** @return string[] */
	public function groupIdsOf(string $userId): array {
		if (isset($this->userGroups[$userId])) {
			return $this->userGroups[$userId];
		}
		$cached = $this->cache->get('ug/' . $userId);
		if (is_array($cached)) {
			return $this->userGroups[$userId] = $cached;
		}
		$user = $this->users->get($userId);
		$ids = $user === null ? [] : array_values(array_map('strval', $this->groups->getUserGroupIds($user)));
		$this->cache->set('ug/' . $userId, $ids, self::CACHE_TTL);
		return $this->userGroups[$userId] = $ids;
	}

	/** @return array<string, string> uid => Anzeigename aller Mitglieder einer Gruppe */
	public function groupMembers(string $groupId): array {
		if (isset($this->groupMembers[$groupId])) {
			return $this->groupMembers[$groupId];
		}
		$cached = $this->cache->get('gm/' . $groupId);
		if (is_array($cached)) {
			return $this->groupMembers[$groupId] = $cached;
		}
		$out = [];
		foreach ($this->groups->displayNamesInGroup($groupId, '', -1, 0) as $uid => $name) {
			$out[(string)$uid] = (string)($name ?: $uid);
		}
		$this->cache->set('gm/' . $groupId, $out, self::CACHE_TTL);
		return $this->groupMembers[$groupId] = $out;
	}

	/** Zwischenspeicher leeren (z. B. nach Umbenennen/Löschen von Benutzern oder Gruppen) */
	public function clearCache(): void {
		$this->userGroups = [];
		$this->groupMembers = [];
		$this->roleCache = [];
		$this->cache->clear();
	}

	/** @return array<string, string> */
	private function expand(array $m): array {
		if ($m['type'] === 'user') {
			return $this->users->userExists($m['principal'])
				? [$m['principal'] => $this->displayName($m['principal'])]
				: [];
		}
		return $this->groupMembers($m['principal']);
	}
}
