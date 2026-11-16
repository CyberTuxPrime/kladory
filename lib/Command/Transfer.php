<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Command;

use OCA\Kladory\Service\TransferService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ kladory:transfer <alter-benutzer> <neuer-benutzer> [--dry-run]
 *
 * Überträgt alle Kladory-Daten auf einen anderen Benutzernamen, z. B. nach einer
 * Umbenennung im LDAP. Der alte Benutzer muss dafür nicht mehr existieren.
 * WICHTIG: vor dem Löschen des alten Kontos ausführen – beim Löschen entfernt Kladory dessen Daten.
 */
class Transfer extends Command {
	public function __construct(
		private TransferService $transfer,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('kladory:transfer')
			->setDescription('Alle Kladory-Daten von einem Benutzernamen auf einen anderen übertragen (z. B. nach LDAP-Umbenennung)')
			->addArgument('from', InputArgument::REQUIRED, 'alter Benutzername (muss nicht mehr existieren)')
			->addArgument('to', InputArgument::REQUIRED, 'neuer Benutzername (muss existieren)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'nur anzeigen, was übertragen würde');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$from = (string)$input->getArgument('from');
		$to = (string)$input->getArgument('to');
		if (!$this->userManager->userExists($to)) {
			$output->writeln('<error>Der neue Benutzer „' . $to . '“ existiert nicht. Er muss sich ggf. erst einmal anmelden.</error>');
			return 1;
		}
		$dry = (bool)$input->getOption('dry-run');
		$counts = $this->transfer->transfer($from, $to, $dry);
		$output->writeln(($dry ? 'Würde übertragen' : 'Übertragen') . ' von „' . $from . '“ auf „' . $to . '“:');
		$output->writeln('  Notizbücher (persönlich und Kursnotizbücher als Besitzer): ' . $counts['notebooks']);
		$output->writeln('  Schülerabschnitte in Kursnotizbüchern:                     ' . $counts['studentSections']);
		$output->writeln('  Mitgliedschaften in Kursnotizbüchern:                      ' . $counts['memberships']);
		$output->writeln('  Seiten mit „zuletzt bearbeitet von“:                       ' . $counts['pagesEditedBy']);
		if ($dry) {
			$output->writeln('Nichts geändert (--dry-run).');
		} elseif ($this->userManager->userExists($from)) {
			$output->writeln('<comment>Hinweis: Der alte Benutzer existiert noch. Er hat jetzt keine Kladory-Daten mehr.</comment>');
		}
		return 0;
	}
}
