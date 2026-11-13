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
	private const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/';
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
	 * IEmojiHelper::isValidSingleEmoji() also accepts letters, bidi overrides,
	 * zero-width characters and unassigned code points, so every code point is checked
	 * against the categories real emoji sequences are made of.
	 */
	private static function isPlainEmoji(string $value): bool {
		if (!preg_match('//u', $value)) {
			return false;
		}
		$pictographic = false;
		foreach (mb_str_split($value) as $char) {
			$cp = mb_ord($char, 'UTF-8');
			if ($cp === 0x200D || $cp === 0xFE0F || ($cp >= 0xE0020 && $cp <= 0xE007F)) {
				continue;
			}
			// Keycap mark and the emoji that Unicode files under letters or punctuation.
			if (\in_array($cp, [0x20E3, 0x2139, 0x24C2, 0x203C, 0x2049], true)) {
				$pictographic = true;
				continue;
			}
			$type = \IntlChar::charType($cp);
			if ($type === \IntlChar::CHAR_CATEGORY_OTHER_SYMBOL || $type === \IntlChar::CHAR_CATEGORY_MATH_SYMBOL) {
				$pictographic = true;
				continue;
			}
			// Skin tone modifiers and keycap bases (#, *, digits); a keycap needs U+20E3 to count.
			if ($type === \IntlChar::CHAR_CATEGORY_MODIFIER_SYMBOL
				|| $type === \IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER
				|| $type === \IntlChar::CHAR_CATEGORY_OTHER_PUNCTUATION) {
				continue;
			}
			return false;
		}
		return $pictographic;
	}
}
