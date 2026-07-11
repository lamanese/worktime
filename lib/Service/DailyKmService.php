<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Service;

use DateTime;
use OCA\WorkTime\Db\Absence;
use OCA\WorkTime\Db\AbsenceMapper;
use OCA\WorkTime\Db\DailyKm;
use OCA\WorkTime\Db\DailyKmMapper;
use OCA\WorkTime\Db\ProjectMapper;
use OCA\WorkTime\Db\TimeEntryMapper;

/**
 * Verwaltet die tageweise gefahrenen Kilometer (Extern). Pro Mitarbeiter und Tag
 * existiert höchstens ein Datensatz; das Speichern arbeitet daher als Upsert.
 *
 * Kilometer sind vergütungsrelevant, deshalb wird serverseitig erzwungen, dass
 * ein Wert nur an einem tatsächlich externen Tag erfasst werden kann (Buchung auf
 * einem Extern-Projekt oder externer Abwesenheitstyp). Die UI-Beschränkung allein
 * genügt nicht.
 */
class DailyKmService {

    private const MAX_KILOMETERS = 100000;

    /** @var int[]|null Cache: Projekt-IDs mit Extern-Flag */
    private ?array $externProjectIds = null;

    public function __construct(
        private DailyKmMapper $dailyKmMapper,
        private AuditLogService $auditLogService,
        private TimeEntryMapper $timeEntryMapper,
        private ProjectMapper $projectMapper,
        private AbsenceMapper $absenceMapper,
        private CompanySettingsService $settings,
        private TimeEntryService $timeEntryService,
    ) {
    }

    /**
     * @return DailyKm[]
     */
    public function findByEmployeeAndMonth(int $employeeId, int $year, int $month): array {
        return $this->dailyKmMapper->findByEmployeeAndMonth($employeeId, $year, $month);
    }

    /**
     * Kilometer für einen Tag setzen. 0 (oder weniger) entfernt einen bestehenden
     * Eintrag, damit keine Nulldatensätze verbleiben.
     */
    public function upsert(int $employeeId, DateTime $date, int $kilometers, string $currentUserId = ''): ?DailyKm {
        if ($kilometers < 0 || $kilometers > self::MAX_KILOMETERS) {
            throw new ValidationException(['kilometers' => 'Ungültiger Kilometerwert']);
        }

        // Vergütungsrelevant: ein positiver Wert ist nur an einem externen Tag
        // zulässig. Das Nullsetzen (Löschen) bleibt immer erlaubt.
        if ($kilometers > 0 && !$this->isExternDay($employeeId, $date)) {
            throw new ValidationException(['date' => 'Kilometer können nur an einem externen Tag erfasst werden.']);
        }

        // Eingereichte/abgeschlossene Monate sind auch für Kilometer gesperrt
        // (gilt auch für das Löschen, km=0) — sonst liessen sich Vergütungs-
        // beträge unter einer laufenden Genehmigung oder nach dem Abschluss noch
        // ändern. "Eingereicht" entspricht der UI-Semantik: es gibt Einträge und
        // keiner ist mehr Entwurf/abgelehnt. Dazu die Sperre aus #148 (voll
        // genehmigt oder Vorjahr). HR öffnet den Monat bei Bedarf über den
        // bestehenden Korrektur-Flow (reopenMonth) und passt die km danach an.
        $year = (int)$date->format('Y');
        $month = (int)$date->format('n');
        $summary = $this->timeEntryMapper->getMonthlyStatusSummary($employeeId, $year, $month);
        $totalEntries = $summary['draft'] + $summary['submitted'] + $summary['approved'] + $summary['rejected'];
        $monthFrozen = $totalEntries > 0 && $summary['draft'] === 0 && $summary['rejected'] === 0;
        if ($monthFrozen || $this->timeEntryService->isMonthLocked($employeeId, $year, $month)) {
            throw new ValidationException(['date' => 'Dieser Zeitraum ist eingereicht oder abgeschlossen. Bitte wende dich an HR.']);
        }

        $existing = $this->dailyKmMapper->findByEmployeeAndDate($employeeId, $date);

        if ($kilometers === 0) {
            if ($existing !== null) {
                $this->dailyKmMapper->delete($existing);
                if ($currentUserId) {
                    $this->auditLogService->logDelete($currentUserId, 'daily_km', $existing->getId(), $existing->jsonSerialize());
                }
            }
            return null;
        }

        if ($existing !== null) {
            $oldValues = $existing->jsonSerialize();
            $existing->setKilometers($kilometers);
            $existing->setUpdatedAt(new DateTime());
            $saved = $this->dailyKmMapper->update($existing);
            if ($currentUserId) {
                $this->auditLogService->logUpdate($currentUserId, 'daily_km', $saved->getId(), $oldValues, $saved->jsonSerialize());
            }
            return $saved;
        }

        $entity = new DailyKm();
        $entity->setEmployeeId($employeeId);
        $entity->setWorkDate($date);
        $entity->setKilometers($kilometers);
        $entity->setCreatedAt(new DateTime());
        $entity->setUpdatedAt(new DateTime());
        $saved = $this->dailyKmMapper->insert($entity);
        if ($currentUserId) {
            $this->auditLogService->logCreate($currentUserId, 'daily_km', $saved->getId(), $saved->jsonSerialize());
        }
        return $saved;
    }

