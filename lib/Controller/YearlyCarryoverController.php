<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Controller;

use OCA\WorkTime\Service\PermissionService;
use OCA\WorkTime\Service\WorkScheduleService;
use OCA\WorkTime\Service\YearlyCarryoverService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class YearlyCarryoverController extends BaseController {

    public function __construct(
        IRequest $request,
        ?string $userId,
        private YearlyCarryoverService $carryoverService,
        private PermissionService $permissionService,
        private WorkScheduleService $workScheduleService,
    ) {
        parent::__construct($request, $userId);
    }

    /**
     * Get all active carryovers for a year with schedule-based activity info.
     */
    #[NoAdminRequired]
    public function index(int $year): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }

        $carryovers = $this->carryoverService->findActiveByYear($year);
        $sourceYear = $year - 1;
        $enriched = [];

        foreach ($carryovers as $carryover) {
            $data = $carryover->jsonSerialize();
            $data['wasActiveInSourceYear'] = $this->workScheduleService->wasActiveInYear(
                $carryover->getEmployeeId(), $sourceYear
            );
            $enriched[] = $data;
        }

        return $this->successResponse($enriched);
    }

    /**
     * Get carryover for a specific employee and year.
     */
    #[NoAdminRequired]
    public function show(int $employeeId, int $year): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        $ownEmployee = $this->permissionService->getEmployeeForUser($this->userId);
        $ownEmployeeId = $ownEmployee?->getId();
        if ($employeeId !== $ownEmployeeId && !$this->permissionService->canViewEmployee($this->userId, $employeeId)) {
            return $this->forbiddenResponse();
        }

        $carryover = $this->carryoverService->findActiveByEmployeeAndYear($employeeId, $year);
        return $this->successResponse($carryover);
    }

    /**
     * Create or update carryover (auto-save on cell change).
     */
    #[NoAdminRequired]
    public function upsert(
        int $employeeId,
        int $year,
        int $overtimeMinutes = 0,
        float $vacationDays = 0,
        ?string $note = null,
    ): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }

        try {
            $carryover = $this->carryoverService->upsert(
                $employeeId, $year, $overtimeMinutes, $vacationDays, $note, $this->userId
            );
            return $this->successResponse($carryover);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Lock a carryover ("Übertrag durchführen").
     */
    #[NoAdminRequired]
    public function lock(int $id): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }

        try {
            $carryover = $this->carryoverService->lock($id, $this->userId);
            return $this->successResponse($carryover);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Cancel a locked carryover and replace with corrected values.
     */
    #[NoAdminRequired]
    public function cancel(
        int $id,
        int $overtimeMinutes = 0,
        float $vacationDays = 0,
        string $reason = '',
    ): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if (!$this->permissionService->canManageEmployees($this->userId)) {
            return $this->forbiddenResponse();
        }

        if (empty(trim($reason))) {
            return new JSONResponse(['error' => 'Reason required.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $replacement = $this->carryoverService->cancelAndReplace(
                $id, $overtimeMinutes, $vacationDays, $reason, $this->userId
            );
            return $this->successResponse($replacement);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
