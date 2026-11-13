<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCA\OwnTalkExtensions\Db\RoomSettingsRepository;
use OCA\OwnTalkExtensions\Exception\ConflictException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Reads and writes the shared settings document with optimistic, per-field concurrency.
 */
class SettingsService {
	private const MAX_ATTEMPTS = 3;

	public function __construct(
		private RoomSettingsRepository $repository,
		private FieldRegistry $fields,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{revision: int, settings: array<string, ?string>, fieldRevisions: array<string, int>, updatedBy: ?string, updatedAt: ?int}
	 */
	public function get(string $token): array {
		return $this->toDocument($this->repository->find($token));
	}

	/**
	 * Applies a validated patch on top of $baseRevision.
	 *
	 * @param array<string, ?string> $patch normalized values, null removes the field
	 * @return array{revision: int, settings: array<string, ?string>, fieldRevisions: array<string, int>, updatedBy: ?string, updatedAt: ?int}
	 * @throws ConflictException
	 */
	public function patch(string $token, int $baseRevision, array $patch, string $userId): array {
		for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
			$row = $this->repository->find($token);
			$current = $row['revision'] ?? 0;
			$fieldRevisions = $row['fieldRevisions'] ?? [];

			if ($baseRevision > $current) {
				throw new ConflictException($this->toDocument($row), array_keys($patch));
			}
			$conflicts = array_values(array_filter(
				array_keys($patch),
				static fn (string $field): bool => ($fieldRevisions[$field] ?? 0) > $baseRevision,
			));
			if ($conflicts !== []) {
				throw new ConflictException($this->toDocument($row), $conflicts);
			}

			$settings = $row['settings'] ?? [];
			$next = $current + 1;
			foreach ($patch as $field => $value) {
				if ($value === null) {
					unset($settings[$field]);
				} else {
					$settings[$field] = $value;
				}
				$fieldRevisions[$field] = $next;
			}

			$now = $this->time->getTime();
			$saved = $row === null
				? $this->repository->insert($token, $settings, $fieldRevisions, $userId, $now)
				: $this->repository->update($token, $current, $settings, $fieldRevisions, $userId, $now);
			if ($saved) {
				return $this->toDocument([
					'revision' => $next,
					'settings' => $settings,
					'fieldRevisions' => $fieldRevisions,
					'updatedBy' => $userId,
					'updatedAt' => $now,
				]);
			}
		}
		$row = $this->repository->find($token);
		$fieldRevisions = $row['fieldRevisions'] ?? [];
		throw new ConflictException($this->toDocument($row), array_values(array_filter(
			array_keys($patch),
			static fn (string $field): bool => ($fieldRevisions[$field] ?? 0) > $baseRevision,
		)));
	}

	/**
	 * @param array{revision: int, settings: array<string, string>, fieldRevisions: array<string, int>, updatedBy: ?string, updatedAt: int}|null $row
	 * @return array{revision: int, settings: array<string, ?string>, fieldRevisions: array<string, int>, updatedBy: ?string, updatedAt: ?int}
	 */
	private function toDocument(?array $row): array {
		$settings = [];
		foreach ($this->fields->fields() as $field) {
			$settings[$field] = $row['settings'][$field] ?? null;
		}
		return [
			'revision' => $row['revision'] ?? 0,
			'settings' => $settings,
			'fieldRevisions' => $row['fieldRevisions'] ?? [],
			'updatedBy' => $row['updatedBy'] ?? null,
			'updatedAt' => $row['updatedAt'] ?? null,
		];
	}
}
