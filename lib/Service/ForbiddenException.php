<?php

/**
 * SPDX-FileCopyrightText: 2026 Kladory contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Kladory\Service;

/** Eintrag ist sichtbar, aber die Aktion ist nicht erlaubt (z. B. Schüler in der Inhaltsbibliothek). */
class ForbiddenException extends \Exception {
}
