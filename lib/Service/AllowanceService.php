<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateTime;
use OCA\Zeitwerk\Db\Absence;
use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Db\DailyKmMapper;
use OCA\Zeitwerk\Db\ProjectMapper;
use OCA\Zeitwerk\Db\TimeEntry;

/**
 * Berechnet die Aussendienst-Spesen und die Extern-Kilometer-Vergütung eines
 * Mitarbeiters über einen Zeitraum.
 *
 * Regeln (alle Schwellen/Beträge über CompanySettings konfigurierbar):
 * - Spesen: Pro Tag wird ausschliesslich die Zeit auf Aussendienst-Projekten
 *   summiert (brutto inkl. Pause oder netto, konfigurierbar) und gegen die
 *   Stundenschwelle geprüft (Operator >= oder >). Buchungen ohne Aussendienst-
 *   Flag zählen NICHT mit. Erfüllt die Aussendienst-Zeit des Tages die Schwelle,
 *   gibt es die Pauschale einmal für diesen Tag.
 * - Optional (Schalter): Tage mit einem als "extern" markierten Abwesenheitstyp
 *   zählen ebenfalls als Spesen-Tag (pauschal, ohne Stundenprüfung).
 * - Kilometer: Summe der tageweise erfassten km × Satz.
 */
class AllowanceService {

    /** @var int[]|null Cache: Projekt-IDs mit Aussendienst-Flag */
    private ?array $fieldWorkProjectIds = null;

    /** @var int[]|null Cache: Projekt-IDs mit Extern-Flag */
    private ?array $externProjectIds = null;

    public function __construct(
        private CompanySettingsService $settings,
        private DailyKmMapper $dailyKmMapper,
        private ProjectMapper $projectMapper,
    ) {
    }

    /**
     * Zusammenfassung für einen Kalendermonat.
     *
     * @param TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @return array<string, int|float>
     */
    public function getMonthlySummary(int $employeeId, int $year, int $month, array $timeEntries, array $absences): array {
        $start = new DateTime("$year-$month-01");
        $end = (clone $start)->modify('last day of this month');

        return $this->getRangeSummary($employeeId, $start, $end, $timeEntries, $absences);
    }

    /**
     * Zusammenfassung für einen beliebigen inklusiven Zeitraum.
     *
     * @param TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @return array<string, int|float>
     */
    public function getRangeSummary(int $employeeId, DateTime $start, DateTime $end, array $timeEntries, array $absences): array {
        $kmRecords = $this->dailyKmMapper->findByEmployeeAndDateRange($employeeId, $start, $end);

        return $this->calculate($timeEntries, $absences, $kmRecords, $start, $end);
    }

    /**
     * Reine Berechnung — ohne DB-Zugriff auf Zeiteinträge/Abwesenheiten, damit
     * gut testbar. Die Projekt-Flags und die Konfiguration werden intern
     * aufgelöst; die tageweisen Kilometer-Datensätze werden übergeben.
     *
     * Kilometer werden nur für Tage vergütet, die zum Berechnungszeitpunkt noch
     * extern sind (Buchung auf Extern-Projekt oder nicht stornierter/abgelehnter
     * externer Abwesenheitstyp). So zählt eine später weggefallene oder abgelehnte
     * Basis keine gespeicherten Kilometer mehr.
     *
     * @param TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @param \OCA\Zeitwerk\Db\DailyKm[] $kmRecords
     * @return array<string, int|float>
     */
    public function calculate(array $timeEntries, array $absences, array $kmRecords, DateTime $rangeStart, DateTime $rangeEnd): array {
        $fieldWorkIds = $this->getFieldWorkProjectIds();
        $thresholdMinutes = $this->settings->getFieldworkAllowanceThresholdHours() * 60;
        $operator = $this->settings->getFieldworkAllowanceOperator();
        $useGross = $this->settings->getFieldworkAllowanceBasis() === CompanySetting::BASIS_GROSS;

        // Tage, die einen Spesen-Anspruch auslösen (dedupliziert über das Datum).
        $allowanceDates = [];

        // 1. Aussendienst-Arbeitstage
        $entriesByDate = [];
        foreach ($timeEntries as $entry) {
            $date = $entry->getDate();
            if (!$date) {
                continue;
            }
            $entriesByDate[$date->format('Y-m-d')][] = $entry;
        }

        foreach ($entriesByDate as $date => $dayEntries) {
            // Nur die Zeit auf Aussendienst-Projekten zählt gegen die Schwelle;
            // Buchungen ohne das Flag werden ignoriert.
            $hasFieldWork = false;
            $fieldWorkMinutes = 0;
            foreach ($dayEntries as $entry) {
                if (!in_array((int)$entry->getProjectId(), $fieldWorkIds, true)) {
                    continue;
                }
                $hasFieldWork = true;
                $fieldWorkMinutes += $useGross ? $entry->getGrossMinutes() : $entry->getWorkMinutes();
            }
            if ($hasFieldWork && $this->meetsThreshold($fieldWorkMinutes, $thresholdMinutes, $operator)) {
                $allowanceDates[$date] = true;
            }
        }

        // 2. Optional: externe Abwesenheitstage (pauschal, ohne Stundenprüfung)
        if ($this->settings->isFieldworkAllowanceOnExternAbsence()) {
            foreach ($this->externAbsenceDates($absences, $rangeStart, $rangeEnd) as $date) {
                $allowanceDates[$date] = true;
            }
        }

        $allowanceDays = count($allowanceDates);
        $allowancePerDay = $this->settings->getFieldworkAllowanceAmount();
        $allowanceAmount = round($allowanceDays * $allowancePerDay, 2);

        // Kilometer nur an aktuell externen Tagen vergüten (keine veraltete Basis).
        $externDates = $this->externDates($timeEntries, $absences, $rangeStart, $rangeEnd);
        $kilometers = 0;
        $kilometersByDate = [];
        foreach ($kmRecords as $record) {
            $recordDate = $record->getWorkDate()?->format('Y-m-d');
            if ($recordDate !== null && isset($externDates[$recordDate])) {
                $kilometers += $record->getKilometers();
                $kilometersByDate[$recordDate] = ($kilometersByDate[$recordDate] ?? 0) + $record->getKilometers();
            }
        }
        ksort($kilometersByDate);

        $mileageRate = $this->settings->getMileageRate();
        $mileageAmount = round($kilometers * $mileageRate, 2);

        $allowanceDateList = array_keys($allowanceDates);
        sort($allowanceDateList);

        return [
            'allowanceDays' => $allowanceDays,
            'allowancePerDay' => $allowancePerDay,
            'allowanceAmount' => $allowanceAmount,
            'kilometers' => $kilometers,
            'mileageRate' => $mileageRate,
            'mileageAmount' => $mileageAmount,
            'total' => round($allowanceAmount + $mileageAmount, 2),
            // Tagesdetails für die Tagesliste im PDF: an welchen Tagen die
            // Pauschale ausgelöst wurde und wie viele km je Tag zählen.
            'allowanceDates' => $allowanceDateList,
            'kilometersByDate' => $kilometersByDate,
        ];
    }

