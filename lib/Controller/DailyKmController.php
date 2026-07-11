<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Controller;

use DateTime;
use Exception;
use OCA\WorkTime\Service\DailyKmService;
use OCA\WorkTime\Service\PermissionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tageweise Extern-Kilometer eines Mitarbeiters. Mitarbeiter pflegen ihre
 * eigenen Werte; Vorgesetzte/HR gemäss den bestehenden Ownership-Regeln.
 */
class DailyKmController extends BaseController {

    public function __construct(
        IRequest $request,
        ?string $userId,
        private DailyKmService $dailyKmService,
        private PermissionService $permissionService,
    ) {
        parent::__construct($request, $userId);
    }

    #[NoAdminRequired]
    public function index(?int $employeeId = null, int $year = 0, int $month = 0): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        $employeeId = $this->resolveEmployeeId($employeeId);
        if ($employeeId === null) {
            return $this->requireEmployeeId(null);
        }

        if (!$this->permissionService->canViewEmployee($this->userId, $employeeId)) {
            return $this->forbiddenResponse();
        }

        if ($year <= 0 || $month <= 0) {
            return $this->successResponse(['error' => 'Jahr und Monat sind erforderlich'], 400);
        }

        return $this->successResponse([
            'records' => $this->dailyKmService->findByEmployeeAndMonth($employeeId, $year, $month),
            // Autorität für die km-Eingabe in der UI: enthält auch Tage mit
            // Buchungen auf inzwischen deaktivierten Extern-Projekten, die die
            // Projektliste des Mitarbeiters (nur aktive) nicht kennt.
            'externDays' => $this->dailyKmService->externDaysInMonth($employeeId, $year, $month),
        ]);
    }

    #[NoAdminRequired]
    public function upsert(?int $employeeId = null, string $date = '', int $kilometers = 0): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        $employeeId = $this->resolveEmployeeId($employeeId);
        if ($employeeId === null) {
            return $this->requireEmployeeId(null);
        }

        if (!$this->permissionService->canEditTimeEntry($this->userId, $employeeId)) {
            return $this->forbiddenResponse();
        }

        $dateObj = $this->parseDate($date);
        if ($dateObj === null) {
            return $this->successResponse(['error' => 'Ungültiges Datum'], 400);
        }

        try {
            $result = $this->dailyKmService->upsert($employeeId, $dateObj, $kilometers, $this->userId);
            return $this->successResponse($result?->jsonSerialize() ?? ['date' => $date, 'kilometers' => 0]);
        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Fällt auf den eigenen Mitarbeiter zurück, wenn keine ID übergeben wurde.
     */
    private function resolveEmployeeId(?int $employeeId): ?int {
        if ($employeeId !== null) {
            return $employeeId;
        }
        $employee = $this->permissionService->getEmployeeForUser($this->userId);
        return $employee?->getId();
    }

    private function parseDate(string $date): ?DateTime {
        if ($date === '') {
            return null;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed === false ? null : $parsed;
    }
}
