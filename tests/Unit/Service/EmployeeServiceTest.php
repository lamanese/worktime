<?php

declare(strict_types=1);

namespace OCA\WorkTime\Tests\Unit\Service;

use DateTime;
use OCA\WorkTime\Db\Employee;
use OCA\WorkTime\Db\EmployeeMapper;
use OCA\WorkTime\Db\WorkSchedule;
use OCA\WorkTime\Db\WorkScheduleMapper;
use OCA\WorkTime\Db\Project;
use OCA\WorkTime\Service\AuditLogService;
use OCA\WorkTime\Service\CompanySettingsService;
use OCA\WorkTime\Service\EmployeeService;
use OCA\WorkTime\Service\ProjectService;
use OCA\WorkTime\Service\ValidationException;
use OCA\WorkTime\Service\WorkScheduleService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for issue #202: the employee overview must reflect the
 * work schedule that is active today, never a stale cache or a future-dated
 * profile. The work schedule is the single source of truth.
 */
class EmployeeServiceTest extends TestCase {

    private EmployeeService $service;
    private EmployeeMapper $employeeMapper;
    private WorkScheduleMapper $workScheduleMapper;
    private WorkScheduleService $workScheduleService;
    private AuditLogService $auditLogService;
    private IUserManager $userManager;
    private LoggerInterface $logger;
    private CompanySettingsService $companySettings;
    private ProjectService $projectService;

