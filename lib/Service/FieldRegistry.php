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

	/** @var array<string, string> field => feature flag */
	private const FIELDS = [
		'chat.backgroundColor' => 'appearance.colors',
		'chat.accentColor' => 'appearance.colors',
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
		return $features;
	}

	public function isKnown(string $field): bool {
		return isset(self::FIELDS[$field]) && \in_array(self::FIELDS[$field], $this->features(), true);
	}

	/**
	 * Returns the normalized value, or throws when it is not acceptable.
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
			if (\strlen($value) > self::MAX_EMOJI_BYTES || !$this->emojiHelper->isValidSingleEmoji($value)) {
				throw new \InvalidArgumentException($field);
			}
			return $value;
		}
		if (!preg_match(self::COLOR_PATTERN, $value)) {
			throw new \InvalidArgumentException($field);
		}
		return strtoupper($value);
	}
}
