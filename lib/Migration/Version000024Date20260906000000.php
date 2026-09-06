<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Migration;

use Closure;
use OCA\Zeitwerk\Service\HolidayService;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.18.1: Feiertage nachtragen, die 0.18.0 fuer Deutschland neu definiert hat
 * (Buss- und Bettag SN, Frauentag BE/MV, Weltkindertag TH), in Auto-Sets, die
 * vor dem Update erzeugt wurden. Die Ensure-Logik generiert nur, wenn fuer Jahr
 * und Region noch gar keine Auto-Zeilen existieren; ohne diesen Schritt fehlten
 * die Tage bis zum manuellen «Feiertage neu erstellen», und Sollstunden wie
 * Urlaubsrechnung waren an diesen Tagen falsch. Nur ergaenzend, idempotent,
 * keine Schemaaenderung; beschraenkt auf genau diese drei Namen, damit bewusst
 * geloeschte andere Auto-Feiertage geloescht bleiben.
 *
 * Abweichend von V22/V23 wird hier bewusst der HolidayService injiziert statt
 * die Logik mit IDBConnection nachzubauen: die Provider-Regeln und die
 * Unique-Behandlung in createHoliday() sollen nicht dupliziert werden, die
 * Methode ist getestet, bei einer Neuinstallation (keine Auto-Zeilen) ein
 * No-op, und alle Abhaengigkeiten sind ueber den App-Container autowirebar
 * (auf NC 34 beim occ upgrade verifiziert).
 *
 * Die IOutput-Meldung landet beim App-Upgrade in nextcloud.log (Nextcloud
 * nutzt dort den logbasierten Output), in der Konsole nur bei
 * `occ migrations:execute`.
 */
class Version000024Date20260906000000 extends SimpleMigrationStep {

    /** Feiertage, die der Deutschland-Provider seit 0.18.0 zusaetzlich liefert. */
    private const ADDED_IN_0_18_0 = ['Buß- und Bettag', 'Internationaler Frauentag', 'Weltkindertag'];

    public function __construct(
        private HolidayService $holidayService,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $added = $this->holidayService->fillMissingAutoHolidays(self::ADDED_IN_0_18_0);
        $total = array_sum($added);
        $details = [];
        foreach ($added as $combo => $count) {
            $details[] = "$combo: $count";
        }
        $output->info(
            "Missing provider holidays added to existing auto-generated sets: $total"
            . ($details === [] ? '' : ' (' . implode(', ', $details) . ')')
        );
    }
}
