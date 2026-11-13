<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Db\RoomSettingsRepository;
use OCA\OwnTalkExtensions\Exception\ConflictException;
use OCA\OwnTalkExtensions\Service\FieldRegistry;
use OCA\OwnTalkExtensions\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IEmojiHelper;
use PHPUnit\Framework\TestCase;

/** In-memory stand-in with the same compare-and-set contract as the DB repository. */
final class MemoryRepository extends RoomSettingsRepository {
	public array $rows = [];
	/** Simulates a concurrent writer winning the next compare-and-set. */
	public int $failNextWrites = 0;

	public function __construct() {
	}

	public function find(string $token): ?array {
		return $this->rows[$token] ?? null;
	}

	public function insert(string $token, array $settings, array $fieldRevisions, string $userId, int $now): bool {
		if ($this->failNextWrites-- > 0 || isset($this->rows[$token])) {
			return false;
		}
		$this->rows[$token] = ['revision' => 1, 'settings' => $settings, 'fieldRevisions' => $fieldRevisions, 'updatedBy' => $userId, 'updatedAt' => $now];
		return true;
	}

	public function update(string $token, int $expectedRevision, array $settings, array $fieldRevisions, string $userId, int $now): bool {
		if ($this->failNextWrites-- > 0 || ($this->rows[$token]['revision'] ?? -1) !== $expectedRevision) {
			return false;
		}
		$this->rows[$token] = ['revision' => $expectedRevision + 1, 'settings' => $settings, 'fieldRevisions' => $fieldRevisions, 'updatedBy' => $userId, 'updatedAt' => $now];
		return true;
	}
}

final class SettingsServiceTest extends TestCase {
	public function testExhaustedRetriesNameOnlyRealConflicts(): void {
		$this->service->patch('room1', 0, ['chat.backgroundColor' => '#111111'], 'alice');
		$this->repo->failNextWrites = 3;
		try {
			$this->service->patch('room1', 1, ['bubble.ownColor' => '#222222'], 'bob');
			self::fail('no conflict');
		} catch (ConflictException $e) {
			self::assertSame([], $e->fields);
		}
	}

	private MemoryRepository $repo;
	private SettingsService $service;

	protected function setUp(): void {
		$emoji = $this->createMock(IEmojiHelper::class);
		$emoji->method('doesPlatformSupportEmoji')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$this->repo = new MemoryRepository();
		$this->service = new SettingsService($this->repo, new FieldRegistry($emoji), $time);
	}

	public function testEmptyRoomHasRevisionZeroAndAllFieldsNull(): void {
		$doc = $this->service->get('room1');
		self::assertSame(0, $doc['revision']);
		self::assertNull($doc['settings']['chat.backgroundColor']);
		self::assertSame([], $doc['fieldRevisions']);
	}

	public function testFirstWriteThenDisjointStaleWriteSucceeds(): void {
		$this->service->patch('room1', 0, ['chat.backgroundColor' => '#112233'], 'a');
		$doc = $this->service->patch('room1', 0, ['bubble.ownColor' => '#445566'], 'b');
		self::assertSame(2, $doc['revision']);
		self::assertSame('#112233', $doc['settings']['chat.backgroundColor']);
		self::assertSame(['chat.backgroundColor' => 1, 'bubble.ownColor' => 2], $doc['fieldRevisions']);
	}

	public function testStaleWriteOfSameFieldConflicts(): void {
		$this->service->patch('room1', 0, ['chat.backgroundColor' => '#112233'], 'a');
		try {
			$this->service->patch('room1', 0, ['chat.backgroundColor' => '#000000'], 'b');
			self::fail('expected conflict');
		} catch (ConflictException $e) {
			self::assertSame(['chat.backgroundColor'], $e->fields);
			self::assertSame('#112233', $e->current['settings']['chat.backgroundColor']);
		}
	}

	public function testBaseFromTheFutureConflicts(): void {
		$this->expectException(ConflictException::class);
		$this->service->patch('room1', 5, ['chat.backgroundColor' => '#112233'], 'a');
	}

	public function testNullRemovesValueButKeepsRevisionGrowing(): void {
		$this->service->patch('room1', 0, ['chat.backgroundColor' => '#112233'], 'a');
		$doc = $this->service->patch('room1', 1, ['chat.backgroundColor' => null], 'a');
		self::assertSame(2, $doc['revision']);
		self::assertNull($doc['settings']['chat.backgroundColor']);
	}

	public function testLostCompareAndSetIsRetried(): void {
		$this->service->patch('room1', 0, ['chat.backgroundColor' => '#112233'], 'a');
		$this->repo->failNextWrites = 2;
		$doc = $this->service->patch('room1', 1, ['bubble.ownColor' => '#445566'], 'b');
		self::assertSame(2, $doc['revision']);
	}

	public function testGivesUpAfterThreeLostCompareAndSets(): void {
		$this->repo->failNextWrites = 3;
		$this->expectException(ConflictException::class);
		$this->service->patch('room1', 0, ['bubble.ownColor' => '#445566'], 'b');
	}
}
