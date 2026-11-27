<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\BackgroundJob;

use OCA\OwnTalkExtensions\Db\RoomToolsRepository;
use OCA\OwnTalkExtensions\Service\FollowupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

final class UnansweredReminders extends TimedJob {
	public function __construct(ITimeFactory $time, private RoomToolsRepository $tools,
		private FollowupService $followups, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void {
		$now = $this->time->getTime();
		foreach ($this->tools->due($now) as $row) {
			try {
				$this->followups->process($row);
			} catch (\Throwable $e) {
				if ($now - (int)$row['due_at'] >= 86400) {
					$this->tools->cancel($row['owner_id'], $row['room_token'], (int)$row['message_id'], (int)$row['due_at']);
					$this->logger->error('Conditional reminder expired after a failed check: ' . $e::class, ['app' => 'owntalk_extensions']);
				} else {
					$this->tools->retryLater($row, $now);
					$this->logger->warning('Conditional reminder check deferred: ' . $e::class, ['app' => 'owntalk_extensions']);
				}
			}
		}
	}
}
