<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Service;

use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Db\CompanySettingMapper;
use OCA\Zeitwerk\Service\AuditLogService;
use OCA\Zeitwerk\Service\CompanySettingsService;
use PHPUnit\Framework\TestCase;

class CompanySettingsServiceDutyRosterTest extends TestCase {

    public function testDefaultIsOff(): void {
        $this->assertSame('0', CompanySetting::DEFAULTS[CompanySetting::KEY_DUTY_ROSTER_ENABLED]);
    }

    public function testIsDutyRosterEnabledReadsBool(): void {
        $mapper = $this->createMock(CompanySettingMapper::class);
        $mapper->method('getValueAsBool')->with('duty_roster_enabled')->willReturn(true);
        $service = new CompanySettingsService($mapper, $this->createMock(AuditLogService::class));

        $this->assertTrue($service->isDutyRosterEnabled());
    }
    public function testTemplatesSidebarDefaultIsOn(): void {
        $this->assertSame('1', CompanySetting::DEFAULTS[CompanySetting::KEY_DUTY_ROSTER_TEMPLATES_SIDEBAR]);
    }

    public function testIsDutyRosterTemplatesSidebarEnabledReadsBool(): void {
        $mapper = $this->createMock(CompanySettingMapper::class);
        $mapper->method('getValueAsBool')->with('duty_roster_templates_sidebar')->willReturn(false);
        $service = new CompanySettingsService($mapper, $this->createMock(AuditLogService::class));

        $this->assertFalse($service->isDutyRosterTemplatesSidebarEnabled());
    }
}
