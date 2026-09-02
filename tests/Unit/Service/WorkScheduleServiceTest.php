<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\WorkSchedule;
use OCA\Zeitwerk\Db\WorkScheduleMapper;
use OCA\Zeitwerk\Db\EmployeeMapper;
use OCA\Zeitwerk\Db\TimeEntryMapper;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\ValidationException;
use OCA\Zeitwerk\Service\WorkScheduleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for issue #281: the annual vacation entitlement must equal
 * the value of the work-schedule profile that is valid for the year, identical
 * across the profile editor, the employee overview and the team view. It must
 * NOT be pro-rated/blended across an overlapping (e.g. auto-created default)
 * profile, which previously produced surprising numbers such as 21 instead of 14.
 */
class WorkScheduleServiceTest extends TestCase {

    private WorkScheduleService $service;
    private WorkScheduleMapper $mapper;
    private TimeEntryMapper $timeEntryMapper;

    protected function setUp(): void {
        $this->mapper = $this->createMock(WorkScheduleMapper::class);
        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        // Default: no time entries anywhere, so no month counts as approved.
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn(self::summary(0, 0));

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn (string $text, $params = []): string => vsprintf($text, (array)$params)
        );

        $this->service = new WorkScheduleService(
            $this->mapper,
            $this->createMock(EmployeeMapper::class),
            $this->createMock(CompanySettingsService::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(LoggerInterface::class),
            $l,
            $this->timeEntryMapper,
        );
    }

    /**
     * @return array{draft: int, submitted: int, approved: int, rejected: int}
     */
    private static function summary(int $approved, int $draft): array {
        return ['draft' => $draft, 'submitted' => 0, 'approved' => $approved, 'rejected' => 0];
    }

    /** First day of the month $monthsAgo months before the current month. */
    private static function firstOfMonthsAgo(int $monthsAgo): DateTime {
        $d = new DateTime('first day of this month');
        $d->setTime(0, 0, 0);
        $d->modify("-{$monthsAgo} months");
        return $d;
    }

    private static function ym(DateTime $d): array {
        return [(int)$d->format('Y'), (int)$d->format('n')];
    }

    private function scheduleAt(DateTime $validFrom, int $id): WorkSchedule {
        $s = new WorkSchedule();
        $s->setId($id);
        $s->setEmployeeId(1);
        $s->setValidFrom(clone $validFrom);
        return $s;
    }

    private const DAY_HOURS = ['mon' => 7.7, 'tue' => 7.7, 'wed' => 7.7, 'thu' => 7.7, 'fri' => 7.7, 'sat' => 0, 'sun' => 0];

    private function schedule(int $vacationDays): WorkSchedule {
        $s = new WorkSchedule();
        $s->setEmployeeId(1);
        $s->setValidFrom(new DateTime('2020-01-01'));
        $s->setVacationDays($vacationDays);
        return $s;
    }

    /**
     * The entitlement equals the valid profile's value – not a blend with any
     * earlier/overlapping profile. We query a past year so the reference date is
     * deterministic (the year's end), independent of the current date.
     */
    public function testReturnsValidProfileVacationDaysWithoutBlending(): void {
        $pastYear = (int)(new DateTime())->format('Y') - 1;

        // Whatever date is asked for in that year, the valid profile has 14 days.
        $this->mapper->method('findForDate')->willReturn($this->schedule(14));

        $this->assertSame(14, $this->service->getVacationDaysForYear(1, $pastYear));
    }

    /**
     * With no persisted schedule, getScheduleForDate falls back to a default
     * (30 days), so the entitlement is the default rather than an error.
     */
    public function testFallsBackToDefaultWhenNoScheduleExists(): void {
        $pastYear = (int)(new DateTime())->format('Y') - 1;

        $this->mapper->method('findForDate')
            ->willThrowException(new DoesNotExistException('none'));

        $this->assertSame(30, $this->service->getVacationDaysForYear(1, $pastYear));
    }

    /**
     * For a past year the reference date is that year's 31 December, ensuring the
     * profile valid back then drives the entitlement.
     */
    public function testPastYearUsesYearEndAsReference(): void {
        $pastYear = (int)(new DateTime())->format('Y') - 1;
        $expectedReference = $pastYear . '-12-31';

        $this->mapper->expects($this->once())
            ->method('findForDate')
            ->with(
                $this->equalTo(1),
                $this->callback(fn (DateTime $d): bool => $d->format('Y-m-d') === $expectedReference),
            )
            ->willReturn($this->schedule(20));

        $this->assertSame(20, $this->service->getVacationDaysForYear(1, $pastYear));
    }

