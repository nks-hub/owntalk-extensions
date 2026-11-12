<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Exception;

final class ConflictException extends \Exception {
	/**
	 * @param array<string, mixed> $current
	 * @param list<string> $fields
	 */
	public function __construct(
		public readonly array $current,
		public readonly array $fields,
	) {
		parent::__construct('revision_conflict');
	}
}
