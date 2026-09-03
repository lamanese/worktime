<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateTime;
use OCA\Zeitwerk\Db\AbsenceMapper;
use OCA\Zeitwerk\Db\MonthStatus;
use OCA\Zeitwerk\Db\MonthStatusMapper;
use OCA\Zeitwerk\Db\TimeEntry;
use OCA\Zeitwerk\Db\TimeEntryMapper;
use OCP\IL10N;

/**
 * Monatsstatus (Monatsabschluss) je Mitarbeiter und Monat — Quelle der Wahrheit
 * fuer Entwurf / eingereicht / zurueckgewiesen / genehmigt. Ein Monat ohne Zeile
 * ist ein Entwurf. Haengt bewusst nur an Mappern (kein Service-Zyklus).
 */
class MonthStatusService {

    public function __construct(
        private MonthStatusMapper $mapper,
        private TimeEntryMapper $timeEntryMapper,
        private AbsenceMapper $absenceMapper,
        private IL10N $l,
    ) {
    }

    public function find(int $employeeId, int $year, int $month): ?MonthStatus {
        return $this->mapper->findByEmployeeAndMonth($employeeId, $year, $month);
    }

    public function getStatus(int $employeeId, int $year, int $month): string {
        return $this->find($employeeId, $year, $month)?->getStatus() ?? MonthStatus::STATUS_DRAFT;
    }

    /**
     * @param int[] $employeeIds
     * @return array<int, string> employeeId => status (draft when no row)
     */
    public function getStatusesForMonth(array $employeeIds, int $year, int $month): array {
        $result = array_fill_keys($employeeIds, MonthStatus::STATUS_DRAFT);
        foreach ($this->mapper->findByEmployeeIdsAndMonth($employeeIds, $year, $month) as $employeeId => $row) {
            $result[$employeeId] = $row->getStatus();
        }
        return $result;
    }

    /**
     * @param int[] $employeeIds
     * @return array<int, array<int, string>> employeeId => [month => status]
     */
    public function getStatusesForYear(array $employeeIds, int $year): array {
        $result = [];
        foreach ($employeeIds as $employeeId) {
            $result[$employeeId] = array_fill(1, 12, MonthStatus::STATUS_DRAFT);
        }
        foreach ($this->mapper->findByEmployeeIdsAndYear($employeeIds, $year) as $employeeId => $rows) {
            foreach ($rows as $month => $row) {
                $result[$employeeId][$month] = $row->getStatus();
            }
        }
        return $result;
    }

    /**
     * @param int[] $employeeIds
     * @return MonthStatus[] ordered year, month ASC
     */
    public function findByStatus(string $status, array $employeeIds): array {
        return $this->mapper->findByStatusForEmployeeIds($status, $employeeIds);
    }

    public function isApproved(int $employeeId, int $year, int $month): bool {
        return $this->getStatus($employeeId, $year, $month) === MonthStatus::STATUS_APPROVED;
    }

    /** Submitted or approved: no employee self-service changes (entries, km). */
    public function isFrozen(int $employeeId, int $year, int $month): bool {
        $status = $this->getStatus($employeeId, $year, $month);
        return $status === MonthStatus::STATUS_SUBMITTED || $status === MonthStatus::STATUS_APPROVED;
    }

    /**
     * Submit rule: never for approved months; always when draft/rejected entries
     * exist (leftovers after an HR correction may be re-submitted); otherwise only
     * for not-yet-submitted months with at least one approved absence.
     *
     * @throws ValidationException
     */
    public function assertCanSubmit(int $employeeId, int $year, int $month): void {
        $status = $this->getStatus($employeeId, $year, $month);
        if ($status === MonthStatus::STATUS_APPROVED) {
            throw ValidationException::fromSingleError('month', $this->l->t('Dieser Monat ist bereits genehmigt.'));
        }

        $summary = $this->timeEntryMapper->getMonthlyStatusSummary($employeeId, $year, $month);
        $openEntries = ($summary[TimeEntry::STATUS_DRAFT] ?? 0) + ($summary[TimeEntry::STATUS_REJECTED] ?? 0);
        if ($openEntries > 0) {
            return;
        }
        if ($status === MonthStatus::STATUS_SUBMITTED) {
            throw ValidationException::fromSingleError('month', $this->l->t('Dieser Monat ist bereits eingereicht.'));
        }
        if (count($this->absenceMapper->findApprovedByEmployeeAndMonth($employeeId, $year, $month)) === 0) {
            throw ValidationException::fromSingleError('month', $this->l->t('In diesem Monat gibt es nichts einzureichen.'));
        }
    }

