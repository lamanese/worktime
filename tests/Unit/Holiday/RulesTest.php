<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Holiday;

use OCA\Zeitwerk\Holiday\HolidayDefinition;
use OCA\Zeitwerk\Holiday\Rules;
use PHPUnit\Framework\TestCase;

class RulesTest extends TestCase {

    /**
     * @dataProvider easterDatesProvider
     */
    public function testEasterSunday(int $year, string $expected): void {
        $this->assertSame($expected, Rules::easterSunday($year)->format('Y-m-d'));
    }

    /** Quelle: https://de.wikipedia.org/wiki/Osterdatum (uebernommen aus HolidayServiceTest) */
    public static function easterDatesProvider(): array {
        return [
            [2020, '2020-04-12'], [2021, '2021-04-04'], [2022, '2022-04-17'], [2023, '2023-04-09'],
            [2024, '2024-03-31'], [2025, '2025-04-20'], [2026, '2026-04-05'], [2027, '2027-03-28'],
            [2028, '2028-04-16'], [2029, '2029-04-01'], [2030, '2030-04-21'],
            [2285, '2285-03-22'], // fruehestmoegliches Ostern
            [2038, '2038-04-25'], // spaetestmoegliches Ostern
            [2000, '2000-04-23'], [1990, '1990-04-15'],
        ];
    }

    public function testFixedAndEasterOffset(): void {
        $this->assertSame('2026-08-01', Rules::fixed(2026, 8, 1)->format('Y-m-d'));
        $this->assertSame('2026-04-03', Rules::easterOffset(2026, -2)->format('Y-m-d'));  // Karfreitag
        $this->assertSame('2026-04-06', Rules::easterOffset(2026, 1)->format('Y-m-d'));   // Ostermontag
        $this->assertSame('2026-05-14', Rules::easterOffset(2026, 39)->format('Y-m-d'));  // Auffahrt
        $this->assertSame('2026-05-25', Rules::easterOffset(2026, 50)->format('Y-m-d'));  // Pfingstmontag
        $this->assertSame('2026-06-04', Rules::easterOffset(2026, 60)->format('Y-m-d'));  // Fronleichnam
        // Ergebnisse sind immutable und tragen keine Uhrzeit
        $this->assertSame('00:00:00', Rules::fixed(2026, 1, 1)->format('H:i:s'));
    }

    public function testNthWeekdayOfMonth(): void {
        // 1. Donnerstag im April 2026 ist der 2.4. (1.4.2026 ist ein Mittwoch)
        $this->assertSame('2026-04-02', Rules::nthWeekdayOfMonth(2026, 4, Rules::THURSDAY, 1)->format('Y-m-d'));
        // 1. Sonntag im September 2026 ist der 6.9., 3. Sonntag der 20.9.
        $this->assertSame('2026-09-06', Rules::nthWeekdayOfMonth(2026, 9, Rules::SUNDAY, 1)->format('Y-m-d'));
        $this->assertSame('2026-09-20', Rules::nthWeekdayOfMonth(2026, 9, Rules::SUNDAY, 3)->format('Y-m-d'));
        // Monat beginnt mit dem gesuchten Wochentag: 1. Donnerstag im April 2021 ist der 1.4.
        $this->assertSame('2021-04-01', Rules::nthWeekdayOfMonth(2021, 4, Rules::THURSDAY, 1)->format('Y-m-d'));
    }

    public function testIsWeekday(): void {
        $friday = Rules::fixed(2026, 1, 2);
        $this->assertTrue(Rules::isWeekday($friday, Rules::FRIDAY));
        $this->assertTrue(Rules::isWeekday($friday, Rules::TUESDAY, Rules::FRIDAY));
        $this->assertFalse(Rules::isWeekday($friday, Rules::TUESDAY, Rules::SATURDAY));
        $this->assertTrue(Rules::isWeekday(Rules::fixed(2023, 1, 2), Rules::MONDAY));
        $this->assertTrue(Rules::isWeekday(Rules::fixed(2026, 12, 26), Rules::SATURDAY));
    }

    public function testHolidayDefinitionDefaults(): void {
        $def = new HolidayDefinition(Rules::fixed(2026, 5, 1), 'Tag der Arbeit');
        $this->assertSame(1.0, $def->scope);
        $half = new HolidayDefinition(Rules::fixed(2026, 5, 1), 'Tag der Arbeit', 0.5);
        $this->assertSame(0.5, $half->scope);
        $this->assertSame('2026-05-01', $half->date->format('Y-m-d'));
    }
}
