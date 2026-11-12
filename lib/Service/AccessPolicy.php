<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

/**
 * Decides who may read and change shared conversation settings.
 *
 * Pure function of the access context so the whole rule table is unit-testable
 * without Nextcloud. Every branch that is not explicitly allowed denies.
 */
final class AccessPolicy {
	public const POLICY_MODERATORS = 'moderators';
	public const POLICY_CHAT = 'chat';

	private const ROOM_ONE_TO_ONE = 1;
	private const ROOM_GROUP = 2;
	private const ROOM_PUBLIC = 3;
	private const ROOM_NOTE_TO_SELF = 6;

	private const PARTICIPANT_OWNER = 1;
	private const PARTICIPANT_MODERATOR = 2;
	private const PARTICIPANT_USER = 3;

	private const PERMISSION_CHAT = 128;

	public function canRead(RoomAccessContext $ctx): bool {
		return $ctx->isMember;
	}

	public function canEditShared(RoomAccessContext $ctx, string $policy): bool {
		if (!$ctx->isMember || $ctx->federated) {
			return false;
		}

		if ($ctx->roleSource !== RoomAccessContext::ROLE_SOURCE_TALK_OCS) {
			// Without Talk roles only one-to-one rooms are editable: both members may edit there.
			return $ctx->callType === 'one2one';
		}

		// Guests (4, 6) and users who only followed a public link (5) never edit.
		if (!\in_array($ctx->participantType, [self::PARTICIPANT_OWNER, self::PARTICIPANT_MODERATOR, self::PARTICIPANT_USER], true)) {
			return false;
		}
		$isModerator = \in_array($ctx->participantType, [self::PARTICIPANT_OWNER, self::PARTICIPANT_MODERATOR], true);

		return match ($ctx->roomType) {
			self::ROOM_ONE_TO_ONE => $ctx->readOnly !== 1 || $isModerator,
			self::ROOM_NOTE_TO_SELF => $ctx->participantType === self::PARTICIPANT_OWNER,
			self::ROOM_GROUP, self::ROOM_PUBLIC => $this->canEditGroup($ctx, $policy, $isModerator),
			default => false,
		};
	}

	private function canEditGroup(RoomAccessContext $ctx, string $policy, bool $isModerator): bool {
		if ($isModerator) {
			return true;
		}
		if ($ctx->readOnly === 1 || $ctx->lobbyActive) {
			return false;
		}
		return $policy === self::POLICY_CHAT
			&& $ctx->permissions !== null
			&& ($ctx->permissions & self::PERMISSION_CHAT) === self::PERMISSION_CHAT;
	}
}
