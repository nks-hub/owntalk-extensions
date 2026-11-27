<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version7000Date20261127000100 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('owntalk_documents')) {
			$table = $schema->createTable('owntalk_documents');
			$table->addColumn('scope_key', Types::STRING, ['length' => 64]);
			$table->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('revision', Types::BIGINT);
			$table->addColumn('body', Types::TEXT);
			$table->setPrimaryKey(['scope_key'], 'ot_documents_pk');
			$table->addIndex(['owner_id'], 'ot_documents_owner');
		}
		if (!$schema->hasTable('owntalk_operations')) {
			$table = $schema->createTable('owntalk_operations');
			$table->addColumn('scope_key', Types::STRING, ['length' => 64]);
			$table->addColumn('operation_id', Types::STRING, ['length' => 36]);
			$table->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('fingerprint', Types::STRING, ['length' => 64]);
			$table->setPrimaryKey(['scope_key', 'operation_id'], 'ot_operations_pk');
			$table->addIndex(['owner_id'], 'ot_operations_owner');
		}
		return $schema;
	}
}
