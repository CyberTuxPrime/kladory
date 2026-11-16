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
 * Papierkorb: deleted_at = 0 bedeutet "nicht gelöscht",
 * sonst Zeitpunkt (Unix-Zeit), an dem der Eintrag in den Papierkorb kam.
 */
class Version0003Date20261115120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		foreach ([
			'kladory_notebooks' => 'kladory_nb_del',
			'kladory_sections' => 'kladory_sec_del',
			'kladory_pages' => 'kladory_page_del',
		] as $tableName => $indexName) {
			$table = $schema->getTable($tableName);
			if (!$table->hasColumn('deleted_at')) {
				$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
				$table->addIndex(['deleted_at'], $indexName);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
