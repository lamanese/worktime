<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\Absence;
use OCA\Zeitwerk\Db\AbsenceMapper;
use OCA\Zeitwerk\Db\DailyKm;
use OCA\Zeitwerk\Db\DailyKmMapper;
use OCA\Zeitwerk\Db\Project;
use OCA\Zeitwerk\Db\ProjectMapper;
use OCA\Zeitwerk\Db\TimeEntry;
use OCA\Zeitwerk\Db\TimeEntryMapper;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\DailyKmService;
use OCA\Zeitwerk\Service\TimeEntryService;
use OCA\Zeitwerk\Service\ValidationException;
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
    private function makeService(
        array $dayEntries,
        array $projects,
        array $dayAbsences = [],
        array $externAbsenceTypes = [],
        bool $monthLocked = false,
        array $statusSummary = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0],
    ): DailyKmService {
        $this->dailyKmMapper = $this->createMock(DailyKmMapper::class);
        $this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
        $this->projectMapper = $this->createMock(ProjectMapper::class);
        $this->absenceMapper = $this->createMock(AbsenceMapper::class);
        $this->settings = $this->createMock(CompanySettingsService::class);

        $this->timeEntryMapper->method('findByEmployeeAndDate')->willReturn($dayEntries);
        $this->timeEntryMapper->method('getMonthlyStatusSummary')->willReturn($statusSummary);
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

    public function testUpsertRejectedInSubmittedMonth(): void {
        // Nach der Einreichung (alle Einträge eingereicht, noch nicht genehmigt)
        // sind km eingefroren — wie die eingereichten Zeiteinträge selbst.
        $service = $this->makeService(
            [$this->entry(1)],
            [$this->project(1, true)],
            statusSummary: ['draft' => 0, 'submitted' => 3, 'approved' => 0, 'rejected' => 0],
        );

        $this->expectException(ValidationException::class);
        $service->upsert(5, new DateTime('2026-07-06'), 42, 'user');
    }

    public function testUpsertAllowedAgainAfterRejection(): void {
        // Ein abgelehnter Monat ist wieder in Bearbeitung — km wieder änderbar.
        $service = $this->makeService(
            [$this->entry(1)],
            [$this->project(1, true)],
            statusSummary: ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 2],
        );
        $this->dailyKmMapper->method('findByEmployeeAndDate')->willReturn(null);
        $this->dailyKmMapper->method('insert')->willReturnArgument(0);

        $result = $service->upsert(5, new DateTime('2026-07-06'), 42, 'user');

        $this->assertSame(42, $result->getKilometers());
    }
}
