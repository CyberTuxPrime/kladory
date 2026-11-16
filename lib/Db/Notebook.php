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
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getSettings()
 * @method void setSettings(?string $settings)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Notebook extends Entity implements JsonSerializable {
	protected $userId;
	protected $name;
	protected $color;
	protected $sortOrder;
	protected $createdAt;
	protected $updatedAt;
	protected $deletedAt;
	protected $kind;
	protected $settings;

	public function __construct() {
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
	}

	public function isClass(): bool {
		return $this->kind === 'class';
	}

	/** @return array<string, mixed> */
	public function getSettingsArray(): array {
		$data = $this->settings ? json_decode($this->settings, true) : null;
		return is_array($data) ? $data : [];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'color' => $this->color,
			'sortOrder' => (int)$this->sortOrder,
			'createdAt' => (int)$this->createdAt,
			'updatedAt' => (int)$this->updatedAt,
			'kind' => $this->kind ?: 'personal',
		];
	}
}
