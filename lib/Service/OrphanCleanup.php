<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCA\OwnTalkExtensions\Db\RoomSettingsRepository;
use OCP\App\IAppManager;
use OCP\Collaboration\Resources\IManager;
use OCP\Collaboration\Resources\IProvider;
use OCP\Collaboration\Resources\IProviderManager;
use OCP\Collaboration\Resources\ResourceException;

/**
 * Deletes settings of conversations Talk no longer knows. Access already ends with the
 * conversation; this only removes the leftover rows.
 */
class OrphanCleanup {
	private const BATCH = 500;

	public function __construct(
		private RoomSettingsRepository $repository,
		private IAppManager $appManager,
		private IProviderManager $providerManager,
		private IManager $resourceManager,
	) {
	}

	/**
	 * @return int|null deleted rows, or null when Talk is not available and nothing was checked
	 */
	public function run(): ?int {
		if (!$this->appManager->isEnabledForAnyone('spreed')) {
			return null;
		}
		$providers = array_values(array_filter(
			$this->providerManager->getResourceProviders(),
			static fn (IProvider $p): bool => $p->getType() === 'room',
		));
		if (\count($providers) !== 1) {
			return null;
		}

		$deleted = 0;
		$lastId = 0;
		while ($tokens = $this->repository->tokensAfter($lastId, self::BATCH)) {
			foreach ($tokens as $id => $token) {
				$lastId = $id;
				try {
					$providers[0]->getResourceRichObject($this->resourceManager->createResource('room', $token));
				} catch (ResourceException) {
					// Talk's provider maps RoomNotFoundException to ResourceException.
					$this->repository->delete($token);
					$deleted++;
				} catch (\Throwable) {
					// Unsupported room types and anything unexpected: keep the row.
				}
			}
		}
		return $deleted;
	}
}
