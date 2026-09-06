<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Holiday\Provider;

use OCA\Zeitwerk\Holiday\HolidayDefinition;

/**
 * Liefert die automatisch zu erzeugenden Feiertage eines Landes.
 * Sondertage aus Firmeneinstellungen (24.12./31.12.) gehoeren nicht dazu,
 * die haengt HolidayService selbst an.
 */
interface HolidayProviderInterface {

    /** ISO-3166-1-Alpha-2, z. B. 'DE' oder 'CH'. */
    public function country(): string;

    /**
     * @param string $region voller Regionscode, z. B. 'DE-BY' oder 'CH-ZH'
     * @return HolidayDefinition[] nach Datum aufsteigend sortiert
     */
    public function holidaysFor(int $year, string $region): array;
}
