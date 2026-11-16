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
 * @template-extends QBMapper<Page>
 */
class PageMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kladory_pages', Page::class);
	}

	/**
	 * Seite unabhängig vom Papierkorb-Status (Prüfung im Service).
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findById(int $id): Page {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Nicht gelöschte Seiten eines Abschnitts – ohne den (evtl. großen) Inhalt.
	 * @return Page[]
	 */
	public function findBySection(int $sectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'section_id', 'title', 'sort_order', 'created_at', 'updated_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('section_id', $qb->createNamedParameter($sectionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return int[] */
	public function findExpiredIds(int $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('deleted_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @param int[] $sectionIds
	 * @return int[] alle Seiten-IDs (auch gelöschte) der Abschnitte
	 */
	public function findIdsBySections(array $sectionIds): array {
		$ids = [];
		foreach (array_chunk($sectionIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from($this->getTableName())
				->where($qb->expr()->in('section_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$ids[] = (int)$row['id'];
			}
			$result->closeCursor();
		}
		return $ids;
	}

	/**
	 * Volltextsuche über nicht gelöschte Seiten in den angegebenen (lesbaren) Abschnitten.
	 * @param int[] $sectionIds
	 * @return array<int, array<string, mixed>>
	 */
	public function search(array $sectionIds, string $query, ?string $tag, int $limit = 50): array {
		if ($sectionIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.id', 'p.title', 'p.search_text', 'p.updated_at')
			->selectAlias('s.id', 'sec_id')
			->selectAlias('s.name', 'sec_name')
			->selectAlias('s.color', 'sec_color')
			->selectAlias('n.id', 'nb_id')
			->selectAlias('n.name', 'nb_name')
			->from($this->getTableName(), 'p')
			->innerJoin('p', 'kladory_sections', 's', $qb->expr()->eq('p.section_id', 's.id'))
			->innerJoin('s', 'kladory_notebooks', 'n', $qb->expr()->eq('s.notebook_id', 'n.id'))
			->where($qb->expr()->in('p.section_id', $qb->createNamedParameter(array_values($sectionIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('p.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		if ($tag !== null && $tag !== '') {
			$qb->innerJoin('p', 'kladory_tags', 't', $qb->expr()->eq('t.page_id', 'p.id'))
				->andWhere($qb->expr()->eq('t.tag', $qb->createNamedParameter($tag)));
		}
		if ($query !== '') {
			$like = '%' . $this->db->escapeLikeParameter($query) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('p.title', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('p.search_text', $qb->createNamedParameter($like)),
			));
		}
		$qb->orderBy('p.updated_at', 'DESC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$rows = [];
		while (($r = $result->fetch()) !== false) {
			$rows[] = $r;
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Gelöschte Seiten in nicht gelöschten Abschnitten der (nicht gelöschten) Notizbücher.
	 * @param int[] $notebookIds
	 * @return array<int, array<string, mixed>>
	 */
	public function findDeletedInNotebooks(array $notebookIds): array {
		if ($notebookIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.id', 'p.title', 'p.deleted_at', 'p.deleted_by')
			->selectAlias('s.id', 'sec_id')
			->selectAlias('s.name', 'sec_name')
			->selectAlias('s.color', 'sec_color')
			->selectAlias('s.area', 'sec_area')
			->selectAlias('s.student_id', 'sec_student')
			->selectAlias('n.id', 'nb_id')
			->selectAlias('n.name', 'nb_name')
			->from($this->getTableName(), 'p')
			->innerJoin('p', 'kladory_sections', 's', $qb->expr()->eq('p.section_id', 's.id'))
			->innerJoin('s', 'kladory_notebooks', 'n', $qb->expr()->eq('s.notebook_id', 'n.id'))
			->where($qb->expr()->in('n.id', $qb->createNamedParameter($notebookIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('n.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('p.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('p.deleted_at', 'DESC');
		$result = $qb->executeQuery();
		$rows = [];
		while (($r = $result->fetch()) !== false) {
			$rows[] = $r;
		}
		$result->closeCursor();
		return $rows;
	}

	/** @param int[] $sectionIds */
	public function deleteBySections(array $sectionIds): void {
		foreach (array_chunk($sectionIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('section_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
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

	public function maxSortOrder(int $sectionId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order'))
			->from($this->getTableName())
			->where($qb->expr()->eq('section_id', $qb->createNamedParameter($sectionId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === null || $max === false ? -1 : (int)$max;
	}
}
