<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version1000Date20261112000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('owntalk_room_settings')) {
			return null;
		}

		$table = $schema->createTable('owntalk_room_settings');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('room_token', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('schema_version', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
		$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('settings', Types::TEXT, ['notnull' => true]);
		$table->addColumn('field_revisions', Types::TEXT, ['notnull' => true]);
		$table->addColumn('updated_by_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['room_token'], 'owntalk_rs_token');
		return $schema;
	}
}
