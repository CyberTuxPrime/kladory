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
 * @template-extends QBMapper<Section>
 */
class SectionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kladory_sections', Section::class);
	}

	/**
	 * Abschnitt unabhängig vom Papierkorb-Status (Prüfung im Service).
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findById(int $id): Section {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Nicht gelöschte Abschnitte der Notizbücher.
	 * @param int[] $notebookIds
	 * @return Section[]
	 */
	public function findByNotebooks(array $notebookIds): array {
		if ($notebookIds === []) {
			return [];
		}
		$out = [];
		foreach (array_chunk($notebookIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('notebook_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('sort_order', 'ASC')
				->addOrderBy('id', 'ASC');
			array_push($out, ...$this->findEntities($qb));
		}
		return $out;
	}

	/**
	 * Alle Abschnitts-IDs (auch gelöschte) der Notizbücher – für endgültiges Löschen.
	 * @param int[] $notebookIds
	 * @return int[]
	 */
	public function findIdsByNotebooks(array $notebookIds): array {
		$ids = [];
		foreach (array_chunk($notebookIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from($this->getTableName())
				->where($qb->expr()->in('notebook_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($ids, ...$this->fetchIds($qb));
		}
		return $ids;
	}

	/** @return string[] Schüler-IDs mit mindestens einem Schülerabschnitt im Papierkorb */
	public function findStudentIdsWithDeleted(int $notebookId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('student_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('notebook_id', $qb->createNamedParameter($notebookId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('area', $qb->createNamedParameter('student')))
			->andWhere($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($r = $result->fetch()) !== false) {
			if ($r['student_id'] !== null) {
				$ids[] = (string)$r['student_id'];
			}
		}
		$result->closeCursor();
		return $ids;
	}

	/** @return int[] alle Schülerabschnitte eines Schülers (auch gelöschte) */
	public function findIdsByStudent(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('area', $qb->createNamedParameter('student')))
			->andWhere($qb->expr()->eq('student_id', $qb->createNamedParameter($userId)));
		return $this->fetchIds($qb);
	}

	/**
	 * Gelöschte Abschnitte in den (nicht gelöschten) Notizbüchern.
	 * @param int[] $notebookIds
	 * @return array<int, array<string, mixed>>
	 */
	public function findDeletedInNotebooks(array $notebookIds): array {
		if ($notebookIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id', 's.name', 's.color', 's.deleted_at', 's.area', 's.student_id')
			->selectAlias('n.id', 'nb_id')
			->selectAlias('n.name', 'nb_name')
			->from($this->getTableName(), 's')
			->innerJoin('s', 'kladory_notebooks', 'n', $qb->expr()->eq('s.notebook_id', 'n.id'))
			->where($qb->expr()->in('n.id', $qb->createNamedParameter($notebookIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('n.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('s.deleted_at', 'DESC');
		$result = $qb->executeQuery();
		$rows = [];
		while (($r = $result->fetch()) !== false) {
			$rows[] = $r;
		}
		$result->closeCursor();
		return $rows;
	}

	/** @return int[] */
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

	public function maxSortOrder(int $notebookId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order'))
			->from($this->getTableName())
			->where($qb->expr()->eq('notebook_id', $qb->createNamedParameter($notebookId, IQueryBuilder::PARAM_INT)));
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
