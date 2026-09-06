<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Holiday;

use OCA\Zeitwerk\Holiday\Provider\HolidayProviderInterface;
use OCA\Zeitwerk\Holiday\Provider\SwitzerlandHolidays;
use PHPUnit\Framework\TestCase;

/**
 * Erwartungswerte aus der Spezifikation 2026-09-05 (Abschnitt 3.6), Quelle
 * BJ-Verzeichnis «Gesetzliche Feiertage und Tage, die in der Schweiz wie
 * gesetzliche Feiertage behandelt werden» (Stand 1.1.2011) plus Wikipedia.
 * 2026: Ostern 5.4., 2.1. ist ein Freitag, 1.5. ein Freitag, 26.12. ein Samstag.
 */
class SwitzerlandHolidaysTest extends TestCase {

    private SwitzerlandHolidays $provider;

    protected function setUp(): void {
        $this->provider = new SwitzerlandHolidays();
    }

    /** @return string[] "YYYY-MM-DD Name" plus " [0.5]" bei Halbtag */
    private function listFor(int $year, string $region): array {
        $out = [];
        foreach ($this->provider->holidaysFor($year, $region) as $def) {
            $out[] = $def->date->format('Y-m-d') . ' ' . $def->name . ($def->scope < 1.0 ? ' [0.5]' : '');
        }
        return $out;
    }

    private function namesFor(int $year, string $region): array {
        return array_map(static fn($def) => $def->name, $this->provider->holidaysFor($year, $region));
    }

    public function testIsAProviderForSwitzerland(): void {
        $this->assertInstanceOf(HolidayProviderInterface::class, $this->provider);
        $this->assertSame('CH', $this->provider->country());
    }

    /**
     * @dataProvider cantons2026Provider
     */
    public function testAllCantons2026(string $region, array $expected): void {
        $this->assertSame($expected, $this->listFor(2026, $region));
    }

