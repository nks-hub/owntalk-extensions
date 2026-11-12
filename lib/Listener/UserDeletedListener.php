<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Listener;

use OCA\OwnTalkExtensions\Db\RoomSettingsRepository;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Removes a deleted user's ID from "last changed by"; the shared settings stay for the
 * remaining participants.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class UserDeletedListener implements IEventListener {
	public function __construct(
		private RoomSettingsRepository $repository,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->repository->forgetWriter($event->getUser()->getUID());
		}
	}
}
