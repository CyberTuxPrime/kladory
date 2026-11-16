<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Controller;

use Closure;
use OCA\Kladory\AppInfo\Application;
use OCA\Kladory\Service\ClassService;
use OCA\Kladory\Service\ConflictException;
use OCA\Kladory\Service\ExportService;
use OCA\Kladory\Service\ForbiddenException;
use OCA\Kladory\Service\ImageStore;
use OCA\Kladory\Service\NoteService;
use OCA\Kladory\Service\NotFoundException;
use OCA\Kladory\Service\TrashService;
use OCA\Kladory\Service\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * JSON-API für die Oberfläche. CSRF-Schutz bleibt aktiv
 * (die Oberfläche sendet den requesttoken-Header mit).
 */
class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private NoteService $service,
		private TrashService $trash,
		private ImageStore $images,
		private ClassService $classes,
		private ExportService $exporter,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// ---------- Baum ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tree')]
	public function tree(): DataResponse {
		return $this->handle(fn (string $uid) => ['notebooks' => $this->service->getTree($uid)]);
	}

	// ---------- Notizbücher ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/notebooks')]
	public function createNotebook(string $name = '', ?string $color = null): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->createNotebook($uid, $name, $color), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/notebooks/{id}')]
	public function updateNotebook(int $id, ?string $name = null, ?string $color = null): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->updateNotebook($uid, $id, $name, $color));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/notebooks/{id}')]
	public function deleteNotebook(int $id): DataResponse {
		return $this->handle(function (string $uid) use ($id) {
			$this->service->deleteNotebook($uid, $id);
			return ['deleted' => $id];
		});
	}

	// ---------- Abschnitte ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/notebooks/{notebookId}/sections')]
	public function createSection(int $notebookId, string $name = '', ?string $color = null, ?string $area = null, ?string $studentId = null): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->createSection($uid, $notebookId, $name, $color, $area, $studentId), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/sections/{id}')]
	public function updateSection(int $id, ?string $name = null, ?string $color = null): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->updateSection($uid, $id, $name, $color));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/sections/{id}')]
	public function deleteSection(int $id): DataResponse {
		return $this->handle(function (string $uid) use ($id) {
			$this->service->deleteSection($uid, $id);
			return ['deleted' => $id];
		});
	}

	// ---------- Seiten ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/sections/{sectionId}/pages')]
	public function listPages(int $sectionId): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->listPages($uid, $sectionId));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/sections/{sectionId}/pages')]
	public function createPage(int $sectionId, ?string $title = null): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->createPage($uid, $sectionId, $title), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/pages/{id}')]
	public function getPage(int $id): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->getPageData($uid, $id));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/pages/{id}')]
	public function updatePage(int $id, ?string $title = null, ?string $content = null, ?int $revision = null): DataResponse {
		// Antwort ohne Inhalt: der Browser hat ihn ja gerade geschickt (spart bei Handschrift hunderte KB)
		return $this->handle(fn (string $uid) => $this->service->updatePage($uid, $id, $title, $content, $revision)->toSaveResult());
	}

	/**
	 * Speichern mit gzip-komprimiertem Inhalt (Header X-Kladory-Encoding: gzip).
	 * Eine vollgeschriebene Handschrift-Seite schrumpft dadurch auf etwa ein Zehntel.
	 * Ältere Browser ohne Komprimierung nutzen weiter PUT /api/pages/{id}.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/pages/{id}/save')]
	public function savePage(int $id): DataResponse {
		return $this->handle(function (string $uid) use ($id) {
			$raw = (string)file_get_contents('php://input', false, null, 0, NoteService::MAX_REQUEST + 1);
			$in = NoteService::decodeSaveBody($raw, strtolower((string)$this->request->getHeader('X-Kladory-Encoding')) === 'gzip');
			return $this->service->updatePage($uid, $id, $in['title'], $in['content'], $in['revision'])->toSaveResult();
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/pages/{id}')]
	public function deletePage(int $id): DataResponse {
		return $this->handle(function (string $uid) use ($id) {
			$this->service->deletePage($uid, $id);
			return ['deleted' => $id];
		});
	}

	// ---------- Bilder ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/pages/{id}/images')]
	public function uploadImage(int $id): DataResponse {
		$file = $this->request->getUploadedFile('image');
		return $this->handle(function (string $uid) use ($id, $file) {
			$this->service->requireWritablePage($uid, $id); // Berechtigung: schreiben
			if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
				throw new ValidationException('Das Bild konnte nicht hochgeladen werden.');
			}
			if ((int)($file['size'] ?? 0) > ImageStore::MAX_BYTES) {
				throw new ValidationException('Das Bild ist zu groß (maximal 15 MB).');
			}
			$data = (string)file_get_contents((string)$file['tmp_name']);
			return ['src' => $this->images->store($id, $data)];
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/pages/{id}/images/{name}', requirements: ['id' => '\\d+', 'name' => '[a-f0-9]{32}\\.(png|jpg|gif|webp)'])]
	public function image(int $id, string $name): Response {
		if ($this->userId === null) {
			return new NotFoundResponse();
		}
		try {
			$this->service->getPage($this->userId, $id); // Berechtigung
			$file = $this->images->get($id, $name);
		} catch (NotFoundException) {
			return new NotFoundResponse();
		}
		$response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => ImageStore::mimeFor($name)]);
		// Dateinamen sind zufällig und ändern sich nie -> lange privat cachen
		$response->cacheFor(30 * 24 * 3600, false, true);
		return $response;
	}

	// ---------- Kursnotizbücher ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/capabilities')]
	public function capabilities(): DataResponse {
		return $this->handle(fn (string $uid) => ['canCreateClass' => $this->classes->canCreate($uid)]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/classes')]
	public function createClass(string $name = '', ?string $color = null, mixed $members = [], mixed $studentSections = []): DataResponse {
		return $this->handle(fn (string $uid) => $this->classes->create($uid, $name, $color, $members, $studentSections), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/notebooks/{id}/members')]
	public function getMembers(int $id): DataResponse {
		return $this->handle(fn (string $uid) => $this->classes->getMembers($uid, $id));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/notebooks/{id}/members')]
	public function setMembers(int $id, mixed $members = []): DataResponse {
		return $this->handle(fn (string $uid) => $this->classes->setMembers($uid, $id, $members));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/notebooks/{id}/student-sections')]
	public function addStudentSection(int $id, string $name = ''): DataResponse {
		return $this->handle(fn (string $uid) => $this->classes->addStudentSection($uid, $id, $name), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/pages/{id}/distribute')]
	public function distribute(int $id, string $sectionName = ''): DataResponse {
		return $this->handle(fn (string $uid) => $this->classes->distribute($uid, $id, $sectionName));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/principals')]
	public function principals(string $q = ''): DataResponse {
		return $this->handle(fn (string $uid) => $this->classes->searchPrincipals($uid, $q));
	}

	// ---------- Export / Import ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/export/options')]
	public function exportOptions(): DataResponse {
		return $this->handle(fn (string $uid) => $this->exporter->options($uid));
	}

	/**
	 * target = download: liefert die Datei; target = files: legt sie in „Kladory-Export“ ab.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/export')]
	public function export(mixed $notebooks = null, mixed $studentAreas = null, string $target = 'download'): Response {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'Nicht angemeldet.'], Http::STATUS_UNAUTHORIZED);
		}
		$nbIds = is_array($notebooks) ? array_map('intval', $notebooks) : null;
		$areaIds = is_array($studentAreas) ? array_map('intval', $studentAreas) : null;
		if ($target === 'files') {
			return $this->handle(function (string $uid) use ($nbIds, $areaIds) {
				$r = $this->exporter->export($uid, $nbIds, $areaIds);
				try {
					$path = $this->exporter->saveToFiles($uid, $r['path'], $r['filename']);
				} finally {
					@unlink($r['path']);
				}
				return ['path' => $path, 'notebooks' => $r['notebooks'], 'pages' => $r['pages'], 'images' => $r['images']];
			});
		}
		try {
			$r = $this->exporter->export($this->userId, $nbIds, $areaIds);
			$data = (string)file_get_contents($r['path']);
			@unlink($r['path']);
			return new DataDownloadResponse($data, $r['filename'], 'application/zip');
		} catch (\Throwable $e) {
			$this->logger->error('Kladory Export: ' . $e->getMessage(), ['exception' => $e, 'app' => Application::APP_ID]);
			return new DataResponse(['message' => 'Export fehlgeschlagen.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/import/files')]
	public function importFiles(): DataResponse {
		return $this->handle(fn (string $uid) => $this->exporter->listFiles($uid));
	}

	/** Import per Upload (Feld „file“) oder aus den Nextcloud-Dateien (fileId) */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/import')]
	public function import(?int $fileId = null): DataResponse {
		$upload = $this->request->getUploadedFile('file');
		return $this->handle(function (string $uid) use ($fileId, $upload) {
			if ($fileId !== null && $fileId > 0) {
				return $this->exporter->importFromFiles($uid, $fileId);
			}
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
				throw new ValidationException('Die Datei konnte nicht hochgeladen werden.');
			}
			return $this->exporter->import($uid, (string)$upload['tmp_name']);
		});
	}

	// ---------- Suche & Tags ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/search')]
	public function search(string $q = '', ?string $tag = null): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->search($uid, $q, $tag));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tags')]
	public function tags(): DataResponse {
		return $this->handle(fn (string $uid) => $this->service->listTags($uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/pages/{id}/tags')]
	public function setTags(int $id, mixed $tags = []): DataResponse {
		return $this->handle(fn (string $uid) => ['tags' => $this->service->setTags($uid, $id, $tags)]);
	}

	// ---------- Papierkorb ----------

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/trash')]
	public function trashList(): DataResponse {
		return $this->handle(fn (string $uid) => $this->trash->list($uid));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/trash/{type}/{id}/restore', requirements: ['type' => 'notebook|section|page', 'id' => '\\d+'])]
	public function trashRestore(string $type, int $id): DataResponse {
		return $this->handle(function (string $uid) use ($type, $id) {
			$this->trash->restore($uid, $type, $id);
			return ['restored' => $id, 'type' => $type];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/trash/{type}/{id}', requirements: ['type' => 'notebook|section|page', 'id' => '\\d+'])]
	public function trashDelete(string $type, int $id): DataResponse {
		return $this->handle(function (string $uid) use ($type, $id) {
			$this->trash->deleteForever($uid, $type, $id);
			return ['deleted' => $id, 'type' => $type];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/trash')]
	public function trashEmpty(): DataResponse {
		return $this->handle(fn (string $uid) => ['deleted' => $this->trash->emptyTrash($uid)]);
	}

	// ---------- Fehlerbehandlung ----------

	private function handle(Closure $fn, int $status = Http::STATUS_OK): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['message' => 'Nicht angemeldet.'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new DataResponse($fn($this->userId), $status);
		} catch (ConflictException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'page' => $e->getPage()], Http::STATUS_CONFLICT);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Kladory: ' . $e->getMessage(), ['exception' => $e, 'app' => Application::APP_ID]);
			return new DataResponse(['message' => 'Interner Fehler.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
