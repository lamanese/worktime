<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Db\Employee;
use OCA\Zeitwerk\Db\MonthStatus;
use OCA\Zeitwerk\Service\AbsenceService;
use OCA\Zeitwerk\Service\AllowanceService;
use OCA\Zeitwerk\Service\ArchiveService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\EmployeeService;
use OCA\Zeitwerk\Service\HolidayService;
use OCA\Zeitwerk\Service\MonthStatusService;
use OCA\Zeitwerk\Service\PdfService;
use OCA\Zeitwerk\Service\TimeEntryService;
use OCA\Zeitwerk\Service\WorkScheduleService;
use PHPUnit\Framework\TestCase;

/**
 * Regression test: an absence-only month (no time entries) must still show its
 * real submission timestamp in the archived PDF, taken from the MonthStatus row
 * instead of the (empty) time entry list.
 */
class ArchiveServiceSubmittedAtTest extends TestCase {

    public function testSubmittedAtFallsBackToMonthStatusWhenNoTimeEntries(): void {
        $settingsService = $this->createMock(CompanySettingsService::class);
        $settingsService->method('get')->willReturnMap([
            [CompanySetting::KEY_PDF_ARCHIVE_USER, null, 'admin'],
            [CompanySetting::KEY_PDF_ARCHIVE_PATH, null, '/Zeitwerk/Archiv'],
        ]);

        $employee = new Employee();
        $employee->setId(1);
        $employee->setFirstName('Erika');
        $employee->setLastName('Musterfrau');
        $employee->setFederalState('BY');
        $employeeService = $this->createMock(EmployeeService::class);
        $employeeService->method('find')->willReturn($employee);

        $timeEntryService = $this->createMock(TimeEntryService::class);
        $timeEntryService->method('findByEmployeeAndMonth')->willReturn([]);

        $absenceService = $this->createMock(AbsenceService::class);
        $absenceService->method('findByEmployeeAndMonth')->willReturn([]);

        $holidayService = $this->createMock(HolidayService::class);
        $holidayService->method('findByMonth')->willReturn([]);

        $workScheduleService = $this->createMock(WorkScheduleService::class);
        $workScheduleService->method('countWorkingDays')->willReturn(20.0);
        $workScheduleService->method('calculateTargetMinutes')->willReturn(9600);

        $submittedAt = new DateTime('2026-09-01 08:00:00');
        $monthStatus = new MonthStatus();
        $monthStatus->setSubmittedAt($submittedAt);
        $monthStatusService = $this->createMock(MonthStatusService::class);
        $monthStatusService->method('find')->willReturn($monthStatus);

        $pdfService = $this->createMock(PdfService::class);
        $pdfService->method('archivedReportExists')->willReturn(false);
        $pdfService->expects($this->once())
            ->method('generateMonthlyReport')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn(?array $approvalInfo): bool => $approvalInfo['submittedAt'] == $submittedAt),
                $this->anything()
            )
            ->willReturn('pdf-content');

        $allowanceService = $this->createMock(AllowanceService::class);
        $allowanceService->method('getMonthlySummary')->willReturn([]);

        $service = new ArchiveService(
            $settingsService,
            $employeeService,
            $timeEntryService,
            $absenceService,
            $holidayService,
            $workScheduleService,
            $pdfService,
            $allowanceService,
            $monthStatusService,
        );

        $service->archiveMonth(1, 2026, 9);
    }
}