    protected function setUp(): void {
        $this->employeeMapper = $this->createMock(EmployeeMapper::class);
        $this->workScheduleMapper = $this->createMock(WorkScheduleMapper::class);
        $this->workScheduleService = $this->createMock(WorkScheduleService::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->companySettings = $this->createMock(CompanySettingsService::class);
        $this->projectService = $this->createMock(ProjectService::class);

        $this->service = new EmployeeService(
            $this->employeeMapper,
            $this->workScheduleMapper,
            $this->workScheduleService,
            $this->auditLogService,
            $this->userManager,
            $this->logger,
            $this->companySettings,
            $this->projectService,
        );
    }

    private function makeEmployee(int $id, string $weeklyHours, int $vacationDays): Employee {
        $employee = new Employee();
        $employee->setId($id);
        $employee->setUserId('user' . $id);
        $employee->setWeeklyHours($weeklyHours);
        $employee->setVacationDays($vacationDays);
        return $employee;
    }

    private function makeSchedule(float $dailyHours, int $vacationDays): WorkSchedule {
        $schedule = new WorkSchedule();
        $schedule->setMonHours(number_format($dailyHours, 2, '.', ''));
        $schedule->setTueHours(number_format($dailyHours, 2, '.', ''));
        $schedule->setWedHours(number_format($dailyHours, 2, '.', ''));
        $schedule->setThuHours(number_format($dailyHours, 2, '.', ''));
        $schedule->setFriHours(number_format($dailyHours, 2, '.', ''));
        $schedule->setSatHours('0.00');
        $schedule->setSunHours('0.00');
        $schedule->setVacationDays($vacationDays);
        return $schedule;
    }

    public function testCreateRejectsZeroWeeklyHours(): void {
        // weeklyHours = 0 would create a zero-hour initial schedule (0/5 per day),
        // which collapses every working-day/absence-day calculation to 0. Must be
        // blocked before any persistence happens.
        $this->employeeMapper->expects($this->never())->method('insert');
        $this->workScheduleMapper->expects($this->never())->method('insert');

        $this->expectException(\OCA\WorkTime\Service\ValidationException::class);
        $this->service->create('user42', 'Erika', 'Musterfrau', null, null, 0.0, 30, null, 'BY', null, 'admin');
    }

    public function testFindSurfacesActiveScheduleValuesOverStaleCache(): void {
        // Cache holds 40h / 30 days, but the schedule active today is 31.5h / 28 days.
        $this->employeeMapper->method('find')
            ->willReturn($this->makeEmployee(6, '40.00', 30));
        $this->workScheduleService->method('getScheduleForDate')
            ->willReturn($this->makeSchedule(6.3, 28)); // 6.3 * 5 = 31.5

        $employee = $this->service->find(6);

        $this->assertSame(31.5, (float)$employee->getWeeklyHours());
        $this->assertSame(28, $employee->getVacationDays());
    }

    public function testFindIgnoresFutureProfileForOverview(): void {
        // Active profile today = 31.5h; a future-dated 40h profile must not leak
        // into the overview. getScheduleForDate already returns the active one.
        $this->employeeMapper->method('find')
            ->willReturn($this->makeEmployee(6, '40.00', 30));
        $this->workScheduleService->method('getScheduleForDate')
            ->with(6, $this->isInstanceOf(DateTime::class))
            ->willReturn($this->makeSchedule(6.3, 28));

        $employee = $this->service->find(6);

        $this->assertSame(31.5, (float)$employee->getWeeklyHours());
    }

    public function testFindAllAppliesActiveScheduleToEachEmployeeViaBatchQuery(): void {
        $this->employeeMapper->method('findAll')->willReturn([
            $this->makeEmployee(1, '40.00', 30),
            $this->makeEmployee(2, '40.00', 30),
        ]);
        // A single batch query resolves the active schedule for every employee.
        $this->workScheduleMapper->expects($this->once())
            ->method('findActiveForEmployees')
            ->with([1, 2], $this->isInstanceOf(DateTime::class))
            ->willReturn([
                1 => $this->makeSchedule(6.3, 28),   // 31.5h
                2 => $this->makeSchedule(8.0, 30),    // 40h
            ]);

        $employees = $this->service->findAll();

        $this->assertSame(31.5, (float)$employees[0]->getWeeklyHours());
        $this->assertSame(28, $employees[0]->getVacationDays());
        $this->assertSame(40.0, (float)$employees[1]->getWeeklyHours());
        $this->assertSame(30, $employees[1]->getVacationDays());
    }

    public function testApplyActiveSchedulesEnrichesExternallyFetchedEmployees(): void {
        // Employees obtained via PermissionService (team view) must also be
        // enriched with the active schedule values.
        $this->workScheduleMapper->expects($this->once())
            ->method('findActiveForEmployees')
            ->with([3], $this->isInstanceOf(DateTime::class))
            ->willReturn([3 => $this->makeSchedule(6.3, 28)]);

        $result = $this->service->applyActiveSchedules([
            $this->makeEmployee(3, '40.00', 30),
        ]);

        $this->assertSame(31.5, (float)$result[0]->getWeeklyHours());
        $this->assertSame(28, $result[0]->getVacationDays());
    }

    // ---- updateMyDefaults: Standard-Projekt/-Beschreibung (Admin-Freigabe) ----

    private function primeMyDefaults(Employee $employee): void {
        $this->employeeMapper->method('findByUserId')->willReturn($employee);
        $this->employeeMapper->method('update')->willReturnArgument(0);
        // findByUserId reichert den Mitarbeiter mit dem aktiven Profil an.
        $this->workScheduleService->method('getScheduleForDate')->willReturn($this->makeSchedule(8.0, 30));
    }

    private function project(int $id): Project {
        $p = new Project();
        $p->setId($id);
        return $p;
    }

    public function testDefaultProjectRejectedWithoutAdminApproval(): void {
        $this->companySettings->method('isEmployeeDefaultProjectAllowed')->willReturn(false);
        $this->primeMyDefaults($this->makeEmployee(1, '40.00', 30));

        $this->expectException(ValidationException::class);
        $this->service->updateMyDefaults('user1', defaultProjectId: 7);
    }

    public function testDefaultDescriptionRejectedWithoutAdminApproval(): void {
        $this->companySettings->method('isEmployeeDefaultDescriptionAllowed')->willReturn(false);
        $this->primeMyDefaults($this->makeEmployee(1, '40.00', 30));

        $this->expectException(ValidationException::class);
        $this->service->updateMyDefaults('user1', defaultDescription: 'Support');
    }

    public function testDefaultProjectMustBeSelectableForEmployee(): void {
        $this->companySettings->method('isEmployeeDefaultProjectAllowed')->willReturn(true);
        $this->projectService->method('getProjectsForEmployee')->willReturn([$this->project(3)]);
        $this->primeMyDefaults($this->makeEmployee(1, '40.00', 30));

        $this->expectException(ValidationException::class);
        $this->service->updateMyDefaults('user1', defaultProjectId: 7);
    }

    public function testDefaultProjectAndDescriptionAreStoredWhenAllowed(): void {
        $this->companySettings->method('isEmployeeDefaultProjectAllowed')->willReturn(true);
        $this->companySettings->method('isEmployeeDefaultDescriptionAllowed')->willReturn(true);
        $this->projectService->method('getProjectsForEmployee')->willReturn([$this->project(7)]);
        $this->primeMyDefaults($this->makeEmployee(1, '40.00', 30));

        $result = $this->service->updateMyDefaults('user1', defaultProjectId: 7, defaultDescription: '  Support  ');

        $this->assertSame(7, $result->getDefaultProjectId());
        $this->assertSame('Support', $result->getDefaultDescription());
    }

    public function testDefaultProjectClearedWithZero(): void {
        $this->companySettings->method('isEmployeeDefaultProjectAllowed')->willReturn(true);
        $employee = $this->makeEmployee(1, '40.00', 30);
        $employee->setDefaultProjectId(7);
        $this->primeMyDefaults($employee);

        $result = $this->service->updateMyDefaults('user1', defaultProjectId: 0);

        $this->assertNull($result->getDefaultProjectId());
    }

    public function testPartialUpdateKeepsDefaultTimes(): void {
        // Regression: ein Partial-Update (nur Sichtbarkeit) darf die
        // gespeicherten Standard-Zeiten nicht löschen.
        $employee = $this->makeEmployee(1, '40.00', 30);
        $employee->setDefaultStartTime(new DateTime('07:30'));
        $employee->setDefaultEndTime(new DateTime('16:30'));
        $this->primeMyDefaults($employee);

        $result = $this->service->updateMyDefaults('user1', absenceVisibility: 'team');

        $this->assertSame('07:30', $result->getDefaultStartTime()?->format('H:i'));
        $this->assertSame('16:30', $result->getDefaultEndTime()?->format('H:i'));
        $this->assertSame('team', $result->getAbsenceVisibility());
    }
}
