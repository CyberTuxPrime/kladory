<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Listener;

use OCA\Kladory\Service\TrashService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Gelöschte Nextcloud-Gruppe aus allen Kursnotizbüchern entfernen.
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener {
	public function __construct(
		private TrashService $trash,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof GroupDeletedEvent)) {
			return;
		}
		$this->trash->removeGroup($event->getGroup()->getGID());
	}
}
