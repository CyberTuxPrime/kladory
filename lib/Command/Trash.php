<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Command;

use OCA\Kladory\Service\NotFoundException;
use OCA\Kladory\Service\TrashService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ kladory:trash list    <benutzer>
 * occ kladory:trash restore <benutzer> <typ> <id>
 * occ kladory:trash delete  <benutzer> <typ> <id>
 * occ kladory:trash purge                      (abgelaufene Einträge aller Nutzer jetzt löschen)
 */
class Trash extends Command {
	public function __construct(
		private TrashService $trash,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('kladory:trash')
			->setDescription('Kladory-Papierkorb eines Benutzers anzeigen, wiederherstellen oder leeren')
			->addArgument('action', InputArgument::REQUIRED, 'list | restore | delete | purge')
			->addArgument('user', InputArgument::OPTIONAL, 'Benutzer-ID (Anmeldename)')
			->addArgument('type', InputArgument::OPTIONAL, 'notebook | section | page')
			->addArgument('id', InputArgument::OPTIONAL, 'ID des Eintrags (siehe list)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');

		if ($action === 'purge') {
			$n = $this->trash->purgeExpired();
			$output->writeln($n . ' abgelaufene Einträge endgültig gelöscht (Aufbewahrung: ' . $this->trash->getRetentionDays() . ' Tage).');
			return 0;
		}

		$user = (string)$input->getArgument('user');
		if ($user === '' || !$this->userManager->userExists($user)) {
			$output->writeln('<error>Benutzer nicht gefunden: ' . $user . '</error>');
			return 1;
		}

		if ($action === 'list') {
			$data = $this->trash->list($user);
			if ($data['items'] === []) {
				$output->writeln('Der Papierkorb von ' . $user . ' ist leer.');
				return 0;
			}
			$labels = ['notebook' => 'Notizbuch', 'section' => 'Abschnitt', 'page' => 'Seite'];
			$table = new Table($output);
			$table->setHeaders(['Typ', 'ID', 'Name', 'Ort', 'Gelöscht am', 'Endgültig weg am']);
			foreach ($data['items'] as $item) {
				$table->addRow([
					$item['type'] . ' (' . $labels[$item['type']] . ')',
					$item['id'],
					$item['name'] !== '' ? $item['name'] : '(ohne Titel)',
					$item['path'],
					date('d.m.Y H:i', $item['deletedAt']),
					$item['purgeAt'] ? date('d.m.Y', $item['purgeAt']) : 'nie',
				]);
			}
			$table->render();
			$output->writeln('Wiederherstellen: occ kladory:trash restore ' . $user . ' <typ> <id>');
			return 0;
		}

		if ($action === 'restore' || $action === 'delete') {
			$type = (string)$input->getArgument('type');
			$id = (int)$input->getArgument('id');
			if (!in_array($type, TrashService::TYPES, true) || $id <= 0) {
				$output->writeln('<error>Bitte Typ (notebook|section|page) und ID angeben.</error>');
				return 1;
			}
			try {
				if ($action === 'restore') {
					$this->trash->restore($user, $type, $id);
					$output->writeln('Wiederhergestellt: ' . $type . ' ' . $id);
				} else {
					$this->trash->deleteForever($user, $type, $id);
					$output->writeln('Endgültig gelöscht: ' . $type . ' ' . $id);
				}
			} catch (NotFoundException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			return 0;
		}

		$output->writeln('<error>Unbekannte Aktion. Erlaubt: list, restore, delete, purge</error>');
		return 1;
	}
}
