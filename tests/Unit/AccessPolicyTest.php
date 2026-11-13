<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Service\AccessPolicy;
use OCA\OwnTalkExtensions\Service\RoomAccessContext as Ctx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessPolicyTest extends TestCase {
	private static function roles(int $roomType, int $participantType, int $permissions = 254, int $readOnly = 0, bool $federated = false): Ctx {
		return new Ctx('abcd1234', true, Ctx::ROLE_SOURCE_TALK_OCS, $roomType, $participantType, $permissions, $readOnly, $federated);
	}

	public static function cases(): array {
		$chatless = 254 & ~128;
		return [
			'non member' => [new Ctx('abcd1234', false, Ctx::ROLE_SOURCE_TALK_OCS, 2, 1), 'moderators', false],
			'1:1 owner' => [self::roles(1, 1), 'moderators', true],
			'1:1 read-only owner' => [self::roles(1, 1, readOnly: 1), 'moderators', true],
			'former 1:1' => [self::roles(5, 1), 'moderators', false],
			'changelog' => [self::roles(4, 3), 'moderators', false],
			'note to self owner' => [self::roles(6, 1), 'moderators', true],
			'group owner' => [self::roles(2, 1), 'moderators', true],
			'group moderator' => [self::roles(2, 2), 'moderators', true],
			'group user' => [self::roles(2, 3), 'moderators', false],
			'group user, chat policy' => [self::roles(2, 3), 'chat', true],
			'group user without chat, chat policy' => [self::roles(2, 3, $chatless), 'chat', false],
			'group user read-only, chat policy' => [self::roles(2, 3, readOnly: 1), 'chat', false],
			'group moderator read-only' => [self::roles(2, 2, readOnly: 1), 'moderators', true],
			'public moderator' => [self::roles(3, 2), 'moderators', true],
			'public self-joined' => [self::roles(3, 5), 'chat', false],
			'guest type' => [self::roles(3, 4), 'chat', false],
			'guest moderator type' => [self::roles(2, 6), 'moderators', false],
			'federated owner' => [self::roles(2, 1, federated: true), 'moderators', false],
			'user waiting in lobby, chat policy' => [new Ctx('abcd1234', true, Ctx::ROLE_SOURCE_TALK_OCS, 2, 3, 254, 0, false, true), 'chat', false],
			'moderator with lobby on' => [new Ctx('abcd1234', true, Ctx::ROLE_SOURCE_TALK_OCS, 2, 2, 254, 0, false, true), 'moderators', true],
			'unknown room type' => [self::roles(99, 1), 'moderators', false],
			'no roles, one2one' => [new Ctx('abcd1234', true, Ctx::ROLE_SOURCE_NONE, callType: 'one2one'), 'moderators', true],
			'roles configured but lookup failed, one2one' => [new Ctx('abcd1234', true, Ctx::ROLE_SOURCE_NONE, callType: 'one2one'), 'moderators', false, true],
			'no roles, group' => [new Ctx('abcd1234', true, Ctx::ROLE_SOURCE_NONE, callType: 'group'), 'chat', false],
			'no roles, unknown type' => [new Ctx('abcd1234', true, Ctx::ROLE_SOURCE_NONE), 'moderators', false],
		];
	}

	#[DataProvider('cases')]
	public function testCanEditShared(Ctx $ctx, string $policy, bool $expected, bool $rolesConfigured = false): void {
		self::assertSame($expected, (new AccessPolicy())->canEditShared($ctx, $policy, $rolesConfigured || $ctx->roleSource === Ctx::ROLE_SOURCE_TALK_OCS));
	}

	public function testReadNeedsMembership(): void {
		$policy = new AccessPolicy();
		self::assertTrue($policy->canRead(self::roles(2, 3)));
		self::assertFalse($policy->canRead(new Ctx('abcd1234', false, Ctx::ROLE_SOURCE_NONE)));
	}
}