    /**
     * Alle km-fähigen (externen) Tage eines Monats als 'Y-m-d'-Liste. Dieselbe
     * Definition wie isExternDay(), aber gebatcht (2 Queries statt 1 pro Tag).
     * Die UI nutzt das als Autorität für die km-Eingabe — sie kennt inaktive
     * Extern-Projekte nicht (die Projektliste des Mitarbeiters enthält nur
     * aktive, buchbare Projekte).
     *
     * @return string[] Y-m-d
     */
    public function externDaysInMonth(int $employeeId, int $year, int $month): array {
        $monthStart = new DateTime(sprintf('%d-%02d-01', $year, $month));
        $monthEnd = (clone $monthStart)->modify('last day of this month');

        $days = [];

        $externProjectIds = $this->getExternProjectIds();
        if (!empty($externProjectIds)) {
            foreach ($this->timeEntryMapper->findByEmployeeAndMonth($employeeId, $year, $month) as $entry) {
                $date = $entry->getDate();
                if ($date && in_array((int)$entry->getProjectId(), $externProjectIds, true)) {
                    $days[$date->format('Y-m-d')] = true;
                }
            }
        }

        $externTypes = $this->settings->getExternAbsenceTypes();
        if (!empty($externTypes)) {
            $externTypeSet = array_fill_keys($externTypes, true);
            foreach ($this->absenceMapper->findByEmployeeAndMonth($employeeId, $year, $month) as $absence) {
                $status = $absence->getStatus();
                if ($status === Absence::STATUS_CANCELLED || $status === Absence::STATUS_REJECTED) {
                    continue;
                }
                if (!isset($externTypeSet[$absence->getType()])) {
                    continue;
                }
                $start = $absence->getStartDate() > $monthStart ? clone $absence->getStartDate() : clone $monthStart;
                $end = $absence->getEndDate() < $monthEnd ? $absence->getEndDate() : $monthEnd;
                for ($day = clone $start; $day <= $end; $day->modify('+1 day')) {
                    $days[$day->format('Y-m-d')] = true;
                }
            }
        }

        $result = array_keys($days);
        sort($result);
        return $result;
    }

    /**
     * Ein Tag ist "extern" (km-fähig), wenn der Mitarbeiter an dem Tag auf einem
     * Extern-Projekt gebucht hat oder eine nicht stornierte/abgelehnte Abwesenheit
     * eines als extern konfigurierten Typs vorliegt.
     */
    public function isExternDay(int $employeeId, DateTime $date): bool {
        $externProjectIds = $this->getExternProjectIds();
        if (!empty($externProjectIds)) {
            foreach ($this->timeEntryMapper->findByEmployeeAndDate($employeeId, $date) as $entry) {
                if (in_array((int)$entry->getProjectId(), $externProjectIds, true)) {
                    return true;
                }
            }
        }

        $externTypes = $this->settings->getExternAbsenceTypes();
        if (!empty($externTypes)) {
            $externTypeSet = array_fill_keys($externTypes, true);
            foreach ($this->absenceMapper->findByEmployeeAndDate($employeeId, $date) as $absence) {
                $status = $absence->getStatus();
                if ($status === Absence::STATUS_CANCELLED || $status === Absence::STATUS_REJECTED) {
                    continue;
                }
                if (isset($externTypeSet[$absence->getType()])) {
                    return true;
                }
            }
        }

        return false;
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
