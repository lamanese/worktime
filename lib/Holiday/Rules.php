<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Holiday;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Datumsbausteine fuer die Feiertags-Provider. Zustandslos.
 * Wochentage wie DateTime::format('N'): 1 = Montag … 7 = Sonntag.
 */
final class Rules {

    public const MONDAY = 1;
    public const TUESDAY = 2;
    public const WEDNESDAY = 3;
    public const THURSDAY = 4;
    public const FRIDAY = 5;
    public const SATURDAY = 6;
    public const SUNDAY = 7;

    /**
     * Ostersonntag nach Gauss (gregorianischer Kalender).
     * Kontrollwerte: 2025 = 20.4., 2026 = 5.4., 2027 = 28.3., 2028 = 16.4.
     */
    public static function easterSunday(int $year): DateTimeImmutable {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return self::fixed($year, $month, $day);
    }

    public static function fixed(int $year, int $month, int $day): DateTimeImmutable {
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day));
    }

    public static function easterOffset(int $year, int $days): DateTimeImmutable {
        return self::easterSunday($year)->modify(sprintf('%+d days', $days));
    }

    /**
     * n-ter Wochentag eines Monats, z. B. (2026, 4, THURSDAY, 1) = erster Donnerstag im April 2026.
     * Keine Bereichspruefung: ein n groesser als die Anzahl Vorkommen im Monat laeuft in den Folgemonat (Aufrufer nutzen n <= 3).
     */
    public static function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $n): DateTimeImmutable {
        $date = self::fixed($year, $month, 1);
        while ((int)$date->format('N') !== $weekday) {
            $date = $date->modify('+1 day');
        }
        return $date->modify(sprintf('+%d days', ($n - 1) * 7));
    }

    public static function isWeekday(DateTimeInterface $date, int ...$weekdays): bool {
        return in_array((int)$date->format('N'), $weekdays, true);
    }
}
