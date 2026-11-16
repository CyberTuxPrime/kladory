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
 * Etappe 2: Revisionszähler je Seite (Konflikterkennung beim Speichern)
 */
class Version0002Date20261115100000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('kladory_pages');
		if (!$table->hasColumn('revision')) {
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			return $schema;
		}
		return null;
	}
}
