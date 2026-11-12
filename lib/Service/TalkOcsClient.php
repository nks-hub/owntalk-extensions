<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Asks this server's public Talk OCS API about a conversation on behalf of the caller.
 *
 * The caller's Authorization header is forwarded only to the origin from the
 * admin-controlled "overwrite.cli.url", never to a host taken from the request.
 * The header is not stored or logged.
 */
class TalkOcsClient {
	private const MAX_BODY_BYTES = 262144;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
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
	 * @return array{status: 'ok', data: array<string, mixed>}|array{status: 'not_member'}|array{status: 'unavailable'}
	 */
	public function getRoom(string $token, string $authorization): array {
		$origin = $this->origin();
		if ($origin === null || !preg_match('/^(Basic|Bearer) [A-Za-z0-9+\/=._~-]+$/', $authorization)) {
			return ['status' => 'unavailable'];
		}

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
		return ['status' => 'ok', 'data' => $data];
	}
}
