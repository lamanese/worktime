<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Holiday;

use OCA\Zeitwerk\Holiday\Provider\GermanyHolidays;
use OCA\Zeitwerk\Holiday\Provider\HolidayProviderInterface;
use PHPUnit\Framework\TestCase;

class GermanyHolidaysTest extends TestCase {

    private GermanyHolidays $provider;

    protected function setUp(): void {
        $this->provider = new GermanyHolidays();
    }

    /** @return string[] "YYYY-MM-DD Name" in Provider-Reihenfolge */
    private function listFor(int $year, string $region): array {
        $out = [];
        foreach ($this->provider->holidaysFor($year, $region) as $def) {
            $out[] = $def->date->format('Y-m-d') . ' ' . $def->name . ($def->scope < 1.0 ? ' [0.5]' : '');
        }
        return $out;
    }

    /** @return string[] nur Namen */
    private function namesFor(int $year, string $region): array {
        return array_map(static fn($def) => $def->name, $this->provider->holidaysFor($year, $region));
    }

    public function testIsAProviderForGermany(): void {
        $this->assertInstanceOf(HolidayProviderInterface::class, $this->provider);
        $this->assertSame('DE', $this->provider->country());
    }

    /**
     * Vollstaendige Listen 2026 je Bundesland (Ostern 2026 = 5.4.).
     * @dataProvider states2026Provider
     */
    public function testAllStates2026(string $region, array $expected): void {
        $this->assertSame($expected, $this->listFor(2026, $region));
    }

    public static function states2026Provider(): array {
        $nationwide = [
            '2026-01-01 Neujahr',
            '2026-04-03 Karfreitag',
            '2026-04-06 Ostermontag',
            '2026-05-01 Tag der Arbeit',
            '2026-05-14 Christi Himmelfahrt',
            '2026-05-25 Pfingstmontag',
            '2026-10-03 Tag der Deutschen Einheit',
            '2026-12-25 1. Weihnachtstag',
            '2026-12-26 2. Weihnachtstag',
        ];
        $with = static function (array $extra) use ($nationwide): array {
            $list = array_merge($nationwide, $extra);
            sort($list); // ISO-Datum am Anfang, also chronologisch
            return $list;
        };
        $hdk = '2026-01-06 Heilige Drei Könige';
        $fl = '2026-06-04 Fronleichnam';
        $mh = '2026-08-15 Mariä Himmelfahrt';
        $ah = '2026-11-01 Allerheiligen';
        $ref = '2026-10-31 Reformationstag';
        $frauentag = '2026-03-08 Internationaler Frauentag';

        return [
            'DE-BW' => ['DE-BW', $with([$hdk, $fl, $ah])],
            'DE-BY' => ['DE-BY', $with([$hdk, $fl, $mh, $ah])],
            'DE-BE' => ['DE-BE', $with([$frauentag])],
            'DE-BB' => ['DE-BB', $with([$ref])],
            'DE-HB' => ['DE-HB', $with([$ref])],
            'DE-HH' => ['DE-HH', $with([$ref])],
            'DE-HE' => ['DE-HE', $with([$fl])],
            'DE-MV' => ['DE-MV', $with([$frauentag, $ref])],
            'DE-NI' => ['DE-NI', $with([$ref])],
            'DE-NW' => ['DE-NW', $with([$fl, $ah])],
            'DE-RP' => ['DE-RP', $with([$fl, $ah])],
            'DE-SL' => ['DE-SL', $with([$fl, $mh, $ah])],
            'DE-SN' => ['DE-SN', $with([$ref, '2026-11-18 Buß- und Bettag'])],
            'DE-ST' => ['DE-ST', $with([$hdk, $ref])],
            'DE-SH' => ['DE-SH', $with([$ref])],
            'DE-TH' => ['DE-TH', $with(['2026-09-20 Weltkindertag', $ref])],
        ];
    }

    public function testCountsMatchTheKnownTotals(): void {
        $this->assertCount(13, $this->provider->holidaysFor(2026, 'DE-BY'));
        $this->assertCount(10, $this->provider->holidaysFor(2026, 'DE-BE'));
        $this->assertCount(11, $this->provider->holidaysFor(2026, 'DE-NW'));
        $this->assertCount(11, $this->provider->holidaysFor(2026, 'DE-SN'));
    }

    public function testYearBoundsOfTheNewHolidays(): void {
        $this->assertNotContains('Internationaler Frauentag', $this->namesFor(2018, 'DE-BE'));
        $this->assertContains('Internationaler Frauentag', $this->namesFor(2019, 'DE-BE'));
        $this->assertNotContains('Internationaler Frauentag', $this->namesFor(2022, 'DE-MV'));
        $this->assertContains('Internationaler Frauentag', $this->namesFor(2023, 'DE-MV'));
        $this->assertNotContains('Internationaler Frauentag', $this->namesFor(2026, 'DE-BY'));
        $this->assertNotContains('Weltkindertag', $this->namesFor(2018, 'DE-TH'));
        $this->assertContains('Weltkindertag', $this->namesFor(2019, 'DE-TH'));
        $this->assertNotContains('Weltkindertag', $this->namesFor(2026, 'DE-BY'));
        $this->assertNotContains('Buß- und Bettag', $this->namesFor(2026, 'DE-BY'));
        $this->assertContains('Buß- und Bettag', $this->namesFor(2026, 'DE-SN'));
    }

    public function testBussUndBettagIsTheWednesdayBeforeNovember23(): void {
        $this->assertSame('2022-11-16', GermanyHolidays::bussUndBettag(2022)->format('Y-m-d')); // 23.11.2022 ist selbst ein Mittwoch
        $this->assertSame('2025-11-19', GermanyHolidays::bussUndBettag(2025)->format('Y-m-d'));
        $this->assertSame('2026-11-18', GermanyHolidays::bussUndBettag(2026)->format('Y-m-d'));
        $this->assertSame('2027-11-17', GermanyHolidays::bussUndBettag(2027)->format('Y-m-d'));
    }

    public function testBareStateSuffixIsTolerated(): void {
        $this->assertSame($this->listFor(2026, 'DE-BY'), $this->listFor(2026, 'BY'));
    }

    public function testResultIsSortedByDate(): void {
        $dates = array_map(static fn($def) => $def->date->format('Y-m-d'), $this->provider->holidaysFor(2026, 'DE-SN'));
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }
}
