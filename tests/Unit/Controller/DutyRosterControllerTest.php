<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Controller;

use OCA\Zeitwerk\Controller\DutyRosterController;
use OCA\Zeitwerk\Service\DutyJobService;
use OCA\Zeitwerk\Service\NotFoundException;
use OCA\Zeitwerk\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class DutyRosterControllerTest extends TestCase {

    private DutyJobService $service;
    private PermissionService $permissions;

    protected function setUp(): void {
        $this->service = $this->createMock(DutyJobService::class);
        $this->permissions = $this->createMock(PermissionService::class);
    }

    private function controller(?string $userId = 'user'): DutyRosterController {
        return new DutyRosterController($this->createMock(IRequest::class), $userId, $this->service, $this->permissions);
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
}
