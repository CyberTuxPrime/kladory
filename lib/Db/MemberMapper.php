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
 * Mitglieder von Kursnotizbüchern.
 * type: user | group, role: teacher | student
 */
class MemberMapper {
	private const TABLE = 'kladory_members';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return array<int, array{type:string, principal:string, role:string}> */
	public function findByNotebook(int $notebookId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('type', 'principal', 'role')
			->from(self::TABLE)
			->where($qb->expr()->eq('notebook_id', $qb->createNamedParameter($notebookId, IQueryBuilder::PARAM_INT)))
			->orderBy('role', 'ASC')
			->addOrderBy('type', 'ASC')
			->addOrderBy('principal', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while (($r = $result->fetch()) !== false) {
			$rows[] = ['type' => (string)$r['type'], 'principal' => (string)$r['principal'], 'role' => (string)$r['role']];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Mitglieder mehrerer Notizbücher mit einer Abfrage.
	 * @param int[] $notebookIds
	 * @return array<int, array<int, array{type:string, principal:string, role:string}>>
	 */
	public function findByNotebooks(array $notebookIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($notebookIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('notebook_id', 'type', 'principal', 'role')
				->from(self::TABLE)
				->where($qb->expr()->in('notebook_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('role', 'ASC')
				->addOrderBy('type', 'ASC')
				->addOrderBy('principal', 'ASC');
			$result = $qb->executeQuery();
			while (($r = $result->fetch()) !== false) {
				$out[(int)$r['notebook_id']][] = ['type' => (string)$r['type'], 'principal' => (string)$r['principal'], 'role' => (string)$r['role']];
			}
			$result->closeCursor();
		}
		return $out;
	}

	/**
	 * Notizbuch-IDs, in denen der Benutzer direkt oder über eine seiner Gruppen Mitglied ist.
	 * @param string[] $groupIds
	 * @return int[]
	 */
	public function findNotebookIdsFor(string $userId, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$conds = [
			$qb->expr()->andX(
				$qb->expr()->eq('type', $qb->createNamedParameter('user')),
				$qb->expr()->eq('principal', $qb->createNamedParameter($userId)),
			),
		];
		if ($groupIds !== []) {
			$conds[] = $qb->expr()->andX(
				$qb->expr()->eq('type', $qb->createNamedParameter('group')),
				$qb->expr()->in('principal', $qb->createNamedParameter(array_values($groupIds), IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		$qb->selectDistinct('notebook_id')
			->from(self::TABLE)
			->where($qb->expr()->orX(...$conds));
		$result = $qb->executeQuery();
		$ids = [];
		while (($r = $result->fetch()) !== false) {
			$ids[] = (int)$r['notebook_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/** @param array<int, array{type:string, principal:string, role:string}> $members */
	public function setForNotebook(int $notebookId, array $members): void {
		$this->deleteByNotebooks([$notebookId]);
		foreach ($members as $m) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)
				->setValue('notebook_id', $qb->createNamedParameter($notebookId, IQueryBuilder::PARAM_INT))
				->setValue('type', $qb->createNamedParameter($m['type']))
				->setValue('principal', $qb->createNamedParameter($m['principal']))
				->setValue('role', $qb->createNamedParameter($m['role']));
			$qb->executeStatement();
		}
	}

	/** @param int[] $notebookIds */
	public function deleteByNotebooks(array $notebookIds): void {
		foreach (array_chunk($notebookIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('notebook_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deletePrincipal(string $type, string $principal): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('principal', $qb->createNamedParameter($principal)));
		$qb->executeStatement();
	}
}
