<?php

declare(strict_types=1);

namespace OCA\WorkTime\Tests\Unit\Service;

use DateTime;
use OCA\WorkTime\Db\Absence;
use OCA\WorkTime\Db\AbsenceMapper;
use OCA\WorkTime\Db\DailyKm;
use OCA\WorkTime\Db\DailyKmMapper;
use OCA\WorkTime\Db\Project;
use OCA\WorkTime\Db\ProjectMapper;
use OCA\WorkTime\Db\TimeEntry;
use OCA\WorkTime\Db\TimeEntryMapper;
use OCA\WorkTime\Service\AuditLogService;
use OCA\WorkTime\Service\CompanySettingsService;
use OCA\WorkTime\Service\DailyKmService;
use OCA\WorkTime\Service\TimeEntryService;
use OCA\WorkTime\Service\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Sichert die serverseitige Vergütungs-Schranke ab: positive Kilometer sind nur
 * an einem tatsächlich externen Tag erlaubt — unabhängig davon, was die UI zeigt.
 */
class DailyKmServiceTest extends TestCase {

    private DailyKmMapper $dailyKmMapper;
    private TimeEntryMapper $timeEntryMapper;
    private ProjectMapper $projectMapper;
    private AbsenceMapper $absenceMapper;
    private CompanySettingsService $settings;

    /**
     * @param TimeEntry[] $dayEntries
     * @param Project[] $projects
     * @param Absence[] $dayAbsences
     * @param string[] $externAbsenceTypes
     */
    private function makeService(array $dayEntries, array $projects, array $dayAbsences = [], array $externAbsenceTypes = [], bool $monthLocked = false): DailyKmService {
        $this->dailyKmMapper = $this->createMock(DailyKmMapper::class);
        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        $this->projectMapper = $this->createMock(ProjectMapper::class);
        $this->absenceMapper = $this->createMock(AbsenceMapper::class);
        $this->settings = $this->createMock(CompanySettingsService::class);

        $this->timeEntryMapper->method('findByEmployeeAndDate')->willReturn($dayEntries);
        $this->projectMapper->method('findAll')->willReturn($projects);
        $this->absenceMapper->method('findByEmployeeAndDate')->willReturn($dayAbsences);
        $this->settings->method('getExternAbsenceTypes')->willReturn($externAbsenceTypes);

        $timeEntryService = $this->createMock(TimeEntryService::class);
        $timeEntryService->method('isMonthLocked')->willReturn($monthLocked);

        return new DailyKmService(
            $this->dailyKmMapper,
            $this->createMock(AuditLogService::class),
            $this->timeEntryMapper,
            $this->projectMapper,
            $this->absenceMapper,
            $this->settings,
            $timeEntryService,
        );
    }

    private function project(int $id, bool $extern): Project {
        $p = new Project();
        $p->setId($id);
        $p->setIsExtern($extern);
        return $p;
    }

    private function entry(int $projectId): TimeEntry {
        $e = new TimeEntry();
        $e->setProjectId($projectId);
        return $e;
    }

    public function testPositiveKmRejectedOnNonExternDay(): void {
        // Buchung auf einem Normalprojekt, keine externen Abwesenheitstypen.
        $service = $this->makeService([$this->entry(2)], [$this->project(1, true), $this->project(2, false)]);

        $this->expectException(ValidationException::class);
        $service->upsert(5, new DateTime('2026-07-06'), 42, 'user');
    }

    public function testPositiveKmAllowedOnExternProjectDay(): void {
        $service = $this->makeService([$this->entry(1)], [$this->project(1, true)]);
        $this->dailyKmMapper->method('findByEmployeeAndDate')->willReturn(null);
        $this->dailyKmMapper->method('insert')->willReturnArgument(0);

        $result = $service->upsert(5, new DateTime('2026-07-06'), 42, 'user');

        $this->assertInstanceOf(DailyKm::class, $result);
        $this->assertSame(42, $result->getKilometers());
    }

    public function testPositiveKmAllowedOnExternAbsenceDay(): void {
        $absence = new Absence();
        $absence->setType(Absence::TYPE_TRAINING);
        $absence->setStatus(Absence::STATUS_APPROVED);
        $service = $this->makeService([], [], [$absence], [Absence::TYPE_TRAINING]);
        $this->dailyKmMapper->method('findByEmployeeAndDate')->willReturn(null);
        $this->dailyKmMapper->method('insert')->willReturnArgument(0);

        $result = $service->upsert(5, new DateTime('2026-07-06'), 10, 'user');

        $this->assertSame(10, $result->getKilometers());
    }

    public function testZeroKmAllowedOnNonExternDay(): void {
        // Löschen/Nullsetzen ist nicht vergütungserhöhend und daher immer erlaubt.
        $service = $this->makeService([$this->entry(2)], [$this->project(2, false)]);
        $this->dailyKmMapper->method('findByEmployeeAndDate')->willReturn(null);

        $this->assertNull($service->upsert(5, new DateTime('2026-07-06'), 0, 'user'));
    }

    public function testExternDaysInMonthIncludesInactiveExternProjectsAndClampsAbsences(): void {
        // Buchung auf einem Extern-Projekt (auch wenn es inzwischen inaktiv wäre —
        // findAll liefert alle Projekte) plus eine monatsübergreifende externe
        // Abwesenheit, die auf den Monat geklammert wird.
        $service = $this->makeService([], [$this->project(1, true)], [], [Absence::TYPE_TRAINING]);

        $entry = $this->entry(1);
        $entry->setDate(new DateTime('2026-07-06'));
        $this->timeEntryMapper->method('findByEmployeeAndMonth')->willReturn([$entry]);

        $absence = new Absence();
        $absence->setType(Absence::TYPE_TRAINING);
        $absence->setStatus(Absence::STATUS_APPROVED);
        $absence->setStartDate(new DateTime('2026-06-29'));
        $absence->setEndDate(new DateTime('2026-07-02'));
        $this->absenceMapper->method('findByEmployeeAndMonth')->willReturn([$absence]);

        $this->assertSame(
            ['2026-07-01', '2026-07-02', '2026-07-06'],
            $service->externDaysInMonth(5, 2026, 7)
        );
    }

    public function testUpsertRejectedInLockedMonth(): void {
        // Abgeschlossener Monat (voll genehmigt oder Vorjahr): km sind gesperrt,
        // sonst liessen sich Vergütungsbeträge nach der Genehmigung noch ändern.
        $service = $this->makeService([$this->entry(1)], [$this->project(1, true)], monthLocked: true);

        $this->expectException(ValidationException::class);
        $service->upsert(5, new DateTime('2026-07-06'), 42, 'user');
    }

    public function testDeleteRejectedInLockedMonth(): void {
        // Auch das Nullsetzen ändert die Vergütung — im gesperrten Monat blockiert.
        $service = $this->makeService([], [], monthLocked: true);

        $this->expectException(ValidationException::class);
        $service->upsert(5, new DateTime('2026-07-06'), 0, 'user');
    }
}
