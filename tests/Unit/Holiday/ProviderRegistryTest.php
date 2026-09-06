<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Holiday;

use OCA\Zeitwerk\Holiday\Provider\GermanyHolidays;
use OCA\Zeitwerk\Holiday\Provider\ProviderRegistry;
use OCA\Zeitwerk\Holiday\Provider\SwitzerlandHolidays;
use PHPUnit\Framework\TestCase;

class ProviderRegistryTest extends TestCase {

    public function testResolvesProviderByCountryOfRegion(): void {
        $registry = new ProviderRegistry(new GermanyHolidays(), new SwitzerlandHolidays());

        $this->assertSame('DE', $registry->forRegion('DE-BY')?->country());
        $this->assertSame('CH', $registry->forRegion('CH-ZH')?->country());
        // Legacy-Code muss vorher normalisiert werden, die Registry ist strikt
        $this->assertNull($registry->forRegion('BY'));
        $this->assertNull($registry->forRegion('AT-9'));
        $this->assertNull($registry->forRegion(''));
    }
}
