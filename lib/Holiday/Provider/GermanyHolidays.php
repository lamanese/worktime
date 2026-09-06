<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Holiday\Provider;

use DateTimeImmutable;
use OCA\Zeitwerk\Holiday\HolidayDefinition;
use OCA\Zeitwerk\Holiday\Rules;

/**
 * Gesetzliche Feiertage in Deutschland nach Bundesland.
 *
 * Die Namen der seit 0.1.0 erzeugten Feiertage sind unveraendert, damit eine
 * Regenerierung keine Diffs erzeugt. Neu seit 0.18.0: Buss- und Bettag (SN),
 * Internationaler Frauentag (BE ab 2019, MV ab 2023), Weltkindertag (TH ab 2019).
 */
final class GermanyHolidays implements HolidayProviderInterface {

    /** @var array<string, array{0: int, 1: int, 2: list<string>|null}> Name => [Monat, Tag, Bundeslaender oder null = alle] */
    private const FIXED = [
        'Neujahr' => [1, 1, null],
        'Heilige Drei Könige' => [1, 6, ['BW', 'BY', 'ST']],
        'Tag der Arbeit' => [5, 1, null],
        'Mariä Himmelfahrt' => [8, 15, ['BY', 'SL']],
        'Tag der Deutschen Einheit' => [10, 3, null],
        'Reformationstag' => [10, 31, ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH']],
        'Allerheiligen' => [11, 1, ['BW', 'BY', 'NW', 'RP', 'SL']],
        '1. Weihnachtstag' => [12, 25, null],
        '2. Weihnachtstag' => [12, 26, null],
    ];

    /** @var array<string, int> Name => Tage relativ zum Ostersonntag, bundesweit */
    private const EASTER_BASED = [
        'Karfreitag' => -2,
        'Ostermontag' => 1,
        'Christi Himmelfahrt' => 39,
        'Pfingstmontag' => 50,
    ];

    private const FRONLEICHNAM_STATES = ['BW', 'BY', 'HE', 'NW', 'RP', 'SL'];

    /** @var array<string, int> Bundesland => erstes Jahr mit Frauentag als Feiertag */
    private const WOMENS_DAY_SINCE = ['BE' => 2019, 'MV' => 2023];

    private const WORLD_CHILDRENS_DAY_SINCE = 2019; // Thueringen

    private const REPENTANCE_DAY_SINCE = 1995; // Sachsen; bis 1994 bundesweit

    public function country(): string {
        return 'DE';
    }

    public function holidaysFor(int $year, string $region): array {
        $state = self::stateOf($region);
        $holidays = [];

        foreach (self::FIXED as $name => [$month, $day, $states]) {
            if ($states === null || in_array($state, $states, true)) {
                $holidays[] = new HolidayDefinition(Rules::fixed($year, $month, $day), $name);
            }
        }

        foreach (self::EASTER_BASED as $name => $offset) {
            $holidays[] = new HolidayDefinition(Rules::easterOffset($year, $offset), $name);
        }

        if (in_array($state, self::FRONLEICHNAM_STATES, true)) {
            $holidays[] = new HolidayDefinition(Rules::easterOffset($year, 60), 'Fronleichnam');
        }

        if (isset(self::WOMENS_DAY_SINCE[$state]) && $year >= self::WOMENS_DAY_SINCE[$state]) {
            $holidays[] = new HolidayDefinition(Rules::fixed($year, 3, 8), 'Internationaler Frauentag');
        }

        if ($state === 'TH' && $year >= self::WORLD_CHILDRENS_DAY_SINCE) {
            $holidays[] = new HolidayDefinition(Rules::fixed($year, 9, 20), 'Weltkindertag');
        }

        if ($state === 'SN' && $year >= self::REPENTANCE_DAY_SINCE) {
            $holidays[] = new HolidayDefinition(self::bussUndBettag($year), 'Buß- und Bettag');
        }

        usort($holidays, static fn(HolidayDefinition $a, HolidayDefinition $b): int => $a->date <=> $b->date);

        return $holidays;
    }

    /** Mittwoch vor dem 23. November, also der letzte Mittwoch zwischen dem 16. und 22.11. */
    public static function bussUndBettag(int $year): DateTimeImmutable {
        $date = Rules::fixed($year, 11, 22);
        while (!Rules::isWeekday($date, Rules::WEDNESDAY)) {
            $date = $date->modify('-1 day');
        }
        return $date;
    }

    private static function stateOf(string $region): string {
        return str_starts_with($region, 'DE-') ? substr($region, 3) : $region;
    }
}
