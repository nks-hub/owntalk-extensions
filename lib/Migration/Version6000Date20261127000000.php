<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version6000Date20261127000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('owntalk_checks')) {
			$t = $schema->createTable('owntalk_checks');
			$t->addColumn('room_token', Types::STRING, ['length' => 64]);
			$t->addColumn('message_id', Types::BIGINT);
			$t->addColumn('item_key', Types::STRING, ['length' => 64]);
			$t->setPrimaryKey(['room_token', 'message_id', 'item_key'], 'owntalk_checks_pk');
		}
		if (!$schema->hasTable('owntalk_followups')) {
			$t = $schema->createTable('owntalk_followups');
			$t->addColumn('owner_id', Types::STRING, ['length' => 64]);
			$t->addColumn('room_token', Types::STRING, ['length' => 64]);
			$t->addColumn('message_id', Types::BIGINT);
			$t->addColumn('room_id', Types::BIGINT);
			$t->addColumn('thread_id', Types::BIGINT);
			$t->addColumn('due_at', Types::BIGINT);
			$t->addColumn('next_check', Types::BIGINT);
			$t->setPrimaryKey(['owner_id', 'room_token', 'message_id'], 'owntalk_followups_pk');
			$t->addIndex(['next_check'], 'owntalk_followups_due');
		}
		return $schema;
	}
}
