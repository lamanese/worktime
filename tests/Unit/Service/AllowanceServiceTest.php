<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\Absence;
use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Db\DailyKm;
use OCA\Zeitwerk\Db\DailyKmMapper;
use OCA\Zeitwerk\Db\Project;
use OCA\Zeitwerk\Db\ProjectMapper;
use OCA\Zeitwerk\Db\TimeEntry;
use OCA\Zeitwerk\Service\AllowanceService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die Spesen- und Kilometer-Berechnung ab: Tagesschwelle (Brutto/Netto,
 * Operator), Aussendienst-Tagesbindung, externe Abwesenheitstage und
 * Kilometer-Vergütung.
 */
class AllowanceServiceTest extends TestCase {

    private function makeSettings(array $overrides = []): CompanySettingsService {
        $defaults = [
            'thresholdHours' => 8.0,
            'operator' => CompanySetting::OPERATOR_GTE,
            'basis' => CompanySetting::BASIS_GROSS,
            'onExternAbsence' => false,
            'amount' => 14.0,
            'mileageRate' => 0.30,
            'externTypes' => [],
        ];
        $c = array_merge($defaults, $overrides);

        $settings = $this->createMock(CompanySettingsService::class);
        $settings->method('getFieldworkAllowanceThresholdHours')->willReturn($c['thresholdHours']);
        $settings->method('getFieldworkAllowanceOperator')->willReturn($c['operator']);
        $settings->method('getFieldworkAllowanceBasis')->willReturn($c['basis']);
        $settings->method('isFieldworkAllowanceOnExternAbsence')->willReturn($c['onExternAbsence']);
        $settings->method('getFieldworkAllowanceAmount')->willReturn($c['amount']);
        $settings->method('getMileageRate')->willReturn($c['mileageRate']);
        $settings->method('getExternAbsenceTypes')->willReturn($c['externTypes']);

        return $settings;
    }

    private function makeService(CompanySettingsService $settings): AllowanceService {
        // Projekt 1 = Aussendienst, Projekt 2 = normal, Projekt 3 = extern.
        $p1 = new Project();
        $p1->setId(1);
        $p1->setIsFieldWork(1);
        $p2 = new Project();
        $p2->setId(2);
        $p2->setIsFieldWork(0);
        $p3 = new Project();
        $p3->setId(3);
        $p3->setIsExtern(1);

        $projectMapper = $this->createMock(ProjectMapper::class);
        $projectMapper->method('findAll')->willReturn([$p1, $p2, $p3]);

        return new AllowanceService($settings, $this->createMock(DailyKmMapper::class), $projectMapper);
    }

    private function km(string $date, int $kilometers): DailyKm {
        $rec = new DailyKm();
        $rec->setWorkDate(new DateTime($date));
        $rec->setKilometers($kilometers);
        return $rec;
    }

    private function entry(string $date, int $projectId, int $workMinutes, int $breakMinutes = 0): TimeEntry {
        $e = new TimeEntry();
        $e->setDate(new DateTime($date));
        $e->setProjectId($projectId);
        $e->setWorkMinutes($workMinutes);
        $e->setBreakMinutes($breakMinutes);
        return $e;
    }

    private function range(): array {
        return [new DateTime('2026-07-01'), new DateTime('2026-07-31')];
    }

    public function testGrossThresholdExactlyMetQualifies(): void {
        // 7,5h Arbeit + 0,5h Pause = 8h brutto, Aussendienst-Projekt, gte 8h.
        $service = $this->makeService($this->makeSettings());
        [$s, $e] = $this->range();

        $result = $service->calculate([$this->entry('2026-07-06', 1, 450, 30)], [], [], $s, $e);

        $this->assertSame(1, $result['allowanceDays']);
        $this->assertSame(14.0, $result['allowanceAmount']);
    }

    public function testNonFieldWorkDayDoesNotQualify(): void {
        // Langer Tag, aber Projekt 2 ist kein Aussendienst -> keine Spesen.
        $service = $this->makeService($this->makeSettings());
        [$s, $e] = $this->range();

        $result = $service->calculate([$this->entry('2026-07-06', 2, 600, 30)], [], [], $s, $e);

        $this->assertSame(0, $result['allowanceDays']);
        $this->assertSame(0.0, $result['allowanceAmount']);
    }

    public function testOperatorGtNeedsMoreThanThreshold(): void {
        // Genau 8h brutto qualifiziert bei '>' NICHT.
        $service = $this->makeService($this->makeSettings(['operator' => CompanySetting::OPERATOR_GT]));
        [$s, $e] = $this->range();

        $result = $service->calculate([$this->entry('2026-07-06', 1, 450, 30)], [], [], $s, $e);

        $this->assertSame(0, $result['allowanceDays']);
    }

