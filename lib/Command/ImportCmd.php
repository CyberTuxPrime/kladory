<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Command;

use OCA\Kladory\Service\ExportService;
use OCA\Kladory\Service\ValidationException;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ kladory:import <benutzer> <datei>
 * Importiert eine .kladory-Datei für den Benutzer. Alles wird neu angelegt, nichts überschrieben.
 */
class ImportCmd extends Command {
	public function __construct(
		private ExportService $export,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('kladory:import')
			->setDescription('Eine .kladory-Datei für einen Benutzer importieren')
			->addArgument('user', InputArgument::REQUIRED, 'Benutzername')
			->addArgument('file', InputArgument::REQUIRED, 'Pfad zur .kladory-Datei im Container');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getArgument('user');
		$file = (string)$input->getArgument('file');
		if (!$this->userManager->userExists($user)) {
			$output->writeln('<error>Benutzer nicht gefunden: ' . $user . '</error>');
			return 1;
		}
		if (!is_file($file)) {
			$output->writeln('<error>Datei nicht gefunden: ' . $file . '</error>');
			return 1;
		}
		try {
			$r = $this->export->import($user, $file);
		} catch (ValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Importiert für ' . $user . ': ' . $r['notebooks'] . ' Notizbücher, ' . $r['sections'] . ' Abschnitte, '
			. $r['pages'] . ' Seiten, ' . $r['images'] . ' Bilder.');
		foreach ($r['warnings'] as $w) {
			$output->writeln('<comment>Hinweis: ' . $w . '</comment>');
		}
		return 0;
	}
}
