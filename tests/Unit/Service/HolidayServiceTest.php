<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use DateTime;
use OCA\Zeitwerk\Db\CompanySettingMapper;
use OCA\Zeitwerk\Db\Holiday;
use OCA\Zeitwerk\Db\HolidayMapper;
use OCA\Zeitwerk\Holiday\Provider\GermanyHolidays;
use OCA\Zeitwerk\Holiday\Provider\ProviderRegistry;
use OCA\Zeitwerk\Holiday\Provider\SwitzerlandHolidays;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\HolidayService;
use OCA\Zeitwerk\Service\ValidationException;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HolidayServiceTest extends TestCase {

    private HolidayService $service;
    private HolidayMapper $holidayMapper;
    private CompanySettingMapper $settingsMapper;
    private AuditLogService $auditLogService;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->holidayMapper = $this->createMock(HolidayMapper::class);
        $this->settingsMapper = $this->createMock(CompanySettingMapper::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new HolidayService(
            $this->holidayMapper,
            $this->settingsMapper,
            $this->auditLogService,
            $this->logger,
            new ProviderRegistry(new GermanyHolidays(), new SwitzerlandHolidays()),
        );
    }

    /**
     * @dataProvider easterDatesProvider
     */
    public function testCalculateEasterSunday(int $year, string $expectedDate): void {
        $result = $this->service->calculateEasterSunday($year);

        $this->assertEquals(
            $expectedDate,
            $result->format('Y-m-d'),
            "Easter Sunday for $year should be $expectedDate"
        );
    }

    /**
     * Gauss algorithm verification data
     * Source: https://de.wikipedia.org/wiki/Osterdatum
     */
    public static function easterDatesProvider(): array {
        return [
            // Recent years
            [2020, '2020-04-12'],
            [2021, '2021-04-04'],
            [2022, '2022-04-17'],
            [2023, '2023-04-09'],
            [2024, '2024-03-31'],
            [2025, '2025-04-20'], // From plan specification
            [2026, '2026-04-05'], // From plan specification
            [2027, '2027-03-28'], // From plan specification
            [2028, '2028-04-16'],
            [2029, '2029-04-01'],
            [2030, '2030-04-21'],

            // Edge cases - earliest possible Easter (March 22)
            [2285, '2285-03-22'],

            // Edge cases - latest possible Easter (April 25)
            [2038, '2038-04-25'],

            // Historical verification
            [2000, '2000-04-23'],
            [1990, '1990-04-15'],
        ];
    }

    public function testCalculateEasterBasedHolidays(): void {
        // Test that Easter-dependent holidays are calculated correctly for 2026
        $easterSunday = $this->service->calculateEasterSunday(2026);

        // Karfreitag (Good Friday) = Easter - 2
        $karfreitag = (clone $easterSunday)->modify('-2 days');
        $this->assertEquals('2026-04-03', $karfreitag->format('Y-m-d'));

        // Ostermontag (Easter Monday) = Easter + 1
        $ostermontag = (clone $easterSunday)->modify('+1 day');
        $this->assertEquals('2026-04-06', $ostermontag->format('Y-m-d'));

        // Christi Himmelfahrt (Ascension Day) = Easter + 39
        $himmelfahrt = (clone $easterSunday)->modify('+39 days');
        $this->assertEquals('2026-05-14', $himmelfahrt->format('Y-m-d'));

        // Pfingstmontag (Whit Monday) = Easter + 50
        $pfingstmontag = (clone $easterSunday)->modify('+50 days');
        $this->assertEquals('2026-05-25', $pfingstmontag->format('Y-m-d'));

        // Fronleichnam (Corpus Christi) = Easter + 60
        $fronleichnam = (clone $easterSunday)->modify('+60 days');
        $this->assertEquals('2026-06-04', $fronleichnam->format('Y-m-d'));
    }

    public function testGetFederalStatesListsAllRegionsOfBothCountries(): void {
        $states = $this->service->getFederalStates();

        $this->assertCount(42, $states);
        $this->assertSame('Bayern', $states['DE-BY']);
        $this->assertSame('Berlin', $states['DE-BE']);
        $this->assertSame('Bern', $states['CH-BE']);
        $this->assertSame('Zürich', $states['CH-ZH']);
        $this->assertArrayNotHasKey('BY', $states);
    }

    /**
     * generateHolidays() legt genau die Provider-Feiertage der Region an
     * (ohne Sondertage, die Firmeneinstellung ist hier aus).
     * @dataProvider generatedCountProvider
     */
    public function testGenerateInsertsProviderHolidays(string $region, int $expectedCount): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(false);
        $this->holidayMapper->method('insert')->willReturnArgument(0);
        $this->holidayMapper->expects($this->once())->method('deleteAutoByYearAndState')->with(2026, $region);

        $holidays = $this->service->generateHolidays(2026, $region);

        $this->assertCount($expectedCount, $holidays);
        foreach ($holidays as $holiday) {
            $this->assertSame($region, $holiday->getFederalState());
            $this->assertSame(2026, $holiday->getYear());
            $this->assertFalse((bool)$holiday->getIsManual());
        }
    }

    public static function generatedCountProvider(): array {
        return [
            ['DE-BY', 13], // Bayern: alle deutschen Feiertage
            ['DE-BE', 10], // Berlin: bundesweit plus Frauentag
            ['DE-NW', 11], // NRW: bundesweit plus Allerheiligen, Fronleichnam
            ['DE-SN', 11], // Sachsen: bundesweit plus Reformationstag, Buss- und Bettag
            ['CH-ZH', 10],
            ['CH-TI', 15],
            ['CH-AR', 7],  // Stephanstag 2026 (Samstag) entfaellt
        ];
    }

    public function testGenerateAppendsSpecialDaysForEveryCountry(): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(true);
        $this->holidayMapper->method('insert')->willReturnArgument(0);

        $holidays = $this->service->generateHolidays(2026, 'CH-ZH');

        $this->assertCount(12, $holidays);
        $names = array_map(static fn(Holiday $h) => $h->getName(), $holidays);
        $this->assertContains('Heiligabend', $names);
        $this->assertContains('Silvester', $names);
        $lastTwo = array_slice($holidays, -2);
        $this->assertSame(0.5, $lastTwo[0]->getScopeValue());
    }

    public function testGenerateNormalizesLegacyCode(): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(false);
        $this->holidayMapper->method('insert')->willReturnArgument(0);
        $this->holidayMapper->expects($this->once())->method('deleteAutoByYearAndState')->with(2026, 'DE-BY');

        $holidays = $this->service->generateHolidays(2026, 'BY');

        $this->assertCount(13, $holidays);
        $this->assertSame('DE-BY', $holidays[0]->getFederalState());
    }

    public function testGenerateWithoutProviderLogsWarningAndReturnsNothing(): void {
        $this->holidayMapper->expects($this->never())->method('insert');
        $this->holidayMapper->expects($this->never())->method('deleteAutoByYearAndState');
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame([], $this->service->generateHolidays(2026, 'AT-9'));
    }

    public function testCreateManualRejectsUnknownRegion(): void {
        $this->holidayMapper->expects($this->never())->method('insert');

        $this->expectException(ValidationException::class);
        $this->service->createManual('2026-05-04', 'Brückentag', ['CH-ZH', 'CH-XX'], 1.0, 'admin');
    }

    public function testCreateManualNormalizesLegacyCodes(): void {
        $this->holidayMapper->method('isHoliday')->willReturn(false);
        $this->holidayMapper->method('insert')->willReturnArgument(0);

        $holidays = $this->service->createManual('2026-05-04', 'Brückentag', ['BY', 'CH-ZH'], 1.0, 'admin');

        $this->assertCount(2, $holidays);
        $this->assertSame('DE-BY', $holidays[0]->getFederalState());
        $this->assertSame('CH-ZH', $holidays[1]->getFederalState());
        $this->assertTrue((bool)$holidays[0]->getIsManual());
    }

    // ---------------------------------------------------------------------
    // #438: Lazy-Ensure fehlender Feiertage
    // ---------------------------------------------------------------------

    public function testEnsureGeneratesHolidaysWhenMissing(): void {
        // No auto holidays for the combo yet → generation runs (inserts happen).
        $this->holidayMapper->method('hasAutoForYearAndState')->with(2027, 'DE-BW')->willReturn(false);
        $this->holidayMapper->method('insert')->willReturnArgument(0);
        $this->holidayMapper->expects($this->atLeastOnce())->method('insert');

        $this->service->ensureHolidaysForYear(2027, 'DE-BW');
    }

    public function testEnsureSkipsGenerationWhenAlreadyPresent(): void {
        // Auto holidays already exist → no delete, no insert.
        $this->holidayMapper->method('hasAutoForYearAndState')->with(2026, 'DE-BY')->willReturn(true);
        $this->holidayMapper->expects($this->never())->method('insert');
        $this->holidayMapper->expects($this->never())->method('deleteAutoByYearAndState');

        $this->service->ensureHolidaysForYear(2026, 'DE-BY');
    }

    public function testEnsureGeneratesWhenOnlyAManualHolidayExists(): void {
        // #438 review: a single pre-existing MANUAL holiday must not suppress the
        // deterministic set — the guard checks auto holidays only, so generation
        // still runs here.
        $this->holidayMapper->method('hasAutoForYearAndState')->with(2027, 'DE-BW')->willReturn(false);
        $this->holidayMapper->method('insert')->willReturnArgument(0);
        $this->holidayMapper->expects($this->atLeastOnce())->method('insert');

        $this->service->ensureHolidaysForYear(2027, 'DE-BW');
    }

    public function testGenerateToleratesUniqueConstraintViolation(): void {
        // #438 review: a concurrent first-time generation (or a manual holiday on
        // the same date) makes an insert hit the (date, state) unique index. The
        // service must treat it as already-present instead of failing the request.
        $this->holidayMapper->method('hasAutoForYearAndState')->willReturn(false);
        $uniqueViolation = new class ('duplicate') extends DbException {
            public function getReason(): ?int {
                return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
            }
        };
        $this->holidayMapper->method('insert')->willThrowException($uniqueViolation);
        $this->holidayMapper->method('findByDateAndState')->willReturn(new Holiday());

        // Must not throw.
        $this->service->ensureHolidaysForYear(2027, 'DE-BW');
        $this->addToAssertionCount(1);
    }

    public function testEnsureMemoizesSoTheCheckRunsOncePerCombo(): void {
        // Two calls for the same (year, state) must hit the DB check only once.
        $this->holidayMapper->expects($this->once())
            ->method('hasAutoForYearAndState')->with(2026, 'DE-BY')->willReturn(true);

        $this->service->ensureHolidaysForYear(2026, 'DE-BY');
        $this->service->ensureHolidaysForYear(2026, 'DE-BY');
    }

    /**
     * 0.18.1: Auto-Sets, die vor 0.18.0 erzeugt wurden, bekommen die neuen
     * Provider-Feiertage nachgetragen, ohne dass etwas geloescht wird.
     */
    public function testFillMissingAddsProviderHolidaysToExistingAutoSet(): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(false);
        $this->holidayMapper->method('findAutoYearStateCombos')->willReturn([
            ['year' => 2026, 'federal_state' => 'DE-SN'],
        ]);
        $existing = $this->providerHolidaysWithout(2026, 'DE-SN', ['Buß- und Bettag']);
        $this->holidayMapper->method('findByYearAndState')->with(2026, 'DE-SN')->willReturn($existing);
        $this->holidayMapper->expects($this->never())->method('deleteAutoByYearAndState');
        $inserted = [];
        $this->holidayMapper->method('insert')->willReturnCallback(function (Holiday $h) use (&$inserted) {
            $inserted[] = $h;
            return $h;
        });

        $result = $this->service->fillMissingAutoHolidays();

        $this->assertSame(['2026 DE-SN' => 1], $result);
        $this->assertCount(1, $inserted);
        $this->assertSame('Buß- und Bettag', $inserted[0]->getName());
        $this->assertSame('2026-11-18', $inserted[0]->getDate()->format('Y-m-d'));
        $this->assertSame('DE-SN', $inserted[0]->getFederalState());
        $this->assertSame(2026, $inserted[0]->getYear());
        $this->assertFalse((bool)$inserted[0]->getIsManual());
    }

    /**
     * Codex-Review 0.18.1: mit Namensfilter werden nur die genannten Feiertage
     * nachgetragen. Ein bewusst geloeschter anderer Auto-Feiertag (hier
     * Reformationstag) bleibt geloescht.
     */
    public function testFillMissingWithNameFilterLeavesOtherMissingHolidaysAlone(): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(false);
        $this->holidayMapper->method('findAutoYearStateCombos')->willReturn([
            ['year' => 2026, 'federal_state' => 'DE-SN'],
        ]);
        $existing = $this->providerHolidaysWithout(2026, 'DE-SN', ['Buß- und Bettag', 'Reformationstag']);
        $this->holidayMapper->method('findByYearAndState')->willReturn($existing);
        $inserted = [];
        $this->holidayMapper->method('insert')->willReturnCallback(function (Holiday $h) use (&$inserted) {
            $inserted[] = $h;
            return $h;
        });

        $result = $this->service->fillMissingAutoHolidays(['Buß- und Bettag', 'Internationaler Frauentag', 'Weltkindertag']);

        $this->assertSame(['2026 DE-SN' => 1], $result);
        $this->assertCount(1, $inserted);
        $this->assertSame('Buß- und Bettag', $inserted[0]->getName());
    }

    public function testFillMissingDoesNothingForCompleteSets(): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(false);
        $this->holidayMapper->method('findAutoYearStateCombos')->willReturn([
            ['year' => 2026, 'federal_state' => 'DE-BW'],
            ['year' => 2026, 'federal_state' => 'CH-ZH'],
        ]);
        $this->holidayMapper->method('findByYearAndState')->willReturnCallback(
            fn (int $year, string $region) => $this->providerHolidaysWithout($year, $region, [])
        );
        $this->holidayMapper->expects($this->never())->method('insert');

        $this->assertSame([], $this->service->fillMissingAutoHolidays());
    }

    public function testFillMissingSkipsDatesTakenByManualHolidays(): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(false);
        $this->holidayMapper->method('findAutoYearStateCombos')->willReturn([
            ['year' => 2026, 'federal_state' => 'DE-SN'],
        ]);
        $existing = $this->providerHolidaysWithout(2026, 'DE-SN', ['Buß- und Bettag']);
        $manual = new Holiday();
        $manual->setDate(new DateTime('2026-11-18'));
        $manual->setName('Betriebsfeiertag');
        $manual->setFederalState('DE-SN');
        $manual->setYear(2026);
        $manual->setIsManual(true);
        $existing[] = $manual;
        $this->holidayMapper->method('findByYearAndState')->willReturn($existing);
        $this->holidayMapper->expects($this->never())->method('insert');

        $this->assertSame([], $this->service->fillMissingAutoHolidays());
    }

    public function testFillMissingSkipsRegionsWithoutProvider(): void {
        $this->settingsMapper->method('getValueAsBool')->willReturn(false);
        $this->holidayMapper->method('findAutoYearStateCombos')->willReturn([
            ['year' => 2026, 'federal_state' => 'XX'],
        ]);
        $this->holidayMapper->expects($this->never())->method('findByYearAndState');
        $this->holidayMapper->expects($this->never())->method('insert');

        $this->assertSame([], $this->service->fillMissingAutoHolidays());
    }

    /**
     * Baut das Auto-Set eines Providers als Entities, optional ohne einzelne Namen.
     *
     * @param string[] $withoutNames
     * @return Holiday[]
     */
    private function providerHolidaysWithout(int $year, string $region, array $withoutNames): array {
        $registry = new ProviderRegistry(new GermanyHolidays(), new SwitzerlandHolidays());
        $holidays = [];
        foreach ($registry->forRegion($region)->holidaysFor($year, $region) as $definition) {
            if (in_array($definition->name, $withoutNames, true)) {
                continue;
            }
            $holiday = new Holiday();
            $holiday->setDate(new DateTime($definition->date->format('Y-m-d')));
            $holiday->setName($definition->name);
            $holiday->setFederalState($region);
            $holiday->setYear($year);
            $holiday->setScopeValue($definition->scope);
            $holidays[] = $holiday;
        }
        return $holidays;
    }

    public function testEnsureRangeCoversEveryYearItTouches(): void {
        // A range crossing New Year must ensure both 2026 and 2027.
        $checkedYears = [];
        $this->holidayMapper->method('hasAutoForYearAndState')->willReturnCallback(
            function (int $year, string $state) use (&$checkedYears): bool {
                $checkedYears[] = $year;
                return true;
            }
        );

        $this->service->ensureHolidaysForRange(
            new DateTime('2026-12-20'), new DateTime('2027-01-10'), 'DE-BY'
        );

        $this->assertSame([2026, 2027], $checkedYears);
    }
}
