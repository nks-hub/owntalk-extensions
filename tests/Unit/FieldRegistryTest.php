<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Tests\Unit;

use OCA\OwnTalkExtensions\Service\FieldRegistry;
use OCP\IEmojiHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldRegistryTest extends TestCase {
	private function registry(bool $emojiSupport = true): FieldRegistry {
		$emoji = $this->createMock(IEmojiHelper::class);
		$emoji->method('doesPlatformSupportEmoji')->willReturn($emojiSupport);
		$emoji->method('isValidSingleEmoji')->willReturnCallback(static fn (string $v): bool => \in_array($v, ['👍', '❤️'], true));
		return new FieldRegistry($emoji);
	}

	public static function invalidColors(): array {
		return [['#fff'], ['red'], ['#12345G'], ['#1234567'], ['red;background:url(x)'], [' #123456'], [123], [['#123456']]];
	}

	#[DataProvider('invalidColors')]
	public function testRejectsInvalidColors(mixed $value): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->registry()->normalize('chat.backgroundColor', $value);
	}

	public function testNormalizesColorToUpperCase(): void {
		self::assertSame('#ABCDEF', $this->registry()->normalize('bubble.ownColor', '#abcdef'));
		self::assertNull($this->registry()->normalize('bubble.ownColor', null));
	}

	public function testEmojiValidation(): void {
		self::assertSame('❤️', $this->registry()->normalize('composer.quickEmoji', '❤️'));
		$this->expectException(\InvalidArgumentException::class);
		$this->registry()->normalize('composer.quickEmoji', '<b>');
	}

	public function testUnknownAndUnsupportedFields(): void {
		self::assertFalse($this->registry()->isKnown('chat.font'));
		self::assertTrue($this->registry()->isKnown('composer.quickEmoji'));
		self::assertFalse($this->registry(false)->isKnown('composer.quickEmoji'));
		self::assertNotContains('composer.quickEmoji', $this->registry(false)->features());
	}
}
