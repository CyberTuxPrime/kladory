<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\BackgroundJob;

use OCA\Kladory\Service\TrashService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Leert einmal täglich alle Papierkorb-Einträge, die älter als die Aufbewahrungsdauer sind,
 * und entfernt Bilder, die auf keiner Seite mehr vorkommen.
 */
class PurgeTrash extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private TrashService $trash,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$count = $this->trash->purgeExpired();
		if ($count > 0) {
			$this->logger->info('Kladory: ' . $count . ' Papierkorb-Einträge endgültig gelöscht.', ['app' => 'kladory']);
		}
		$images = $this->trash->cleanupImages();
		if ($images > 0) {
			$this->logger->info('Kladory: ' . $images . ' nicht mehr verwendete Bilder entfernt.', ['app' => 'kladory']);
		}
	}
}
