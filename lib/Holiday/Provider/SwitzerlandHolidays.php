<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Holiday\Provider;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Zeitwerk\Holiday\HolidayDefinition;
use OCA\Zeitwerk\Holiday\Rules;

/**
 * Kantonsweit arbeitsfreie Feiertage der Schweiz.
 *
 * Quelle: Bundesamt fuer Justiz, «Gesetzliche Feiertage und Tage, die in der
 * Schweiz wie gesetzliche Feiertage behandelt werden» (Stand 1.1.2011),
 * Abschnitte a (gesetzlich anerkannt) und b (wie gesetzliche Feiertage behandelt),
 * ergaenzt um kantonsweit faktisch arbeitsfreie ganze Tage (Wikipedia
 * «Feiertage in der Schweiz», Neuenburg: Ostermontag, Pfingstmontag).
 *
 * Einschlussregel (siehe FEIERTAGE.md, Abschnitt «Einschlussregel»):
 * - gemeindeweise Feiertage werden nicht erzeugt (Solothurner Josefstag und
 *   Patrozinien, Luzerner Josefstag, Graubuendner Gemeindefeste, Fronleichnam in
 *   Le Landeron NE)
 * - Bezirksausnahmen werden zugunsten der Kantonsmehrheit ignoriert (Freiburger
 *   Seebezirk, Solothurner Bezirk Buchegg, Appenzeller Bezirk Oberegg)
 * - Halbtag nur, wenn gesetzlich: Solothurn 1. Mai. Aargau 1. Mai (Brauch) fehlt.
 * - reine Sonntage (Bettag) werden nicht erzeugt
 * - Bundesfeiertag 1.8. ueberall (Bundesrecht)
 * - Aargau: katholische Feste nach BJ-Verzeichnis kantonsweit (Entscheid 2026-09-05)
 *
 * Details, Tabellen und Sonderfaelle: FEIERTAGE.md im Repo-Root.
 */
final class SwitzerlandHolidays implements HolidayProviderInterface {

    /** Suffix in CANTONS: entfaellt, wenn der Tag auf Dienstag oder Samstag faellt (UR, AR, AI, AG) */
    private const MOD_SKIP_TUE_SAT = '*';
    /** Suffix in CANTONS: nur, wenn der Tag ein Montag ist (Neuenburg 2.1. und 26.12.) */
    private const MOD_ONLY_MONDAY = '?';
    /** Suffix in CANTONS: halber Tag (Scope 0.5), ganzer Tag wenn Montag (Solothurn 1. Mai) */
    private const MOD_HALF_UNLESS_MONDAY = '~';

    /** @var array<string, string> Kuerzel => gespeicherter Name */
    private const NAMES = [
        'NJ' => 'Neujahr',
        'BT' => 'Berchtoldstag',
        'DK' => 'Heilige Drei Könige',
        'IR' => 'Instauration de la République',
        'JT' => 'Josefstag',
        'KF' => 'Karfreitag',
        'OM' => 'Ostermontag',
        'NF' => 'Näfelser Fahrt',
        'TA' => 'Tag der Arbeit',
        'AU' => 'Auffahrt',
        'PM' => 'Pfingstmontag',
        'FL' => 'Fronleichnam',
        'PJ' => 'Commémoration du plébiscite jurassien',
        'PP' => 'Peter und Paul',
        'BF' => 'Bundesfeiertag',
        'MH' => 'Mariä Himmelfahrt',
        'JG' => 'Jeûne genevois',
        'BM' => 'Bettagsmontag',
        'MT' => 'Mauritiustag',
        'BK' => 'Bruderklausenfest',
        'AH' => 'Allerheiligen',
        'ME' => 'Mariä Empfängnis',
        'WT' => 'Weihnachtstag',
        'ST' => 'Stephanstag',
        'RR' => 'Restauration de la République',
    ];

