<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

/**
 * What the server knows about one user in one conversation for a single request.
 *
 * Membership always comes from the Talk collaboration resource provider. Role data
 * is present only when the Talk OCS API answered for the same user (roleSource
 * "talk-ocs"); otherwise only the collaboration call type is known.
 */
final class RoomAccessContext {
	public const ROLE_SOURCE_TALK_OCS = 'talk-ocs';
	public const ROLE_SOURCE_NONE = 'none';

	public function __construct(
		public readonly string $token,
		public readonly bool $isMember,
		public readonly string $roleSource,
		/** Talk conversation type (1 one-to-one, 2 group, 3 public, 4 changelog, 5 former one-to-one, 6 note to self) */
		public readonly ?int $roomType = null,
		/** Talk participant type (1 owner, 2 moderator, 3 user, 4 guest, 5 self-joined, 6 guest moderator) */
		public readonly ?int $participantType = null,
		public readonly ?int $permissions = null,
		public readonly ?int $readOnly = null,
		public readonly bool $federated = false,
		/** Lobby is on; non-moderators are waiting in it */
		public readonly bool $lobbyActive = false,
		/** Collaboration resource call type, used only without role data: one2one, group, public */
		public readonly ?string $callType = null,
	) {
	}
}
