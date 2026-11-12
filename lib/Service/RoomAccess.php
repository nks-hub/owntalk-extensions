<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCA\OwnTalkExtensions\Exception\AccessException;
use OCP\App\IAppManager;
use OCP\Collaboration\Resources\IManager;
use OCP\Collaboration\Resources\IProvider;
use OCP\Collaboration\Resources\IProviderManager;
use OCP\Collaboration\Resources\ResourceException;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Builds the access context for one user and one conversation using public APIs only.
 *
 * Membership: Talk's "room" collaboration resource provider, called directly so the
 * server-side access cache never answers with a stale value. Roles: Talk OCS API on
 * behalf of the caller. Both sources must agree on membership.
 */
class RoomAccess {
	public const TOKEN_PATTERN = '/^[a-z0-9]{4,64}$/D';

	public function __construct(
		private IAppManager $appManager,
		private IProviderManager $providerManager,
		private IManager $resourceManager,
		private TalkOcsClient $talk,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws AccessException
	 */
	/**
	 * @param bool $fresh true for writes: role data must not come from the short read cache
	 */
	public function resolve(IUser $user, string $token, ?string $authorization, bool $fresh = false): RoomAccessContext {
		if (!preg_match(self::TOKEN_PATTERN, $token)) {
			throw new AccessException(AccessException::INVALID_TOKEN);
		}
		$provider = $this->roomProvider($user);
		$resource = $this->resourceManager->createResource('room', $token);

		try {
			$isMember = $provider->canAccessResource($resource, $user);
		} catch (ResourceException) {
			$isMember = false;
		} catch (\Throwable $e) {
			$this->logger->warning('Talk membership check failed: ' . $e::class, ['app' => 'owntalk_extensions']);
			throw new AccessException(AccessException::TALK_INCOMPATIBLE);
		}
		if (!$isMember) {
			throw new AccessException(AccessException::NOT_FOUND);
		}

		$room = $authorization === null
			? ['status' => 'unavailable']
			: $this->talk->getRoom($token, $authorization, $user->getUID(), $fresh);
		if ($room['status'] === 'not_member') {
			$this->logger->warning('Talk membership sources disagree', ['app' => 'owntalk_extensions']);
			throw new AccessException(AccessException::SOURCE_MISMATCH);
		}
		if ($room['status'] === 'ok') {
			$data = $room['data'];
			return new RoomAccessContext(
				token: $token,
				isMember: true,
				roleSource: RoomAccessContext::ROLE_SOURCE_TALK_OCS,
				roomType: $data['type'],
				participantType: $data['participantType'],
				permissions: \is_int($data['permissions'] ?? null) ? $data['permissions'] : null,
				readOnly: \is_int($data['readOnly'] ?? null) ? $data['readOnly'] : null,
				federated: \is_string($data['remoteServer'] ?? null) && $data['remoteServer'] !== '',
				lobbyActive: ($data['lobbyState'] ?? 0) !== 0,
			);
		}

		// Rich object is read only after the membership check: it leaks the room name otherwise.
		$callType = null;
		try {
			$callType = $provider->getResourceRichObject($resource)['call-type'] ?? null;
		} catch (\Throwable) {
			$callType = null;
		}
		return new RoomAccessContext(
			token: $token,
			isMember: true,
			roleSource: RoomAccessContext::ROLE_SOURCE_NONE,
			callType: \is_string($callType) ? $callType : null,
		);
	}

	public function isTalkAvailable(?IUser $user): bool {
		try {
			$this->roomProvider($user);
			return true;
		} catch (AccessException) {
			return false;
		}
	}

	/**
	 * @throws AccessException
	 */
	private function roomProvider(?IUser $user): IProvider {
		if (!$this->appManager->isEnabledForUser('spreed', $user)) {
			throw new AccessException(AccessException::TALK_UNAVAILABLE);
		}
		$providers = array_values(array_filter(
			$this->providerManager->getResourceProviders(),
			static fn (IProvider $p): bool => $p->getType() === 'room',
		));
		if (\count($providers) !== 1) {
			throw new AccessException(AccessException::TALK_UNAVAILABLE);
		}
		return $providers[0];
	}
}
