<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getSectionId()
 * @method void setSectionId(int $sectionId)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getContent()
 * @method void setContent(?string $content)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string|null getDeletedBy()
 * @method void setDeletedBy(?string $deletedBy)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method string|null getSearchText()
 * @method void setSearchText(?string $searchText)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 */
class Page extends Entity implements JsonSerializable {
	protected $sectionId;
	protected $title;
	protected $content;
	protected $sortOrder;
	protected $createdAt;
	protected $updatedAt;
	protected $deletedAt;
	protected $deletedBy;
	protected $updatedBy;
	protected $revision;
	protected $searchText;

	public function __construct() {
		$this->addType('sectionId', Types::BIGINT);
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
		$this->addType('revision', Types::INTEGER);
	}

	/** Kurzform für Seitenlisten (ohne Inhalt) */
	public function toSummary(): array {
		return [
			'id' => $this->getId(),
			'sectionId' => (int)$this->sectionId,
			'title' => (string)($this->title ?? ''),
			'sortOrder' => (int)$this->sortOrder,
			'createdAt' => (int)$this->createdAt,
			'updatedAt' => (int)$this->updatedAt,
		];
	}

	/** Antwort nach dem Speichern – ohne den (großen) Inhalt */
	public function toSaveResult(): array {
		return $this->toSummary() + [
			'updatedBy' => $this->updatedBy,
			'revision' => (int)$this->revision,
		];
	}

	public function jsonSerialize(): array {
		return $this->toSummary() + [
			'content' => $this->content,
			'updatedBy' => $this->updatedBy,
			'revision' => (int)$this->revision,
		];
	}
}
