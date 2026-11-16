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
 * Etappe 4: Kursnotizbücher
 *  - notebooks.kind      personal | class
 *  - notebooks.settings  JSON (z. B. Standard-Abschnitte für Schüler)
 *  - sections.area       normal | library | collab | teacher | student
 *  - sections.student_id Besitzer eines Schülerabschnitts
 *  - *.deleted_by        wer etwas in den Papierkorb gelegt hat
 *  - kladory_members     Lehrkräfte/Schüler (Benutzer oder Gruppen) eines Kursnotizbuchs
 */
class Version0005Date20261115140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$nb = $schema->getTable('kladory_notebooks');
		if (!$nb->hasColumn('kind')) {
			$nb->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'personal']);
		}
		if (!$nb->hasColumn('settings')) {
			$nb->addColumn('settings', Types::TEXT, ['notnull' => false]);
		}

		$sec = $schema->getTable('kladory_sections');
		if (!$sec->hasColumn('area')) {
			$sec->addColumn('area', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'normal']);
		}
		if (!$sec->hasColumn('student_id')) {
			$sec->addColumn('student_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$sec->addIndex(['student_id'], 'kladory_sec_student');
		}
		if (!$sec->hasColumn('deleted_by')) {
			$sec->addColumn('deleted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		$pages = $schema->getTable('kladory_pages');
		if (!$pages->hasColumn('deleted_by')) {
			$pages->addColumn('deleted_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		if (!$schema->hasTable('kladory_members')) {
			$t = $schema->createTable('kladory_members');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('notebook_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 8]);       // user | group
			$t->addColumn('principal', Types::STRING, ['notnull' => true, 'length' => 64]); // uid | gid
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);      // teacher | student
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['notebook_id', 'type', 'principal'], 'kladory_mem_uniq');
			$t->addIndex(['type', 'principal'], 'kladory_mem_principal');
		}

		return $schema;
	}
}