    public function canSubmit(int $employeeId, int $year, int $month): bool {
        try {
            $this->assertCanSubmit($employeeId, $year, $month);
            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function markSubmitted(int $employeeId, int $year, int $month, ?int $byEmployeeId, DateTime $now): MonthStatus {
        return $this->upsert($employeeId, $year, $month, $now, static function (MonthStatus $row) use ($byEmployeeId, $now): void {
            $row->setStatus(MonthStatus::STATUS_SUBMITTED);
            $row->setSubmittedAt($now);
            $row->setSubmittedBy($byEmployeeId);
            $row->setApprovedAt(null);
            $row->setApprovedBy(null);
        });
    }

    public function markApproved(int $employeeId, int $year, int $month, ?int $byEmployeeId, DateTime $now): MonthStatus {
        return $this->upsert($employeeId, $year, $month, $now, static function (MonthStatus $row) use ($byEmployeeId, $now): void {
            $row->setStatus(MonthStatus::STATUS_APPROVED);
            $row->setApprovedAt($now);
            $row->setApprovedBy($byEmployeeId);
        });
    }

    public function markRejected(int $employeeId, int $year, int $month, DateTime $now): MonthStatus {
        return $this->upsert($employeeId, $year, $month, $now, static function (MonthStatus $row): void {
            $row->setStatus(MonthStatus::STATUS_REJECTED);
            $row->setApprovedAt(null);
            $row->setApprovedBy(null);
        });
    }

    public function markReopened(int $employeeId, int $year, int $month, DateTime $now): MonthStatus {
        return $this->upsert($employeeId, $year, $month, $now, static function (MonthStatus $row): void {
            $row->setStatus(MonthStatus::STATUS_DRAFT);
            $row->setSubmittedAt(null);
            $row->setSubmittedBy(null);
            $row->setApprovedAt(null);
            $row->setApprovedBy(null);
        });
    }

    /**
     * Re-derive the month status from the per-entry statuses (legacy per-entry
     * endpoints). A draft result without an existing row needs no row.
     */
    public function syncFromEntries(int $employeeId, int $year, int $month): ?MonthStatus {
        $derived = MonthStatus::deriveFromSummary($this->timeEntryMapper->getMonthlyStatusSummary($employeeId, $year, $month));
        $existing = $this->find($employeeId, $year, $month);
        if ($existing === null && $derived === MonthStatus::STATUS_DRAFT) {
            return null;
        }
        $now = new DateTime();
        return $this->upsert($employeeId, $year, $month, $now, static function (MonthStatus $row) use ($derived, $now): void {
            $row->setStatus($derived);
            if ($derived === MonthStatus::STATUS_SUBMITTED && $row->getSubmittedAt() === null) {
                $row->setSubmittedAt($now);
            }
            if ($derived === MonthStatus::STATUS_APPROVED) {
                if ($row->getApprovedAt() === null) {
                    $row->setApprovedAt($now);
                }
            } else {
                $row->setApprovedAt(null);
                $row->setApprovedBy(null);
            }
        });
    }

    /** @param callable(MonthStatus): void $mutate */
    private function upsert(int $employeeId, int $year, int $month, DateTime $now, callable $mutate): MonthStatus {
        $row = $this->find($employeeId, $year, $month);
        if ($row === null) {
            $row = new MonthStatus();
            $row->setEmployeeId($employeeId);
            $row->setYear($year);
            $row->setMonth($month);
            $row->setCreatedAt($now);
            $mutate($row);
            $row->setUpdatedAt($now);
            return $this->mapper->insert($row);
        }
        $mutate($row);
        $row->setUpdatedAt($now);
        return $this->mapper->update($row);
    }
}
