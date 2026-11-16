<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Überträgt alle Kladory-Daten von einem Benutzernamen auf einen anderen.
 * Gedacht für umbenannte Konten (z. B. geänderter LDAP-Benutzername):
 * Der alte Benutzer muss dafür nicht mehr existieren.
 */
class TransferService {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array<string, int> Anzahl geänderter Einträge je Bereich
	 */
	public function transfer(string $from, string $to, bool $dryRun = false): array {
		if ($from === '' || $to === '' || $from === $to) {
			throw new ValidationException('Alter und neuer Benutzername müssen verschieden sein.');
		}
		$counts = [
			'notebooks' => $this->count('kladory_notebooks', 'user_id', $from),
			'studentSections' => $this->count('kladory_sections', 'student_id', $from),
			'memberships' => $this->countMembers($from),
			'pagesEditedBy' => $this->count('kladory_pages', 'updated_by', $from),
		];
		if ($dryRun) {
			return $counts;
		}

		$this->db->beginTransaction();
		try {
			$this->set('kladory_notebooks', 'user_id', $from, $to);
			$this->set('kladory_sections', 'student_id', $from, $to);
			$this->set('kladory_sections', 'deleted_by', $from, $to);
			$this->set('kladory_pages', 'deleted_by', $from, $to);
			$this->set('kladory_pages', 'updated_by', $from, $to);
			$this->transferMembers($from, $to);
			$this->dropOwnerMemberships($to);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $counts;
	}

	private function count(string $table, string $column, string $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	private function countMembers(string $user): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('kladory_members')
			->where($qb->expr()->eq('type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('principal', $qb->createNamedParameter($user)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	private function set(string $table, string $column, string $from, string $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter($to))
			->where($qb->expr()->eq($column, $qb->createNamedParameter($from)));
		$qb->executeStatement();
	}

	/** Mitgliedschaften umschreiben; hat der neue Name schon einen Eintrag, gewinnt die Lehrkraft-Rolle */
	private function transferMembers(string $from, string $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'notebook_id', 'role')
			->from('kladory_members')
			->where($qb->expr()->eq('type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('principal', $qb->createNamedParameter($from)));
		$result = $qb->executeQuery();
		$rows = [];
		while (($r = $result->fetch()) !== false) {
			$rows[] = $r;
		}
		$result->closeCursor();

		foreach ($rows as $r) {
			$existing = $this->db->getQueryBuilder();
			$existing->select('id', 'role')
				->from('kladory_members')
				->where($existing->expr()->eq('notebook_id', $existing->createNamedParameter((int)$r['notebook_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($existing->expr()->eq('type', $existing->createNamedParameter('user')))
				->andWhere($existing->expr()->eq('principal', $existing->createNamedParameter($to)));
			$res = $existing->executeQuery();
			$other = $res->fetch();
			$res->closeCursor();

			if ($other === false) {
				$u = $this->db->getQueryBuilder();
				$u->update('kladory_members')
					->set('principal', $u->createNamedParameter($to))
					->where($u->expr()->eq('id', $u->createNamedParameter((int)$r['id'], IQueryBuilder::PARAM_INT)));
				$u->executeStatement();
				continue;
			}
			if ($r['role'] === 'teacher' && $other['role'] !== 'teacher') {
				$u = $this->db->getQueryBuilder();
				$u->update('kladory_members')
					->set('role', $u->createNamedParameter('teacher'))
					->where($u->expr()->eq('id', $u->createNamedParameter((int)$other['id'], IQueryBuilder::PARAM_INT)));
				$u->executeStatement();
			}
			$d = $this->db->getQueryBuilder();
			$d->delete('kladory_members')
				->where($d->expr()->eq('id', $d->createNamedParameter((int)$r['id'], IQueryBuilder::PARAM_INT)));
			$d->executeStatement();
		}
	}

	/** Wer jetzt Besitzer ist, braucht keinen zusätzlichen Mitgliedseintrag im selben Kursnotizbuch */
	private function dropOwnerMemberships(string $user): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id')
			->from('kladory_members', 'm')
			->innerJoin('m', 'kladory_notebooks', 'n', $qb->expr()->eq('m.notebook_id', 'n.id'))
			->where($qb->expr()->eq('m.type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('m.principal', $qb->createNamedParameter($user)))
			->andWhere($qb->expr()->eq('n.user_id', $qb->createNamedParameter($user)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($r = $result->fetch()) !== false) {
			$ids[] = (int)$r['id'];
		}
		$result->closeCursor();
		if ($ids !== []) {
			$d = $this->db->getQueryBuilder();
			$d->delete('kladory_members')
				->where($d->expr()->in('id', $d->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
			$d->executeStatement();
		}
	}
}
