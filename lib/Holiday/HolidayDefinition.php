<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Holiday;

use DateTimeImmutable;

/**
 * Ein von einem Provider berechneter Feiertag, noch ohne Datenbankbezug.
 * scope 1.0 = ganzer Tag, 0.5 = halber Tag (Solothurn 1. Mai).
 */
final class HolidayDefinition {

    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly string $name,
        public readonly float $scope = 1.0,
    ) {
    }
}
