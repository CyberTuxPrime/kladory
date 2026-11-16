<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Tags an Seiten (kleingeschrieben gespeichert).
 */
class TagMapper {
	private const TABLE = 'kladory_tags';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return string[] */
	public function getForPage(int $pageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('tag')
			->from(self::TABLE)
			->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId, IQueryBuilder::PARAM_INT)))
			->orderBy('tag', 'ASC');
		$result = $qb->executeQuery();
		$tags = [];
		while (($row = $result->fetch()) !== false) {
			$tags[] = (string)$row['tag'];
		}
		$result->closeCursor();
		return $tags;
	}

	/** @param string[] $tags bereits bereinigt */
	public function setForPage(int $pageId, array $tags): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('page_id', $qb->createNamedParameter($pageId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		foreach ($tags as $tag) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert(self::TABLE)
				->setValue('page_id', $ins->createNamedParameter($pageId, IQueryBuilder::PARAM_INT))
				->setValue('tag', $ins->createNamedParameter($tag));
			$ins->executeStatement();
		}
	}

	/**
	 * Tags mit Anzahl über alle nicht gelöschten Seiten der angegebenen Abschnitte.
	 * @param int[] $sectionIds
	 * @return array<int, array{tag:string, count:int}>
	 */
	public function listForSections(array $sectionIds): array {
		if ($sectionIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.tag')
			->selectAlias($qb->func()->count('t.id'), 'cnt')
			->from(self::TABLE, 't')
			->innerJoin('t', 'kladory_pages', 'p', $qb->expr()->eq('t.page_id', 'p.id'))
			->where($qb->expr()->in('p.section_id', $qb->createNamedParameter(array_values($sectionIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('p.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('t.tag')
			->orderBy('t.tag', 'ASC');
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = ['tag' => (string)$row['tag'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();
		return $out;
	}

	/** @param int[] $pageIds */
	public function deleteByPages(array $pageIds): void {
		foreach (array_chunk($pageIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('page_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}
}
