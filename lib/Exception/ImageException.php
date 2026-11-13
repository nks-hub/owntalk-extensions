<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Exception;

use OCP\AppFramework\Http;

final class ImageException extends \Exception {
	public const INVALID = 'invalid_image';
	public const TOO_LARGE = 'payload_too_large';
	public const UNSUPPORTED = 'unsupported_media_type';
	public const QUOTA = 'quota_exceeded';

	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct($reason);
	}

	public function httpStatus(): int {
		return match ($this->reason) {
			self::TOO_LARGE => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			self::UNSUPPORTED => Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
			self::QUOTA => Http::STATUS_INSUFFICIENT_STORAGE,
			default => Http::STATUS_BAD_REQUEST,
		};
	}
}