    /**
     * Kanton => Kuerzel (siehe NAMES) mit optionalem Modifier-Suffix.
     * Reihenfolge innerhalb der Zeile ist egal, das Ergebnis wird nach Datum sortiert.
     *
     * @var array<string, string>
     */
    private const CANTONS = [
        'AG' => 'NJ BT* KF OM AU PM FL MH BF AH ME WT ST*',
        'AI' => 'NJ KF OM AU PM FL BF MH MT AH ME WT ST*',
        'AR' => 'NJ KF OM AU PM BF WT ST*',
        'BE' => 'NJ BT KF OM AU PM BF WT ST',
        'BL' => 'NJ KF OM TA AU PM BF WT ST',
        'BS' => 'NJ KF OM TA AU PM BF WT ST',
        'FR' => 'NJ BT KF OM AU PM FL BF MH AH ME WT ST',
        'GE' => 'NJ KF OM AU PM BF JG WT RR',
        'GL' => 'NJ BT KF OM NF AU PM BF AH WT ST',
        'GR' => 'NJ KF OM AU PM BF WT ST',
        'JU' => 'NJ BT KF OM TA AU PM FL PJ BF MH AH WT',
        'LU' => 'NJ BT KF OM AU PM FL BF MH AH ME WT ST',
        'NE' => 'NJ BT? IR KF OM TA AU PM BF WT ST?',
        'NW' => 'NJ BT JT KF OM AU PM FL BF MH AH ME WT ST',
        'OW' => 'NJ BT KF OM AU PM FL BF MH BK AH ME WT ST',
        'SG' => 'NJ BT KF OM AU PM BF AH WT ST',
        'SH' => 'NJ BT KF OM TA AU PM BF WT ST',
        'SO' => 'NJ BT KF OM TA~ AU PM FL BF MH AH WT ST',
        'SZ' => 'NJ DK JT KF OM AU PM FL BF MH AH ME WT ST',
        'TG' => 'NJ BT KF OM TA AU PM BF WT ST',
        'TI' => 'NJ DK JT OM TA AU PM FL PP BF MH AH ME WT ST',
        'UR' => 'NJ DK JT KF OM AU PM FL BF MH AH ME WT ST*',
        'VD' => 'NJ BT KF OM AU PM BF BM WT',
        'VS' => 'NJ BT JT OM AU PM FL BF MH AH ME WT ST',
        'ZG' => 'NJ BT KF OM AU PM FL BF MH AH ME WT ST',
        'ZH' => 'NJ BT KF OM TA AU PM BF WT ST',
    ];

    public function country(): string {
        return 'CH';
    }

    public function holidaysFor(int $year, string $region): array {
        $canton = str_starts_with($region, 'CH-') ? substr($region, 3) : $region;
        if (!isset(self::CANTONS[$canton])) {
            return [];
        }

        $holidays = [];
        foreach (explode(' ', self::CANTONS[$canton]) as $token) {
            $code = substr($token, 0, 2);
            $modifier = substr($token, 2);
            $date = self::dateFor($code, $year);

            if ($modifier === self::MOD_SKIP_TUE_SAT && Rules::isWeekday($date, Rules::TUESDAY, Rules::SATURDAY)) {
                continue;
            }
            if ($modifier === self::MOD_ONLY_MONDAY && !Rules::isWeekday($date, Rules::MONDAY)) {
                continue;
            }
            $scope = 1.0;
            if ($modifier === self::MOD_HALF_UNLESS_MONDAY && !Rules::isWeekday($date, Rules::MONDAY)) {
                $scope = 0.5;
            }

            $holidays[] = new HolidayDefinition($date, self::NAMES[$code], $scope);
        }

        usort($holidays, static fn(HolidayDefinition $a, HolidayDefinition $b): int => $a->date <=> $b->date);

        return $holidays;
    }

    /** Datum eines Feiertags-Kuerzels im Jahr. */
    public static function dateFor(string $code, int $year): DateTimeImmutable {
        return match ($code) {
            'NJ' => Rules::fixed($year, 1, 1),
            'BT' => Rules::fixed($year, 1, 2),
            'DK' => Rules::fixed($year, 1, 6),
            'IR' => Rules::fixed($year, 3, 1),
            'JT' => Rules::fixed($year, 3, 19),
            'KF' => Rules::easterOffset($year, -2),
            'OM' => Rules::easterOffset($year, 1),
            'NF' => self::naefelserFahrt($year),
            'TA' => Rules::fixed($year, 5, 1),
            'AU' => Rules::easterOffset($year, 39),
            'PM' => Rules::easterOffset($year, 50),
            'FL' => Rules::easterOffset($year, 60),
            'PJ' => Rules::fixed($year, 6, 23),
            'PP' => Rules::fixed($year, 6, 29),
            'BF' => Rules::fixed($year, 8, 1),
            'MH' => Rules::fixed($year, 8, 15),
            // Donnerstag nach dem ersten Sonntag im September
            'JG' => Rules::nthWeekdayOfMonth($year, 9, Rules::SUNDAY, 1)->modify('+4 days'),
            // Montag nach dem Eidgenoessischen Dank-, Buss- und Bettag (dritter Sonntag im September)
            'BM' => Rules::nthWeekdayOfMonth($year, 9, Rules::SUNDAY, 3)->modify('+1 day'),
            'MT' => Rules::fixed($year, 9, 22),
            'BK' => Rules::fixed($year, 9, 25),
            'AH' => Rules::fixed($year, 11, 1),
            'ME' => Rules::fixed($year, 12, 8),
            'WT' => Rules::fixed($year, 12, 25),
            'ST' => Rules::fixed($year, 12, 26),
            'RR' => Rules::fixed($year, 12, 31),
            default => throw new InvalidArgumentException("Unknown Swiss holiday code: $code"),
        };
    }

    /** Erster Donnerstag im April; faellt er auf den Gruendonnerstag, eine Woche spaeter. */
    public static function naefelserFahrt(int $year): DateTimeImmutable {
        $date = Rules::nthWeekdayOfMonth($year, 4, Rules::THURSDAY, 1);
        $maundyThursday = Rules::easterOffset($year, -3);
        if ($date->format('Y-m-d') === $maundyThursday->format('Y-m-d')) {
            $date = $date->modify('+7 days');
        }
        return $date;
    }
}
