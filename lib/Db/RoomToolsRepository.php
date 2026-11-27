<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class RoomToolsRepository {
	public function __construct(private IDBConnection $db) {
	}

	private function where(IQueryBuilder $q, array $keys): IQueryBuilder {
		foreach ($keys as $column => $value) {
			$q->andWhere($q->expr()->eq($column, $q->createNamedParameter($value,
				is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		}
		return $q;
	}

	private function select(string $table, array $keys, string ...$columns): IQueryBuilder {
		$q = $this->db->getQueryBuilder();
		return $this->where($q->select(...$columns)->from($table), $keys);
	}

	private function delete(string $table, array $keys): int {
		$q = $this->db->getQueryBuilder();
		return $this->where($q->delete($table), $keys)->executeStatement();
	}

	public function checked(string $token, int $id, array $items): array {
		$q = $this->select('owntalk_checks', ['room_token' => $token, 'message_id' => $id], 'item_key');
		$q->andWhere($q->expr()->in('item_key', $q->createNamedParameter($items, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $q->executeQuery();
		$checked = array_intersect($items, $result->fetchFirstColumn());
		$result->closeCursor();
		return array_keys($checked);
	}

	public function setChecked(string $token, int $id, string $item, bool $checked): void {
		$keys = ['room_token' => $token, 'message_id' => $id, 'item_key' => $item];
		if ($checked) {
			$this->db->insertIgnoreConflict('owntalk_checks', $keys);
		} else {
			$this->delete('owntalk_checks', $keys);
		}
	}

	public function reminder(string $owner, string $token, int $id): ?array {
		$result = $this->select('owntalk_followups', self::key($owner, $token, $id), '*')->executeQuery();
		$value = $result->fetchAssociative();
		$result->closeCursor();
		return $value === false ? null : $value;
	}

	public function schedule(string $owner, string $token, int $id, int $room, int $thread, int $due): void {
		$key = self::key($owner, $token, $id);
		$this->db->beginTransaction();
		try {
			if (!$this->db->insertIgnoreConflict('owntalk_followups', $key + ['room_id' => $room, 'thread_id' => $thread,
				'due_at' => $due, 'next_check' => $due])) {
				$q = $this->db->getQueryBuilder();
				$this->where($q->update('owntalk_followups')
					->set('due_at', $q->createNamedParameter($due, IQueryBuilder::PARAM_INT))
					->set('next_check', $q->createNamedParameter($due, IQueryBuilder::PARAM_INT))
					->set('thread_id', $q->createNamedParameter($thread, IQueryBuilder::PARAM_INT)), $key)->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
	}

	private static function key(string $owner, string $token, int $id): array {
		return ['owner_id' => $owner, 'room_token' => $token, 'message_id' => $id];
	}

	public function cancel(string $owner, string $token, int $id, ?int $expectedDue = null): bool {
		$keys = self::key($owner, $token, $id);
		if ($expectedDue !== null) {
			$keys['due_at'] = $expectedDue;
		}
		return $this->delete('owntalk_followups', $keys) > 0;
	}

	public function purgeOwner(string $owner): void {
		$this->delete('owntalk_followups', ['owner_id' => $owner]);
	}

	public function retryLater(array $row, int $now): void {
		$key = self::key($row['owner_id'], $row['room_token'], (int)$row['message_id']);
		$key['due_at'] = (int)$row['due_at'];
		$key['next_check'] = (int)$row['next_check'];
		$q = $this->db->getQueryBuilder();
		$this->where($q->update('owntalk_followups')
			->set('next_check', $q->createNamedParameter($now + 300, IQueryBuilder::PARAM_INT)), $key)->executeStatement();
	}

	public function due(int $now): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('owntalk_followups')
			->where($q->expr()->lte('next_check', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('next_check', 'ASC')->setMaxResults(100);
		$result = $q->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();
		return $rows;
	}
}
