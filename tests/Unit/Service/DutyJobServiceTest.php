<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\Absence;
use OCA\Zeitwerk\Db\DutyJob;
use OCA\Zeitwerk\Db\DutyJobMapper;
use OCA\Zeitwerk\Db\DutyWeekLock;
use OCA\Zeitwerk\Db\DutyWeekLockMapper;
use OCA\Zeitwerk\Db\Employee;
use OCA\Zeitwerk\Db\EmployeeMapper;
use OCA\Zeitwerk\Db\Holiday;
use OCA\Zeitwerk\Service\AbsenceService;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\DutyJobService;
use OCA\Zeitwerk\Service\ForbiddenException;
use OCA\Zeitwerk\Service\HolidayService;
use OCA\Zeitwerk\Service\NotFoundException;
use OCA\Zeitwerk\Service\PermissionService;
use OCA\Zeitwerk\Service\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class DutyJobServiceTest extends TestCase {

    private DutyJobMapper $jobMapper;
    private EmployeeMapper $employeeMapper;
    private AbsenceService $absenceService;
    private HolidayService $holidayService;
    private PermissionService $permissionService;
    private AuditLogService $auditLogService;
    private CompanySettingsService $settingsService;
    private DutyWeekLockMapper $lockMapper;
    private IUserManager $userManager;
    private IConfig $config;
    private bool $templatesSidebar = true;
    private string $templatesUserPref = '';
    /** @var string[] locked Mondays (Y-m-d) */
    private array $lockedWeeks = [];
    private DutyJobService $service;
    private bool $absenceRowVisible = true;

    protected function setUp(): void {
        $this->jobMapper = $this->createMock(DutyJobMapper::class);
        $this->employeeMapper = $this->createMock(EmployeeMapper::class);
        $this->absenceService = $this->createMock(AbsenceService::class);
        $this->absenceRowVisible = true;
        $this->absenceService->method('isEmployeeVisibleInOverview')
            ->willReturnCallback(fn (): bool => $this->absenceRowVisible);
        $this->holidayService = $this->createMock(HolidayService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->settingsService = $this->createMock(CompanySettingsService::class);
        $this->settingsService->method('isDutyRosterTemplatesSidebarEnabled')
            ->willReturnCallback(fn (): bool => $this->templatesSidebar);
        $this->lockMapper = $this->createMock(DutyWeekLockMapper::class);
        $this->lockMapper->method('findByWeekStart')->willReturnCallback(function (DateTime $monday): DutyWeekLock {
            if (!in_array($monday->format('Y-m-d'), $this->lockedWeeks, true)) {
                throw new DoesNotExistException('open');
            }
            $lock = new DutyWeekLock();
            $lock->setWeekStart(clone $monday);
            $lock->setLockedBy('hr1');
            $lock->setLockedAt(new DateTime('2026-09-15 08:00:00'));
            return $lock;
        });
        $this->userManager = $this->createMock(IUserManager::class);
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getUserValue')->willReturnCallback(fn (): string => $this->templatesUserPref);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf($s, $p));

        $this->service = new DutyJobService(
            $this->jobMapper,
            $this->employeeMapper,
            $this->absenceService,
            $this->holidayService,
            $this->permissionService,
            $this->auditLogService,
            $l10n,
            $this->settingsService,
            $this->lockMapper,
            $this->userManager,
            $this->config,
        );
    }

    private function makeEmployee(int $id, bool $inRoster = true, string $region = 'DE-BW', string $userId = 'u', string $visibility = 'all', string $detail = 'masked'): Employee {
        $e = new Employee();
        $e->setAbsenceVisibility($visibility);
        $e->setAbsenceDetail($detail);
        $e->setId($id);
        $e->setUserId($userId . $id);
        $e->setFirstName('F' . $id);
        $e->setLastName('L' . $id);
        $e->setIsActive(true);
        $e->setInDutyRoster($inRoster);
        $e->setFederalState($region);
        return $e;
    }

    private function makeJob(int $id, int $employeeId, string $date, ?string $time, string $title): DutyJob {
        $j = new DutyJob();
        $j->setId($id);
        $j->setEmployeeId($employeeId);
        $j->setJobDate(new DateTime($date));
        $j->setStartTime($time);
        $j->setTitle($title);
        return $j;
    }

    private function makeAbsence(string $from, string $to, string $type, string $status, float $scope = 1.0): Absence {
        $a = new Absence();
        $a->setEmployeeId(1);
        $a->setType($type);
        $a->setStartDate(new DateTime($from));
        $a->setEndDate(new DateTime($to));
        $a->setStatus($status);
        $a->setScope((string)$scope);
        return $a;
    }

    private function validData(array $override = []): array {
        return array_merge([
            'employeeId' => 1,
            'date' => '2026-09-15',
            'startTime' => '09:00',
            'durationMinutes' => 60,
            'title' => 'CarTech',
            'note' => null,
            'onCall' => false,
        ], $override);
    }

    // --- mondayOf ---

    public function testMondayOfNormalizesAnyWeekday(): void {
        $this->assertSame('2026-09-14', DutyJobService::mondayOf(new DateTime('2026-09-20'))->format('Y-m-d')); // Sunday
        $this->assertSame('2026-09-14', DutyJobService::mondayOf(new DateTime('2026-09-14'))->format('Y-m-d')); // Monday
        $this->assertSame('2025-12-29', DutyJobService::mondayOf(new DateTime('2026-01-01'))->format('Y-m-d')); // year boundary
    }

    // --- validation ---

    public function testCreateRejectsEmptyTitle(): void {
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        $this->expectException(ValidationException::class);
        $this->service->create($this->validData(['title' => '   ']), 'admin');
    }

    public function testCreateRejectsBadTimeAndDate(): void {
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        try {
            $this->service->create($this->validData(['startTime' => '25:00', 'date' => '2026-02-30']), 'admin');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue($e->hasError('startTime'));
            $this->assertTrue($e->hasError('date'));
        }
    }

    public function testCreateRejectsDurationOutOfRange(): void {
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        foreach ([0, 1441] as $bad) {
            try {
                $this->service->create($this->validData(['durationMinutes' => $bad]), 'admin');
                $this->fail('expected ValidationException for ' . $bad);
            } catch (ValidationException $e) {
                $this->assertTrue($e->hasError('durationMinutes'));
            }
        }
    }

    public function testCreateRejectsEmployeeNotInRoster(): void {
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1, false));
        try {
            $this->service->create($this->validData(), 'admin');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue($e->hasError('employeeId'));
        }
    }

    public function testCreateAllowsEmptyTimeAndDurationAndLogsAudit(): void {
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        $this->jobMapper->method('insert')->willReturnCallback(function (DutyJob $j) { $j->setId(11); return $j; });
        $this->auditLogService->expects($this->once())->method('logCreate')
            ->with('admin', 'duty_job', 11, $this->anything());

        $job = $this->service->create($this->validData(['startTime' => null, 'durationMinutes' => null]), 'admin');

        $this->assertSame(11, $job->getId());
        $this->assertNull($job->getStartTime());
        $this->assertSame('admin', $job->getCreatedBy());
    }

    // --- update / move / delete ---

    public function testUpdateUnknownIdThrowsNotFound(): void {
        $this->jobMapper->method('find')->willThrowException(new DoesNotExistException(''));
        $this->expectException(NotFoundException::class);
        $this->service->update(99, $this->validData(), 'admin');
    }

    public function testMoveToSameCellDoesNothing(): void {
        $this->jobMapper->method('find')->willReturn($this->makeJob(5, 1, '2026-09-15', '09:00', 'X'));
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        $this->jobMapper->expects($this->never())->method('update');
        $this->auditLogService->expects($this->never())->method('logUpdate');

        $job = $this->service->move(5, 1, '2026-09-15', 'admin');
        $this->assertSame(5, $job->getId());
    }

    public function testMoveChangesEmployeeAndDateWithAudit(): void {
        $this->jobMapper->method('find')->willReturn($this->makeJob(5, 1, '2026-09-15', '09:00', 'X'));
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(2));
        $this->jobMapper->method('update')->willReturnArgument(0);
        $this->auditLogService->expects($this->once())->method('logUpdate');

        $job = $this->service->move(5, 2, '2026-09-16', 'admin');
        $this->assertSame(2, $job->getEmployeeId());
        $this->assertSame('2026-09-16', $job->getJobDate()->format('Y-m-d'));
    }

    public function testMoveToEmployeeOutsideRosterIsRejected(): void {
        $this->jobMapper->method('find')->willReturn($this->makeJob(5, 1, '2026-09-15', '09:00', 'X'));
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(2, false));
        $this->expectException(ValidationException::class);
        $this->service->move(5, 2, '2026-09-16', 'admin');
    }

    public function testDeleteLogsAndDeletes(): void {
        $job = $this->makeJob(5, 1, '2026-09-15', '09:00', 'X');
        $this->jobMapper->method('find')->willReturn($job);
        $this->jobMapper->expects($this->once())->method('delete')->with($job);
        $this->auditLogService->expects($this->once())->method('logDelete')->with('admin', 'duty_job', 5, $this->anything());

        $this->service->delete(5, 'admin');
    }

    // --- getWeek ---

    /**
     * Viewer defaults: not privileged, no employee record, empty subtree.
     */
    private function mockViewer(bool $isPrivileged = false, ?Employee $viewer = null, array $subtree = []): void {
        $this->permissionService->method('isAdmin')->willReturn($isPrivileged);
        $this->permissionService->method('isHrManager')->willReturn(false);
        $this->permissionService->method('getEmployeeForUser')->willReturn($viewer);
        $this->permissionService->method('getSubordinateEmployees')->willReturn($subtree);
    }

    public function testGetWeekOnlyRosterEmployeesAndResolvesAbsencesPerDay(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
        $this->mockViewer(true);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(1)]);
        $this->jobMapper->method('findByDateRange')->willReturn([
            $this->makeJob(1, 1, '2026-09-15', '09:00', 'A'),
            $this->makeJob(2, 7, '2026-09-15', '09:00', 'Fremd'), // employee 7 not in roster: dropped
        ]);
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([
            $this->makeAbsence('2026-09-13', '2026-09-15', 'vacation', 'approved'),   // spans into week: Mon+Tue
            $this->makeAbsence('2026-09-17', '2026-09-17', 'sick', 'pending'),
            $this->makeAbsence('2026-09-18', '2026-09-18', 'sick', 'rejected'),      // filtered out
        ]);
        $holiday = new Holiday();
        $holiday->setDate(new DateTime('2026-09-16'));
        $holiday->setName('Testtag');
        $this->holidayService->method('findHolidaysInRange')->willReturn([$holiday]);

        $week = $this->service->getWeek(new DateTime('2026-09-14'), 'admin');

        $this->assertSame('2026-09-14', $week['weekStart']);
        $this->assertSame('2026-09-20', $week['weekEnd']);
        $this->assertTrue($week['canManage']);
        $this->assertCount(7, $week['days']);
        $this->assertTrue($week['days'][5]['isWeekend']);
        $this->assertCount(1, $week['rows']);
        $row = $week['rows'][0];
        $this->assertSame(1, $row['employee']['id']);
        $this->assertCount(1, $row['jobs']);
        $dates = array_map(fn ($a) => $a['date'] . ':' . $a['status'], $row['absences']);
        $this->assertSame(['2026-09-14:approved', '2026-09-15:approved', '2026-09-17:pending'], $dates);
        $this->assertSame('Urlaub', $row['absences'][0]['typeName']);
        $this->assertSame([['date' => '2026-09-16', 'name' => 'Testtag']], $row['holidays']);
    }

    public function testGetWeekMasksAbsencesForNonPlanner(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(false);
        $this->mockViewer();
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(1)]);
        $this->jobMapper->method('findByDateRange')->willReturn([$this->makeJob(1, 1, '2026-09-15', '09:00', 'A')]);
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([
            $this->makeAbsence('2026-09-15', '2026-09-15', 'sick', 'approved'),
            $this->makeAbsence('2026-09-16', '2026-09-16', 'vacation', 'pending'),
        ]);
        $this->holidayService->method('findHolidaysInRange')->willReturn([]);

        $week = $this->service->getWeek(new DateTime('2026-09-14'), 'worker');

        $this->assertFalse($week['canManage']);
        $absences = $week['rows'][0]['absences'];
        $this->assertCount(1, $absences);
        $this->assertSame('absent', $absences[0]['type']);
        $this->assertSame('Abwesend', $absences[0]['typeName']);
        // L1: non-planners must not see who created a card or when
        $job = $week['rows'][0]['jobs'][0];
        $this->assertArrayNotHasKey('createdBy', $job);
        $this->assertArrayNotHasKey('createdAt', $job);
        $this->assertArrayNotHasKey('updatedAt', $job);
        $this->assertSame('A', $job['title']);
    }

    public function testGetWeekMasksAbsencesForSupervisorOutsideOwnSubtree(): void {
        // canManageDutyRoster is true (planner), but employee 3 is not in the subtree:
        // planning rights must not unmask absence reasons.
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
        $this->mockViewer(false, $this->makeEmployee(2), [$this->makeEmployee(5)]);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(3)]);
        $this->jobMapper->method('findByDateRange')->willReturn([]);
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([
            $this->makeAbsence('2026-09-15', '2026-09-15', 'sick', 'approved'),
            $this->makeAbsence('2026-09-16', '2026-09-16', 'vacation', 'pending'),
        ]);
        $this->holidayService->method('findHolidaysInRange')->willReturn([]);

        $week = $this->service->getWeek(new DateTime('2026-09-14'), 'boss');

        $absences = $week['rows'][0]['absences'];
        $this->assertCount(1, $absences);
        $this->assertSame('absent', $absences[0]['type']);
        $this->assertSame('Abwesend', $absences[0]['typeName']);
    }

    public function testGetWeekShowsFullAbsencesForSupervisorInsideOwnSubtree(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
        $this->mockViewer(false, $this->makeEmployee(2), [$this->makeEmployee(3)]);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(3)]);
        $this->jobMapper->method('findByDateRange')->willReturn([]);
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([
            $this->makeAbsence('2026-09-15', '2026-09-15', 'sick', 'approved'),
            $this->makeAbsence('2026-09-16', '2026-09-16', 'vacation', 'pending'),
        ]);
        $this->holidayService->method('findHolidaysInRange')->willReturn([]);

        $week = $this->service->getWeek(new DateTime('2026-09-14'), 'boss');

        $absences = $week['rows'][0]['absences'];
        $this->assertCount(2, $absences);
        $this->assertSame('sick', $absences[0]['type']);
        $this->assertSame('pending', $absences[1]['status']);
        $this->assertSame('vacation', $absences[1]['type']);
    }

    public function testGetWeekHidesAbsencesButKeepsRowWhenNotVisible(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(false);
        $this->mockViewer(false, $this->makeEmployee(2));
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(3, true, 'DE-BW', 'u', 'none')]);
        $this->jobMapper->method('findByDateRange')->willReturn([$this->makeJob(1, 3, '2026-09-15', '09:00', 'A')]);
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([
            $this->makeAbsence('2026-09-15', '2026-09-15', 'sick', 'approved'),
        ]);
        $this->holidayService->method('findHolidaysInRange')->willReturn([]);

        // The real visibility rule decides: 'none' hides the absences of employee 3.
        $this->absenceRowVisible = false;

        $week = $this->service->getWeek(new DateTime('2026-09-14'), 'worker');

        $this->assertCount(1, $week['rows']);
        $this->assertSame(3, $week['rows'][0]['employee']['id']);
        $this->assertSame([], $week['rows'][0]['absences']);
        $this->assertCount(1, $week['rows'][0]['jobs']);
    }

    public function testGetWeekDetailedAbsenceDetailShowsTypeButNoPending(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(false);
        $this->mockViewer(false, $this->makeEmployee(2));
        $this->employeeMapper->method('findAllActiveInDutyRoster')
            ->willReturn([$this->makeEmployee(3, true, 'DE-BW', 'u', 'all', 'detailed')]);
        $this->jobMapper->method('findByDateRange')->willReturn([]);
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([
            $this->makeAbsence('2026-09-15', '2026-09-15', 'sick', 'approved'),
            $this->makeAbsence('2026-09-16', '2026-09-16', 'vacation', 'pending'),
        ]);
        $this->holidayService->method('findHolidaysInRange')->willReturn([]);

        $week = $this->service->getWeek(new DateTime('2026-09-14'), 'worker');

        $absences = $week['rows'][0]['absences'];
        $this->assertCount(1, $absences);
        $this->assertSame('sick', $absences[0]['type']);
        $this->assertSame('approved', $absences[0]['status']);
    }

    public function testGetWeekOwnRowIsAlwaysUnmasked(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(false);
        $this->mockViewer(false, $this->makeEmployee(3));
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(3)]);
        $this->jobMapper->method('findByDateRange')->willReturn([]);
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([
            $this->makeAbsence('2026-09-15', '2026-09-15', 'sick', 'approved'),
            $this->makeAbsence('2026-09-16', '2026-09-16', 'vacation', 'pending'),
        ]);
        $this->holidayService->method('findHolidaysInRange')->willReturn([]);

        $week = $this->service->getWeek(new DateTime('2026-09-14'), 'worker');

        $absences = $week['rows'][0]['absences'];
        $this->assertCount(2, $absences);
        $this->assertSame('sick', $absences[0]['type']);
        $this->assertSame('vacation', $absences[1]['type']);
    }

    public function testGetWeekClampsAbsenceEndAtSundayWithoutMutating(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
        $this->mockViewer(true);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(1)]);
        $this->jobMapper->method('findByDateRange')->willReturn([]);
        $absence = $this->makeAbsence('2026-09-19', '2026-09-25', 'vacation', 'approved');
        $this->absenceService->method('findByEmployeeAndDateRange')->willReturn([$absence]);
        $this->holidayService->method('findHolidaysInRange')->willReturn([]);

        $monday = new DateTime('2026-09-14');
        $week = $this->service->getWeek($monday, 'admin');

        $dates = array_map(fn ($a) => $a['date'], $week['rows'][0]['absences']);
        $this->assertSame(['2026-09-19', '2026-09-20'], $dates);
        // clamp must not mutate the source Absence or the caller's $monday
        $this->assertSame('2026-09-25', $absence->getEndDate()->format('Y-m-d'));
        $this->assertSame('2026-09-14', $monday->format('Y-m-d'));
    }

    // --- copyWeek ---

    public function testCopyWeekSkipsDuplicatesAndCounts(): void {
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(1)]);
        $this->jobMapper->method('findByDateRange')->willReturnCallback(function (DateTime $from) {
            if ($from->format('Y-m-d') === '2026-09-14') {
                return [
                    $this->makeJob(1, 1, '2026-09-15', '09:00', 'A'),
                    $this->makeJob(2, 1, '2026-09-16', null, 'B'),
                ];
            }
            return [$this->makeJob(9, 1, '2026-09-22', '09:00', 'A')]; // already in target week
        });
        $inserted = [];
        $this->jobMapper->method('insert')->willReturnCallback(function (DutyJob $j) use (&$inserted) { $inserted[] = $j; $j->setId(100 + count($inserted)); return $j; });
        $this->auditLogService->expects($this->once())->method('log');

        $count = $this->service->copyWeek(new DateTime('2026-09-14'), new DateTime('2026-09-21'), 'admin');

        $this->assertSame(1, $count);
        $this->assertSame('2026-09-23', $inserted[0]->getJobDate()->format('Y-m-d'));
        $this->assertSame('B', $inserted[0]->getTitle());
        $this->assertSame('admin', $inserted[0]->getCreatedBy());
    }

    public function testCopyWeekDedupeIsCaseInsensitive(): void {
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(1)]);
        $this->jobMapper->method('findByDateRange')->willReturnCallback(function (DateTime $from) {
            if ($from->format('Y-m-d') === '2026-09-14') {
                return [$this->makeJob(1, 1, '2026-09-15', '09:00', 'CarTech')];
            }
            return [$this->makeJob(9, 1, '2026-09-22', '09:00', 'cartech')]; // same cell, different case
        });
        $this->jobMapper->expects($this->never())->method('insert');
        $this->auditLogService->expects($this->once())->method('log');

        $count = $this->service->copyWeek(new DateTime('2026-09-14'), new DateTime('2026-09-21'), 'admin');

        $this->assertSame(0, $count);
    }

    public function testCopyWeekSameWeekReturnsZeroWithoutAudit(): void {
        $this->jobMapper->expects($this->never())->method('findByDateRange');
        $this->auditLogService->expects($this->never())->method('log');

        $count = $this->service->copyWeek(new DateTime('2026-09-14'), new DateTime('2026-09-16'), 'admin');

        $this->assertSame(0, $count);
    }

    // --- suggestTitles ---

    public function testSuggestTitlesRequiresPrefix(): void {
        $this->jobMapper->expects($this->never())->method('findDistinctTitles');
        $this->assertSame([], $this->service->suggestTitles('  '));
    }

    public function testSuggestTitlesDelegatesWithLimit(): void {
        $this->jobMapper->expects($this->once())->method('findDistinctTitles')->with('car', 10)->willReturn(['CarTech']);
        $this->assertSame(['CarTech'], $this->service->suggestTitles('car'));
    }
    public function testGetWeekShowTemplatesFollowsSettingForPlanners(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
        $this->mockViewer(true);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([]);

        $this->templatesSidebar = true;
        $this->assertTrue($this->service->getWeek(new DateTime('2026-09-14'), 'admin')['showTemplates']);

        $this->templatesSidebar = false;
        $this->assertFalse($this->service->getWeek(new DateTime('2026-09-14'), 'admin')['showTemplates']);
    }

    public function testUserPreferenceOverridesCompanyDefaultForTemplates(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
        $this->mockViewer(true);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([]);

        $this->templatesSidebar = true;
        $this->templatesUserPref = '0';
        $this->assertFalse($this->service->getWeek(new DateTime('2026-09-14'), 'admin')['showTemplates']);

        $this->templatesSidebar = false;
        $this->templatesUserPref = '1';
        $this->assertTrue($this->service->getWeek(new DateTime('2026-09-14'), 'admin')['showTemplates']);

        $this->config->expects($this->once())->method('setUserValue')->with('admin', 'zeitwerk', 'duty_roster_templates_sidebar', '0');
        $this->service->setTemplatesSidebarVisible('admin', false);
    }

    public function testGetWeekShowTemplatesFalseForReaders(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(false);
        $this->mockViewer(false);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([]);
        $this->templatesSidebar = true;

        $this->assertFalse($this->service->getWeek(new DateTime('2026-09-14'), 'user')['showTemplates']);
    }
    // ---- Wochensperre ----

    public function testGetWeekCarriesLockInfoWithDisplayName(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
        $this->permissionService->method('canUnlockDutyWeek')->willReturn(false);
        $this->mockViewer(false);
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([]);
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Hanna HR');
        $this->userManager->method('get')->with('hr1')->willReturn($user);
        $this->lockedWeeks = ['2026-09-14'];

        $week = $this->service->getWeek(new DateTime('2026-09-16'), 'sup');

        $this->assertTrue($week['locked']);
        $this->assertSame('Hanna HR', $week['lockedBy']);
        $this->assertStringStartsWith('2026-09-15T08:00:00', $week['lockedAt']);
        $this->assertFalse($week['canUnlock']);
        $this->assertFalse($week['canExportPdf']);

        $this->lockedWeeks = [];
        $open = $this->service->getWeek(new DateTime('2026-09-16'), 'sup');
        $this->assertFalse($open['locked']);
        $this->assertNull($open['lockedBy']);
    }

    public function testLockWeekInsertsOnceAndIsIdempotent(): void {
        $this->lockMapper->expects($this->once())->method('insert')->willReturnCallback(function (DutyWeekLock $lock): DutyWeekLock {
            $this->assertSame('2026-09-14', $lock->getWeekStart()->format('Y-m-d'));
            $this->assertSame('sup', $lock->getLockedBy());
            $this->lockedWeeks[] = '2026-09-14';
            return $lock;
        });
        $this->auditLogService->expects($this->once())->method('log')
            ->with('sup', 'lock_week', 'duty_job', null, null, ['weekStart' => '2026-09-14']);

        $first = $this->service->lockWeek(new DateTime('2026-09-17'), 'sup'); // Thursday -> Monday
        $this->assertTrue($first['locked']);
        $second = $this->service->lockWeek(new DateTime('2026-09-14'), 'sup');
        $this->assertTrue($second['locked']);
    }

    public function testUnlockWeekDeletesAndIsNoopWhenOpen(): void {
        $this->lockedWeeks = ['2026-09-14'];
        $this->lockMapper->expects($this->once())->method('delete')->willReturnCallback(function (DutyWeekLock $lock): DutyWeekLock {
            $this->lockedWeeks = [];
            return $lock;
        });
        $this->auditLogService->expects($this->once())->method('log')
            ->with('admin', 'unlock_week', 'duty_job', null, ['weekStart' => '2026-09-14', 'lockedBy' => 'hr1'], null);

        $this->assertFalse($this->service->unlockWeek(new DateTime('2026-09-14'), 'admin')['locked']);
        $this->assertFalse($this->service->unlockWeek(new DateTime('2026-09-14'), 'admin')['locked']);
    }

    public function testCreateRejectsLockedWeek(): void {
        $this->lockedWeeks = ['2026-09-14'];
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        $this->jobMapper->expects($this->never())->method('insert');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Woche ist gesperrt');
        $this->service->create(['employeeId' => 1, 'date' => '2026-09-18', 'title' => 'X'], 'sup');
    }

    public function testUpdateRejectsWhenSourceOrTargetWeekLocked(): void {
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        $this->jobMapper->method('find')->willReturn($this->makeJob(5, 1, '2026-09-15', '09:00', 'A'));
        $this->jobMapper->expects($this->never())->method('update');

        // target week locked, source open
        $this->lockedWeeks = ['2026-09-21'];
        try {
            $this->service->update(5, ['employeeId' => 1, 'date' => '2026-09-22', 'title' => 'A'], 'sup');
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }
        // source week locked, target open
        $this->lockedWeeks = ['2026-09-14'];
        $this->expectException(ForbiddenException::class);
        $this->service->update(5, ['employeeId' => 1, 'date' => '2026-09-22', 'title' => 'A'], 'sup');
    }

    public function testMoveAndDeleteRejectLockedWeek(): void {
        $this->employeeMapper->method('find')->willReturn($this->makeEmployee(1));
        $this->jobMapper->method('find')->willReturn($this->makeJob(5, 1, '2026-09-15', '09:00', 'A'));
        $this->jobMapper->expects($this->never())->method('update');
        $this->jobMapper->expects($this->never())->method('delete');
        $this->lockedWeeks = ['2026-09-14'];

        try {
            $this->service->move(5, 1, '2026-09-16', 'sup');
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }
        $this->expectException(ForbiddenException::class);
        $this->service->delete(5, 'sup');
    }

    public function testCopyWeekRejectsLockedTargetButAllowsLockedSource(): void {
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(1)]);
        $this->jobMapper->method('findByDateRange')->willReturnCallback(function (DateTime $from): array {
            return $from->format('Y-m-d') === '2026-09-14' ? [$this->makeJob(1, 1, '2026-09-15', '09:00', 'A')] : [];
        });

        $this->lockedWeeks = ['2026-09-14']; // source locked: fine
        $this->jobMapper->expects($this->once())->method('insert')->willReturnArgument(0);
        $this->assertSame(1, $this->service->copyWeek(new DateTime('2026-09-14'), new DateTime('2026-09-21'), 'sup'));

        $this->lockedWeeks = ['2026-09-21']; // target locked
        $this->expectException(ForbiddenException::class);
        $this->service->copyWeek(new DateTime('2026-09-14'), new DateTime('2026-09-21'), 'sup');
    }
}
