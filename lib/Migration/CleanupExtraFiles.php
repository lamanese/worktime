<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Migration;

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
			$appPath = $this->appManager->getAppPath('zeitwerk');
		} catch (AppPathNotFoundException) {
			return;
		}

		$filesToRemove = [
			$appPath . '/appinfo/zeitwerk.crt',
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

		$this->removeStaleJsBundles($appPath, $output);
	}

	/**
	 * Webpack emits hashed bundle filenames (e.g. zeitwerk-<hash>.js).
	 * NC's update path copies new bundles in but does not delete the
	 * previous version's hash, so each upgrade leaves stale .js / .js.map
	 * / .js.LICENSE.txt files behind, which the integrity check flags
	 * as EXTRA_FILE. Keep only the bundles that match the current
	 * signature.json, drop everything else under js/ that follows the
	 * zeitwerk-<hash> pattern.
	 */
	private function removeStaleJsBundles(string $appPath, IOutput $output): void {
		$jsDir = $appPath . '/js';
		if (!is_dir($jsDir)) {
			return;
		}

		$signaturePath = $appPath . '/appinfo/signature.json';
		if (!is_file($signaturePath)) {
			return;
		}

		$raw = file_get_contents($signaturePath);
		$signature = json_decode($raw, true);
		$signedFiles = $signature['hashes'] ?? null;
		if (!is_array($signedFiles)) {
			return;
		}

		$keep = [];
		foreach (array_keys($signedFiles) as $rel) {
			if (str_starts_with($rel, 'js/')) {
				$keep[basename($rel)] = true;
			}
		}

		$pattern = '/^zeitwerk-[a-f0-9]{20}\.js(\.map|\.LICENSE\.txt)?$/';
		foreach (scandir($jsDir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (!preg_match($pattern, $entry)) {
				continue;
			}
			if (isset($keep[$entry])) {
				continue;
			}
			$path = $jsDir . '/' . $entry;
			if (is_file($path) && @unlink($path)) {
				$output->info('Removed stale JS bundle: ' . $entry);
			}
		}
	}
}