    private function meetsThreshold(int $basisMinutes, float $thresholdMinutes, string $operator): bool {
        return $operator === CompanySetting::OPERATOR_GT
            ? $basisMinutes > $thresholdMinutes
            : $basisMinutes >= $thresholdMinutes;
    }

    /**
     * Wochentage (Mo–Fr) innerhalb genehmigter Abwesenheiten eines externen
     * Typs, geklammert auf den Zeitraum. Wochenenden werden ausgelassen;
     * Feiertage werden hier bewusst nicht ausgenommen (Randfall, Schalter ist
     * standardmässig aus).
     *
     * @param Absence[] $absences
     * @return string[] Y-m-d Datumswerte
     */
    private function externAbsenceDates(array $absences, DateTime $rangeStart, DateTime $rangeEnd): array {
        $externTypes = $this->settings->getExternAbsenceTypes();
        if (empty($externTypes)) {
            return [];
        }
        $externTypeSet = array_fill_keys($externTypes, true);

        $dates = [];
        foreach ($absences as $absence) {
            if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
                continue;
            }
            if (!isset($externTypeSet[$absence->getType()])) {
                continue;
            }
            $start = $absence->getStartDate() > $rangeStart ? clone $absence->getStartDate() : clone $rangeStart;
            $end = $absence->getEndDate() < $rangeEnd ? $absence->getEndDate() : $rangeEnd;

            for ($day = clone $start; $day <= $end; $day->modify('+1 day')) {
                $weekday = (int)$day->format('N');
                if ($weekday <= 5) {
                    $dates[] = $day->format('Y-m-d');
                }
            }
        }

        return $dates;
    }

    /**
     * Menge der Tage im Zeitraum, die als "extern" gelten (km-vergütungsfähig):
     * Buchung auf einem Extern-Projekt oder nicht stornierter/abgelehnter
     * externer Abwesenheitstyp.
     *
     * @param TimeEntry[] $timeEntries
     * @param Absence[] $absences
     * @return array<string, bool> Y-m-d => true
     */
    private function externDates(array $timeEntries, array $absences, DateTime $rangeStart, DateTime $rangeEnd): array {
        $externProjectIds = $this->getExternProjectIds();
        $dates = [];

        foreach ($timeEntries as $entry) {
            $date = $entry->getDate();
            if ($date && in_array((int)$entry->getProjectId(), $externProjectIds, true)) {
                $dates[$date->format('Y-m-d')] = true;
            }
        }

        $externTypes = $this->settings->getExternAbsenceTypes();
        if (!empty($externTypes)) {
            $externTypeSet = array_fill_keys($externTypes, true);
            foreach ($absences as $absence) {
                $status = $absence->getStatus();
                if ($status === Absence::STATUS_CANCELLED || $status === Absence::STATUS_REJECTED) {
                    continue;
                }
                if (!isset($externTypeSet[$absence->getType()])) {
                    continue;
                }
                $start = $absence->getStartDate() > $rangeStart ? clone $absence->getStartDate() : clone $rangeStart;
                $end = $absence->getEndDate() < $rangeEnd ? $absence->getEndDate() : $rangeEnd;
                for ($day = clone $start; $day <= $end; $day->modify('+1 day')) {
                    $dates[$day->format('Y-m-d')] = true;
                }
            }
        }

        return $dates;
    }

    /**
     * Projekt-IDs mit Aussendienst-Flag (einmal geladen und gecached).
     *
     * @return int[]
     */
    private function getFieldWorkProjectIds(): array {
        if ($this->fieldWorkProjectIds === null) {
            $this->fieldWorkProjectIds = [];
            foreach ($this->projectMapper->findAll() as $project) {
                if ((bool)$project->getIsFieldWork()) {
                    $this->fieldWorkProjectIds[] = $project->getId();
                }
            }
        }
        return $this->fieldWorkProjectIds;
    }

    /**
     * Projekt-IDs mit Extern-Flag (einmal geladen und gecached).
     *
     * @return int[]
     */
    private function getExternProjectIds(): array {
        if ($this->externProjectIds === null) {
            $this->externProjectIds = [];
            foreach ($this->projectMapper->findAll() as $project) {
                if ((bool)$project->getIsExtern()) {
                    $this->externProjectIds[] = $project->getId();
                }
            }
        }
        return $this->externProjectIds;
    }
}
