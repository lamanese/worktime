<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Controller;

use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\DutyJobTemplateService;
use OCA\Zeitwerk\Service\PermissionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Auftragsvorlagen des Dienstplans. Pflege nur fuer Admin (canManageSettings,
 * wie die Firmeneinstellungen); die Leseroute «visible» fuer Planer, damit die
 * Seitenleiste der Wochenansicht die sichtbaren Vorlagen zeigt.
 *
 * canManageSettings kennt den Modul-Schalter NICHT (es ist schlicht isAdmin),
 * darum steht auf den Admin-Routen zusaetzlich isDutyRosterEnabled(). Bei
 * canManageDutyRoster ist der Schalter bereits eingebaut.
 */
class DutyJobTemplateController extends BaseController {

    public function __construct(
        IRequest $request,
        ?string $userId,
        private DutyJobTemplateService $templateService,
        private PermissionService $permissionService,
        private CompanySettingsService $settingsService,
    ) {
        parent::__construct($request, $userId);
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        if ($guard = $this->requireTemplateAdmin()) {
            return $guard;
        }
        return $this->successResponse($this->templateService->findAll());
    }

    #[NoAdminRequired]
    public function visible(): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->permissionService->canManageDutyRoster($this->userId)) {
            return $this->forbiddenResponse();
        }
        return $this->successResponse($this->templateService->findVisible());
    }

    #[NoAdminRequired]
    public function create(
        string $title = '',
        ?string $startTime = null,
        ?int $durationMinutes = null,
        ?string $note = null,
        bool $onCall = false,
        bool $isVisible = true
    ): JSONResponse {
        if ($guard = $this->requireTemplateAdmin()) {
            return $guard;
        }
        try {
            $template = $this->templateService->create(
                compact('title', 'startTime', 'durationMinutes', 'note', 'onCall', 'isVisible'),
                $this->userId
            );
            return $this->createdResponse($template);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function update(
        int $id,
        string $title = '',
        ?string $startTime = null,
        ?int $durationMinutes = null,
        ?string $note = null,
        bool $onCall = false,
        bool $isVisible = true
    ): JSONResponse {
        if ($guard = $this->requireTemplateAdmin()) {
            return $guard;
        }
        try {
            $template = $this->templateService->update(
                $id,
                compact('title', 'startTime', 'durationMinutes', 'note', 'onCall', 'isVisible'),
                $this->userId
            );
            return $this->successResponse($template);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        if ($guard = $this->requireTemplateAdmin()) {
            return $guard;
        }
        try {
            $this->templateService->delete($id, $this->userId);
            return $this->deletedResponse();
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * 401 without a user, 403 when the module is off or the user is not admin.
     */
    private function requireTemplateAdmin(): ?JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }
        if (!$this->settingsService->isDutyRosterEnabled()
            || !$this->permissionService->canManageSettings($this->userId)) {
            return $this->forbiddenResponse();
        }
        return null;
    }
}
