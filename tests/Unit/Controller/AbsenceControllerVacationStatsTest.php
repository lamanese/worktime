<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Controller;

use OCA\Zeitwerk\Controller\AbsenceController;
use OCA\Zeitwerk\Service\AbsenceService;
use OCA\Zeitwerk\Service\EmployeeService;
use OCA\Zeitwerk\Service\PermissionService;
use OCA\Zeitwerk\Service\WorkScheduleService;
use OCA\Zeitwerk\Service\YearlyCarryoverService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * WorkTime #525: the vacation overview must charge the previous-year carryover
 * exactly (half days), the same way the quota check does — a 12.5-day carryover
 * used to show up as 13 in the balance while the request check charged 12.5.
 */
class AbsenceControllerVacationStatsTest extends TestCase {

    public function testVacationStatsUsesExactHalfDayCarryover(): void {
        $absenceService = $this->createMock(AbsenceService::class);
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('canViewEmployee')->willReturn(true);
        $workScheduleService = $this->createMock(WorkScheduleService::class);
        $workScheduleService->method('getVacationDaysForYear')->willReturn(30);
        $carryoverService = $this->createMock(YearlyCarryoverService::class);
        $carryoverService->method('getVacationCarryoverDays')->willReturn(12.5);

        $absenceService->expects($this->once())
            ->method('getVacationStats')
            ->with(1, 2026, 42.5)
            ->willReturn(['total' => 42.5, 'used' => 0.0, 'remaining' => 42.5]);

        $controller = new AbsenceController(
            $this->createMock(IRequest::class),
            'admin',
            $absenceService,
            $this->createMock(EmployeeService::class),
            $permissionService,
            $workScheduleService,
            $carryoverService,
        );

        $data = $controller->vacationStats(1, 2026)->getData();

        $this->assertSame(12.5, $data['carryover']);
        $this->assertSame(30, $data['entitlement']);
    }
}
