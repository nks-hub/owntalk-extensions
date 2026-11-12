<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Row access for shared settings. Every method is scoped by room token; there is
 * deliberately no lookup by internal id.
 */
class RoomSettingsRepository {
	private const TABLE = 'owntalk_room_settings';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{revision: int, settings: array<string, string>, fieldRevisions: array<string, int>, updatedBy: ?string, updatedAt: int}|null
	 */
	public function find(string $token): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('revision', 'settings', 'field_revisions', 'updated_by_id', 'updated_at')
			->from(self::TABLE)
			->where($qb->expr()->eq('room_token', $qb->createNamedParameter($token)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return [
			'revision' => (int)$row['revision'],
			'settings' => json_decode((string)$row['settings'], true, 4, JSON_THROW_ON_ERROR),
			'fieldRevisions' => json_decode((string)$row['field_revisions'], true, 4, JSON_THROW_ON_ERROR),
			'updatedBy' => $row['updated_by_id'] === null ? null : (string)$row['updated_by_id'],
			'updatedAt' => (int)$row['updated_at'],
		];
	}

	/**
	 * Tokens with stored settings, in id order, for batch maintenance.
	 *
	 * @return array<int, string> id => token
	 */
	public function tokensAfter(int $lastId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'room_token')
			->from(self::TABLE)
			->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
			->orderBy('id')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$tokens = [];
		while ($row = $result->fetch()) {
			$tokens[(int)$row['id']] = (string)$row['room_token'];
		}
		$result->closeCursor();
		return $tokens;
	}

	public function delete(string $token): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('room_token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}

	public function deleteAll(): int {
		return $this->db->getQueryBuilder()->delete(self::TABLE)->executeStatement();
	}

	public function forgetWriter(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('updated_by_id', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('updated_by_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Inserts the first revision. Returns false when another request inserted first.
	 *
	 * @param array<string, string> $settings
	 * @param array<string, int> $fieldRevisions
	 */
	public function insert(string $token, array $settings, array $fieldRevisions, string $userId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'room_token' => $qb->createNamedParameter($token),
			'schema_version' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'settings' => $qb->createNamedParameter(json_encode((object)$settings, JSON_THROW_ON_ERROR)),
			'field_revisions' => $qb->createNamedParameter(json_encode((object)$fieldRevisions, JSON_THROW_ON_ERROR)),
			'updated_by_id' => $qb->createNamedParameter($userId),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		try {
			$qb->executeStatement();
			return true;
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	/**
	 * Compare-and-set update. Returns false when the stored revision moved on.
	 *
	 * @param array<string, string> $settings
	 * @param array<string, int> $fieldRevisions
	 */
	public function update(string $token, int $expectedRevision, array $settings, array $fieldRevisions, string $userId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('revision', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->set('settings', $qb->createNamedParameter(json_encode((object)$settings, JSON_THROW_ON_ERROR)))
			->set('field_revisions', $qb->createNamedParameter(json_encode((object)$fieldRevisions, JSON_THROW_ON_ERROR)))
			->set('updated_by_id', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('room_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}
}
