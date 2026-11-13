<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\BackgroundJob;

use OCA\OwnTalkExtensions\Service\OrphanCleanup;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

final class CleanupOrphans extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OrphanCleanup $cleanup,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->cleanup->run();
		$this->cleanup->deleteUnusedImages();
	}
}