    public function testNetBasisIgnoresBreak(): void {
        // Netto 450min (7,5h) < 8h -> keine Spesen, obwohl brutto 8h waere.
        $service = $this->makeService($this->makeSettings(['basis' => CompanySetting::BASIS_NET]));
        [$s, $e] = $this->range();

        $result = $service->calculate([$this->entry('2026-07-06', 1, 450, 30)], [], [], $s, $e);

        $this->assertSame(0, $result['allowanceDays']);
    }

    public function testMultipleFieldWorkEntriesSameDaySumToThreshold(): void {
        // Zwei Aussendienst-Buchungen am selben Tag summieren sich (5h + 3h = 8h).
        $service = $this->makeService($this->makeSettings());
        [$s, $e] = $this->range();

        $result = $service->calculate([
            $this->entry('2026-07-06', 1, 300),
            $this->entry('2026-07-06', 1, 180),
        ], [], [], $s, $e);

        $this->assertSame(1, $result['allowanceDays']);
    }

    public function testNonFieldWorkTimeDoesNotCountTowardThreshold(): void {
        // 5h Aussendienst + 5h Normalprojekt am selben Tag: nur die 5h Aussendienst
        // zählen -> Schwelle 8h nicht erreicht -> keine Spesen.
        $service = $this->makeService($this->makeSettings());
        [$s, $e] = $this->range();

        $result = $service->calculate([
            $this->entry('2026-07-06', 1, 300),
            $this->entry('2026-07-06', 2, 300),
        ], [], [], $s, $e);

        $this->assertSame(0, $result['allowanceDays']);
    }

    public function testMileagePricedForExternProjectDay(): void {
        // km an einem Tag mit Extern-Projekt-Buchung werden vergütet.
        $service = $this->makeService($this->makeSettings());
        [$s, $e] = $this->range();

        $result = $service->calculate(
            [$this->entry('2026-07-06', 3, 60)],
            [],
            [$this->km('2026-07-06', 120)],
            $s,
            $e
        );

        $this->assertSame(120, $result['kilometers']);
        $this->assertSame(36.0, $result['mileageAmount']); // 120 * 0.30
        $this->assertSame(36.0, $result['total']);
    }

    public function testMileageIgnoredWhenBasisGone(): void {
        // Gespeicherte km, aber der Tag ist nicht (mehr) extern -> keine Vergütung.
        $service = $this->makeService($this->makeSettings());
        [$s, $e] = $this->range();

        $result = $service->calculate(
            [$this->entry('2026-07-06', 2, 480)], // Normalprojekt, nicht extern
            [],
            [$this->km('2026-07-06', 120)],
            $s,
            $e
        );

        $this->assertSame(0, $result['kilometers']);
        $this->assertSame(0.0, $result['mileageAmount']);
    }

    public function testMileageIgnoredForRejectedExternAbsence(): void {
        // Abgelehnte externe Abwesenheit ist keine gültige Basis.
        $absence = new Absence();
        $absence->setType(Absence::TYPE_TRAINING);
        $absence->setStatus(Absence::STATUS_REJECTED);
        $absence->setStartDate(new DateTime('2026-07-06'));
        $absence->setEndDate(new DateTime('2026-07-06'));
        $service = $this->makeService($this->makeSettings(['externTypes' => [Absence::TYPE_TRAINING]]));
        [$s, $e] = $this->range();

        $result = $service->calculate([], [$absence], [$this->km('2026-07-06', 50)], $s, $e);

        $this->assertSame(0, $result['kilometers']);
    }

    public function testExternAbsenceDaysCountOnlyWhenEnabled(): void {
        $absence = new Absence();
        $absence->setType(Absence::TYPE_TRAINING);
        $absence->setStatus(Absence::STATUS_APPROVED);
        $absence->setStartDate(new DateTime('2026-07-06')); // Mo
        $absence->setEndDate(new DateTime('2026-07-07'));    // Di
        [$s, $e] = $this->range();

        // Schalter aus -> keine Spesen.
        $off = $this->makeService($this->makeSettings(['externTypes' => [Absence::TYPE_TRAINING]]));
        $this->assertSame(0, $off->calculate([], [$absence], [], $s, $e)['allowanceDays']);

        // Schalter an -> 2 Werktage = 2 Spesen-Tage.
        $on = $this->makeService($this->makeSettings([
            'onExternAbsence' => true,
            'externTypes' => [Absence::TYPE_TRAINING],
        ]));
        $result = $on->calculate([], [$absence], [], $s, $e);
        $this->assertSame(2, $result['allowanceDays']);
        $this->assertSame(28.0, $result['allowanceAmount']);
    }
}
