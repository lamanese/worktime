<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Holiday;

/**
 * Laender und Regionen, fuer die Zeitwerk Feiertage kennt.
 *
 * Regionscodes folgen ISO 3166-2 mit Laenderpraefix (DE-BY, CH-ZH). Das Praefix
 * ist Pflicht, weil sich Zweibuchstaben-Codes zwischen den Laendern ueberschneiden
 * (BE = Berlin / Bern, SH = Schleswig-Holstein / Schaffhausen, NW = Nordrhein-
 * Westfalen / Nidwalden). Reine Datenklasse ohne Abhaengigkeiten, damit sie auch
 * aus Entities heraus benutzt werden kann.
 */
final class RegionRegistry {

    public const DEFAULT_REGION = 'DE-BY';

    /** @var array<string, array{name: string, regionLabel: string}> */
    public const COUNTRIES = [
        'DE' => ['name' => 'Deutschland', 'regionLabel' => 'Bundesland'],
        'CH' => ['name' => 'Schweiz', 'regionLabel' => 'Kanton'],
    ];

    /** @var array<string, string> Code => Anzeigename, je Land alphabetisch nach Name */
    public const REGIONS = [
        'DE-BW' => 'Baden-Württemberg',
        'DE-BY' => 'Bayern',
        'DE-BE' => 'Berlin',
        'DE-BB' => 'Brandenburg',
        'DE-HB' => 'Bremen',
        'DE-HH' => 'Hamburg',
        'DE-HE' => 'Hessen',
        'DE-MV' => 'Mecklenburg-Vorpommern',
        'DE-NI' => 'Niedersachsen',
        'DE-NW' => 'Nordrhein-Westfalen',
        'DE-RP' => 'Rheinland-Pfalz',
        'DE-SL' => 'Saarland',
        'DE-SN' => 'Sachsen',
        'DE-ST' => 'Sachsen-Anhalt',
        'DE-SH' => 'Schleswig-Holstein',
        'DE-TH' => 'Thüringen',
        'CH-AG' => 'Aargau',
        'CH-AR' => 'Appenzell Ausserrhoden',
        'CH-AI' => 'Appenzell Innerrhoden',
        'CH-BL' => 'Basel-Landschaft',
        'CH-BS' => 'Basel-Stadt',
        'CH-BE' => 'Bern',
        'CH-FR' => 'Freiburg',
        'CH-GE' => 'Genf',
        'CH-GL' => 'Glarus',
        'CH-GR' => 'Graubünden',
        'CH-JU' => 'Jura',
        'CH-LU' => 'Luzern',
        'CH-NE' => 'Neuenburg',
        'CH-NW' => 'Nidwalden',
        'CH-OW' => 'Obwalden',
        'CH-SH' => 'Schaffhausen',
        'CH-SZ' => 'Schwyz',
        'CH-SO' => 'Solothurn',
        'CH-SG' => 'St. Gallen',
        'CH-TI' => 'Tessin',
        'CH-TG' => 'Thurgau',
        'CH-UR' => 'Uri',
        'CH-VD' => 'Waadt',
        'CH-VS' => 'Wallis',
        'CH-ZG' => 'Zug',
        'CH-ZH' => 'Zürich',
    ];

    public static function isValid(string $code): bool {
        return array_key_exists($code, self::REGIONS);
    }

    /**
     * Trimmt, schreibt gross und liest einen nackten Zweibuchstaben-Code als
     * deutsches Bundesland (Datenstand vor 0.18.0). Unbekannte Codes bleiben
     * unveraendert, damit die Validierung sie ablehnen kann.
     */
    public static function normalize(string $code): string {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{2}$/', $code) === 1 && array_key_exists('DE-' . $code, self::REGIONS)) {
            return 'DE-' . $code;
        }
        return $code;
    }

    /** Anzeigename, Fallback ist der Code selbst. */
    public static function name(string $code): string {
        return self::REGIONS[$code] ?? $code;
    }

    /** Laendercode einer gueltigen Region, sonst null. */
    public static function countryOf(string $code): ?string {
        if (!self::isValid($code)) {
            return null;
        }
        return substr($code, 0, 2);
    }

    /** @return array<string, string> Code => Name aller Regionen eines Landes */
    public static function regionsOf(string $country): array {
        $regions = [];
        foreach (self::REGIONS as $code => $name) {
            if (str_starts_with($code, $country . '-')) {
                $regions[$code] = $name;
            }
        }
        return $regions;
    }

    /** @return array<string, string> Alle 42 Regionen, Code => Name (ersetzt die fruehere Bundesland-Konstante der Employee-Entity) */
    public static function flatLabels(): array {
        return self::REGIONS;
    }

    /**
     * Struktur fuer GET /api/holidays/regions.
     *
     * @return list<array{code: string, name: string, regionLabel: string, regions: list<array{code: string, name: string}>}>
     */
    public static function toApiStructure(): array {
        $countries = [];
        foreach (self::COUNTRIES as $countryCode => $info) {
            $regions = [];
            foreach (self::regionsOf($countryCode) as $code => $name) {
                $regions[] = ['code' => $code, 'name' => $name];
            }
            $countries[] = [
                'code' => $countryCode,
                'name' => $info['name'],
                'regionLabel' => $info['regionLabel'],
                'regions' => $regions,
            ];
        }
        return $countries;
    }
}
