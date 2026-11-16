<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCA\Kladory\AppInfo\Application;
use OCA\Kladory\Db\MemberMapper;
use OCA\Kladory\Db\NotebookMapper;
use OCA\Kladory\Db\PageMapper;
use OCA\Kladory\Db\SectionMapper;
use OCA\Kladory\Db\TagMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Papierkorb: auflisten, wiederherstellen, endgültig löschen, automatisch leeren.
 *
 * Ein gelöschtes Notizbuch nimmt seine Abschnitte/Seiten "mit" (sie bleiben unverändert
 * gespeichert und werden mit dem Notizbuch wiederhergestellt). Im Papierkorb erscheinen
 * deshalb nur die obersten gelöschten Einträge.
 */
class TrashService {
	public const TYPES = ['notebook', 'section', 'page'];
	public const DEFAULT_RETENTION_DAYS = 30;

	public function __construct(
		private NotebookMapper $notebooks,
		private SectionMapper $sections,
		private PageMapper $pages,
		private IDBConnection $db,
		private ITimeFactory $time,
		private IAppConfig $appConfig,
		private TagMapper $tags,
		private ImageStore $images,
		private ContentSanitizer $sanitizer,
		private NoteService $notes,
		private AccessService $access,
		private MemberMapper $members,
	) {
	}

	/** Aufbewahrungsdauer in Tagen (0 = nie automatisch leeren) */
	public function getRetentionDays(): int {
		$days = $this->appConfig->getValueInt(Application::APP_ID, 'trash_retention_days', self::DEFAULT_RETENTION_DAYS);
		return max(0, $days);
	}

	/**
	 * Papierkorb aus Sicht des Benutzers:
	 *  - eigene gelöschte Notizbücher
	 *  - gelöschte Abschnitte/Seiten in Notizbüchern, in denen er Besitzer oder Lehrkraft ist
	 *  - als Schüler: Seiten, die er selbst gelöscht hat und weiterhin bearbeiten dürfte
	 */
	public function list(string $userId): array {
		$items = [];
		foreach ($this->notebooks->findDeletedForUser($userId) as $nb) {
			$items[] = [
				'type' => 'notebook',
				'id' => $nb->getId(),
				'name' => $nb->getName(),
				'color' => $nb->getColor(),
				'path' => '',
				'deletedAt' => (int)$nb->getDeletedAt(),
			];
		}

		$visible = [];
		$teacherIds = [];
		foreach ($this->notes->visibleNotebooks($userId) as $nb) {
			$visible[$nb->getId()] = $nb;
			if ($this->access->isTeacher($userId, $nb)) {
				$teacherIds[] = $nb->getId();
			}
		}

		foreach ($this->sections->findDeletedInNotebooks($teacherIds) as $r) {
			$items[] = [
				'type' => 'section',
				'id' => (int)$r['id'],
				'name' => (string)$r['name'],
				'color' => (string)$r['color'],
				'path' => (string)$r['nb_name'] . $this->studentSuffix($r['area'] ?? null, $r['student_id'] ?? null),
				'deletedAt' => (int)$r['deleted_at'],
			];
		}

		foreach ($this->pages->findDeletedInNotebooks(array_keys($visible)) as $r) {
			$nb = $visible[(int)$r['nb_id']];
			$allowed = $this->access->isTeacher($userId, $nb)
				|| ($r['deleted_by'] === $userId
					&& $this->access->canWriteArea($userId, $nb, (string)$r['sec_area'], $r['sec_student'] ?? null));
			if (!$allowed) {
				continue;
			}
			$items[] = [
				'type' => 'page',
				'id' => (int)$r['id'],
				'name' => (string)($r['title'] ?? ''),
				'color' => (string)$r['sec_color'],
				'path' => (string)$r['nb_name'] . ' › ' . (string)$r['sec_name']
					. $this->studentSuffix($r['sec_area'] ?? null, $r['sec_student'] ?? null),
				'deletedAt' => (int)$r['deleted_at'],
			];
		}
		usort($items, static fn ($a, $b) => $b['deletedAt'] <=> $a['deletedAt']);

		$days = $this->getRetentionDays();
		foreach ($items as &$item) {
			$item['purgeAt'] = $days > 0 ? $item['deletedAt'] + $days * 86400 : null;
		}
		unset($item);

		return ['retentionDays' => $days, 'items' => $items];
	}

	private function studentSuffix(?string $area, ?string $studentId): string {
		return ($area === 'student' && $studentId) ? ' (' . $this->access->displayName($studentId) . ')' : '';
	}

	public function restore(string $userId, string $type, int $id): void {
		$entity = $this->findTrashed($userId, $type, $id);
		$entity->setDeletedAt(0);
		if ($type !== 'notebook') {
			$entity->setDeletedBy(null);
		}
		$entity->setUpdatedAt($this->time->getTime());
		match ($type) {
			'notebook' => $this->notebooks->update($entity),
			'section' => $this->sections->update($entity),
			'page' => $this->pages->update($entity),
		};
	}

	public function deleteForever(string $userId, string $type, int $id): void {
		$entity = $this->findTrashed($userId, $type, $id);
		$this->purge($type, [$entity->getId()]);
	}

	/** @return int Anzahl endgültig gelöschter Einträge */
	public function emptyTrash(string $userId): int {
		$list = $this->list($userId)['items'];
		$byType = ['notebook' => [], 'section' => [], 'page' => []];
		foreach ($list as $item) {
			$byType[$item['type']][] = $item['id'];
		}
		foreach ($byType as $type => $ids) {
			if ($ids !== []) {
				$this->purge($type, $ids);
			}
		}
		return count($list);
	}

