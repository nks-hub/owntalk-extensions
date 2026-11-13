<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells every participant's OwnTalk that a conversation's settings changed, through
 * the notify_push app when it is installed. Clients get the frame
 * `owntalk_room_settings {"token":"…","revision":n}` and read the settings again.
 *
 * Best effort: without notify_push, without Talk roles, or on any failure nothing is
 * sent and clients pick the change up on their next poll.
 */
class ChangeSignal {
	public const MESSAGE = 'owntalk_room_settings';
	private const QUEUE = 'OCA\\NotifyPush\\Queue\\IQueue';

	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private TalkOcsClient $talk,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->appManager->isEnabledForAnyone('notify_push') && interface_exists(self::QUEUE);
	}

	/**
	 * @return int number of users the signal was queued for
	 */
	public function send(string $token, int $revision, string $authorization): int {
		if (!$this->isAvailable()) {
			return 0;
		}
		$users = $this->talk->participantUserIds($token, $authorization);
		if ($users === null || $users === []) {
			return 0;
		}
		try {
			$queue = $this->container->get(self::QUEUE);
			foreach ($users as $user) {
				$queue->push('notify_custom', [
					'user' => $user,
					'message' => self::MESSAGE,
					'body' => ['token' => $token, 'revision' => $revision],
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->info('notify_push signal failed: ' . $e::class, ['app' => 'owntalk_extensions']);
			return 0;
		}
		return \count($users);
	}
}
