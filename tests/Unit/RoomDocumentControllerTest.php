<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Controller\RoomDocumentController;
use OCA\OwnTalkExtensions\Db\RoomDocuments;
use OCA\OwnTalkExtensions\Exception\AccessException;
use OCA\OwnTalkExtensions\Exception\RoomToolException;
use OCA\OwnTalkExtensions\Service\AccessPolicy;
use OCA\OwnTalkExtensions\Service\PersonalRoomDocuments;
use OCA\OwnTalkExtensions\Service\PhotoNotes;
use OCA\OwnTalkExtensions\Service\PhotoFileAccess;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\RoomAccessContext;
use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class RoomDocumentControllerTest extends TestCase {
	private const A = '10000000-0000-4000-8000-000000000001';
	private const B = '10000000-0000-4000-8000-000000000002';
	private const C = '10000000-0000-4000-8000-000000000003';
	private string $owner = 'alice';
	private bool $member = true;
	private bool $editable = true;
	private bool $readable = true;
	private string $version = '"v1"';
	private array $visible;
	private RoomDocumentController $api;

	protected function setUp(): void {
		$state = $ops = [];
		$documents = $this->createStub(RoomDocuments::class);
		$documents->method('read')->willReturnCallback(function ($scope) use (&$state) { return $state[$scope] ?? []; });
		$documents->method('operation')->willReturnCallback(function ($scope, $id) use (&$ops) { return $ops[$scope][$id] ?? null; });
		$documents->method('change')->willReturnCallback(function ($scope, $owner, $id, $input, $apply) use (&$ops, &$state) {
			$hash = hash('sha256', json_encode($input));
			if ($id !== null && isset($ops[$scope][$id])) {
				if ($ops[$scope][$id] !== $hash) {
					throw new RoomToolException(409);
				}
			} else {
				$state[$scope] = $apply($state[$scope] ?? []);
				if ($id !== null) {
					$ops[$scope][$id] = $hash;
				}
			}
			return $state[$scope];
		});
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer fixture');
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturnCallback(fn () => $this->owner);
		$users = $this->createStub(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		$rooms = $this->createStub(RoomAccess::class);
		$rooms->method('isTalkAvailable')->willReturn(true);
		$rooms->method('resolve')->willReturnCallback(function ($user, $token) {
			if (!$this->member) {
				throw new AccessException(AccessException::NOT_FOUND);
			}
			return new RoomAccessContext($token, true, RoomAccessContext::ROLE_SOURCE_TALK_OCS, 2, 3, $this->editable ? 128 : 0);
		});
		$this->visible = [
			7 => ['isThread' => true, 'threadId' => 7],
			8 => ['threadId' => 7, 'messageParameters' => ['file' => ['type' => 'file', 'id' => '12']]],
			9 => ['threadId' => 9],
		];
		$talk = $this->createStub(TalkOcsClient::class);
		$talk->method('contextMessage')->willReturnCallback(fn ($room, $id) =>
			$this->visible[$id] ?? throw new AccessException(AccessException::NOT_FOUND));
		$file = $this->createStub(File::class);
		$file->method('isReadable')->willReturnCallback(fn () => $this->readable);
		$file->method('getMimetype')->willReturn('image/jpeg');
		$file->method('getEtag')->willReturnCallback(fn () => $this->version);
		$files = $this->createStub(PhotoFileAccess::class);
		$files->method('forUser')->willReturnCallback(fn ($owner, $id) => $owner === $this->owner && $id === 12 ? $file : null);
		$this->api = new RoomDocumentController($request, $users, $rooms, $talk, new AccessPolicy(),
			new PersonalRoomDocuments($documents), new PhotoNotes($documents, $files));
	}

	public function testFavoriteReplayAfterARemoteRemovalDoesNotRestoreTheGif(): void {
		self::assertTrue($this->api->saveGif('giphy', 'abc123', self::A, 'Hello')->getData()['saved']);
		self::assertSame('Hello', $this->api->gifs()->getData()['items'][0]['title']);
		$this->owner = 'bob';
		self::assertSame([], $this->api->gifs()->getData()['items']);
		self::assertTrue($this->api->saveGif('giphy', 'abc123', self::A)->getData()['saved']);
		$this->owner = 'alice';
		self::assertFalse($this->api->deleteGif('giphy', 'abc123', self::B)->getData()['saved']);
		self::assertFalse($this->api->saveGif('giphy', 'abc123', self::A, 'Hello')->getData()['saved']);
		self::assertSame(409, $this->api->saveGif('giphy', 'different', self::A, 'Hello')->getStatus());
		self::assertSame(400, $this->api->saveGif('unknown', 'abc123', self::C)->getStatus());
	}

	public function testDraftSiblingsSurviveTransferAndOnlyTheSentVersionIsRemoved(): void {
		self::assertSame(404, $this->api->writeDraft('room1', 7, self::A, 'Hi', null, 9)->getStatus());
		self::assertSame(200, $this->api->writeDraft('room1', 7, self::A, 'Hi', null, 8)->getStatus());
		$this->api->writeDraft('room1', 7, self::B, 'Phone', self::A, 8);
		$this->api->writeDraft('room1', 7, self::C, 'Desktop', self::A, 8);
		$this->owner = 'bob';
		self::assertSame([], $this->api->drafts('room1', 7)->getData()['variants']);
		$this->owner = 'alice';
		self::assertSame([], $this->api->drafts('room1', 0)->getData()['variants']);
		self::assertSame([], $this->api->drafts('room2', 7)->getData()['variants']);
		$variants = $this->api->drafts('room1', 7)->getData()['variants'];
		self::assertSame(['Desktop', 'Phone'], array_column($variants, 'text'));
		self::assertSame([8, 8], array_column($variants, 'replyTo'));
		$this->api->deleteDraft('room1', 7, self::B);
		self::assertSame([self::C], array_column($this->api->writeDraft('room1', 7, self::B, 'Phone', self::A, 8)->getData()['variants'], 'id'));
		unset($this->visible[8]);
		self::assertNull($this->api->drafts('room1', 7)->getData()['variants'][0]['replyTo']);
		$this->member = false;
		self::assertSame(404, $this->api->drafts('room1', 7)->getStatus());
	}

	public function testPhotoNotesRequireTheOriginalMessageFileAndVersionForEveryParticipant(): void {
		self::assertSame(404, $this->api->photoNotes('room1', 9, 12)->getStatus());
		self::assertSame(404, $this->api->photoNotes('room1', 8, 13)->getStatus());
		self::assertSame('v1', $this->api->photoNotes('room1', 8, 12)->getData()['version']);
		self::assertSame(400, $this->api->addNote('room1', 8, 12, 'v1', self::A, -0.1, 0, 'Hi')->getStatus());
		self::assertSame(400, $this->api->addNote('room1', 8, 12, 'v1', self::A, 'invalid', 0, 'Hi')->getStatus());
		$add = fn () => $this->api->addNote('room1', 8, 12, 'v1', self::A, 0.2, 0.9, 'Here');
		self::assertSame(200, $add()->getStatus());
		self::assertCount(1, $add()->getData()['notes']);
		$this->owner = 'bob';
		self::assertSame(409, $add()->getStatus());
		$get = fn () => $this->api->photoNotes('room1', 8, 12, 'v1');
		$reply = fn () => $this->api->reply('room1', 8, 12, self::A, '"v1"', self::B, 'Yes');
		self::assertSame('bob', $reply()->getData()['notes'][0]['replies'][0]['author']);
		self::assertCount(1, $reply()->getData()['notes'][0]['replies']);
		$this->editable = false;
		self::assertFalse($get()->getData()['canEdit']);
		self::assertSame(403, $reply()->getStatus());
		$this->version = 'v2';
		self::assertSame(409, $get()->getStatus());
		self::assertSame([], $this->api->photoNotes('room1', 8, 12)->getData()['notes']);
		$this->readable = false;
		self::assertSame(404, $reply()->getStatus());
		$this->member = false;
		self::assertSame(404, $get()->getStatus());
	}

	public function testFullPhotoAndThreadStayReadableAndDistinguishLimitsFromAVersionChange(): void {
		$id = static fn (int $i) => sprintf('10000000-0000-4000-8000-%012d', $i);
		$pin = fn (int $i) => $this->api->addNote('room1', 8, 12, 'v1', $id($i), .4, .6, 'Point');
		$reply = fn (int $i, int $parent) => $this->api->reply('room1', 8, 12, $id($parent), 'v1', $id($i), 'Reply');
		for ($i = 1; $i <= 100; ++$i) {
			$pin($i);
		}
		self::assertSame(422, $pin(101)->getStatus());
		for ($i = 101; $i <= 200; ++$i) {
			$reply($i, 1);
		}
		self::assertSame(422, $reply(201, 1)->getStatus());
		for ($i = 201; $i <= 256; ++$i) {
			$reply($i, 2);
		}
		self::assertSame(422, $reply(257, 2)->getStatus());
		self::assertSame(200, $reply(256, 2)->getStatus());
		$notes = $this->api->photoNotes('room1', 8, 12, 'v1')->getData()['notes'];
		self::assertCount(100, $notes);
		self::assertCount(100, $notes[0]['replies']);
		self::assertCount(56, $notes[1]['replies']);
	}
}
