<?php

declare(strict_types=1);

namespace OCA\WorkTime\Service;

use DateTime;
use OCA\WorkTime\Db\YearlyCarryover;
use OCA\WorkTime\Db\YearlyCarryoverMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class YearlyCarryoverService {

    public function __construct(
        private YearlyCarryoverMapper $mapper,
        private AuditLogService $auditLogService,
    ) {
    }

    /**
     * @return YearlyCarryover[]
     */
    public function findByEmployee(int $employeeId): array {
        return $this->mapper->findByEmployee($employeeId);
    }

    /**
     * @return YearlyCarryover[]
     */
    public function findByYear(int $year): array {
        return $this->mapper->findByYear($year);
    }

    /**
     * @return YearlyCarryover[]
     */
    public function findActiveByYear(int $year): array {
        return $this->mapper->findActiveByYear($year);
    }

    public function findByEmployeeAndYear(int $employeeId, int $year): ?YearlyCarryover {
        try {
            return $this->mapper->findByEmployeeAndYear($employeeId, $year);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findActiveByEmployeeAndYear(int $employeeId, int $year): ?YearlyCarryover {
        try {
            return $this->mapper->findActiveByEmployeeAndYear($employeeId, $year);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function getOvertimeCarryoverMinutes(int $employeeId, int $year): int {
        $carryover = $this->findActiveByEmployeeAndYear($employeeId, $year);
        return $carryover ? $carryover->getOvertimeMinutes() : 0;
    }

    public function getVacationCarryoverDays(int $employeeId, int $year): float {
        $carryover = $this->findActiveByEmployeeAndYear($employeeId, $year);
        return $carryover ? $carryover->getVacationDaysFloat() : 0.0;
    }

    /**
     * Create or update a carryover (auto-save). Locked records cannot be modified.
     */
    public function upsert(
        int $employeeId,
        int $year,
        int $overtimeMinutes,
        float $vacationDays,
        ?string $note,
        string $currentUserId,
    ): YearlyCarryover {
        $existing = $this->findActiveByEmployeeAndYear($employeeId, $year);

        if ($existing && $existing->isLocked()) {
            throw new \RuntimeException('Carryover is locked and cannot be modified. Use cancel and replace.');
        }

        if ($existing) {
            $oldValues = $existing->jsonSerialize();
            $existing->setOvertimeMinutes($overtimeMinutes);
            $existing->setVacationDays(number_format($vacationDays, 1, '.', ''));
            $existing->setNote($note);
            $existing->setUpdatedAt(new DateTime());

            $result = $this->mapper->update($existing);

            $this->auditLogService->logUpdate(
                $currentUserId, 'yearly_carryover', $result->getId(),
                $oldValues, $result->jsonSerialize()
            );

            return $result;
        }

        $carryover = new YearlyCarryover();
        $carryover->setEmployeeId($employeeId);
        $carryover->setYear($year);
        $carryover->setOvertimeMinutes($overtimeMinutes);
        $carryover->setVacationDays(number_format($vacationDays, 1, '.', ''));
        $carryover->setNote($note);
        $carryover->setCreatedBy($currentUserId);
        $carryover->setCreatedAt(new DateTime());
        $carryover->setUpdatedAt(new DateTime());

        $result = $this->mapper->insert($carryover);

        $this->auditLogService->logCreate(
            $currentUserId, 'yearly_carryover', $result->getId(),
            $result->jsonSerialize()
        );

        return $result;
    }

    /**
     * Lock a carryover ("Übertrag durchführen") — makes it immutable.
     */
    public function lock(int $id, string $currentUserId): YearlyCarryover {
        $carryover = $this->mapper->find($id);

        if ($carryover->isCancelled()) {
            throw new \RuntimeException('Cannot lock a cancelled carryover.');
        }

        if ($carryover->isLocked()) {
            return $carryover;
        }

        $oldValues = $carryover->jsonSerialize();
        $carryover->setLockedAt(new DateTime());
        $carryover->setUpdatedAt(new DateTime());

        $result = $this->mapper->update($carryover);

        $this->auditLogService->logUpdate(
            $currentUserId, 'yearly_carryover', $result->getId(),
            $oldValues, $result->jsonSerialize()
        );

        return $result;
    }

    /**
     * Cancel a locked carryover and create a replacement with corrected values.
     */
    public function cancelAndReplace(
        int $id,
        int $overtimeMinutes,
        float $vacationDays,
        string $reason,
        string $currentUserId,
    ): YearlyCarryover {
        $existing = $this->mapper->find($id);

        if ($existing->isCancelled()) {
            throw new \RuntimeException('Carryover is already cancelled.');
        }

        if (!$existing->isLocked()) {
            throw new \RuntimeException('Only locked carryovers need cancellation. Unlocked ones can be edited directly.');
        }

        $oldValues = $existing->jsonSerialize();
        $existing->setCancelledAt(new DateTime());
        $existing->setCancelledBy($currentUserId);
        $existing->setUpdatedAt(new DateTime());
        $this->mapper->update($existing);

        $this->auditLogService->logUpdate(
            $currentUserId, 'yearly_carryover', $existing->getId(),
            $oldValues, $existing->jsonSerialize()
        );

        $replacement = new YearlyCarryover();
        $replacement->setEmployeeId($existing->getEmployeeId());
        $replacement->setYear($existing->getYear());
        $replacement->setOvertimeMinutes($overtimeMinutes);
        $replacement->setVacationDays(number_format($vacationDays, 1, '.', ''));
        $replacement->setNote($reason);
        $replacement->setCreatedBy($currentUserId);
        $replacement->setCreatedAt(new DateTime());
        $replacement->setUpdatedAt(new DateTime());
        $replacement->setLockedAt(new DateTime());
        $replacement->setReplacesId($existing->getId());

        $result = $this->mapper->insert($replacement);

        $this->auditLogService->logCreate(
            $currentUserId, 'yearly_carryover', $result->getId(),
            $result->jsonSerialize()
        );

        return $result;
    }

    public function deleteByEmployeeId(int $employeeId): void {
        $this->mapper->deleteByEmployeeId($employeeId);
    }
}
