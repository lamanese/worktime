<?php

declare(strict_types=1);

namespace OCA\WorkTime\Tests\Unit\Service;

use DateTime;
use OCA\WorkTime\Db\Absence;
use OCA\WorkTime\Db\Holiday;
use OCA\WorkTime\Db\ProjectMapper;
use OCA\WorkTime\Db\TimeEntry;
use OCA\WorkTime\Service\CompanySettingsService;
use OCA\WorkTime\Service\PdfService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the gap-free day overview of the monthly PDF report (#318):
 * absences shown in the day row, holidays, weekends and blank workdays.
 */
class PdfServiceTest extends TestCase {

    private PdfService $service;
    private ReflectionMethod $buildDayRows;

    protected function setUp(): void {
        $this->service = new PdfService(
            $this->createMock(CompanySettingsService::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(ProjectMapper::class),
        );
        $this->buildDayRows = new ReflectionMethod(PdfService::class, 'buildDayRows');
        $this->buildDayRows->setAccessible(true);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>> day (d.m.Y) => rows,
     *   with the date carried forward across multi-row days.
     */
    private function rowsByDay(array $timeEntries, array $absences, array $holidays, int $year, int $month, array $projectNames = []): array {
        $rows = $this->buildDayRows->invoke($this->service, $timeEntries, $absences, $holidays, $year, $month, $projectNames);
        $byDay = [];
        $current = null;
        foreach ($rows as $row) {
            if ($row['date'] !== '') {
                $current = $row['date'];
            }
            $byDay[$current][] = $row;
        }
        return $byDay;
    }

    private function entry(string $date, string $start, string $end, int $break, int $work, ?int $projectId, string $desc): TimeEntry {
        $e = new TimeEntry();
        $e->setDate(new DateTime($date));
        $e->setStartTime(new DateTime("$date $start"));
        $e->setEndTime(new DateTime("$date $end"));
        $e->setBreakMinutes($break);
        $e->setWorkMinutes($work);
        $e->setProjectId($projectId);
        $e->setDescription($desc);
        return $e;
    }

    private function absence(string $type, string $start, string $end, string $status, float $scope = 1.0): Absence {
        $a = new Absence();
        $a->setType($type);
        $a->setStartDate(new DateTime($start));
        $a->setEndDate(new DateTime($end));
        $a->setStatus($status);
        $a->setScopeValue($scope);
        return $a;
    }

    private function holiday(string $date, string $name): Holiday {
        $h = new Holiday();
        $h->setDate(new DateTime($date));
        $h->setName($name);
        return $h;
    }

    public function testOverviewIsGapFreeWithOneEntryPerCalendarDay(): void {
        // June 2026 has 30 days; with no data every day must still produce a row.
        $byDay = $this->rowsByDay([], [], [], 2026, 6);

        $this->assertCount(30, $byDay);
        $this->assertArrayHasKey('01.06.2026', $byDay);
        $this->assertArrayHasKey('30.06.2026', $byDay);
    }

    public function testFullDayAbsenceShowsTypeInTheDayRow(): void {
        // Vacation 08.–10.06. with no bookings on those days.
        $absences = [$this->absence(Absence::TYPE_VACATION, '2026-06-08', '2026-06-10', Absence::STATUS_APPROVED)];

        $byDay = $this->rowsByDay([], $absences, [], 2026, 6);

        foreach (['08.06.2026', '09.06.2026', '10.06.2026'] as $day) {
            $this->assertCount(1, $byDay[$day]);
            $this->assertSame('Urlaub', $byDay[$day][0]['note']);
            $this->assertSame('-', $byDay[$day][0]['start']);
        }
    }

    public function testBookingShowsTimesAndProject(): void {
        $entries = [$this->entry('2026-06-01', '08:00', '16:00', 30, 450, 2, 'Arbeit')];

        $byDay = $this->rowsByDay($entries, [], [], 2026, 6, [2 => 'Projekt X']);

        $row = $byDay['01.06.2026'][0];
        $this->assertSame('08:00', $row['start']);
        $this->assertSame('Projekt X', $row['project']);
        $this->assertSame('Arbeit', $row['note']);
    }

    public function testHalfDayAbsenceAddsMarkerRowAlongsideBooking(): void {
        // Morning work + afternoon compensatory leave on the same day.
        $entries = [$this->entry('2026-06-15', '08:00', '12:00', 0, 240, null, 'Vormittag')];
        $absences = [$this->absence(Absence::TYPE_COMPENSATORY, '2026-06-15', '2026-06-15', Absence::STATUS_APPROVED, 0.5)];

        $byDay = $this->rowsByDay($entries, $absences, [], 2026, 6);

        $this->assertCount(2, $byDay['15.06.2026']);
        $this->assertSame('Vormittag', $byDay['15.06.2026'][0]['note']);
        $this->assertSame('Freizeitausgleich (halber Tag)', $byDay['15.06.2026'][1]['note']);
        $this->assertSame('-', $byDay['15.06.2026'][1]['start']);
    }

    public function testFullDayAbsenceWithBookingDoesNotAddMarkerRow(): void {
        // Contradictory data (full work + full-day vacation): show only the
        // booking, no confusing "worked + absent" marker row.
        $entries = [$this->entry('2026-06-09', '08:00', '17:00', 30, 480, null, 'Projektarbeit')];
        $absences = [$this->absence(Absence::TYPE_VACATION, '2026-06-09', '2026-06-09', Absence::STATUS_APPROVED)];

        $byDay = $this->rowsByDay($entries, $absences, [], 2026, 6);

        $this->assertCount(1, $byDay['09.06.2026']);
        $this->assertSame('Projektarbeit', $byDay['09.06.2026'][0]['note']);
    }

    public function testRejectedAbsenceIsIgnored(): void {
        // 22.06.2026 is a Monday; a rejected absence must not appear, leaving a
        // blank workday row (empty time cells, not dashes).
        $absences = [$this->absence(Absence::TYPE_VACATION, '2026-06-22', '2026-06-22', Absence::STATUS_REJECTED)];

        $byDay = $this->rowsByDay([], $absences, [], 2026, 6);

        $this->assertCount(1, $byDay['22.06.2026']);
        $this->assertSame('', $byDay['22.06.2026'][0]['note']);
        $this->assertSame('', $byDay['22.06.2026'][0]['start']);
    }

    public function testHolidayShowsLabel(): void {
        $holidays = [$this->holiday('2026-06-18', 'Testfeiertag')];

        $byDay = $this->rowsByDay([], [], $holidays, 2026, 6);

        $this->assertSame('Feiertag: Testfeiertag', $byDay['18.06.2026'][0]['note']);
        $this->assertSame('-', $byDay['18.06.2026'][0]['start']);
    }

    public function testWeekendRowHasDashesAndNoLabel(): void {
        // 06.06.2026 is a Saturday: dashes, empty note, grey fill.
        $byDay = $this->rowsByDay([], [], [], 2026, 6);

        $row = $byDay['06.06.2026'][0];
        $this->assertSame('-', $row['start']);
        $this->assertSame('', $row['note']);
        $this->assertTrue($row['fill']);
    }
}
