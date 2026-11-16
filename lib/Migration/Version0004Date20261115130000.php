<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Migration;

use Closure;
use OCA\Kladory\Service\ContentSanitizer;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Etappe 3: Suchtext je Seite + Tags.
 */
class Version0004Date20261115130000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
		private ContentSanitizer $sanitizer,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$pages = $schema->getTable('kladory_pages');
		if (!$pages->hasColumn('search_text')) {
			$pages->addColumn('search_text', Types::TEXT, ['notnull' => false]);
		}

		if (!$schema->hasTable('kladory_tags')) {
			$t = $schema->createTable('kladory_tags');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('page_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('tag', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['page_id', 'tag'], 'kladory_tag_page_tag');
			$t->addIndex(['tag'], 'kladory_tag_tag');
		}

		return $schema;
	}

	/** Suchtext für bereits vorhandene Seiten nachtragen */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'title', 'content')
			->from('kladory_pages')
			->where($qb->expr()->isNull('search_text'));
		$result = $qb->executeQuery();

		$update = $this->db->getQueryBuilder();
		$update->update('kladory_pages')
			->set('search_text', $update->createParameter('text'))
			->where($update->expr()->eq('id', $update->createParameter('id')));

		while (($row = $result->fetch()) !== false) {
			$text = $this->sanitizer->buildSearchText((string)($row['title'] ?? ''), $row['content'] ?? null);
			$update->setParameter('text', $text);
			$update->setParameter('id', (int)$row['id'], IQueryBuilder::PARAM_INT);
			$update->executeStatement();
		}
		$result->closeCursor();
	}
}
