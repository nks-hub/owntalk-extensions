<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Exception;

use OCP\AppFramework\Http;

final class AccessException extends \Exception {
	public const INVALID_TOKEN = 'invalid_token';
	public const NOT_FOUND = 'not_found';
	public const SOURCE_MISMATCH = 'source_mismatch';
	public const TALK_UNAVAILABLE = 'talk_unavailable';
	public const TALK_INCOMPATIBLE = 'talk_incompatible';

	public function __construct(public readonly string $reason) {
		parent::__construct($reason);
	}

	public function httpStatus(): int {
		return match ($this->reason) {
			self::INVALID_TOKEN => Http::STATUS_BAD_REQUEST,
			self::NOT_FOUND => Http::STATUS_NOT_FOUND,
			self::SOURCE_MISMATCH => Http::STATUS_FORBIDDEN,
			default => Http::STATUS_SERVICE_UNAVAILABLE,
		};
	}
}
