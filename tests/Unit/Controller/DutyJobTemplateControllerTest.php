<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Controller;

use OCA\Zeitwerk\Controller\DutyJobTemplateController;
use OCA\Zeitwerk\Db\DutyJobTemplate;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\DutyJobTemplateService;
use OCA\Zeitwerk\Service\NotFoundException;
use OCA\Zeitwerk\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class DutyJobTemplateControllerTest extends TestCase {

    private DutyJobTemplateService $service;
    private PermissionService $permissions;
    private CompanySettingsService $settings;

    protected function setUp(): void {
        $this->service = $this->createMock(DutyJobTemplateService::class);
        $this->permissions = $this->createMock(PermissionService::class);
        $this->settings = $this->createMock(CompanySettingsService::class);
    }

    private function controller(?string $userId = 'user'): DutyJobTemplateController {
        return new DutyJobTemplateController(
            $this->createMock(IRequest::class),
            $userId,
            $this->service,
            $this->permissions,
            $this->settings,
        );
    }

    /** Module on, caller is admin: the only combination that may write. */
    private function asAdmin(): DutyJobTemplateController {
        $this->settings->method('isDutyRosterEnabled')->willReturn(true);
        $this->permissions->method('canManageSettings')->willReturn(true);
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        return $this->controller();
    }

    private function assertAdminRoutesForbidden(DutyJobTemplateController $c): void {
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->index()->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->create('X')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->update(1, 'X')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->destroy(1)->getStatus());
    }

    public function testIndexIs401WithoutUser(): void {
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->index()->getStatus());
    }

    public function testEmployeeGetsForbiddenEverywhere(): void {
        $this->settings->method('isDutyRosterEnabled')->willReturn(true);
        $this->permissions->method('canManageSettings')->willReturn(false);
        $this->permissions->method('canManageDutyRoster')->willReturn(false);
        $c = $this->controller();

        $this->assertAdminRoutesForbidden($c);
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->visible()->getStatus());
    }

    public function testPlannerWithoutAdminReadsVisibleButCannotManage(): void {
        $this->settings->method('isDutyRosterEnabled')->willReturn(true);
        $this->permissions->method('canManageSettings')->willReturn(false);
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->service->method('findVisible')->willReturn([]);
        $c = $this->controller();

        $this->assertAdminRoutesForbidden($c);
        $this->assertSame(Http::STATUS_OK, $c->visible()->getStatus());
    }

    public function testAdminReadsAllTemplates(): void {
        $template = new DutyJobTemplate();
        $template->setId(3);
        $template->setTitle('HU');
        $this->service->method('findAll')->willReturn([$template]);

        $response = $this->asAdmin()->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([$template], $response->getData());
    }

    public function testModuleOffIs403OnEveryRoute(): void {
        $this->settings->method('isDutyRosterEnabled')->willReturn(false);
        // Admin rights alone must not be enough while the module is off.
        $this->permissions->method('canManageSettings')->willReturn(true);
        $this->permissions->method('canManageDutyRoster')->willReturn(false);
        $c = $this->controller();

        $this->assertAdminRoutesForbidden($c);
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->visible()->getStatus());
    }

    public function testCreateDelegatesPayload(): void {
        $c = $this->asAdmin();
        $this->service->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(static fn (array $d): bool => $d['title'] === 'HU' && $d['startTime'] === '08:00'
                    && $d['durationMinutes'] === 120 && $d['onCall'] === false && $d['isVisible'] === true),
                'user'
            )
            ->willReturn(new DutyJobTemplate());

        $this->assertSame(Http::STATUS_CREATED, $c->create('HU', '08:00', 120, null, false, true)->getStatus());
    }

    public function testUpdateUnknownIdIs404(): void {
        $c = $this->asAdmin();
        $this->service->method('update')->willThrowException(new NotFoundException('Duty job template not found'));
        $this->assertSame(Http::STATUS_NOT_FOUND, $c->update(99, 'HU')->getStatus());
    }

    public function testDestroyUnknownIdIs404(): void {
        $c = $this->asAdmin();
        $this->service->method('delete')->willThrowException(new NotFoundException('Duty job template not found'));
        $this->assertSame(Http::STATUS_NOT_FOUND, $c->destroy(99)->getStatus());
    }

    public function testDestroyReturnsNoContentOnSuccess(): void {
        $c = $this->asAdmin();
        $this->service->expects($this->once())->method('delete')->with(4, 'user');
        // BaseController::deletedResponse() answers 200 with a status body, not 204.
        $this->assertSame(Http::STATUS_OK, $c->destroy(4)->getStatus());
    }
}
