<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions;

use OCA\OwnTalkExtensions\Service\FieldRegistry;
use OCA\OwnTalkExtensions\Service\RoomAccess;
use OCA\OwnTalkExtensions\Service\TalkOcsClient;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IUserSession;

final class Capabilities implements ICapability {
	public function __construct(
		private IAppManager $appManager,
		private IUserSession $userSession,
		private RoomAccess $roomAccess,
		private FieldRegistry $fields,
		private TalkOcsClient $talk,
	) {
	}

	/**
	 * @return array{owntalk_extensions?: array<string, mixed>}
	 */
	public function getCapabilities(): array {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->roomAccess->isTalkAvailable($user)) {
			return [];
		}
		return [
			'owntalk_extensions' => [
				'version' => $this->appManager->getAppVersion('owntalk_extensions'),
				'api' => 1,
				'features' => $this->fields->features(),
				'acl' => $this->talk->origin() === null ? 'membership' : 'roles',
				'roomTypes' => ['one2one', 'group', 'public'],
			],
		];
	}
}
