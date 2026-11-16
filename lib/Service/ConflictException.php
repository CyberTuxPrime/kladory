<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

use OCA\Kladory\Db\Page;

/** Die Seite wurde seit dem Laden von anderer Stelle geändert. */
class ConflictException extends \Exception {
	public function __construct(
		private Page $page,
	) {
		parent::__construct('Die Seite wurde inzwischen an anderer Stelle geändert.');
	}

	public function getPage(): Page {
		return $this->page;
	}
}
