<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Controller\RoomToolsController;
use OCA\OwnTalkExtensions\Db\RoomToolsRepository;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCA\OwnTalkExtensions\Service\AccessPolicy;
use OCA\OwnTalkExtensions\Service\FollowupService;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\RoomAccessContext;
use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;

final class RoomToolsControllerTest extends TestCase {
	private string $owner = 'alice';
	private array $source = ['actorType' => 'users', 'actorId' => 'alice', 'threadId' => 10];
	private array $replies = [];
	private array $stored = [];
	private array $checked = [];
	private RoomAccessContext $context;
	private RoomToolsController $controller;

	protected function setUp(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer example');
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturnCallback(fn () => $this->owner);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->context = $this->room();
		$access = $this->createStub(RoomAccess::class);
		$access->method('resolve')->willReturnCallback(function () {
			if (!$this->context->isMember) {
				throw new AccessException(AccessException::NOT_FOUND);
			}
			return $this->context;
		});
		$talk = $this->createStub(TalkOcsClient::class);
		$talk->method('visibleMessage')->willReturnCallback(fn () => $this->source);
		$tools = $this->createStub(RoomToolsRepository::class);
		$tools->method('schedule')->willReturnCallback(function ($owner, $room, $id, $roomId, $thread, $due) {
			$this->stored[$owner][$room][$id] = ['room_id' => $roomId, 'thread_id' => $thread, 'due_at' => $due];
		});
		$tools->method('reminder')->willReturnCallback(fn ($owner, $room, $id) => $this->stored[$owner][$room][$id] ?? null);
		$tools->method('cancel')->willReturnCallback(function ($owner, $room, $id, $expected = null) {
			if ($expected !== null && ($this->stored[$owner][$room][$id]['due_at'] ?? null) !== $expected) {
				return false;
			}
			unset($this->stored[$owner][$room][$id]);
			return true;
		});
		$tools->method('setChecked')->willReturnCallback(function ($room, $id, $key, $checked) {
			$this->checked[$room][$id][$key] = $checked;
		});
		$tools->method('checked')->willReturnCallback(fn ($room, $id, $items) => array_keys(array_filter(
			$items, fn ($key) => $this->checked[$room][$id][$key] ?? false)));
		$comments = $this->createStub(ICommentsManager::class);
		$source = $this->createStub(IComment::class);
		$source->method('getObjectType')->willReturn('chat');
		$source->method('getObjectId')->willReturn('17');
		$comments->method('get')->willReturn($source);
		$comments->method('getCommentsWithVerbForObjectSinceComment')->willReturnCallback(
			function ($type, $room, $verbs, $after, $sort, $limit, $include, $parent) {
				self::assertSame(['chat', '17', 'asc', false], [$type, $room, $sort, $include]);
				$matches = [];
				foreach ($this->replies as [$id, $thread, $actor, $verb, $ts]) {
					if ($id <= $after || !in_array($verb, $verbs, true) || ($parent !== '' && (int)$parent !== $thread)) {
						continue;
					}
					$reply = $this->createStub(IComment::class);
					$reply->method('getActorType')->willReturn('users');
					$reply->method('getActorId')->willReturn($actor);
					$reply->method('getId')->willReturn((string)$id);
					$reply->method('getTopmostParentId')->willReturn((string)$thread);
					$reply->method('getCreationDateTime')->willReturn(new \DateTime('@' . $ts));
					$matches[] = $reply;
				}
				return array_slice($matches, 0, $limit);
			});
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1000);
		$service = new FollowupService($tools, $comments, $access, $this->createStub(IUserManager::class),
			$this->createStub(IManager::class), $this->createStub(IDBConnection::class), $clock);
		$this->controller = new RoomToolsController($request, $session, $access, $talk, new AccessPolicy(), $tools, $comments, $clock, $service);
	}

	private function room(int $type = 1, int $permissions = 128, bool $member = true, bool $federated = false): RoomAccessContext {
		return new RoomAccessContext('room1', $member, RoomAccessContext::ROLE_SOURCE_TALK_OCS, $type, 3, $permissions, 0, $federated);
	}

	public function testChecklistKeepsIndependentEditsWhenTheTitleOrOrderChanges(): void {
		$text = "☑ Žádost\r\n- [ ]  😀\r\n- [ ] Dva\r\n- [ ] Dva";
		$this->source['message'] = $text;
		$rev = hash('sha256', $text);
		self::assertSame($rev, $this->controller->checklist('room1', 10)->getData()['revision']);
		self::assertSame([0], $this->controller->setItem('room1', 10, $rev, 0, true)->getData()['checked']);
		$this->owner = 'bob';
		self::assertSame([0, 2], $this->controller->setItem('room1', 10, $rev, 2, true)->getData()['checked']);
		$this->source['message'] = "☑ Jiné\n- [ ] Dva\n- [ ] Dva\n- [ ]  😀\n- [ ] Nové";
		self::assertSame([1, 2], $this->controller->checklist('room1', 10)->getData()['checked']);
		self::assertSame(409, $this->controller->setItem('room1', 10, $rev, 1, false)->getStatus());
		$this->context = $this->room(2, 0);
		$state = $this->controller->checklist('room1', 10)->getData();
		self::assertFalse($state['canEdit']);
		self::assertSame(403, $this->controller->setItem('room1', 10, $state['revision'], 1, false)->getStatus());
		$this->context = $this->room(member: false);
		self::assertSame(404, $this->controller->checklist('room1', 10)->getStatus());
	}

	public function testOnlyAReplyFromAnotherPersonInTheOriginalThreadClearsTheReminder(): void {
		$this->context = $this->room(2);
		self::assertSame(400, $this->controller->schedule('room1', 10, 1050, 10)->getStatus());
		$this->source['isThread'] = true;
		self::assertSame(400, $this->controller->schedule('room1', 10, 1050, 12)->getStatus());
		$this->replies = [[12, 10, 'bob', 'reaction', 901], [13, 10, 'alice', 'comment', 902],
			[14, 7, 'bob', 'comment', 903], [15, 10, 'bob', 'read', 904]];
		self::assertSame(['dueAt' => 1050], $this->controller->schedule('room1', 10, 1050, 10)->getData());
		$this->owner = 'bob';
		self::assertSame(['dueAt' => null], $this->controller->followup('room1', 10)->getData());
		self::assertSame(403, $this->controller->schedule('room1', 10, 1050, 10)->getStatus());
		$this->controller->cancel('room1', 10);
		$this->owner = 'alice';
		self::assertSame(['dueAt' => 1050], $this->controller->followup('room1', 10)->getData());
		$this->replies[] = [16, 10, 'bob', 'comment', 980];
		self::assertSame(['dueAt' => null], $this->controller->followup('room1', 10)->getData());
		self::assertEmpty($this->stored['alice']['room1']);
		self::assertSame(['dueAt' => null], $this->controller->schedule('room1', 10, 1051, 10)->getData());
	}

	public function testOrdinaryDirectMessageUsesTheConversationAndRejectsInvalidDates(): void {
		self::assertSame(400, $this->controller->schedule('room1', 10, 1000)->getStatus());
		self::assertSame(400, $this->controller->schedule('room1', 10, 1000 + 367 * 86400)->getStatus());
		$this->replies[] = [12, 8, 'bob', 'comment', 901];
		self::assertSame(['dueAt' => 1030], $this->controller->schedule('room1', 10, 1030)->getData());
		$this->replies[] = [13, 0, 'bob', 'comment', 902];
		self::assertSame(['dueAt' => null], $this->controller->followup('room1', 10)->getData());
		$this->context = $this->room(federated: true);
		self::assertSame(403, $this->controller->followup('room1', 10)->getStatus());
	}
}
