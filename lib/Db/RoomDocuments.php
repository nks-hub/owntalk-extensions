<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Db;

use OCA\OwnTalkExtensions\Exception\RoomToolException;
use OCP\IDBConnection;

class RoomDocuments {
	private const DOCUMENTS = 'owntalk_documents';
	private const OPERATIONS = 'owntalk_operations';
	private const RETIRED = '-';

	public function __construct(private IDBConnection $db) {
	}

	public static function key(string ...$parts): string {
		return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
	}

	public function read(string $scope): array {
		$q = $this->db->getQueryBuilder();
		$q->select('body')->from(self::DOCUMENTS)->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)));
		$result = $q->executeQuery();
		$json = $result->fetchOne();
		$result->closeCursor();
		return $json === false ? [] : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
	}

	public function operation(string $scope, string $id): ?string {
		$q = $this->db->getQueryBuilder();
		$q->select('fingerprint')->from(self::OPERATIONS)
			->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)))
			->andWhere($q->expr()->eq('operation_id', $q->createNamedParameter($id)));
		$result = $q->executeQuery();
		$hash = $result->fetchOne();
		$result->closeCursor();
		return $hash === false ? null : $hash;
	}

	public function change(string $scope, ?string $owner, ?string $operationId, array $input, callable $apply): array {
		$this->db->beginTransaction();
		try {
			$this->db->insertIgnoreConflict(self::DOCUMENTS, ['scope_key' => $scope, 'owner_id' => $owner, 'revision' => 0, 'body' => '[]']);
			// Take the document's write lock before reading another device's changes.
			$q = $this->db->getQueryBuilder();
			$q->update(self::DOCUMENTS)->set('revision', $q->createFunction('revision + 1'))
				->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)))->executeStatement();
			$current = $this->read($scope);
			$seen = $operationId === null ? null : $this->operation($scope, $operationId);
			$fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
			if ($seen !== null && $seen !== self::RETIRED && $seen !== $fingerprint) {
				throw new RoomToolException(409);
			}
			if ($seen === null) {
				$current = $apply($current);
				$q = $this->db->getQueryBuilder();
				$q->update(self::DOCUMENTS)->set('body', $q->createNamedParameter(json_encode($current, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
					->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)))->executeStatement();
				if ($operationId !== null) {
					$this->remember($scope, $owner, $operationId, $fingerprint);
				}
			}
			$this->db->commit();
			return $current;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Keep a delayed first upload from reviving a draft that was already sent. */
	public function retireDraft(string $scope, string $owner, string $id): void {
		$this->remember($scope, $owner, $id, self::RETIRED);
	}

	private function remember(string $scope, ?string $owner, string $id, string $fingerprint): void {
		$this->db->insertIgnoreConflict(self::OPERATIONS, ['scope_key' => $scope, 'operation_id' => $id,
			'owner_id' => $owner, 'fingerprint' => $fingerprint]);
	}

	public function purgeOwner(string $owner): void {
		$this->db->beginTransaction();
		try {
			foreach ([self::DOCUMENTS, self::OPERATIONS] as $table) {
				$q = $this->db->getQueryBuilder();
				$q->delete($table)->where($q->expr()->eq('owner_id', $q->createNamedParameter($owner)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
