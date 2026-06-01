<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Controller;

use DateTime;
use OCA\WorkTime\Db\AuditLogMapper;
use OCA\WorkTime\Service\PermissionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AuditController extends BaseController {

    public function __construct(
        IRequest $request,
        ?string $userId,
        private AuditLogMapper $auditLogMapper,
        private PermissionService $permissionService,
    ) {
        parent::__construct($request, $userId);
    }

    #[NoAdminRequired]
    public function index(
        string $action = '',
        string $entityType = '',
        string $from = '',
        string $to = '',
        int $limit = 200,
        int $offset = 0,
        string $userId = ''
    ): JSONResponse {
        if ($authError = $this->requireAuth()) {
            return $authError;
        }

        if (!$this->permissionService->isAdmin($this->userId) && !$this->permissionService->isHrManager($this->userId)) {
            return $this->forbiddenResponse();
        }

        $fromDate = $from !== '' ? new DateTime($from) : null;
        $toDate = $to !== '' ? new DateTime($to) : null;

        $entries = $this->auditLogMapper->findFiltered(
            $action !== '' ? $action : null,
            $entityType !== '' ? $entityType : null,
            $fromDate,
            $toDate,
            min($limit, 500),
            $offset,
            $userId !== '' ? $userId : null,
        );

        return $this->successResponse(array_map(fn($e) => $e->jsonSerialize(), $entries));
    }
}