    public static function cantons2026Provider(): array {
        $NJ = '2026-01-01 Neujahr';
        $BT = '2026-01-02 Berchtoldstag';
        $DK = '2026-01-06 Heilige Drei Könige';
        $IR = '2026-03-01 Instauration de la République';
        $JT = '2026-03-19 Josefstag';
        $KF = '2026-04-03 Karfreitag';
        $OM = '2026-04-06 Ostermontag';
        $NF = '2026-04-09 Näfelser Fahrt'; // 1. Donnerstag (2.4.) ist Gruendonnerstag, also +7
        $TA = '2026-05-01 Tag der Arbeit';
        $TAh = '2026-05-01 Tag der Arbeit [0.5]';
        $AU = '2026-05-14 Auffahrt';
        $PM = '2026-05-25 Pfingstmontag';
        $FL = '2026-06-04 Fronleichnam';
        $PJ = '2026-06-23 Commémoration du plébiscite jurassien';
        $PP = '2026-06-29 Peter und Paul';
        $BF = '2026-08-01 Bundesfeiertag';
        $MH = '2026-08-15 Mariä Himmelfahrt';
        $JG = '2026-09-10 Jeûne genevois';
        $BM = '2026-09-21 Bettagsmontag';
        $MT = '2026-09-22 Mauritiustag';
        $BK = '2026-09-25 Bruderklausenfest';
        $AH = '2026-11-01 Allerheiligen';
        $ME = '2026-12-08 Mariä Empfängnis';
        $WT = '2026-12-25 Weihnachtstag';
        $ST = '2026-12-26 Stephanstag';
        $RR = '2026-12-31 Restauration de la République';

        return [
            // Stephanstag entfaellt 2026 (Samstag) in AG, AI, AR, UR; Berchtoldstag bleibt (Freitag)
            'CH-AG' => ['CH-AG', [$NJ, $BT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT]],
            'CH-AI' => ['CH-AI', [$NJ, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $MT, $AH, $ME, $WT]],
            'CH-AR' => ['CH-AR', [$NJ, $KF, $OM, $AU, $PM, $BF, $WT]],
            'CH-BE' => ['CH-BE', [$NJ, $BT, $KF, $OM, $AU, $PM, $BF, $WT, $ST]],
            'CH-BL' => ['CH-BL', [$NJ, $KF, $OM, $TA, $AU, $PM, $BF, $WT, $ST]],
            'CH-BS' => ['CH-BS', [$NJ, $KF, $OM, $TA, $AU, $PM, $BF, $WT, $ST]],
            'CH-FR' => ['CH-FR', [$NJ, $BT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT, $ST]],
            'CH-GE' => ['CH-GE', [$NJ, $KF, $OM, $AU, $PM, $BF, $JG, $WT, $RR]],
            'CH-GL' => ['CH-GL', [$NJ, $BT, $KF, $OM, $NF, $AU, $PM, $BF, $AH, $WT, $ST]],
            'CH-GR' => ['CH-GR', [$NJ, $KF, $OM, $AU, $PM, $BF, $WT, $ST]],
            'CH-JU' => ['CH-JU', [$NJ, $BT, $KF, $OM, $TA, $AU, $PM, $FL, $PJ, $BF, $MH, $AH, $WT]],
            'CH-LU' => ['CH-LU', [$NJ, $BT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT, $ST]],
            // NE: 2.1. und 26.12. nur, wenn Montag; 2026 beides nicht
            'CH-NE' => ['CH-NE', [$NJ, $IR, $KF, $OM, $TA, $AU, $PM, $BF, $WT]],
            'CH-NW' => ['CH-NW', [$NJ, $BT, $JT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT, $ST]],
            'CH-OW' => ['CH-OW', [$NJ, $BT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $BK, $AH, $ME, $WT, $ST]],
            'CH-SG' => ['CH-SG', [$NJ, $BT, $KF, $OM, $AU, $PM, $BF, $AH, $WT, $ST]],
            'CH-SH' => ['CH-SH', [$NJ, $BT, $KF, $OM, $TA, $AU, $PM, $BF, $WT, $ST]],
            // SO: 1. Mai halber Tag (2026 ein Freitag)
            'CH-SO' => ['CH-SO', [$NJ, $BT, $KF, $OM, $TAh, $AU, $PM, $FL, $BF, $MH, $AH, $WT, $ST]],
            'CH-SZ' => ['CH-SZ', [$NJ, $DK, $JT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT, $ST]],
            'CH-TG' => ['CH-TG', [$NJ, $BT, $KF, $OM, $TA, $AU, $PM, $BF, $WT, $ST]],
            'CH-TI' => ['CH-TI', [$NJ, $DK, $JT, $OM, $TA, $AU, $PM, $FL, $PP, $BF, $MH, $AH, $ME, $WT, $ST]],
            'CH-UR' => ['CH-UR', [$NJ, $DK, $JT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT]],
            'CH-VD' => ['CH-VD', [$NJ, $BT, $KF, $OM, $AU, $PM, $BF, $BM, $WT]],
            'CH-VS' => ['CH-VS', [$NJ, $BT, $JT, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT, $ST]],
            'CH-ZG' => ['CH-ZG', [$NJ, $BT, $KF, $OM, $AU, $PM, $FL, $BF, $MH, $AH, $ME, $WT, $ST]],
            'CH-ZH' => ['CH-ZH', [$NJ, $BT, $KF, $OM, $TA, $AU, $PM, $BF, $WT, $ST]],
        ];
    }

    public function testFourHolidaysAreNationwide(): void {
        foreach (array_keys(self::cantons2026Provider()) as $region) {
            $names = $this->namesFor(2026, $region);
            foreach (['Neujahr', 'Auffahrt', 'Bundesfeiertag', 'Weihnachtstag'] as $name) {
                $this->assertContains($name, $names, "$name fehlt in $region");
            }
        }
    }

    public function testNaefelserFahrtAvoidsMaundyThursday(): void {
        $this->assertSame('2021-04-08', SwitzerlandHolidays::naefelserFahrt(2021)->format('Y-m-d')); // 1.4.2021 = Gruendonnerstag
        $this->assertSame('2026-04-09', SwitzerlandHolidays::naefelserFahrt(2026)->format('Y-m-d')); // 2.4.2026 = Gruendonnerstag
        $this->assertSame('2027-04-01', SwitzerlandHolidays::naefelserFahrt(2027)->format('Y-m-d')); // Ostern 28.3., keine Kollision
    }

