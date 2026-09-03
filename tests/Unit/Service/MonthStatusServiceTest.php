<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\Absence;
use OCA\Zeitwerk\Db\AbsenceMapper;
use OCA\Zeitwerk\Db\MonthStatus;
use OCA\Zeitwerk\Db\MonthStatusMapper;
use OCA\Zeitwerk\Db\TimeEntryMapper;
use OCA\Zeitwerk\Service\MonthStatusService;
use OCA\Zeitwerk\Service\ValidationException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class MonthStatusServiceTest extends TestCase {

    private MonthStatusMapper $mapper;
    private TimeEntryMapper $timeEntryMapper;
    private AbsenceMapper $absenceMapper;
    private MonthStatusService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(MonthStatusMapper::class);
        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        $this->absenceMapper = $this->createMock(AbsenceMapper::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn(string $text, array $p = []): string => $text);
        $this->service = new MonthStatusService($this->mapper, $this->timeEntryMapper, $this->absenceMapper, $l);
    }

    private function row(string $status): MonthStatus {
        $row = new MonthStatus();
        $row->setId(11);
        $row->setEmployeeId(1);
        $row->setYear(2026);
        $row->setMonth(8);
        $row->setStatus($status);
        return $row;
    }

    private function summary(int $draft = 0, int $submitted = 0, int $approved = 0, int $rejected = 0): array {
        return ['draft' => $draft, 'submitted' => $submitted, 'approved' => $approved, 'rejected' => $rejected];
    }

    public function testStatusIsDraftWithoutRow(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn(null);
        $this->assertSame('draft', $this->service->getStatus(1, 2026, 8));
        $this->assertFalse($this->service->isApproved(1, 2026, 8));
        $this->assertFalse($this->service->isFrozen(1, 2026, 8));
    }

    public function testFrozenForSubmittedAndApproved(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('submitted'));
        $this->assertTrue($this->service->isFrozen(1, 2026, 8));
        $this->assertFalse($this->service->isApproved(1, 2026, 8));
    }

    public function testCanSubmitAbsenceOnlyDraftMonth(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn(null);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary());
        $this->absenceMapper->method('findApprovedByEmployeeAndMonth')->willReturn([new Absence()]);

        $this->assertTrue($this->service->canSubmit(1, 2026, 8));
    }

    public function testCannotSubmitEmptyMonth(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn(null);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary());
        $this->absenceMapper->method('findApprovedByEmployeeAndMonth')->willReturn([]);

        $this->assertFalse($this->service->canSubmit(1, 2026, 8));
        try {
            $this->service->assertCanSubmit(1, 2026, 8);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->getErrors());
        }
    }

    public function testCannotSubmitApprovedMonthEvenWithDrafts(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('approved'));
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary(draft: 2));

        $this->assertFalse($this->service->canSubmit(1, 2026, 8));
    }

    public function testCanResubmitLeftoverDraftsInSubmittedMonth(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('submitted'));
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary(draft: 1, submitted: 5));

        $this->assertTrue($this->service->canSubmit(1, 2026, 8));
    }

    public function testCannotSubmitSubmittedMonthWithoutDraftsEvenWithAbsences(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('submitted'));
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary());
        $this->absenceMapper->method('findApprovedByEmployeeAndMonth')->willReturn([new Absence()]);

        $this->assertFalse($this->service->canSubmit(1, 2026, 8));
    }

    public function testCanSubmitRejectedMonthWithRejectedEntries(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('rejected'));
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary(rejected: 3));

        $this->assertTrue($this->service->canSubmit(1, 2026, 8));
    }

    public function testMarkSubmittedInsertsRowWhenMissing(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn(null);
        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(fn(MonthStatus $row) => $row);
        $this->mapper->expects($this->never())->method('update');

        $now = new DateTime('2026-09-01 09:00:00');
        $row = $this->service->markSubmitted(1, 2026, 8, 42, $now);

        $this->assertSame('submitted', $row->getStatus());
        $this->assertSame(1, $row->getEmployeeId());
        $this->assertSame(2026, $row->getYear());
        $this->assertSame(8, $row->getMonth());
        $this->assertSame(42, $row->getSubmittedBy());
        $this->assertEquals($now, $row->getSubmittedAt());
        $this->assertNull($row->getApprovedAt());
        $this->assertEquals($now, $row->getCreatedAt());
    }

    public function testMarkSubmittedUpdatesExistingRowAndClearsApproval(): void {
        $existing = $this->row('rejected');
        $existing->setApprovedAt(new DateTime('2026-08-30 10:00:00'));
        $existing->setApprovedBy(9);
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($existing);
        $this->mapper->expects($this->once())->method('update')
            ->willReturnCallback(fn(MonthStatus $row) => $row);
        $this->mapper->expects($this->never())->method('insert');

        $row = $this->service->markSubmitted(1, 2026, 8, 42, new DateTime());

        $this->assertSame('submitted', $row->getStatus());
        $this->assertNull($row->getApprovedAt());
        $this->assertNull($row->getApprovedBy());
    }

    public function testMarkApprovedSetsApprovalStamps(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('submitted'));
        $this->mapper->method('update')->willReturnCallback(fn(MonthStatus $row) => $row);

        $now = new DateTime('2026-09-02 12:00:00');
        $row = $this->service->markApproved(1, 2026, 8, 5, $now);

        $this->assertSame('approved', $row->getStatus());
        $this->assertSame(5, $row->getApprovedBy());
        $this->assertEquals($now, $row->getApprovedAt());
    }

    public function testMarkRejectedClearsApproval(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('submitted'));
        $this->mapper->method('update')->willReturnCallback(fn(MonthStatus $row) => $row);

        $row = $this->service->markRejected(1, 2026, 8, new DateTime());

        $this->assertSame('rejected', $row->getStatus());
        $this->assertNull($row->getApprovedAt());
        $this->assertNull($row->getApprovedBy());
    }

    public function testMarkReopenedResetsToDraft(): void {
        $existing = $this->row('approved');
        $existing->setSubmittedAt(new DateTime());
        $existing->setSubmittedBy(1);
        $existing->setApprovedAt(new DateTime());
        $existing->setApprovedBy(5);
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($existing);
        $this->mapper->method('update')->willReturnCallback(fn(MonthStatus $row) => $row);

        $row = $this->service->markReopened(1, 2026, 8, new DateTime());

        $this->assertSame('draft', $row->getStatus());
        $this->assertNull($row->getSubmittedAt());
        $this->assertNull($row->getSubmittedBy());
        $this->assertNull($row->getApprovedAt());
        $this->assertNull($row->getApprovedBy());
    }

    public function testSyncFromEntriesDoesNothingForDraftMonthWithoutRow(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn(null);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary(draft: 2));
        $this->mapper->expects($this->never())->method('insert');
        $this->mapper->expects($this->never())->method('update');

        $this->assertNull($this->service->syncFromEntries(1, 2026, 8));
    }

    public function testSyncFromEntriesCreatesRowForSubmittedEntries(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn(null);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary(submitted: 2));
        $this->mapper->expects($this->once())->method('insert')->willReturnCallback(fn(MonthStatus $row) => $row);

        $row = $this->service->syncFromEntries(1, 2026, 8);

        $this->assertSame('submitted', $row->getStatus());
        $this->assertNotNull($row->getSubmittedAt());
    }

    public function testSyncFromEntriesUpdatesExistingRow(): void {
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($this->row('submitted'));
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary(approved: 2));
        $this->mapper->expects($this->once())->method('update')->willReturnCallback(fn(MonthStatus $row) => $row);

        $row = $this->service->syncFromEntries(1, 2026, 8);

        $this->assertSame('approved', $row->getStatus());
    }

    public function testSyncFromEntriesClearsApprovalWhenApprovedMonthGetsNewSubmittedEntry(): void {
        $existing = $this->row('approved');
        $existing->setApprovedAt(new DateTime('2026-08-30 10:00:00'));
        $existing->setApprovedBy(9);
        $this->mapper->method('findByEmployeeAndMonth')->willReturn($existing);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($this->summary(submitted: 1, approved: 2));
        $this->mapper->method('update')->willReturnCallback(fn(MonthStatus $row) => $row);

        $row = $this->service->syncFromEntries(1, 2026, 8);

        $this->assertSame('submitted', $row->getStatus());
        $this->assertNull($row->getApprovedAt());
        $this->assertNull($row->getApprovedBy());
    }

    public function testStatusesForYearDefaultToDraft(): void {
        $august = $this->row('approved');
        $this->mapper->method('findByEmployeeIdsAndYear')->willReturn([1 => [8 => $august]]);

        $statuses = $this->service->getStatusesForYear([1, 2], 2026);

        $this->assertCount(12, $statuses[1]);
        $this->assertCount(12, $statuses[2]);
        $this->assertSame('approved', $statuses[1][8]);
        $this->assertSame('draft', $statuses[1][7]);
        $this->assertSame('draft', $statuses[2][8]);
    }

    public function testStatusesForMonthDefaultToDraft(): void {
        $this->mapper->method('findByEmployeeIdsAndMonth')->willReturn([2 => $this->row('submitted')]);

        $statuses = $this->service->getStatusesForMonth([1, 2], 2026, 8);

        $this->assertSame('draft', $statuses[1]);
        $this->assertSame('submitted', $statuses[2]);
    }
}
