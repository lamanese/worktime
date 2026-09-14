<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Db;

use OCA\Zeitwerk\Db\Employee;
use PHPUnit\Framework\TestCase;

class EmployeeDutyRosterTest extends TestCase {

    public function testDefaultIsNotInRoster(): void {
        $employee = new Employee();
        $this->assertSame(0, $employee->getInDutyRoster());
        $this->assertFalse($employee->jsonSerialize()['inDutyRoster']);
    }

    public function testSetterAcceptsBoolAndInt(): void {
        $employee = new Employee();
        $employee->setInDutyRoster(true);
        $this->assertSame(1, $employee->getInDutyRoster());
        $this->assertTrue($employee->jsonSerialize()['inDutyRoster']);

        $employee->setInDutyRoster(0);
        $this->assertSame(0, $employee->getInDutyRoster());
    }

    public function testDutyRosterOrderDefaultsToZero(): void {
        $employee = new Employee();
        $this->assertSame(0, $employee->getDutyRosterOrder());
        $this->assertSame(0, $employee->jsonSerialize()['dutyRosterOrder']);
    }

    public function testDutyRosterOrderSetterAndJsonSerialize(): void {
        $employee = new Employee();
        $employee->setDutyRosterOrder(7);
        $this->assertSame(7, $employee->getDutyRosterOrder());
        $this->assertSame(7, $employee->jsonSerialize()['dutyRosterOrder']);
    }
}
