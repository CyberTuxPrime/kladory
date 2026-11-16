<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCA\Kladory\Db\MemberMapper;
use OCA\Kladory\Db\Notebook;
use OCA\Kladory\Db\PageMapper;
use OCA\Kladory\Db\SectionMapper;
use OCA\Kladory\Db\TagMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ITempManager;
use OCP\IUserManager;
use ZipArchive;

/**
 * Export/Import von Notizbüchern als .kladory-Datei (ZIP mit data.json + Bildern).
 *
 * Exportiert werden:
 *  - eigene persönliche Notizbücher
 *  - Kursnotizbücher, in denen der Benutzer Besitzer oder Lehrkraft ist (komplett, inkl. aller Schülerbereiche und Mitglieder)
 *  - als Schüler: der eigene Bereich in Kursnotizbüchern (wird beim Import zu einem persönlichen Notizbuch)
 * Gelöschte Einträge (Papierkorb) werden nicht exportiert.
 */
class ExportService {
	public const FORMAT = 'kladory-export';
	public const FORMAT_VERSION = 1;
	public const FOLDER = 'Kladory-Export';
	public const EXTENSION = 'kladory';

	private const MAX_DATA_JSON = 200 * 1024 * 1024;
	private const MAX_ENTRIES = 100000;
	private const MAX_NOTEBOOKS = 500;
	private const MAX_SECTIONS = 20000;
	private const MAX_PAGES = 100000;

	public function __construct(
		private NoteService $notes,
		private ClassService $classes,
		private AccessService $access,
		private SectionMapper $sections,
		private PageMapper $pages,
		private TagMapper $tags,
		private MemberMapper $members,
		private ImageStore $images,
		private ContentSanitizer $sanitizer,
		private IUserManager $users,
		private IGroupManager $groups,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	// =================================================================== Auswahl

	/**
	 * Was kann der Benutzer exportieren?
	 * @return array{notebooks: array<int, array<string, mixed>>, studentAreas: array<int, array<string, mixed>>}
	 */
	public function options(string $userId): array {
		$notebooks = [];
		$areas = [];
		foreach ($this->notes->visibleNotebooks($userId) as $nb) {
			$role = $this->access->role($userId, $nb);
			if (!$nb->isClass() || AccessService::isTeacherRole($role)) {
				$notebooks[] = [
					'id' => $nb->getId(),
					'name' => $nb->getName(),
					'color' => $nb->getColor(),
					'kind' => $nb->isClass() ? 'class' : 'personal',
					'role' => $role,
				];
			} elseif ($role === 'student') {
				$areas[] = [
					'id' => $nb->getId(),
					'name' => $nb->getName(),
					'color' => $nb->getColor(),
				];
			}
		}
		return ['notebooks' => $notebooks, 'studentAreas' => $areas];
	}

	// =================================================================== Export

	/**
	 * Erstellt die Exportdatei.
	 * @param int[]|null $notebookIds null = alle exportierbaren
	 * @param int[]|null $studentAreaIds null = alle
	 * @return array{path: string, filename: string, notebooks: int, pages: int, images: int}
	 */
	public function export(string $userId, ?array $notebookIds = null, ?array $studentAreaIds = null): array {
		$options = $this->options($userId);
		$wantNb = $notebookIds === null ? null : array_map('intval', $notebookIds);
		$wantArea = $studentAreaIds === null ? null : array_map('intval', $studentAreaIds);

		$tmp = $this->tempManager->getTemporaryFile('.' . self::EXTENSION);
		if ($tmp === false) {
			throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
		}
		$zip = new ZipArchive();
		if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Exportdatei konnte nicht angelegt werden.');
		}

		$stats = ['notebooks' => 0, 'pages' => 0, 'images' => 0];
		$pageCounter = 0;
		$out = [];

		foreach ($options['notebooks'] as $opt) {
			if ($wantNb !== null && !in_array($opt['id'], $wantNb, true)) {
				continue;
			}
			$nb = $this->notes->requireNotebook($userId, $opt['id'], $opt['kind'] === 'class' ? 'manage' : 'own');
			$out[] = $this->exportNotebook($nb, null, $zip, $stats, $pageCounter);
			$stats['notebooks']++;
		}
		foreach ($options['studentAreas'] as $opt) {
			if ($wantArea !== null && !in_array($opt['id'], $wantArea, true)) {
				continue;
			}
			$nb = $this->notes->requireNotebook($userId, $opt['id'], 'read');
			$out[] = $this->exportNotebook($nb, $userId, $zip, $stats, $pageCounter);
			$stats['notebooks']++;
		}

		$data = [
			'format' => self::FORMAT,
			'formatVersion' => self::FORMAT_VERSION,
			'exportedAt' => gmdate('c', $this->time->getTime()),
			'exportedBy' => $userId,
			'notebooks' => $out,
		];
		$zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$zip->addFromString('LIESMICH.txt', "Kladory-Export\n\nDiese Datei enthält Notizbücher aus Kladory (Nextcloud).\n"
			. "Importieren: In Kladory unten links auf \"Export / Import\" tippen und diese Datei wählen.\n"
			. "Nicht entpacken und wieder einpacken – die Datei muss unverändert bleiben.\n");
		$zip->close();

		$safeUser = preg_replace('/[^A-Za-z0-9._-]/', '_', $userId) ?? 'user';
		$filename = 'Kladory-Export-' . $safeUser . '-' . date('Y-m-d-Hi', $this->time->getTime()) . '.' . self::EXTENSION;
		return ['path' => $tmp, 'filename' => $filename] + $stats;
	}

