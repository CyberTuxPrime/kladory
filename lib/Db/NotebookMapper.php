<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Notebook>
 */
class NotebookMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kladory_notebooks', Notebook::class);
	}

	/**
	 * Notizbuch des Nutzers. Standard: nur nicht gelöschte.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findForUser(int $id, string $userId, bool $deleted = false): Notebook {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($deleted) {
			$qb->andWhere($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntity($qb);
	}

	/**
	 * Notizbuch unabhängig von Besitzer und Papierkorb.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findById(int $id): Notebook {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Nicht gelöschtes Notizbuch unabhängig vom Besitzer (Rechte prüft der AccessService).
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findLive(int $id): Notebook {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $ids
	 * @return Notebook[] nicht gelöschte Notizbücher mit diesen IDs
	 */
	public function findLiveByIds(array $ids): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			array_push($out, ...$this->findEntities($qb));
		}
		return $out;
	}

	/** @return Notebook[] nicht gelöschte Notizbücher */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return Notebook[] Notizbücher im Papierkorb */
	public function findDeletedForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('deleted_at', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return int[] alle Notizbuch-IDs eines Nutzers (auch gelöschte) */
	public function findAllIdsForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->fetchIds($qb);
	}

	/** @return int[] seit vor $cutoff im Papierkorb */
	public function findExpiredIds(int $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('deleted_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		return $this->fetchIds($qb);
	}

	/** @param int[] $ids */
	public function deleteByIds(array $ids): void {
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function maxSortOrder(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === null || $max === false ? -1 : (int)$max;
	}

	/** @return int[] */
	private function fetchIds(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}
}
