<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateTime;
use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Db\CompanySettingMapper;
use OCA\Zeitwerk\Db\Holiday;
use OCA\Zeitwerk\Db\HolidayMapper;
use OCA\Zeitwerk\Holiday\Provider\ProviderRegistry;
use OCA\Zeitwerk\Holiday\RegionRegistry;
use OCA\Zeitwerk\Holiday\Rules;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Feiertage je Region (DE-Bundesland, CH-Kanton). Die Regeln liegen in den
 * Providern unter OCA\Zeitwerk\Holiday\Provider, dieser Service kuemmert sich um
 * Persistenz, Lazy-Ensure (#438), Sondertage aus den Firmeneinstellungen und
 * manuelle Feiertage. Der Spaltenname federal_state ist historisch, er traegt
 * seit 0.18.0 den Regionscode (RegionRegistry).
 */
class HolidayService {

    /** @var array<string, true> memo of (year, region) combos already ensured this request */
    private array $ensuredYearStates = [];

    public function __construct(
        private HolidayMapper $holidayMapper,
        private CompanySettingMapper $settingsMapper,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
        private ProviderRegistry $providers,
    ) {
    }

    /**
     * #438: Public holidays are deterministic, but the database is only populated
     * when an admin manually generates a (year, region) combination. If a vacation
     * is booked over a holiday for a year/region that was never generated, the
     * holiday is missing from the range query and gets counted as a vacation day.
     * This ensures the relevant holidays exist on demand before any working-day
     * calculation reads them — safe because generation is purely a function of
     * year + region.
     *
     * Idempotent: only generates when nothing exists yet for the combo, and
     * memoises checked combos so a request does not re-query per calculation.
     */
    public function ensureHolidaysForYear(int $year, string $federalState, string $currentUserId = ''): void {
        $federalState = RegionRegistry::normalize($federalState);
        $key = $year . '|' . $federalState;
        if (isset($this->ensuredYearStates[$key])) {
            return;
        }
        // Mark first so a generation failure does not retry on every call.
        $this->ensuredYearStates[$key] = true;
        // Guard on AUTO holidays, not "any row": a single manually-added holiday
        // must not suppress generation of the deterministic set (#438 review).
        if (!$this->holidayMapper->hasAutoForYearAndState($year, $federalState)) {
            $this->generateHolidays($year, $federalState, $currentUserId);
        }
    }

    /**
     * #438: ensure holidays exist for every calendar year the range touches.
     */
    public function ensureHolidaysForRange(DateTime $startDate, DateTime $endDate, string $federalState, string $currentUserId = ''): void {
        $firstYear = (int)$startDate->format('Y');
        $lastYear = (int)$endDate->format('Y');
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $this->ensureHolidaysForYear($year, $federalState, $currentUserId);
        }
    }

    /**
     * @return Holiday[]
     */
    public function findByYearAndState(int $year, string $federalState): array {
        return $this->holidayMapper->findByYearAndState($year, RegionRegistry::normalize($federalState));
    }

    /**
     * @return Holiday[]
     */
    public function findByMonth(int $year, int $month, string $federalState): array {
        $federalState = RegionRegistry::normalize($federalState);
        $this->ensureHolidaysForYear($year, $federalState);
        return $this->holidayMapper->findByMonth($year, $month, $federalState);
    }

    /**
     * @throws NotFoundException
     */
    public function find(int $id): Holiday {
        try {
            return $this->holidayMapper->find($id);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException('Holiday not found');
        }
    }

    /**
     * Check if a specific date is a holiday
     */
    public function isHoliday(DateTime $date, string $federalState): bool {
        return $this->holidayMapper->isHoliday($date, RegionRegistry::normalize($federalState));
    }

    /**
     * Generate all automatic holidays for a year and region: the provider set of
     * the region's country plus the company-wide special days (24.12./31.12.).
     * Manual holidays are kept.
     *
     * @return Holiday[]
     */
    public function generateHolidays(int $year, string $federalState, string $currentUserId = ''): array {
        $federalState = RegionRegistry::normalize($federalState);

        $provider = $this->providers->forRegion($federalState);
        if ($provider === null) {
            $this->logger->warning('Zeitwerk: no holiday provider for region {region}, nothing generated', [
                'region' => $federalState,
                'year' => $year,
            ]);
            return [];
        }

        // Delete only auto-generated holidays for this year/region (keep manual ones)
        $this->holidayMapper->deleteAutoByYearAndState($year, $federalState);

        $holidays = [];
        foreach ($provider->holidaysFor($year, $federalState) as $definition) {
            $holidays[] = $this->createHoliday(
                $year,
                (int)$definition->date->format('n'),
                (int)$definition->date->format('j'),
                $definition->name,
                $federalState,
                $definition->scope
            );
        }

        // Add special half-day holidays (Christmas Eve, New Year's Eve)
        $holidays = array_merge($holidays, $this->generateSpecialDays($year, $federalState));

        // Audit log
        if ($currentUserId) {
            $this->auditLogService->logCreate($currentUserId, 'holiday', null, [
                'year' => $year,
                'federalState' => $federalState,
                'count' => count($holidays),
            ]);
        }

        return $holidays;
    }

    /**
     * 0.18.1: Nachtragen von Feiertagen, die ein Provider inzwischen definiert,
     * die aber in bereits erzeugten Auto-Sets fehlen (nach dem Update auf 0.18.0:
     * Buss- und Bettag SN, Frauentag BE/MV, Weltkindertag TH). Die Ensure-Logik
     * generiert nur, wenn fuer Jahr und Region noch gar keine Auto-Zeilen
     * existieren, deshalb blieben solche Sets bis zum manuellen
     * «Feiertage neu erstellen» unvollstaendig.
     *
     * Nur ergaenzend und idempotent: nichts wird geloescht, manuelle Eintraege
     * bleiben unberuehrt, ein bereits belegtes Datum (auto oder manuell) wird
     * uebersprungen, Regionen ohne Provider werden ausgelassen. Sondertage
     * (Heiligabend, Silvester) werden hier nicht behandelt.
     *
     * @return array<string, int> "<Jahr> <Region>" => Anzahl nachgetragener Tage (nur Eintraege > 0)
     */
    public function fillMissingAutoHolidays(): array {
        $added = [];
        foreach ($this->holidayMapper->findAutoYearStateCombos() as $combo) {
            $year = (int)$combo['year'];
            $region = (string)$combo['federal_state'];
            $provider = $this->providers->forRegion($region);
            if ($provider === null) {
                continue;
            }

            $taken = [];
            foreach ($this->holidayMapper->findByYearAndState($year, $region) as $existing) {
                $taken[$existing->getDate()->format('Y-m-d')] = true;
            }

            $count = 0;
            foreach ($provider->holidaysFor($year, $region) as $definition) {
                $date = $definition->date->format('Y-m-d');
                if (isset($taken[$date])) {
                    continue;
                }
                $this->createHoliday(
                    $year,
                    (int)$definition->date->format('n'),
                    (int)$definition->date->format('j'),
                    $definition->name,
                    $region,
                    $definition->scope
                );
                $taken[$date] = true;
                $count++;
            }
            if ($count > 0) {
                $added[$year . ' ' . $region] = $count;
            }
        }

        return $added;
    }

    /**
     * Generate special days (Christmas Eve, New Year's Eve) as half-day holidays
     * based on company settings. Company practice, not law, so they apply to
     * every region of every country.
     *
     * @return Holiday[]
     */
    private function generateSpecialDays(int $year, string $federalState): array {
        $holidays = [];

        // Christmas Eve (24.12.) - half day (scope = 0.5)
        $christmasEveHalfDay = $this->settingsMapper->getValueAsBool(CompanySetting::KEY_CHRISTMAS_EVE_HALF_DAY);
        if ($christmasEveHalfDay) {
            $holidays[] = $this->createHoliday($year, 12, 24, 'Heiligabend', $federalState, 0.5);
        }

        // New Year's Eve (31.12.) - half day (scope = 0.5)
        $newYearsEveHalfDay = $this->settingsMapper->getValueAsBool(CompanySetting::KEY_NEW_YEARS_EVE_HALF_DAY);
        if ($newYearsEveHalfDay) {
            $holidays[] = $this->createHoliday($year, 12, 31, 'Silvester', $federalState, 0.5);
        }

        return $holidays;
    }

    /**
     * Easter Sunday (Gauss algorithm, see Rules::easterSunday). Kept as DateTime
     * for the existing controller endpoint.
     */
    public function calculateEasterSunday(int $year): DateTime {
        return DateTime::createFromImmutable(Rules::easterSunday($year));
    }

    /**
     * Create and save a holiday
     *
     * @param float $scope 1.0 = full day, 0.5 = half day
     */
    private function createHoliday(int $year, int $month, int $day, string $name, string $federalState, float $scope = 1.0): Holiday {
        $holiday = new Holiday();
        $holiday->setDate(new DateTime("$year-$month-$day"));
        $holiday->setName($name);
        $holiday->setFederalState($federalState);
        $holiday->setScopeValue($scope);
        $holiday->setYear($year);
        $holiday->setCreatedAt(new DateTime());

        try {
            return $this->holidayMapper->insert($holiday);
        } catch (DbException $e) {
            // #438 review: the (date, federal_state) unique index can already be
            // taken by a manual holiday on the same date, or by a concurrent
            // first-time generation. Treat as already present instead of failing.
            if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                try {
                    return $this->holidayMapper->findByDateAndState($holiday->getDate(), $federalState);
                } catch (DoesNotExistException) {
                    // Conflicting row not yet visible to this transaction (e.g. an
                    // uncommitted concurrent insert). Surface the original error
                    // rather than an unrelated "not found".
                    throw $e;
                }
            }
            throw $e;
        }
    }

    /**
     * Count holidays in a date range for a region
     */
    public function countHolidaysInRange(DateTime $startDate, DateTime $endDate, string $federalState): int {
        return $this->holidayMapper->countHolidaysInRange($startDate, $endDate, RegionRegistry::normalize($federalState));
    }

    /**
     * Get holidays in a date range for a region
     *
     * @return Holiday[]
     */
    public function findHolidaysInRange(DateTime $startDate, DateTime $endDate, string $federalState): array {
        $federalState = RegionRegistry::normalize($federalState);
        $this->ensureHolidaysForRange($startDate, $endDate, $federalState);
        return $this->holidayMapper->findHolidaysInRange($startDate, $endDate, $federalState);
    }

    /**
     * Check if holidays exist for a year and region
     */
    public function existsForYearAndState(int $year, string $federalState): bool {
        return $this->holidayMapper->existsForYearAndState($year, RegionRegistry::normalize($federalState));
    }

    /**
     * All regions (DE-Bundeslaender und CH-Kantone), code => name
     *
     * @return array<string, string>
     */
    public function getFederalStates(): array {
        return RegionRegistry::flatLabels();
    }

    /**
     * Create a manual holiday for multiple regions
     *
     * @param string[] $federalStates region codes (legacy two-letter codes are read as German states)
     * @param float $scope 1.0 = full day, 0.5 = half day
     * @return Holiday[]
     * @throws ValidationException if a region code is unknown
     * @throws \Exception if holiday already exists for any region
     */
    public function createManual(string $date, string $name, array $federalStates, float $scope, string $currentUserId): array {
        $federalStates = array_values(array_unique(array_map(
            static fn(string $code): string => RegionRegistry::normalize($code),
            array_map('strval', $federalStates)
        )));
        $invalid = array_values(array_filter(
            $federalStates,
            static fn(string $code): bool => !RegionRegistry::isValid($code)
        ));
        if ($invalid !== []) {
            throw ValidationException::fromSingleError('federalStates', 'Unbekannte Region: ' . implode(', ', $invalid));
        }

        $dateObj = new DateTime($date);
        $year = (int)$dateObj->format('Y');
        $holidays = [];

        // Check for existing holidays first
        $existingStates = [];
        foreach ($federalStates as $federalState) {
            if ($this->holidayMapper->isHoliday($dateObj, $federalState)) {
                $existingStates[] = $federalState;
            }
        }

        if (!empty($existingStates)) {
            $stateNames = array_map(static fn(string $s): string => RegionRegistry::name($s), $existingStates);
            throw new \Exception(
                sprintf(
                    'Für das Datum %s existiert bereits ein Feiertag in: %s',
                    $dateObj->format('d.m.Y'),
                    implode(', ', $stateNames)
                )
            );
        }

        foreach ($federalStates as $federalState) {
            $holiday = new Holiday();
            $holiday->setDate($dateObj);
            $holiday->setName($name);
            $holiday->setFederalState($federalState);
            $holiday->setScopeValue($scope);
            $holiday->setYear($year);
            $holiday->setIsManual(true);
            $holiday->setCreatedAt(new DateTime());

            $holidays[] = $this->holidayMapper->insert($holiday);
        }

        $this->auditLogService->logCreate($currentUserId, 'holiday', null, [
            'date' => $date,
            'name' => $name,
            'federalStates' => $federalStates,
            'scope' => $scope,
            'isManual' => true,
        ]);

        return $holidays;
    }

    /**
     * Update an existing holiday
     *
     * @param float $scope 1.0 = full day, 0.5 = half day
     */
    public function update(int $id, string $date, string $name, float $scope, string $currentUserId): Holiday {
        $holiday = $this->holidayMapper->find($id);
        $oldData = $holiday->jsonSerialize();

        $dateObj = new DateTime($date);
        $holiday->setDate($dateObj);
        $holiday->setName($name);
        $holiday->setScopeValue($scope);
        $holiday->setYear((int)$dateObj->format('Y'));

        $updated = $this->holidayMapper->update($holiday);

        $this->auditLogService->logUpdate($currentUserId, 'holiday', $id, $oldData, $updated->jsonSerialize());

        return $updated;
    }

    /**
     * Delete a holiday
     */
    public function delete(int $id, string $currentUserId): void {
        $holiday = $this->holidayMapper->find($id);
        $oldData = $holiday->jsonSerialize();

        $this->holidayMapper->delete($holiday);

        $this->auditLogService->logDelete($currentUserId, 'holiday', $id, $oldData);
    }

    /**
     * Find all holidays for a year (across all regions)
     *
     * @return Holiday[]
     */
    public function findByYear(int $year): array {
        return $this->holidayMapper->findByYear($year);
    }
}