	/**
	 * @param string|null $onlyStudent nur den Schülerbereich dieses Schülers (als persönliches Notizbuch)
	 */
	private function exportNotebook(Notebook $nb, ?string $onlyStudent, ZipArchive $zip, array &$stats, int &$pageCounter): array {
		$isClass = $nb->isClass() && $onlyStudent === null;
		$studentNames = $isClass ? $this->access->students($nb) : [];
		$sections = [];
		foreach ($this->sections->findByNotebooks([$nb->getId()]) as $s) {
			if ($onlyStudent !== null && !($s->getArea() === 'student' && $s->getStudentId() === $onlyStudent)) {
				continue;
			}
			$pages = [];
			foreach ($this->pages->findBySection($s->getId()) as $summary) {
				$page = $this->pages->findById($summary->getId());
				$ref = 'p' . (++$pageCounter);
				$content = $page->getContent();
				foreach ($this->sanitizer->extractImageRefs($content) as $name) {
					try {
						$zip->addFromString('images/' . $ref . '/' . $name, $this->images->get($page->getId(), $name)->getContent());
						$stats['images']++;
					} catch (NotFoundException) {
						// Bild fehlt – Seite trotzdem exportieren
					}
				}
				$pages[] = [
					'ref' => $ref,
					'title' => (string)($page->getTitle() ?? ''),
					'content' => $content,
					'tags' => $this->tags->getForPage($page->getId()),
					'createdAt' => (int)$page->getCreatedAt(),
					'updatedAt' => (int)$page->getUpdatedAt(),
				];
				$stats['pages']++;
			}
			$entry = [
				'name' => $s->getName(),
				'color' => $s->getColor(),
				'area' => $isClass ? $s->getArea() : 'normal',
				'pages' => $pages,
			];
			if ($isClass && $s->getArea() === 'student' && $s->getStudentId() !== null) {
				$entry['studentId'] = $s->getStudentId();
				$entry['studentName'] = $studentNames[$s->getStudentId()] ?? $this->access->displayName($s->getStudentId());
			}
			$sections[] = $entry;
		}

		$json = [
			'name' => $onlyStudent !== null ? $nb->getName() . ' – mein Bereich' : $nb->getName(),
			'color' => $nb->getColor(),
			'kind' => $isClass ? 'class' : 'personal',
			'sections' => $sections,
		];
		if ($isClass) {
			$json['settings'] = $nb->getSettingsArray();
			$json['owner'] = $nb->getUserId();
			$json['members'] = $this->access->membersOf($nb->getId());
		}
		return $json;
	}

