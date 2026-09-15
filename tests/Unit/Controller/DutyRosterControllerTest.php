<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Controller;

use OCA\Zeitwerk\Controller\DutyRosterController;
use OCA\Zeitwerk\Service\DutyJobService;
use OCA\Zeitwerk\Service\DutyRosterPdfService;
use OCA\Zeitwerk\Service\ForbiddenException;
use OCA\Zeitwerk\Service\NotFoundException;
use OCA\Zeitwerk\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class DutyRosterControllerTest extends TestCase {

    private DutyJobService $service;
    private PermissionService $permissions;
    private DutyRosterPdfService $pdfService;

    protected function setUp(): void {
        $this->service = $this->createMock(DutyJobService::class);
        $this->permissions = $this->createMock(PermissionService::class);
        $this->pdfService = $this->createMock(DutyRosterPdfService::class);
    }

    private function controller(?string $userId = 'user', ?PermissionService $permissions = null): DutyRosterController {
        return new DutyRosterController($this->createMock(IRequest::class), $userId, $this->service, $permissions ?? $this->permissions, $this->pdfService);
    }

    public function testWeekIs401WithoutUser(): void {
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->week('2026-09-14')->getStatus());
    }

    public function testWeekIs403WhenModuleOffOrNoAccess(): void {
        $this->permissions->method('canViewDutyRoster')->willReturn(false);
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->week('2026-09-14')->getStatus());
    }

    public function testWeekRejectsBadStart(): void {
        $this->permissions->method('canViewDutyRoster')->willReturn(true);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->week('2026-02-30')->getStatus());
        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->week('gestern')->getStatus());
    }

    public function testWeekDelegatesForViewer(): void {
        $this->permissions->method('canViewDutyRoster')->willReturn(true);
        $this->service->expects($this->once())->method('getWeek')->willReturn(['rows' => []]);
        $response = $this->controller()->week('2026-09-16');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['rows' => []], $response->getData());
    }

    public function testWriteRoutesAre403ForViewers(): void {
        $this->permissions->method('canViewDutyRoster')->willReturn(true);
        $this->permissions->method('canManageDutyRoster')->willReturn(false);
        $c = $this->controller();
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->titles('c')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->create(1, '2026-09-15', null, null, 'X', null, false)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->update(1, 1, '2026-09-15', null, null, 'X', null, false)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->move(1, 1, '2026-09-15')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->destroy(1)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->copyWeek('2026-09-14', '2026-09-21')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->pdf('2026-09-14')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->templatesSidebar(false)->getStatus());
    }

    public function testTemplatesSidebarStoresPreference(): void {
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->service->expects($this->once())->method('setTemplatesSidebarVisible')->with('user', false);
        $this->assertSame(['showTemplates' => false], $this->controller()->templatesSidebar(false)->getData());
    }

    public function testDestroyMapsNotFoundTo404(): void {
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->service->method('delete')->willThrowException(new NotFoundException('Duty job not found'));
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->destroy(99)->getStatus());
    }

    public function testCopyWeekReturnsCount(): void {
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->service->method('copyWeek')->willReturn(4);
        $response = $this->controller()->copyWeek('2026-09-14', '2026-09-21');
        $this->assertSame(['created' => 4], $response->getData());
    }
    public function testLockIs403ForViewersAndUnlockIs403ForSupervisors(): void {
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->permissions->method('canUnlockDutyWeek')->willReturn(false);
        $this->service->expects($this->never())->method('unlockWeek');
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->unlock('2026-09-14')->getStatus());

        $viewerPermissions = $this->createMock(PermissionService::class);
        $viewerPermissions->method('canManageDutyRoster')->willReturn(false);
        $c = $this->controller('user', $viewerPermissions);
        $this->assertSame(Http::STATUS_FORBIDDEN, $c->lock('2026-09-14')->getStatus());
    }

    public function testLockAndUnlockDelegateAndValidateDate(): void {
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->permissions->method('canUnlockDutyWeek')->willReturn(true);
        $info = ['locked' => true, 'lockedBy' => 'Admin', 'lockedAt' => '2026-09-15T08:00:00+00:00'];
        $this->service->expects($this->once())->method('lockWeek')->willReturn($info);
        $this->service->expects($this->once())->method('unlockWeek')->willReturn(['locked' => false, 'lockedBy' => null, 'lockedAt' => null]);

        $c = $this->controller();
        $this->assertSame($info, $c->lock('2026-09-16')->getData());
        $this->assertFalse($c->unlock('2026-09-16')->getData()['locked']);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $c->lock('2026-02-30')->getStatus());
        $this->assertSame(Http::STATUS_BAD_REQUEST, $c->unlock('heute')->getStatus());
    }

    public function testWriteOnLockedWeekIs403(): void {
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->service->method('move')->willThrowException(new ForbiddenException('Woche ist gesperrt'));
        $response = $this->controller()->move(1, 1, '2026-09-15');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame('Woche ist gesperrt', $response->getData()['error']);
    }
    public function testPdfArchivesAndReportsArchiveState(): void {
        $this->permissions->method('canManageDutyRoster')->willReturn(true);
        $this->service->expects($this->once())->method('getWeek')->willReturn(['weekStart' => '2026-09-14', 'rows' => []]);
        $this->pdfService->expects($this->once())->method('export')
            ->with(['weekStart' => '2026-09-14', 'rows' => []], 'user')
            ->willReturn(['pdf' => '%PDF-1.7 x', 'filename' => 'KW38-2026.pdf', 'archive' => 'failed', 'path' => null]);

        $response = $this->controller()->pdf('2026-09-16');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['archive' => 'failed', 'path' => null, 'filename' => 'KW38-2026.pdf'], $response->getData());
        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->pdf('2026-13-01')->getStatus());
    }
}
