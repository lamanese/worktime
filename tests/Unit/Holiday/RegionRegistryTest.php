<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Holiday;

use OCA\Zeitwerk\Holiday\RegionRegistry;
use PHPUnit\Framework\TestCase;

class RegionRegistryTest extends TestCase {

    public function testHas42RegionsInTwoCountries(): void {
        $this->assertCount(42, RegionRegistry::flatLabels());
        $this->assertCount(16, RegionRegistry::regionsOf('DE'));
        $this->assertCount(26, RegionRegistry::regionsOf('CH'));
        $this->assertSame(['DE', 'CH'], array_keys(RegionRegistry::COUNTRIES));
        $this->assertSame('DE-BY', RegionRegistry::DEFAULT_REGION);
        $this->assertSame([], RegionRegistry::regionsOf('AT'));
    }

    public function testAllCodesArePrefixedAndCollisionsAreResolved(): void {
        foreach (array_keys(RegionRegistry::REGIONS) as $code) {
            $this->assertMatchesRegularExpression('/^(DE|CH)-[A-Z]{2}$/', $code);
        }
        $this->assertSame('Berlin', RegionRegistry::name('DE-BE'));
        $this->assertSame('Bern', RegionRegistry::name('CH-BE'));
        $this->assertSame('Schleswig-Holstein', RegionRegistry::name('DE-SH'));
        $this->assertSame('Schaffhausen', RegionRegistry::name('CH-SH'));
        $this->assertSame('Nordrhein-Westfalen', RegionRegistry::name('DE-NW'));
        $this->assertSame('Nidwalden', RegionRegistry::name('CH-NW'));
    }

    public function testNormalizeMapsLegacyGermanCodes(): void {
        $this->assertSame('DE-BY', RegionRegistry::normalize('BY'));
        // Legacy-Zweibuchstaben-Code ist immer ein deutsches Bundesland (Berlin, nicht Bern)
        $this->assertSame('DE-BE', RegionRegistry::normalize('BE'));
        $this->assertSame('DE-BY', RegionRegistry::normalize('DE-BY'));
        $this->assertSame('CH-ZH', RegionRegistry::normalize(' ch-zh '));
        // Unbekanntes bleibt unbekannt (nur getrimmt und gross geschrieben)
        $this->assertSame('XX', RegionRegistry::normalize(' xx '));
        $this->assertSame('CH-XX', RegionRegistry::normalize('CH-XX'));
    }

    public function testValidityAndCountry(): void {
        $this->assertTrue(RegionRegistry::isValid('CH-ZH'));
        $this->assertTrue(RegionRegistry::isValid('DE-TH'));
        $this->assertFalse(RegionRegistry::isValid('ZH'));
        $this->assertFalse(RegionRegistry::isValid('BY'));
        $this->assertFalse(RegionRegistry::isValid('CH-XX'));
        $this->assertSame('CH', RegionRegistry::countryOf('CH-ZH'));
        $this->assertSame('DE', RegionRegistry::countryOf('DE-BY'));
        $this->assertNull(RegionRegistry::countryOf('BY'));
        $this->assertNull(RegionRegistry::countryOf('AT-9'));
        // name() faellt auf den Code zurueck
        $this->assertSame('BY', RegionRegistry::name('BY'));
    }

    public function testApiStructure(): void {
        $countries = RegionRegistry::toApiStructure();
        $this->assertCount(2, $countries);
        $this->assertSame('DE', $countries[0]['code']);
        $this->assertSame('Deutschland', $countries[0]['name']);
        $this->assertSame('Bundesland', $countries[0]['regionLabel']);
        $this->assertCount(16, $countries[0]['regions']);
        $this->assertSame(['code' => 'DE-BW', 'name' => 'Baden-Württemberg'], $countries[0]['regions'][0]);
        $this->assertSame('CH', $countries[1]['code']);
        $this->assertSame('Schweiz', $countries[1]['name']);
        $this->assertSame('Kanton', $countries[1]['regionLabel']);
        $this->assertCount(26, $countries[1]['regions']);
        $this->assertSame(['code' => 'CH-AG', 'name' => 'Aargau'], $countries[1]['regions'][0]);
        $this->assertSame(['code' => 'CH-ZH', 'name' => 'Zürich'], $countries[1]['regions'][25]);
    }
}