	// =================================================================== Import

	/**
	 * Importiert eine .kladory-Datei für den Benutzer. Alles wird neu angelegt, nichts überschrieben.
	 * @return array{notebooks:int, sections:int, pages:int, images:int, warnings: string[]}
	 */
	public function import(string $userId, string $zipPath): array {
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
			throw new ValidationException('Die Datei ist keine gültige Kladory-Exportdatei.');
		}
		try {
			if ($zip->numFiles > self::MAX_ENTRIES) {
				throw new ValidationException('Die Exportdatei enthält zu viele Einträge.');
			}
			$stat = $zip->statName('data.json');
			if ($stat === false || $stat['size'] > self::MAX_DATA_JSON) {
				throw new ValidationException('Die Datei ist keine gültige Kladory-Exportdatei.');
			}
			$data = json_decode((string)$zip->getFromName('data.json'), true);
			if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT || !is_array($data['notebooks'] ?? null)) {
				throw new ValidationException('Die Datei ist keine gültige Kladory-Exportdatei.');
			}
			if ((int)($data['formatVersion'] ?? 0) > self::FORMAT_VERSION) {
				throw new ValidationException('Die Exportdatei stammt aus einer neueren Kladory-Version. Bitte Kladory aktualisieren.');
			}
			$this->checkLimits($data['notebooks']);
			return $this->importData($userId, $data['notebooks'], $zip);
		} finally {
			$zip->close();
		}
	}

	private function checkLimits(array $notebooks): void {
		$sec = 0;
		$pages = 0;
		foreach ($notebooks as $nb) {
			foreach ((is_array($nb['sections'] ?? null) ? $nb['sections'] : []) as $s) {
				$sec++;
				$pages += is_array($s['pages'] ?? null) ? count($s['pages']) : 0;
			}
		}
		if (count($notebooks) > self::MAX_NOTEBOOKS || $sec > self::MAX_SECTIONS || $pages > self::MAX_PAGES) {
			throw new ValidationException('Die Exportdatei ist zu groß für einen Import.');
		}
	}

	private function importData(string $userId, array $notebooks, ZipArchive $zip): array {
		$canClass = $this->classes->canCreate($userId);
		$existingNames = array_map(static fn (Notebook $n) => mb_strtolower($n->getName()), $this->notes->visibleNotebooks($userId));
		$stats = ['notebooks' => 0, 'sections' => 0, 'pages' => 0, 'images' => 0, 'warnings' => []];
		$now = $this->time->getTime();
		$imageJobs = [];

		$this->db->beginTransaction();
		try {
			foreach ($notebooks as $nbData) {
				if (!is_array($nbData)) {
					continue;
				}
				$asClass = ($nbData['kind'] ?? '') === 'class' && $canClass;
				if (($nbData['kind'] ?? '') === 'class' && !$canClass) {
					$stats['warnings'][] = '„' . $this->str($nbData['name'] ?? '') . '“ wurde als persönliches Notizbuch importiert (keine Berechtigung für Kursnotizbücher).';
				}
				$name = trim($this->str($nbData['name'] ?? '')) ?: 'Importiertes Notizbuch';
				if (in_array(mb_strtolower($name), $existingNames, true)) {
					$name .= ' (Import)';
				}
				$existingNames[] = mb_strtolower($name);

				$settings = null;
				if ($asClass) {
					$names = $nbData['settings']['studentSections'] ?? [];
					$names = is_array($names) ? array_values(array_slice(array_filter($names, 'is_string'), 0, 8)) : [];
					$settings = ['studentSections' => $names];
				}
				$nb = $this->notes->insertNotebook($userId, $name, $this->color($nbData['color'] ?? null), $asClass ? 'class' : 'personal', $settings);
				$stats['notebooks']++;

				if ($asClass) {
					$stats['warnings'] = array_merge($stats['warnings'], $this->importMembers($nb, $nbData['members'] ?? [], $userId, $nbData['owner'] ?? null));
				}

				foreach ((is_array($nbData['sections'] ?? null) ? $nbData['sections'] : []) as $sData) {
					if (!is_array($sData)) {
						continue;
					}
					$area = 'normal';
					$studentId = null;
					$sName = trim($this->str($sData['name'] ?? '')) ?: 'Abschnitt';
					if ($asClass) {
						$area = in_array($sData['area'] ?? '', AccessService::AREAS, true) ? $sData['area'] : 'collab';
						if ($area === 'student') {
							$studentId = is_string($sData['studentId'] ?? null) && $sData['studentId'] !== '' ? $sData['studentId'] : null;
							if ($studentId === null) {
								$area = 'collab';
							}
						}
					} elseif (($sData['area'] ?? '') === 'student' && is_string($sData['studentName'] ?? null)) {
						// Kursnotizbuch ohne Berechtigung als persönliches importiert: Schülername im Abschnittsnamen behalten
						$sName .= ' (' . $sData['studentName'] . ')';
					}
					$section = $this->notes->insertSection($nb->getId(), $sName, $this->color($sData['color'] ?? null), $area, $studentId, $now);
					$stats['sections']++;

					foreach ((is_array($sData['pages'] ?? null) ? $sData['pages'] : []) as $pData) {
						if (!is_array($pData)) {
							continue;
						}
						$content = null;
						if (is_string($pData['content'] ?? null) && $pData['content'] !== '') {
							try {
								$content = $this->sanitizer->sanitizeContent($pData['content']);
							} catch (ValidationException) {
								$stats['warnings'][] = 'Inhalt einer Seite war ungültig und wurde nicht übernommen: „' . $this->str($pData['title'] ?? '') . '“';
							}
						}
						$page = $this->notes->insertPage($section->getId(), $this->str($pData['title'] ?? ''), $content, $userId);
						$created = $pData['createdAt'] ?? null;
						$updated = $pData['updatedAt'] ?? null;
						if (is_int($created) && is_int($updated) && $created > 0 && $updated > 0 && $updated <= $now + 86400) {
							$page->setCreatedAt($created);
							$page->setUpdatedAt($updated);
							$this->pages->update($page);
						}
						$tags = [];
						foreach ((is_array($pData['tags'] ?? null) ? $pData['tags'] : []) as $t) {
							if (is_string($t) && ($t = NoteService::normalizeTag($t)) !== '') {
								$tags[$t] = true;
							}
						}
						if ($tags !== []) {
							$this->tags->setForPage($page->getId(), array_slice(array_keys($tags), 0, 20));
						}
						$ref = is_string($pData['ref'] ?? null) && preg_match('/^p\d{1,9}$/', $pData['ref']) ? $pData['ref'] : null;
						if ($ref !== null) {
							foreach ($this->sanitizer->extractImageRefs($content) as $imgName) {
								$imageJobs[] = [$page->getId(), $ref, $imgName];
							}
						}
						$stats['pages']++;
					}
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Bilder nach erfolgreichem Datenbank-Import übernehmen
		foreach ($imageJobs as [$pageId, $ref, $imgName]) {
			$entry = 'images/' . $ref . '/' . $imgName;
			$stat = $zip->statName($entry);
			if ($stat === false || $stat['size'] > ImageStore::MAX_BYTES) {
				$stats['warnings'][] = 'Ein Bild fehlte in der Exportdatei.';
				continue;
			}
			try {
				$this->images->storeNamed($pageId, $imgName, (string)$zip->getFromName($entry));
				$stats['images']++;
			} catch (ValidationException) {
				$stats['warnings'][] = 'Ein Bild war ungültig und wurde übersprungen.';
			}
		}
		$stats['warnings'] = array_values(array_unique($stats['warnings']));
		return $stats;
	}

	/** @return string[] Warnungen */
	private function importMembers(Notebook $nb, mixed $members, string $userId, mixed $oldOwner): array {
		$warnings = [];
		$clean = [];
		$list = is_array($members) ? $members : [];
		// Ursprünglicher Besitzer wird Mitlehrkraft, falls es nicht der Importierende ist
		if (is_string($oldOwner) && $oldOwner !== $userId) {
			$list[] = ['type' => 'user', 'principal' => $oldOwner, 'role' => 'teacher'];
		}
		foreach ($list as $m) {
			if (!is_array($m)) {
				continue;
			}
			$type = $m['type'] ?? '';
			$id = is_string($m['principal'] ?? null) ? $m['principal'] : '';
			$role = $m['role'] ?? '';
			if (!in_array($type, ['user', 'group'], true) || !in_array($role, ['teacher', 'student'], true) || $id === '') {
				continue;
			}
			if ($type === 'user' && $id === $userId) {
				continue;
			}
			$exists = $type === 'user' ? $this->users->userExists($id) : $this->groups->groupExists($id);
			if (!$exists) {
				$warnings[] = ($type === 'user' ? 'Benutzer' : 'Gruppe') . ' „' . $id . '“ gibt es nicht mehr und wurde nicht als Mitglied übernommen.';
				continue;
			}
			$key = $type . ':' . $id;
			if (!isset($clean[$key]) || $role === 'teacher') {
				$clean[$key] = ['type' => $type, 'principal' => $id, 'role' => $role];
			}
		}
		$this->members->setForNotebook($nb->getId(), array_values($clean));
		$this->access->forget($nb->getId());
		return $warnings;
	}

	private function str(mixed $v): string {
		return is_string($v) ? mb_substr($v, 0, 255) : '';
	}

	private function color(mixed $c): ?string {
		return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : null;
	}

	// =================================================================== Nextcloud-Dateien

	/** Exportdatei im Ordner „Kladory-Export“ der Nextcloud-Dateien ablegen */
	public function saveToFiles(string $userId, string $tmpPath, string $filename): string {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		try {
			$folder = $userFolder->get(self::FOLDER);
			if (!($folder instanceof Folder)) {
				throw new ValidationException('„' . self::FOLDER . '“ ist in deinen Dateien kein Ordner.');
			}
		} catch (FileNotFoundException) {
			$folder = $userFolder->newFolder(self::FOLDER);
		}
		$stream = fopen($tmpPath, 'rb');
		if ($stream === false) {
			throw new \RuntimeException('Exportdatei konnte nicht gelesen werden.');
		}
		try {
			$folder->newFile($filename, $stream);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		return '/' . self::FOLDER . '/' . $filename;
	}

	/** @return array<int, array{id:int, name:string, size:int, mtime:int}> .kladory-Dateien im Export-Ordner */
	public function listFiles(string $userId): array {
		try {
			$folder = $this->rootFolder->getUserFolder($userId)->get(self::FOLDER);
		} catch (FileNotFoundException) {
			return [];
		}
		if (!($folder instanceof Folder)) {
			return [];
		}
		$out = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && str_ends_with(strtolower($node->getName()), '.' . self::EXTENSION)) {
				$out[] = ['id' => (int)$node->getId(), 'name' => $node->getName(), 'size' => (int)$node->getSize(), 'mtime' => (int)$node->getMTime()];
			}
		}
		usort($out, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
		return $out;
	}

	/** Datei aus den eigenen Nextcloud-Dateien importieren */
	public function importFromFiles(string $userId, int $fileId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$node = method_exists($userFolder, 'getFirstNodeById')
			? $userFolder->getFirstNodeById($fileId)
			: ($userFolder->getById($fileId)[0] ?? null);
		if (!($node instanceof File)) {
			throw new NotFoundException('Datei nicht gefunden.');
		}
		$tmp = $this->tempManager->getTemporaryFile('.' . self::EXTENSION);
		if ($tmp === false) {
			throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
		}
		$in = $node->fopen('rb');
		$out = fopen($tmp, 'wb');
		if ($in === false || $out === false) {
			throw new \RuntimeException('Datei konnte nicht gelesen werden.');
		}
		stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		try {
			return $this->import($userId, $tmp);
		} finally {
			@unlink($tmp);
		}
	}
}