	/**
	 * Hintergrundjob: alles, was länger als die Aufbewahrungsdauer im Papierkorb liegt.
	 * @return int Anzahl gelöschter Einträge (oberste Ebene)
	 */
	public function purgeExpired(): int {
		$days = $this->getRetentionDays();
		if ($days === 0) {
			return 0;
		}
		$cutoff = $this->time->getTime() - $days * 86400;
		$count = 0;
		foreach ([
			'notebook' => $this->notebooks->findExpiredIds($cutoff),
			'section' => $this->sections->findExpiredIds($cutoff),
			'page' => $this->pages->findExpiredIds($cutoff),
		] as $type => $ids) {
			if ($ids !== []) {
				$this->purge($type, $ids);
				$count += count($ids);
			}
		}
		return $count;
	}

	/**
	 * Nextcloud-Konto wurde gelöscht:
	 *  - persönliche Notizbücher endgültig löschen
	 *  - eigene Kursnotizbücher an eine andere Lehrkraft übergeben, sonst löschen
	 *  - Schülerabschnitte des Kontos löschen, Mitgliedschaften entfernen
	 */
	public function deleteAllForUser(string $userId): void {
		$purge = [];
		foreach ($this->notebooks->findAllIdsForUser($userId) as $id) {
			try {
				$nb = $this->notebooks->findById($id);
			} catch (DoesNotExistException) {
				continue;
			}
			$heir = null;
			if ($nb->isClass()) {
				foreach ($this->members->findByNotebook($id) as $m) {
					if ($m['role'] === 'teacher' && $m['type'] === 'user' && $m['principal'] !== $userId) {
						$heir = $m['principal'];
						break;
					}
				}
			}
			if ($heir !== null) {
				$nb->setUserId($heir);
				$this->notebooks->update($nb);
			} else {
				$purge[] = $id;
			}
		}
		if ($purge !== []) {
			$this->purge('notebook', $purge);
		}
		$studentSections = $this->sections->findIdsByStudent($userId);
		if ($studentSections !== []) {
			$this->purge('section', $studentSections);
		}
		$this->members->deletePrincipal('user', $userId);
	}

	/** Gruppe wurde gelöscht: Mitgliedschaften dieser Gruppe entfernen */
	public function removeGroup(string $groupId): void {
		$this->members->deletePrincipal('group', $groupId);
	}

	// ---------- intern ----------

	/** Endgültig löschen inkl. aller enthaltenen Einträge, Tags und Bilder */
	private function purge(string $type, array $ids): void {
		$pageIds = [];
		$this->db->beginTransaction();
		try {
			switch ($type) {
				case 'notebook':
					$sectionIds = $this->sections->findIdsByNotebooks($ids);
					$pageIds = $this->pages->findIdsBySections($sectionIds);
					$this->tags->deleteByPages($pageIds);
					$this->pages->deleteBySections($sectionIds);
					$this->sections->deleteByIds($sectionIds);
					$this->notebooks->deleteByIds($ids);
					$this->members->deleteByNotebooks($ids);
					break;
				case 'section':
					$pageIds = $this->pages->findIdsBySections($ids);
					$this->tags->deleteByPages($pageIds);
					$this->pages->deleteBySections($ids);
					$this->sections->deleteByIds($ids);
					break;
				case 'page':
					$pageIds = $ids;
					$this->tags->deleteByPages($pageIds);
					$this->pages->deleteByIds($ids);
					break;
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Bilder erst nach erfolgreichem Löschen in der Datenbank entfernen
		$this->images->deleteForPages($pageIds);
	}

	/**
	 * Bilder entfernen, die auf keiner Seite mehr verwendet werden
	 * (z. B. Bild-Block gelöscht). Seiten im Papierkorb behalten ihre Bilder.
	 */
	public function cleanupImages(): int {
		return $this->images->cleanup(function (int $pageId): ?array {
			try {
				$page = $this->pages->findById($pageId);
			} catch (DoesNotExistException) {
				return null;
			}
			return $this->sanitizer->extractImageRefs($page->getContent());
		}, $this->time->getTime());
	}

	/**
	 * Eintrag im Papierkorb, auf den der Benutzer zugreifen darf – nur oberste Ebene
	 * (z. B. keine Seite, deren Abschnitt selbst im Papierkorb liegt).
	 */
	private function findTrashed(string $userId, string $type, int $id): \OCP\AppFramework\Db\Entity {
		$notFound = new NotFoundException('Eintrag nicht im Papierkorb gefunden.');
		try {
			switch ($type) {
				case 'notebook':
					return $this->notebooks->findForUser($id, $userId, true);

				case 'section':
					$s = $this->sections->findById($id);
					if ((int)$s->getDeletedAt() === 0) {
						throw $notFound;
					}
					$nb = $this->notebooks->findLive($s->getNotebookId());
					if (!$this->access->isTeacher($userId, $nb)) {
						throw $notFound;
					}
					return $s;

				case 'page':
					$p = $this->pages->findById($id);
					if ((int)$p->getDeletedAt() === 0) {
						throw $notFound;
					}
					$s = $this->sections->findById($p->getSectionId());
					if ((int)$s->getDeletedAt() > 0) {
						throw $notFound;
					}
					$nb = $this->notebooks->findLive($s->getNotebookId());
					$allowed = $this->access->isTeacher($userId, $nb)
						|| ($p->getDeletedBy() === $userId && $this->access->canWriteSection($userId, $nb, $s));
					if (!$allowed) {
						throw $notFound;
					}
					return $p;
			}
		} catch (DoesNotExistException) {
			throw $notFound;
		}
		throw new ValidationException('Unbekannter Typ.');
	}
}
