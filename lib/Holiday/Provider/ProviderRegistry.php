<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Holiday\Provider;

use OCA\Zeitwerk\Holiday\RegionRegistry;

/**
 * Ordnet einer Region den Feiertags-Provider ihres Landes zu.
 * Neue Laender: Provider implementieren, hier im Konstruktor ergaenzen,
 * Regionen in RegionRegistry eintragen. Sonst nichts.
 */
final class ProviderRegistry {

    /** @var array<string, HolidayProviderInterface> Land => Provider */
    private array $providers;

    public function __construct(GermanyHolidays $germany, SwitzerlandHolidays $switzerland) {
        $this->providers = [
            $germany->country() => $germany,
            $switzerland->country() => $switzerland,
        ];
    }

    /** Provider fuer einen vollen Regionscode (DE-BY, CH-ZH), null wenn Region oder Land unbekannt. */
    public function forRegion(string $regionCode): ?HolidayProviderInterface {
        $country = RegionRegistry::countryOf($regionCode);
        if ($country === null) {
            return null;
        }
        return $this->providers[$country] ?? null;
    }
}
