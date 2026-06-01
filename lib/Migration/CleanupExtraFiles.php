<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Migration;

use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Remove extra files that were accidentally included in earlier releases
 * and cause integrity check failures.
 */
class CleanupExtraFiles implements IRepairStep {

	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function getName(): string {
		return 'Remove extra files from earlier releases';
	}

	public function run(IOutput $output): void {
		try {
			$appPath = $this->appManager->getAppPath('worktime');
		} catch (AppPathNotFoundException) {
			return;
		}

		$filesToRemove = [
			$appPath . '/appinfo/worktime.crt',
			$appPath . '/test-results/.last-run.json',
		];

		$dirsToRemove = [
			$appPath . '/test-results',
		];

		foreach ($filesToRemove as $file) {
			if (file_exists($file)) {
				@unlink($file);
				$output->info('Removed: ' . basename($file));
			}
		}

		foreach ($dirsToRemove as $dir) {
			if (is_dir($dir)) {
				@rmdir($dir);
				$output->info('Removed directory: ' . basename($dir));
			}
		}
	}
}
