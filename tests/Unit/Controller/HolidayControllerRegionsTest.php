<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Controller;

use OCA\Zeitwerk\Controller\HolidayController;
use OCA\Zeitwerk\Service\CompanySettingsService;
use OCA\Zeitwerk\Service\HolidayService;
use OCA\Zeitwerk\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class HolidayControllerRegionsTest extends TestCase {

    private function controller(?string $userId, string $defaultRegion = 'DE-BY'): HolidayController {
        $settings = $this->createMock(CompanySettingsService::class);
        $settings->method('getDefaultFederalState')->willReturn($defaultRegion);

        return new HolidayController(
            $this->createMock(IRequest::class),
            $userId,
            $this->createMock(HolidayService::class),
            $this->createMock(PermissionService::class),
            $settings,
        );
    }

    public function testRegionsRequiresAuthentication(): void {
        $response = $this->controller(null)->regions();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testRegionsListsBothCountriesAndTheDefault(): void {
        $response = $this->controller('alice', 'CH-ZH')->regions();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $data = $response->getData();
        $this->assertSame('CH-ZH', $data['defaultRegion']);
        $this->assertCount(2, $data['countries']);
        $this->assertSame('DE', $data['countries'][0]['code']);
        $this->assertSame('Bundesland', $data['countries'][0]['regionLabel']);
        $this->assertCount(16, $data['countries'][0]['regions']);
        $this->assertSame('CH', $data['countries'][1]['code']);
        $this->assertSame('Kanton', $data['countries'][1]['regionLabel']);
        $this->assertCount(26, $data['countries'][1]['regions']);
        $this->assertSame(['code' => 'CH-ZH', 'name' => 'Zürich'], $data['countries'][1]['regions'][25]);
    }
}
