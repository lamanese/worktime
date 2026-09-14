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
use OCA\Zeitwerk\Db\Employee;
use OCA\Zeitwerk\Db\EmployeeMapper;
use OCA\Zeitwerk\Db\Holiday;
use OCA\Zeitwerk\Service\AbsenceService;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\DutyJobService;
use OCA\Zeitwerk\Service\HolidayService;
use OCA\Zeitwerk\Service\NotFoundException;
use OCA\Zeitwerk\Service\PermissionService;
use OCA\Zeitwerk\Service\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DutyJobServiceTest extends TestCase {

    private DutyJobMapper $jobMapper;
    private EmployeeMapper $employeeMapper;
    private AbsenceService $absenceService;
    private HolidayService $holidayService;
    private PermissionService $permissionService;
    private AuditLogService $auditLogService;
    private DutyJobService $service;

    protected function setUp(): void {
        $this->jobMapper = $this->createMock(DutyJobMapper::class);
        $this->employeeMapper = $this->createMock(EmployeeMapper::class);
        $this->absenceService = $this->createMock(AbsenceService::class);
        $this->holidayService = $this->createMock(HolidayService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
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
            $this->createMock(LoggerInterface::class),
        );
    }

    private function makeEmployee(int $id, bool $inRoster = true, string $region = 'DE-BW', string $userId = 'u'): Employee {
        $e = new Employee();
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

    public function testGetWeekOnlyRosterEmployeesAndResolvesAbsencesPerDay(): void {
        $this->permissionService->method('canManageDutyRoster')->willReturn(true);
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
        $this->employeeMapper->method('findAllActiveInDutyRoster')->willReturn([$this->makeEmployee(1)]);
        $this->jobMapper->method('findByDateRange')->willReturn([]);
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

    // --- suggestTitles ---

    public function testSuggestTitlesRequiresPrefix(): void {
        $this->jobMapper->expects($this->never())->method('findDistinctTitles');
        $this->assertSame([], $this->service->suggestTitles('  '));
    }

    public function testSuggestTitlesDelegatesWithLimit(): void {
        $this->jobMapper->expects($this->once())->method('findDistinctTitles')->with('car', 10)->willReturn(['CarTech']);
        $this->assertSame(['CarTech'], $this->service->suggestTitles('car'));
    }
}
