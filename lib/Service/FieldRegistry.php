<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Service;

use OCP\IEmojiHelper;

/**
 * Allowlist of shared settings fields and their validation.
 *
 * Adding a field means adding it here with its feature flag; storage and API
 * stay unchanged because values live in one JSON document per conversation.
 */
class FieldRegistry {
	private const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/D';
	// ICU UProperty numbers; PHP's IntlChar has no named constants for them.
	private const UCHAR_EMOJI = 57;
	private const UCHAR_EMOJI_COMPONENT = 61;
	private const UCHAR_REGIONAL_INDICATOR = 62;
	private const UCHAR_EXTENDED_PICTOGRAPHIC = 64;
	private const MAX_EMOJI_BYTES = 32;
	/** IDs of backgrounds the client draws itself. */
	public const PRESETS = ['aurora', 'sunset', 'forest', 'ocean', 'dots', 'grid'];

	/** @var array<string, string> field => feature flag */
	private const FIELDS = [
		'chat.backgroundColor' => 'appearance.colors',
		'chat.accentColor' => 'appearance.colors',
		'chat.backgroundPreset' => 'appearance.presets',
		'chat.backgroundImage' => 'appearance.image',
		'bubble.ownColor' => 'appearance.bubbles',
		'bubble.ownTextColor' => 'appearance.bubbles',
		'bubble.otherColor' => 'appearance.bubbles',
		'bubble.otherTextColor' => 'appearance.bubbles',
		'composer.quickEmoji' => 'composer.quickEmoji',
	];

	public function __construct(
		private IEmojiHelper $emojiHelper,
	) {
	}

	/** @return list<string> */
	public function fields(): array {
		return array_keys(self::FIELDS);
	}

	/** @return list<string> */
	public function features(): array {
		$features = array_values(array_unique(self::FIELDS));
		if (!$this->emojiHelper->doesPlatformSupportEmoji()) {
			$features = array_values(array_diff($features, ['composer.quickEmoji']));
		}
		if (!BackgroundImageService::isSupported()) {
			$features = array_values(array_diff($features, ['appearance.image']));
		}
		return $features;
	}

	public function isKnown(string $field): bool {
		return isset(self::FIELDS[$field]) && \in_array(self::FIELDS[$field], $this->features(), true);
	}

	/**
	 * Returns the normalized value, or throws when it is not acceptable. The background image
	 * is set only by an upload, so through this path it can only be cleared.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function normalize(string $field, mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value)) {
			throw new \InvalidArgumentException($field);
		}
		if ($field === 'composer.quickEmoji') {
			if (\strlen($value) > self::MAX_EMOJI_BYTES || !self::isPlainEmoji($value)
				|| !$this->emojiHelper->isValidSingleEmoji($value)) {
				throw new \InvalidArgumentException($field);
			}
			return $value;
		}
		if ($field === 'chat.backgroundPreset') {
			if (!\in_array($value, self::PRESETS, true)) {
				throw new \InvalidArgumentException($field);
			}
			return $value;
		}
		if ($field === 'chat.backgroundImage') {
			throw new \InvalidArgumentException($field);
		}
		if (!preg_match(self::COLOR_PATTERN, $value)) {
			throw new \InvalidArgumentException($field);
		}
		return strtoupper($value);
	}

	/**
	 * IEmojiHelper::isValidSingleEmoji() also accepts letters, symbols such as U+221E, bidi
	 * overrides and zero-width characters, so every code point must carry the Unicode Emoji
	 * or Emoji_Component property and at least one must be pictographic, a regional
	 * indicator or the keycap mark (a bare digit is an Emoji_Component, not an emoji).
	 */
	private static function isPlainEmoji(string $value): bool {
		if (!preg_match('//u', $value)) {
			return false;
		}
		$pictographic = false;
		foreach (mb_str_split($value) as $char) {
			$cp = mb_ord($char, 'UTF-8');
			if (!\IntlChar::hasBinaryProperty($cp, self::UCHAR_EMOJI)
				&& !\IntlChar::hasBinaryProperty($cp, self::UCHAR_EMOJI_COMPONENT)) {
				return false;
			}
			if ($cp === 0x20E3
				|| \IntlChar::hasBinaryProperty($cp, self::UCHAR_EXTENDED_PICTOGRAPHIC)
				|| \IntlChar::hasBinaryProperty($cp, self::UCHAR_REGIONAL_INDICATOR)) {
				$pictographic = true;
			}
		}
		return $pictographic;
	}
}
