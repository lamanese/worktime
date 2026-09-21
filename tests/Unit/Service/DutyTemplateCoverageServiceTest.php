<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\DutyJob;
use OCA\Zeitwerk\Db\DutyJobTemplate;
use OCA\Zeitwerk\Db\DutyJobTemplateMapper;
use OCA\Zeitwerk\Db\DutyTemplateWeekSkip;
use OCA\Zeitwerk\Db\DutyTemplateWeekSkipMapper;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\DutyTemplateCoverageService;
use OCA\Zeitwerk\Service\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class DutyTemplateCoverageServiceTest extends TestCase {

    private DutyJobTemplateMapper $templateMapper;
    private DutyTemplateWeekSkipMapper $skipMapper;
    private AuditLogService $auditLogService;
    private DutyTemplateCoverageService $service;

    protected function setUp(): void {
        $this->templateMapper = $this->createMock(DutyJobTemplateMapper::class);
        $this->skipMapper = $this->createMock(DutyTemplateWeekSkipMapper::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->service = new DutyTemplateCoverageService($this->templateMapper, $this->skipMapper, $this->auditLogService);
    }

    private function makeTemplate(int $id, string $title, int $weekdays): DutyJobTemplate {
        $t = new DutyJobTemplate();
        $t->setId($id);
        $t->setTitle($title);
        $t->setWeekdays($weekdays);
        return $t;
    }

    private function makeJob(?int $templateId, string $date, int $employeeId = 1): DutyJob {
        $j = new DutyJob();
        $j->setEmployeeId($employeeId);
        $j->setJobDate(new DateTime($date));
        $j->setTemplateId($templateId);
        return $j;
    }

    private function makeSkip(int $templateId): DutyTemplateWeekSkip {
        $s = new DutyTemplateWeekSkip();
        $s->setTemplateId($templateId);
        $s->setWeekStart(new DateTime('2026-09-21'));
        return $s;
    }

    public function testCoverageSplitsDoneAndOpenDaysAcrossEmployees(): void {
        $this->templateMapper->method('findVisible')->willReturn([
            $this->makeTemplate(7, 'Reinigung', 0b0010101), // Mo, Mi, Fr
            $this->makeTemplate(8, 'Frei', 0),              // any day: no target days
        ]);
        $this->skipMapper->method('findByWeekStart')->willReturn([]);

        $coverage = $this->service->coverage(new DateTime('2026-09-21'), [
            $this->makeJob(7, '2026-09-21', 1),    // Monday
            $this->makeJob(7, '2026-09-21', 2),    // Monday again, other employee
            $this->makeJob(7, '2026-09-22', 1),    // Tuesday: not a target day
            $this->makeJob(null, '2026-09-23', 1), // free card on Wednesday does not count
            $this->makeJob(99, '2026-09-25', 1),   // orphaned template id
        ]);

        $this->assertCount(2, $coverage);
        $this->assertSame(8, $coverage[1]['templateId']); // free template: listed, no days
        $this->assertSame([], $coverage[1]['weekdays']);
        $this->assertSame([], $coverage[1]['openDays']);
        $this->assertSame(7, $coverage[0]['templateId']);
        $this->assertSame([1, 3, 5], $coverage[0]['weekdays']);
        $this->assertSame([1], $coverage[0]['doneDays']);
        $this->assertSame([3, 5], $coverage[0]['openDays']);
        $this->assertFalse($coverage[0]['skipped']);
        $this->assertSame(2, DutyTemplateCoverageService::countOpenDays($coverage));
    }

    public function testSkippedTemplateIsFlaggedAndNotCounted(): void {
        $this->templateMapper->method('findVisible')->willReturn([$this->makeTemplate(7, 'Reinigung', 0b0000011)]);
        $this->skipMapper->method('findByWeekStart')->willReturn([$this->makeSkip(7)]);

        $coverage = $this->service->coverage(new DateTime('2026-09-21'), []);

        $this->assertTrue($coverage[0]['skipped']);
        $this->assertSame([1, 2], $coverage[0]['openDays']);
        $this->assertSame(0, DutyTemplateCoverageService::countOpenDays($coverage));
    }

    public function testCoverageWithoutTemplatesSkipsTheSkipQuery(): void {
        $this->templateMapper->method('findVisible')->willReturn([]);
        $this->skipMapper->expects($this->never())->method('findByWeekStart');
        $this->assertSame([], $this->service->coverage(new DateTime('2026-09-21'), []));
    }

    public function testFreeTemplateCanBeMarkedDoneForTheWeek(): void {
        $this->templateMapper->method('findVisible')->willReturn([$this->makeTemplate(8, 'Frei', 0)]);
        $this->skipMapper->method('findByWeekStart')->willReturn([$this->makeSkip(8)]);

        $coverage = $this->service->coverage(new DateTime('2026-09-21'), []);

        $this->assertTrue($coverage[0]['skipped']);
        $this->assertSame(0, DutyTemplateCoverageService::countOpenDays($coverage));
    }

    public function testHolidayForWholeRosterLiftsTargetDayUnlessAlreadyPlaced(): void {
        $this->templateMapper->method('findVisible')->willReturn([$this->makeTemplate(7, 'Reinigung', 0b0010101)]); // Mo, Mi, Fr
        $this->skipMapper->method('findByWeekStart')->willReturn([]);

        // Monday and Friday are holidays; Friday already has a card, Tuesday is no target day
        $coverage = $this->service->coverage(new DateTime('2026-09-21'), [$this->makeJob(7, '2026-09-25')], [1, 2, 5]);

        $this->assertSame([5], $coverage[0]['doneDays']);
        $this->assertSame([1], $coverage[0]['holidayDays']);
        $this->assertSame([3], $coverage[0]['openDays']);
        $this->assertSame(1, DutyTemplateCoverageService::countOpenDays($coverage));
    }

    public function testResolveTemplate(): void {
        $this->templateMapper->method('find')->willReturnCallback(function (int $id): DutyJobTemplate {
            if ($id !== 7) {
                throw new DoesNotExistException('nope');
            }
            return $this->makeTemplate(7, 'Reinigung', 1);
        });
        $this->assertSame(7, $this->service->resolveTemplate(7)?->getId());
        $this->assertSame(7, $this->service->resolveTemplate('7')?->getId());
        $this->assertNull($this->service->resolveTemplate(99));
        $this->assertNull($this->service->resolveTemplate(null));
        $this->assertNull($this->service->resolveTemplate(0));
    }

    public function testSetSkippedInsertsOnceAndAudits(): void {
        $this->templateMapper->method('find')->willReturn($this->makeTemplate(7, 'Reinigung', 1));
        $this->skipMapper->method('findByWeekStart')->willReturnOnConsecutiveCalls([], [$this->makeSkip(7)]);
        $this->skipMapper->expects($this->once())->method('insert')
            ->with($this->callback(static fn (DutyTemplateWeekSkip $s): bool => $s->getTemplateId() === 7
                && $s->getWeekStart()->format('Y-m-d') === '2026-09-21'
                && $s->getSkippedBy() === 'sup'));
        $this->auditLogService->expects($this->once())->method('log')
            ->with('sup', 'skip_template_week', 'duty_job_template', 7, null, $this->anything());

        $this->service->setSkipped(7, new DateTime('2026-09-21'), true, 'sup');
        $this->service->setSkipped(7, new DateTime('2026-09-21'), true, 'sup'); // already skipped: no-op
    }

    public function testSetSkippedFalseDeletesAndIsNoopWhenNotSkipped(): void {
        $skip = $this->makeSkip(7);
        $this->templateMapper->method('find')->willReturn($this->makeTemplate(7, 'Reinigung', 1));
        $this->skipMapper->method('findByWeekStart')->willReturnOnConsecutiveCalls([$this->makeSkip(8), $skip], []);
        $this->skipMapper->expects($this->once())->method('delete')->with($skip);
        $this->auditLogService->expects($this->once())->method('log')
            ->with('sup', 'unskip_template_week', 'duty_job_template', 7, $this->anything(), null);

        $this->service->setSkipped(7, new DateTime('2026-09-21'), false, 'sup');
        $this->service->setSkipped(7, new DateTime('2026-09-21'), false, 'sup');
    }

    public function testSetSkippedUnknownTemplateThrowsNotFound(): void {
        $this->templateMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
        $this->expectException(NotFoundException::class);
        $this->service->setSkipped(404, new DateTime('2026-09-21'), true, 'sup');
    }
}
