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
 * @method int getNotebookId()
 * @method void setNotebookId(int $notebookId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getArea()
 * @method void setArea(string $area)
 * @method string|null getStudentId()
 * @method void setStudentId(?string $studentId)
 * @method string|null getDeletedBy()
 * @method void setDeletedBy(?string $deletedBy)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Section extends Entity implements JsonSerializable {
	protected $notebookId;
	protected $name;
	protected $color;
	protected $sortOrder;
	protected $createdAt;
	protected $updatedAt;
	protected $deletedAt;
	protected $area;
	protected $studentId;
	protected $deletedBy;

	public function __construct() {
		$this->addType('notebookId', Types::BIGINT);
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'notebookId' => (int)$this->notebookId,
			'name' => $this->name,
			'color' => $this->color,
			'sortOrder' => (int)$this->sortOrder,
			'createdAt' => (int)$this->createdAt,
			'updatedAt' => (int)$this->updatedAt,
			'area' => $this->area ?: 'normal',
			'studentId' => $this->studentId,
		];
	}
}
