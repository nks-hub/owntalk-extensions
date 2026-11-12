<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TalkOcsClientTest extends TestCase {
	private array $sentOptions = [];
	private string $sentUrl = '';

	private function client(string $origin, int $status, array $data): TalkOcsClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode(['ocs' => ['meta' => [], 'data' => $data]]));
		$http = $this->createMock(IClient::class);
		$http->method('get')->willReturnCallback(function (string $url, array $options) use ($response) {
			$this->sentUrl = $url;
			$this->sentOptions = $options;
			return $response;
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($origin);
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('isLocalCacheAvailable')->willReturn(false);
		return new TalkOcsClient($service, $config, $cache, new NullLogger());
	}

	private static function room(string $actorId): array {
		return ['token' => 'abcd1234', 'type' => 2, 'participantType' => 2, 'actorType' => 'users', 'actorId' => $actorId];
	}

	public function testAnswerForTheCallerIsAccepted(): void {
		$result = $this->client('https://cloud.example', 200, self::room('alice'))
			->getRoom('abcd1234', 'Basic YWxpY2U6cHc=', 'alice', true);
		self::assertSame('ok', $result['status']);
		self::assertSame('https://cloud.example/ocs/v2.php/apps/spreed/api/v4/room/abcd1234', $this->sentUrl);
		self::assertSame('', $this->sentOptions['proxy']);
		self::assertFalse($this->sentOptions['allow_redirects']);
	}

	public function testAnswerForAnotherUserIsRejected(): void {
		$result = $this->client('https://cloud.example', 200, self::room('mallory'))
			->getRoom('abcd1234', 'Basic YWxpY2U6cHc=', 'alice', true);
		self::assertSame('unavailable', $result['status']);
	}

	public function testPlainHttpToAnotherHostIsNotUsed(): void {
		$client = $this->client('http://cloud.example', 200, self::room('alice'));
		self::assertNull($client->origin());
		self::assertSame('unavailable', $client->getRoom('abcd1234', 'Basic YWxpY2U6cHc=', 'alice', true)['status']);
		self::assertSame('', $this->sentUrl);
	}

	public function testHeaderWithTrailingNewlineIsNotForwarded(): void {
		$this->client('https://cloud.example', 200, self::room('alice'))
			->getRoom('abcd1234', "Basic YWxpY2U6cHc=\n", 'alice', true);
		self::assertSame('', $this->sentUrl);
	}

	public function testNotFoundMeansNotAMember(): void {
		$result = $this->client('https://cloud.example', 404, [])->getRoom('abcd1234', 'Bearer abc', 'alice', true);
		self::assertSame('not_member', $result['status']);
	}
}
