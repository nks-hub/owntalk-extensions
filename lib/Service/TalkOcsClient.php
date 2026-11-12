<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Asks this server's public Talk OCS API about a conversation on behalf of the caller.
 *
 * The caller's Authorization header is forwarded only to the origin from the
 * admin-controlled "overwrite.cli.url", never to a host taken from the request,
 * never through the configured outgoing proxy, and never with redirects. The
 * header is not stored or logged; only a hash of it is part of the cache key.
 * The answer is accepted only when Talk reports the same user as the caller.
 */
class TalkOcsClient {
	private const MAX_BODY_BYTES = 262144;
	private const CACHE_TTL = 15;

	private ?ICache $cache;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->isLocalCacheAvailable() ? $cacheFactory->createLocal('owntalk_extensions_roles') : null;
	}

	public function origin(): ?string {
		$url = rtrim($this->config->getSystemValueString('overwrite.cli.url', ''), '/');
		$parts = parse_url($url);
		if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['query'])) {
			return null;
		}
		if ($parts['scheme'] === 'https') {
			return $url;
		}
		// Plain HTTP only to the local machine, where no network sits between the hops.
		if ($parts['scheme'] === 'http' && \in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)) {
			return $url;
		}
		return null;
	}

	/**
	 * Role data for $userId in $token. With $fresh = false a result up to 15 s old may be
	 * returned; writes must pass $fresh = true.
	 *
	 * @return array{status: 'ok', data: array<string, mixed>}|array{status: 'not_member'}|array{status: 'unavailable'}
	 */
	public function getRoom(string $token, string $authorization, string $userId, bool $fresh): array {
		$origin = $this->origin();
		if ($origin === null || !preg_match('/^(Basic|Bearer) [A-Za-z0-9+\/=._~-]+$/D', $authorization)) {
			return ['status' => 'unavailable'];
		}

		$key = hash('sha256', $userId . "\n" . $token . "\n" . $authorization);
		if (!$fresh && $this->cache !== null) {
			$cached = $this->cache->get($key);
			if (\is_array($cached) && isset($cached['status'])) {
				return $cached;
			}
		}

		$result = $this->fetch($origin, $token, $authorization, $userId);
		if ($this->cache !== null && $result['status'] !== 'unavailable') {
			$this->cache->set($key, $result, self::CACHE_TTL);
		}
		return $result;
	}

	/**
	 * @return array{status: 'ok', data: array<string, mixed>}|array{status: 'not_member'}|array{status: 'unavailable'}
	 */
	private function fetch(string $origin, string $token, string $authorization, string $userId): array {
		try {
			$response = $this->clientService->newClient()->get(
				$origin . '/ocs/v2.php/apps/spreed/api/v4/room/' . rawurlencode($token),
				[
					'headers' => [
						'Authorization' => $authorization,
						'OCS-APIRequest' => 'true',
						'Accept' => 'application/json',
					],
					'timeout' => 3,
					'connect_timeout' => 2,
					'allow_redirects' => false,
					'http_errors' => false,
					// An empty proxy disables the instance-wide "proxy" setting for this call.
					'proxy' => '',
					'nextcloud' => ['allow_local_address' => true],
				],
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Talk OCS role lookup failed: ' . $e::class, ['app' => 'owntalk_extensions']);
			return ['status' => 'unavailable'];
		}

		$status = $response->getStatusCode();
		if ($status === 404) {
			return ['status' => 'not_member'];
		}
		$body = $response->getBody();
		if ($status !== 200 || !\is_string($body) || \strlen($body) > self::MAX_BODY_BYTES) {
			return ['status' => 'unavailable'];
		}

		try {
			$json = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['status' => 'unavailable'];
		}
		$data = $json['ocs']['data'] ?? null;
		if (!\is_array($data) || ($data['token'] ?? null) !== $token
			|| !\is_int($data['type'] ?? null) || !\is_int($data['participantType'] ?? null)) {
			return ['status' => 'unavailable'];
		}
		if (($data['actorType'] ?? null) !== 'users' || ($data['actorId'] ?? null) !== $userId) {
			$this->logger->warning('Talk OCS answered for a different actor', ['app' => 'owntalk_extensions']);
			return ['status' => 'unavailable'];
		}
		return ['status' => 'ok', 'data' => $data];
	}
}
