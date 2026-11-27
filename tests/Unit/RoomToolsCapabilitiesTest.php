<?php

declare(strict_types=1);

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Capabilities;
use OCA\OwnTalkExtensions\Service\ChangeSignal;
use OCA\OwnTalkExtensions\Service\FieldRegistry;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class RoomToolsCapabilitiesTest extends TestCase {
	public function testVersionAndFlagsAdvertiseRoomToolsOnlyWithTalkRoleLookup(): void {
		$info = simplexml_load_file(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$version = (string)$info->version;
		self::assertTrue(version_compare($version, '0.7.0', '>='));
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn($version);
		$users = $this->createStub(IUserSession::class);
		$users->method('getUser')->willReturn($this->createStub(IUser::class));
		$rooms = $this->createStub(RoomAccess::class);
		$rooms->method('isTalkAvailable')->willReturn(true);
		foreach ([null, 'https://cloud.example'] as $origin) {
			$talk = $this->createStub(TalkOcsClient::class);
			$talk->method('origin')->willReturn($origin);
			$cap = new Capabilities($appManager, $users, $rooms, $this->createStub(FieldRegistry::class), $talk,
				$this->createStub(ChangeSignal::class));
			$data = $cap->getCapabilities()['owntalk_extensions'];
			self::assertSame($version, $data['version']);
			self::assertContains('personal.gifFavorites', $data['features']);
			foreach (['chat.checklists', 'chat.unansweredReminders', 'personal.draftHandoff', 'chat.photoNotes'] as $feature) {
				self::assertSame($origin !== null, in_array($feature, $data['features'], true));
			}
		}
	}
}
