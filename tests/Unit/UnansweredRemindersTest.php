<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\BackgroundJob\UnansweredReminders;
use OCA\OwnTalkExtensions\Db\RoomToolsRepository;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCA\OwnTalkExtensions\Service\FollowupService;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\RoomAccessContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UnansweredRemindersTest extends TestCase {
	private function makeJob(array $rows, array $replyTimes, array $inaccessible, array &$sent, array &$cancelled, array &$deferred): UnansweredReminders {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(100000);
		$tools = $this->createStub(RoomToolsRepository::class);
		$tools->method('due')->willReturn($rows);
		$tools->method('cancel')->willReturnCallback(function ($owner, $token, $id, $due) use (&$cancelled) {
			if (isset($cancelled[$id])) {
				return false;
			}
			$cancelled[$id] = $due;
			return true;
		});
		$tools->method('retryLater')->willReturnCallback(function ($row, $now) use (&$deferred) {
			$deferred[$row['message_id']] = $now;
		});
		$rooms = $this->createStub(RoomAccess::class);
		$rooms->method('resolve')->willReturnCallback(static function ($user, $token) use ($inaccessible) {
			if (isset($inaccessible[$token])) {
				throw new AccessException($inaccessible[$token]);
			}
			return new RoomAccessContext($token, true, RoomAccessContext::ROLE_SOURCE_NONE);
		});
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($this->createStub(IUser::class));
		$comments = $this->createStub(ICommentsManager::class);
		$source = $this->createStub(IComment::class);
		$source->method('getObjectType')->willReturn('chat');
		$source->method('getObjectId')->willReturn('42');
		$source->method('getActorType')->willReturn('users');
		$source->method('getActorId')->willReturn('alice');
		$source->method('getVerb')->willReturn('comment');
		$comments->method('get')->willReturn($source);
		$comments->method('getCommentsWithVerbForObjectSinceComment')->willReturnCallback(function ($type, $room, $verbs, $cursor) use ($replyTimes) {
			if (!isset($replyTimes[$cursor])) {
				return [];
			}
			$reply = $this->createStub(IComment::class);
			$reply->method('getCreationDateTime')->willReturn(new \DateTime('@' . $replyTimes[$cursor]));
			$reply->method('getActorType')->willReturn('users');
			$reply->method('getActorId')->willReturn('bob');
			return [$reply];
		});
		$notices = $this->createMock(IManager::class);
		$notices->method('createNotification')->willReturnCallback(function () {
			$notice = $this->createMock(INotification::class);
			foreach (['setApp', 'setUser', 'setObject', 'setDateTime', 'setSubject'] as $method) {
				$notice->method($method)->willReturnSelf();
			}
			$notice->method('setMessage')->willReturnCallback(function ($message, $params) use ($notice) {
				$notice->method('getMessageParameters')->willReturn($params);
				return $notice;
			});
			return $notice;
		});
		$notices->method('notify')->willReturnCallback(function ($notice) use (&$sent) {
			$sent[] = $notice->getMessageParameters()['commentId'];
		});
		$service = new FollowupService($tools, $comments, $rooms, $users, $notices, $this->createStub(IDBConnection::class), $time);
		return new UnansweredReminders($time, $tools, $service, new NullLogger());
	}

	private static function row(int $id, string $token = 'room1', int $due = 99900): array {
		return ['owner_id' => 'alice', 'message_id' => $id, 'room_token' => $token, 'room_id' => 42,
			'thread_id' => $id, 'due_at' => $due, 'next_check' => $due];
	}

	public function testDueJobHonoursDeadlineAndMembershipWhileProcessingLaterMessages(): void {
		$sent = $cancelled = $deferred = [];
		$rows = [self::row(10, 'retry'), self::row(11, 'left'), self::row(12), self::row(13), self::row(14), self::row(15, 'retry', 500)];
		$job = $this->makeJob($rows, [12 => 99900, 13 => 99901],
			['retry' => AccessException::TALK_UNAVAILABLE, 'left' => AccessException::NOT_FOUND], $sent, $cancelled, $deferred);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
		$run->invoke($job, null);
		self::assertSame([13, 14], $sent);
		self::assertSame([11, 12, 13, 14, 15], array_keys($cancelled));
		self::assertSame([10 => 100000], $deferred);
	}
}