    // ---- Backdating (valid_from in the past) ----

    /**
     * HR must be able to create a profile whose valid_from lies before the
     * current month, e.g. to fix a profile that should have applied from the
     * entry date. The old "no earlier than the 1st of the current month" rule
     * is gone.
     */
    public function testCreateAcceptsValidFromBeforeCurrentMonth(): void {
        $validFrom = self::firstOfMonthsAgo(2)->modify('+19 days');
        $this->mapper->method('findByEmployeeId')->willReturn([]);
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (WorkSchedule $s): WorkSchedule => $s);

        $created = $this->service->create(1, $validFrom->format('Y-m-d'), self::DAY_HOURS, 30, 'hr');

        $this->assertSame($validFrom->format('Y-m-d'), $created->getValidFrom()->format('Y-m-d'));
    }

    /**
     * A backdated profile changes the target hours of every month from its
     * valid_from onwards. If one of those months is fully approved, the change
     * is refused: HR has to reopen the month first so the approval chain stays
     * honest. The error names the affected month.
     */
    public function testCreateIsRejectedWhenAffectedMonthIsApproved(): void {
        $approvedMonth = self::firstOfMonthsAgo(2);
        [$y, $m] = self::ym($approvedMonth);

        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')
            ->willReturnCallback(static fn (int $e, int $year, int $month): array =>
                ($year === $y && $month === $m) ? self::summary(5, 0) : self::summary(0, 0));
        $this->setUpServiceWithCurrentMocks();

        $this->mapper->method('findByEmployeeId')->willReturn([]);
        $this->mapper->expects($this->never())->method('insert');

        try {
            $this->service->create(1, $approvedMonth->modify('+19 days')->format('Y-m-d'), self::DAY_HOURS, 30, 'hr');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('period', $errors);
            $this->assertStringContainsString(sprintf('%02d/%d', $m, $y), $errors['period'][0]);
        }
    }

    /**
     * The affected range ends the day before the next (later) profile: an
     * approved month that is already covered by a later profile is untouched
     * by the new one and must not block it.
     */
    public function testCreateIgnoresApprovedMonthsCoveredByLaterProfile(): void {
        $newFrom = self::firstOfMonthsAgo(3);
        $laterProfileFrom = self::firstOfMonthsAgo(1);
        [$y, $m] = self::ym($laterProfileFrom);

        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')
            ->willReturnCallback(static fn (int $e, int $year, int $month): array =>
                ($year === $y && $month === $m) ? self::summary(5, 0) : self::summary(0, 0));
        $this->setUpServiceWithCurrentMocks();

        $this->mapper->method('findByEmployeeId')->willReturn([$this->scheduleAt($laterProfileFrom, 7)]);
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (WorkSchedule $s): WorkSchedule => $s);

        $this->service->create(1, $newFrom->format('Y-m-d'), self::DAY_HOURS, 30, 'hr');
    }

    /**
     * A month with a mix of approved and open entries is not "fully approved"
     * and therefore does not block the change (HR corrects open months freely).
     */
    public function testCreateAllowsPartiallyApprovedMonth(): void {
        $month = self::firstOfMonthsAgo(1);
        [$y, $m] = self::ym($month);

        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')
            ->willReturnCallback(static fn (int $e, int $year, int $mo): array =>
                ($year === $y && $mo === $m) ? self::summary(3, 2) : self::summary(0, 0));
        $this->setUpServiceWithCurrentMocks();

        $this->mapper->method('findByEmployeeId')->willReturn([]);
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static fn (WorkSchedule $s): WorkSchedule => $s);

        $this->service->create(1, $month->format('Y-m-d'), self::DAY_HOURS, 30, 'hr');
    }

    /** Rebuild the service after a test swapped one of the mocks. */
    private function setUpServiceWithCurrentMocks(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn (string $text, $params = []): string => vsprintf($text, (array)$params)
        );
        $this->service = new WorkScheduleService(
            $this->mapper,
            $this->createMock(EmployeeMapper::class),
            $this->createMock(CompanySettingsService::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(LoggerInterface::class),
            $l,
            $this->timeEntryMapper,
        );
    }

    // ---- Editing valid_from / guard on update and delete ----

    /**
     * The valid-from date of an existing profile is editable, so a profile that
     * was created "from the 1st of next month" can be moved back to the entry
     * date instead of being deleted and recreated.
     */
    public function testUpdateChangesValidFrom(): void {
        $profile = $this->scheduleAt(self::firstOfMonthsAgo(0), 5);
        $newFrom = self::firstOfMonthsAgo(1)->modify('+19 days');

        $this->mapper->method('find')->willReturn($profile);
        $this->mapper->method('findByEmployeeId')->willReturn([$profile]);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static fn (WorkSchedule $s): WorkSchedule => $s);

        $updated = $this->service->update(5, 1, self::DAY_HOURS, 30, 'hr', $newFrom->format('Y-m-d'));

        $this->assertSame($newFrom->format('Y-m-d'), $updated->getValidFrom()->format('Y-m-d'));
    }

    /** Omitting valid_from on update keeps the stored date (partial update). */
    public function testUpdateWithoutValidFromKeepsDate(): void {
        $from = self::firstOfMonthsAgo(0);
        $profile = $this->scheduleAt($from, 5);

        $this->mapper->method('find')->willReturn($profile);
        $this->mapper->method('findByEmployeeId')->willReturn([$profile]);
        $this->mapper->method('update')->willReturnCallback(static fn (WorkSchedule $s): WorkSchedule => $s);

        $updated = $this->service->update(5, 1, self::DAY_HOURS, 30, 'hr');

        $this->assertSame($from->format('Y-m-d'), $updated->getValidFrom()->format('Y-m-d'));
    }

    /** Two profiles of one employee can never share a valid-from date. */
    public function testUpdateRejectsDuplicateValidFrom(): void {
        $profile = $this->scheduleAt(self::firstOfMonthsAgo(0), 5);
        $other = $this->scheduleAt(self::firstOfMonthsAgo(1), 6);

        $this->mapper->method('find')->willReturn($profile);
        $this->mapper->method('findByEmployeeId')->willReturn([$profile, $other]);
        $this->mapper->expects($this->never())->method('update');

        try {
            $this->service->update(5, 1, self::DAY_HOURS, 30, 'hr', $other->getValidFrom()->format('Y-m-d'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('validFrom', $e->getErrors());
        }
    }

    /**
     * Moving a profile back over a fully approved month is refused just like
     * creating a backdated one.
     */
    public function testUpdateIsRejectedWhenAffectedMonthIsApproved(): void {
        $approvedMonth = self::firstOfMonthsAgo(2);
        [$y, $m] = self::ym($approvedMonth);
        $this->useApprovedMonth($y, $m);

        $profile = $this->scheduleAt(self::firstOfMonthsAgo(0), 5);
        $this->mapper->method('find')->willReturn($profile);
        $this->mapper->method('findByEmployeeId')->willReturn([$profile]);
        $this->mapper->expects($this->never())->method('update');

        try {
            $this->service->update(5, 1, self::DAY_HOURS, 30, 'hr', $approvedMonth->format('Y-m-d'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->getErrors());
        }
    }

    /**
     * Changing only the hours of a profile that covers an approved month
     * alters that month's target just the same, so it is guarded too.
     */
    public function testHoursOnlyUpdateIsRejectedWhenAffectedMonthIsApproved(): void {
        $approvedMonth = self::firstOfMonthsAgo(2);
        [$y, $m] = self::ym($approvedMonth);
        $this->useApprovedMonth($y, $m);

        $profile = $this->scheduleAt($approvedMonth, 5);
        $this->mapper->method('find')->willReturn($profile);
        $this->mapper->method('findByEmployeeId')->willReturn([$profile]);
        $this->mapper->expects($this->never())->method('update');

        $this->expectException(ValidationException::class);
        $this->service->update(5, 1, self::DAY_HOURS, 30, 'hr');
    }

    /** Deleting a profile that covers an approved month is refused as well. */
    public function testDeleteIsRejectedWhenAffectedMonthIsApproved(): void {
        $approvedMonth = self::firstOfMonthsAgo(2);
        [$y, $m] = self::ym($approvedMonth);
        $this->useApprovedMonth($y, $m);

        $profile = $this->scheduleAt($approvedMonth, 5);
        $older = $this->scheduleAt(new DateTime('2020-01-01'), 6);
        $this->mapper->method('find')->willReturn($profile);
        $this->mapper->method('findByEmployeeId')->willReturn([$profile, $older]);
        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(ValidationException::class);
        $this->service->delete(5, 1, 'hr');
    }

    /** Make exactly one month count as fully approved. */
    private function useApprovedMonth(int $y, int $m): void {
        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')
            ->willReturnCallback(static fn (int $e, int $year, int $month): array =>
                ($year === $y && $month === $m) ? self::summary(5, 0) : self::summary(0, 0));
        $this->setUpServiceWithCurrentMocks();
    }
}