    public function testGenevaAndVaudMovableDays(): void {
        $this->assertSame('2026-09-10', SwitzerlandHolidays::dateFor('JG', 2026)->format('Y-m-d')); // Donnerstag nach dem 1. Septembersonntag
        $this->assertSame('2026-09-21', SwitzerlandHolidays::dateFor('BM', 2026)->format('Y-m-d')); // Montag nach dem 3. Septembersonntag
    }

    public function testNeuchatelOnlyOnMondays(): void {
        // 2.1.2023 ist ein Montag, 26.12.2023 ein Dienstag
        $names2023 = $this->namesFor(2023, 'CH-NE');
        $this->assertContains('Berchtoldstag', $names2023);
        $this->assertNotContains('Stephanstag', $names2023);
        $this->assertCount(10, $names2023);
        // 2026: Freitag bzw. Samstag
        $names2026 = $this->namesFor(2026, 'CH-NE');
        $this->assertNotContains('Berchtoldstag', $names2026);
        $this->assertNotContains('Stephanstag', $names2026);
    }

    public function testStephanstagAndBerchtoldstagSkipTuesdayAndSaturday(): void {
        // 26.12.2025 ist ein Freitag: Stephanstag vorhanden
        foreach (['CH-UR', 'CH-AR', 'CH-AI', 'CH-AG'] as $region) {
            $this->assertContains('Stephanstag', $this->namesFor(2025, $region), $region);
            $this->assertNotContains('Stephanstag', $this->namesFor(2026, $region), $region); // Samstag
        }
        // Aargau: 2.1.2027 ist ein Samstag, 2.1.2029 ein Dienstag, 2.1.2026 ein Freitag
        $this->assertNotContains('Berchtoldstag', $this->namesFor(2027, 'CH-AG'));
        $this->assertNotContains('Berchtoldstag', $this->namesFor(2029, 'CH-AG'));
        $this->assertContains('Berchtoldstag', $this->namesFor(2026, 'CH-AG'));
        // Bern kennt die Regel nicht
        $this->assertContains('Stephanstag', $this->namesFor(2026, 'CH-BE'));
    }

    public function testSolothurnFirstOfMayIsHalfDayUnlessMonday(): void {
        $find = function (int $year) {
            foreach ($this->provider->holidaysFor($year, 'CH-SO') as $def) {
                if ($def->name === 'Tag der Arbeit') {
                    return $def->scope;
                }
            }
            return null;
        };
        $this->assertSame(0.5, $find(2026)); // Freitag
        $this->assertSame(1.0, $find(2028)); // Montag
        $this->assertSame(1.0, $find(2023)); // Montag
        // Aargau bekommt den 1. Mai gar nicht (nur Brauch)
        $this->assertNotContains('Tag der Arbeit', $this->namesFor(2026, 'CH-AG'));
    }

    public function testKnownGaps(): void {
        $this->assertNotContains('Karfreitag', $this->namesFor(2026, 'CH-TI'));
        $this->assertNotContains('Karfreitag', $this->namesFor(2026, 'CH-VS'));
        $this->assertNotContains('Stephanstag', $this->namesFor(2026, 'CH-VD'));
        $this->assertNotContains('Stephanstag', $this->namesFor(2026, 'CH-GE'));
        $this->assertNotContains('Stephanstag', $this->namesFor(2026, 'CH-JU'));
        $this->assertNotContains('Mariä Empfängnis', $this->namesFor(2026, 'CH-SO'));
    }

    public function testUnknownCantonYieldsNothingAndBareSuffixIsTolerated(): void {
        $this->assertSame([], $this->provider->holidaysFor(2026, 'CH-XX'));
        $this->assertSame([], $this->provider->holidaysFor(2026, 'DE-BY'));
        $this->assertSame($this->listFor(2026, 'CH-ZH'), $this->listFor(2026, 'ZH'));
    }

    public function testResultIsSortedByDate(): void {
        foreach (['CH-GE', 'CH-GL', 'CH-TI'] as $region) {
            $dates = array_map(static fn($def) => $def->date->format('Y-m-d'), $this->provider->holidaysFor(2026, $region));
            $sorted = $dates;
            sort($sorted);
            $this->assertSame($sorted, $dates, $region);
        }
    }
}
