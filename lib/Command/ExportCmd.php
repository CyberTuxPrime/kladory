<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Command;

use OCA\Kladory\Service\ExportService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ kladory:export <benutzer> [<zieldatei>] [--to-files]
 * Exportiert alle Notizbücher eines Benutzers (persönliche, Kursnotizbücher als Besitzer/Lehrkraft,
 * eigene Schülerbereiche) als .kladory-Datei.
 */
class ExportCmd extends Command {
	public function __construct(
		private ExportService $export,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('kladory:export')
			->setDescription('Alle Notizbücher eines Benutzers als .kladory-Datei exportieren')
			->addArgument('user', InputArgument::REQUIRED, 'Benutzername')
			->addArgument('file', InputArgument::OPTIONAL, 'Zieldatei im Container, z. B. /tmp/export.kladory')
			->addOption('to-files', null, InputOption::VALUE_NONE, 'zusätzlich im Ordner „Kladory-Export“ der Nextcloud-Dateien des Benutzers ablegen');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getArgument('user');
		if (!$this->userManager->userExists($user)) {
			$output->writeln('<error>Benutzer nicht gefunden: ' . $user . '</error>');
			return 1;
		}
		$file = $input->getArgument('file');
		if (!$file && !$input->getOption('to-files')) {
			$output->writeln('<error>Bitte eine Zieldatei angeben oder --to-files verwenden.</error>');
			return 1;
		}
		$r = $this->export->export($user);
		try {
			if ($file) {
				if (!copy($r['path'], (string)$file)) {
					$output->writeln('<error>Konnte nicht nach ' . $file . ' schreiben.</error>');
					return 1;
				}
				$output->writeln('Gespeichert: ' . $file);
			}
			if ($input->getOption('to-files')) {
				$output->writeln('In Nextcloud-Dateien von ' . $user . ': ' . $this->export->saveToFiles($user, $r['path'], $r['filename']));
			}
		} finally {
			@unlink($r['path']);
		}
		$output->writeln($r['notebooks'] . ' Notizbücher, ' . $r['pages'] . ' Seiten, ' . $r['images'] . ' Bilder exportiert.');
		return 0;
	}
}
