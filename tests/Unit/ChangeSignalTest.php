<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NotifyPush\Queue {
	if (!interface_exists(IQueue::class)) {
		interface IQueue {
			public function push(string $channel, $message): void;
		}
	}
}

namespace OCA\OwnTalkExtensions\Tests\Unit {
	use OCA\NotifyPush\Queue\IQueue;
	use OCA\OwnTalkExtensions\Service\ChangeSignal;
	use OCA\OwnTalkExtensions\Service\TalkOcsClient;
	use OCP\App\IAppManager;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\NullLogger;

	final class ChangeSignalTest extends TestCase {
		/** @var list<array{string, mixed}> */
		private array $pushed = [];

		private function signal(bool $installed, ?array $participants): ChangeSignal {
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isEnabledForAnyone')->with('notify_push')->willReturn($installed);
			$queue = new class($this->pushed) implements IQueue {
				public function __construct(private array &$pushed) {
				}

				public function push(string $channel, $message): void {
					$this->pushed[] = [$channel, $message];
				}
			};
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->with(IQueue::class)->willReturn($queue);
			$talk = $this->createMock(TalkOcsClient::class);
			$talk->method('participantUserIds')->with('room1234', 'Basic abc')->willReturn($participants);
			return new ChangeSignal($apps, $container, $talk, new NullLogger());
		}

		public function testSignalsEveryUserInTheRoom(): void {
			self::assertSame(2, $this->signal(true, ['alice', 'bob'])->send('room1234', 7, 'Basic abc'));
			self::assertSame([
				['notify_custom', ['user' => 'alice', 'message' => 'owntalk_room_settings', 'body' => ['token' => 'room1234', 'revision' => 7]]],
				['notify_custom', ['user' => 'bob', 'message' => 'owntalk_room_settings', 'body' => ['token' => 'room1234', 'revision' => 7]]],
			], $this->pushed);
		}

		public function testNothingWithoutNotifyPush(): void {
			self::assertSame(0, $this->signal(false, ['alice'])->send('room1234', 7, 'Basic abc'));
			self::assertSame([], $this->pushed);
		}

		public function testNothingWhenTalkCannotListParticipants(): void {
			self::assertSame(0, $this->signal(true, null)->send('room1234', 7, 'Basic abc'));
			self::assertSame([], $this->pushed);
		}
	}
}
