<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 NKS Hub
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OwnTalkExtensions\Command;

use OCA\OwnTalkExtensions\AppInfo\Application;
use OCA\OwnTalkExtensions\Db\RoomSettingsRepository;
use OCA\OwnTalkExtensions\Service\BackgroundImageService;
use OCA\OwnTalkExtensions\Service\OrphanCleanup;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Nextcloud runs uninstall repair steps on every app:disable, so deleting all data is an
 * explicit admin action here instead of an uninstall step.
 */
final class Cleanup extends Command {
	public function __construct(
		private OrphanCleanup $orphans,
		private RoomSettingsRepository $repository,
		private IAppConfig $appConfig,
		private BackgroundImageService $images,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('owntalk-extensions:cleanup')
			->setDescription('Delete settings of deleted conversations and unused images, or with --all everything stored')
			->addOption('all', null, InputOption::VALUE_NONE, 'Delete all stored settings, images and app configuration');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('all')) {
			$rows = $this->repository->deleteAll();
			$this->images->deleteAll();
			$this->appConfig->deleteApp(Application::APP_ID);
			$output->writeln("Deleted $rows conversation settings, all background images and the app configuration.");
			return self::SUCCESS;
		}
		$deleted = $this->orphans->run();
		if ($deleted === null) {
			$output->writeln('<error>Talk is not available; nothing was checked.</error>');
			return self::FAILURE;
		}
		$images = $this->orphans->deleteUnusedImages();
		$writers = $this->orphans->forgetDeletedWriters();
		$output->writeln("Deleted settings of $deleted removed conversations and $images unused background images; forgot $writers deleted writers.");
		return self::SUCCESS;
	}
}
