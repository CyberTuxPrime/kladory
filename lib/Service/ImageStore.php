<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCA\Kladory\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;

/**
 * Bilder der Seiten. Gespeichert in den App-Daten von Nextcloud
 * (data/appdata_<instanz>/kladory/page-<id>/<zufall>.<endung>),
 * also nicht im Dateibereich der Nutzer und nicht auf deren Speicherplatz angerechnet.
 * Zugriff nur über die Kladory-API mit Berechtigungsprüfung.
 */
class ImageStore {
	public const MAX_BYTES = 15 * 1024 * 1024;

	private const TYPES = [
		IMAGETYPE_PNG => ['png', 'image/png'],
		IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
		IMAGETYPE_GIF => ['gif', 'image/gif'],
		IMAGETYPE_WEBP => ['webp', 'image/webp'],
	];

	private IAppData $appData;

	public function __construct(IAppDataFactory $factory) {
		$this->appData = $factory->get(Application::APP_ID);
	}

	/** @return string Dateiname des gespeicherten Bildes */
	public function store(int $pageId, string $data): string {
		$ext = $this->detect($data);
		$name = bin2hex(random_bytes(16)) . '.' . $ext;
		$this->folder($pageId, true)->newFile($name, $data);
		return $name;
	}

	/**
	 * Bild unter festem Namen speichern (Import). Der Name muss zum Inhalt passen.
	 * @throws ValidationException
	 */
	public function storeNamed(int $pageId, string $name, string $data): void {
		if (!preg_match(ContentSanitizer::IMAGE_NAME_RE, $name)) {
			throw new ValidationException('Ungültiger Bildname.');
		}
		$ext = $this->detect($data);
		if (pathinfo($name, PATHINFO_EXTENSION) !== $ext) {
			throw new ValidationException('Bildtyp passt nicht zum Namen.');
		}
		$folder = $this->folder($pageId, true);
		if (!$folder->fileExists($name)) {
			$folder->newFile($name, $data);
		}
	}

	/** Typ am Inhalt erkennen, nicht an Dateiname/Angabe des Browsers (SVG o. Ä. wird abgelehnt) */
	private function detect(string $data): string {
		if ($data === '' || strlen($data) > self::MAX_BYTES) {
			throw new ValidationException('Das Bild ist zu groß (maximal 15 MB).');
		}
		$info = @getimagesizefromstring($data);
		if ($info === false || !isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
			throw new ValidationException('Nur PNG, JPG, GIF oder WebP sind erlaubt.');
		}
		// Zusätzlich vollständig dekodieren (sofern GD vorhanden), damit nur echte Bilder angenommen werden
		if ($info[2] !== IMAGETYPE_WEBP && function_exists('imagecreatefromstring')) {
			$im = @imagecreatefromstring($data);
			if ($im === false) {
				throw new ValidationException('Die Bilddatei ist beschädigt oder ungültig.');
			}
			imagedestroy($im);
		}
		return self::TYPES[$info[2]][0];
	}

	/** @throws NotFoundException */
	public function get(int $pageId, string $name): ISimpleFile {
		if (!preg_match(ContentSanitizer::IMAGE_NAME_RE, $name)) {
			throw new NotFoundException('Bild nicht gefunden.');
		}
		try {
			return $this->folder($pageId, false)->getFile($name);
		} catch (FileNotFoundException) {
			throw new NotFoundException('Bild nicht gefunden.');
		}
	}

	public static function mimeFor(string $name): string {
		return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			default => 'application/octet-stream',
		};
	}

	/**
	 * Bilder einer Seite für eine kopierte Seite übernehmen (z. B. beim Verteilen).
	 * @param string[] $names
	 */
	public function copy(int $fromPageId, int $toPageId, array $names): void {
		$target = null;
		foreach ($names as $name) {
			try {
				$file = $this->get($fromPageId, $name);
			} catch (NotFoundException) {
				continue;
			}
			$target ??= $this->folder($toPageId, true);
			if (!$target->fileExists($name)) {
				$target->newFile($name, $file->getContent());
			}
		}
	}

	/** @param int[] $pageIds */
	public function deleteForPages(array $pageIds): void {
		foreach ($pageIds as $id) {
			try {
				$this->appData->getFolder('page-' . (int)$id)->delete();
			} catch (FileNotFoundException) {
				// keine Bilder
			}
		}
	}

	/**
	 * Aufräumen: Bilder, die auf keiner Seite mehr vorkommen und älter als $minAge Sekunden sind.
	 * $resolve(pageId) liefert die Liste der verwendeten Dateinamen oder null, wenn die Seite nicht mehr existiert.
	 * @param callable(int): (string[]|null) $resolve
	 * @return int Anzahl gelöschter Dateien
	 */
	public function cleanup(callable $resolve, int $now, int $minAge = 86400): int {
		$deleted = 0;
		foreach ($this->appData->getDirectoryListing() as $folder) {
			if (!preg_match('/^page-(\d+)$/', $folder->getName(), $m)) {
				continue;
			}
			$used = $resolve((int)$m[1]);
			if ($used === null) {
				$deleted += count($folder->getDirectoryListing());
				$folder->delete();
				continue;
			}
			foreach ($folder->getDirectoryListing() as $file) {
				if (!in_array($file->getName(), $used, true) && $file->getMTime() < $now - $minAge) {
					$file->delete();
					$deleted++;
				}
			}
		}
		return $deleted;
	}

	private function folder(int $pageId, bool $create): \OCP\Files\SimpleFS\ISimpleFolder {
		$name = 'page-' . $pageId;
		try {
			return $this->appData->getFolder($name);
		} catch (FileNotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $this->appData->newFolder($name);
		}
	}
}
