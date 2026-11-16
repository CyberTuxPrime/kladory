<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Grundschema: Notizbücher -> Abschnitte -> Seiten
 */
class Version0001Date20261114200000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kladory_notebooks')) {
			$t = $schema->createTable('kladory_notebooks');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 7, 'default' => '#4f6bed']);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'kladory_nb_user');
		}

		if (!$schema->hasTable('kladory_sections')) {
			$t = $schema->createTable('kladory_sections');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('notebook_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 7, 'default' => '#4f6bed']);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['notebook_id'], 'kladory_sec_nb');
		}

		if (!$schema->hasTable('kladory_pages')) {
			$t = $schema->createTable('kladory_pages');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('section_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
			// Seiteninhalt als JSON (Leinwand-Blöcke ab Etappe 2)
			$t->addColumn('content', Types::TEXT, ['notnull' => false]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['section_id'], 'kladory_page_sec');
		}

		return $schema;
	}
}
