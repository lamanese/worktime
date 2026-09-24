<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Controller;

use DateTime;
use OCA\Zeitwerk\Service\DutyJobService;
use OCA\Zeitwerk\Service\DutyRosterPdfService;
use OCA\Zeitwerk\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Dienstplan-API. Lesen: canViewDutyRoster; alles andere (auch Titel-Vorschlaege):
 * canManageDutyRoster. Ist das Modul aus, liefern beide 403.
 */
class DutyRosterController extends BaseController {

    public function __construct(
        IRequest $request,
        ?string $userId,
        private DutyJobService $dutyJobService,
        private PermissionService $permissionService,
        private DutyRosterPdfService $pdfService,
    ) {
        parent::__construct($request, $userId);
    }

    #[NoAdminRequired]
    public function week(string $start = ''): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canViewDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        $monday = $this->parseDate($start === '' ? (new DateTime())->format('Y-m-d') : $start);
        if ($monday === null) {
            return new JSONResponse(['error' => 'Invalid start date'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return $this->successResponse($this->dutyJobService->getWeek($monday, $this->userId));
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function titles(string $q = ''): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        return $this->successResponse($this->dutyJobService->suggestTitles($q));
    }

    #[NoAdminRequired]
    public function create(
        int $employeeId,
        string $date,
        ?string $startTime = null,
        ?int $durationMinutes = null,
        string $title = '',
        ?string $note = null,
        bool $onCall = false,
        ?int $templateId = null
    ): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        try {
            $job = $this->dutyJobService->create(
                compact('employeeId', 'date', 'startTime', 'durationMinutes', 'title', 'note', 'onCall', 'templateId'),
                $this->userId
            );
            return $this->createdResponse($job);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function update(
        int $id,
        int $employeeId,
        string $date,
        ?string $startTime = null,
        ?int $durationMinutes = null,
        string $title = '',
        ?string $note = null,
        bool $onCall = false
    ): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        try {
            $job = $this->dutyJobService->update(
                $id,
                compact('employeeId', 'date', 'startTime', 'durationMinutes', 'title', 'note', 'onCall'),
                $this->userId
            );
            return $this->successResponse($job);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function move(int $id, int $employeeId, string $date): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        try {
            return $this->successResponse($this->dutyJobService->move($id, $employeeId, $date, $this->userId));
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        try {
            $this->dutyJobService->delete($id, $this->userId);
            return $this->deletedResponse();
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function copyWeek(string $from, string $to): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        $fromDate = $this->parseDate($from);
        $toDate = $this->parseDate($to);
        if ($fromDate === null || $toDate === null) {
            return new JSONResponse(['error' => 'Invalid date'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $created = $this->dutyJobService->copyWeek($fromDate, $toDate, $this->userId);
            return $this->successResponse(['created' => $created]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * «Woche leeren»: alle Auftraege der Woche loeschen. Der Client zeigt vorher
     * einen Dialog mit Pflicht-Checkbox; der Server prueft Rolle und Wochensperre.
     */
    #[NoAdminRequired]
    public function clearWeek(string $start): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        $day = $this->parseDate($start);
        if ($day === null) {
            return new JSONResponse(['error' => 'Invalid date'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $deleted = $this->dutyJobService->clearWeek($day, $this->userId);
            return $this->successResponse(['deleted' => $deleted]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Auge-Schalter: Vorlagen-Leiste fuer den eigenen Benutzer ein-/ausblenden
     * (ueberschreibt die Firmenvorgabe nur fuer diesen Planer).
     */
    #[NoAdminRequired]
    public function templatesSidebar(bool $visible): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        $this->dutyJobService->setTemplatesSidebarVisible($this->userId, $visible);
        return $this->successResponse(['showTemplates' => $visible]);
    }

    /**
     * «Diese Woche ignorieren» fuer eine Vorlage mit festen Wochentagen: alle
     * Planer, Woche muss offen sein. $start = beliebiger Tag der Woche.
     */
    #[NoAdminRequired]
    public function skipTemplate(int $id, string $start, bool $skipped = true): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        $day = $this->parseDate($start);
        if ($day === null) {
            return new JSONResponse(['error' => 'Invalid start date'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->dutyJobService->setTemplateSkipped($id, $day, $skipped, $this->userId);
            return $this->successResponse(['templateId' => $id, 'skipped' => $skipped]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Woche sperren: alle Planer. $start = beliebiger Tag der Woche.
     */
    #[NoAdminRequired]
    public function lock(string $start): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        $day = $this->parseDate($start);
        if ($day === null) {
            return new JSONResponse(['error' => 'Invalid start date'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return $this->successResponse($this->dutyJobService->lockWeek($day, $this->userId));
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Woche entsperren: nur Admin und HR-Manager.
     */
    #[NoAdminRequired]
    public function unlock(string $start): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canUnlockDutyWeek($this->userId)) {
            return $this->forbiddenResponse();
        }
        $day = $this->parseDate($start);
        if ($day === null) {
            return new JSONResponse(['error' => 'Invalid start date'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return $this->successResponse($this->dutyJobService->unlockWeek($day, $this->userId));
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Wochenplan als PDF ins Nextcloud-Archiv (kein Browser-Download, Entscheid
     * 2026-09-15). Nur Admin/HR, weil ins Konto des Archiv-Benutzers geschrieben
     * wird. Antwort: archive (saved|skipped|failed), path, filename.
     * POST, damit der CSRF-Schutz greift.
     */
    #[NoAdminRequired]
    public function pdf(string $start): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canArchiveDutyRosterPdf($this->userId)) {
            return $this->forbiddenResponse();
        }
        $day = $this->parseDate($start);
        if ($day === null) {
            return new JSONResponse(['error' => 'Invalid start date'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $week = $this->dutyJobService->getWeek($day, $this->userId);
            $result = $this->pdfService->export($week, $this->userId);
            return $this->successResponse([
                'archive' => $result['archive'],
                'path' => $result['path'],
                'filename' => $result['filename'],
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Strict Y-m-d calendar date (WorkTime #537 rule), null when invalid.
     */
    private function parseDate(string $value): ?DateTime {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return new DateTime($value);
    }
}
