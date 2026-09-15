<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Db;

use DateTime;
use OCA\Zeitwerk\Db\DutyJob;
use PHPUnit\Framework\TestCase;

class DutyJobTest extends TestCase {

    public function testJsonSerializeExposesDateAndBool(): void {
        $job = new DutyJob();
        $job->setId(7);
        $job->setEmployeeId(3);
        $job->setJobDate(new DateTime('2026-09-15'));
        $job->setStartTime('09:30');
        $job->setDurationMinutes(90);
        $job->setTitle('CarTech');
        $job->setNote('Reifen');
        $job->setOnCall(true);
        $job->setCreatedBy('admin');

        $data = $job->jsonSerialize();

        $this->assertSame(7, $data['id']);
        $this->assertSame(3, $data['employeeId']);
        $this->assertSame('2026-09-15', $data['date']);
        $this->assertSame('09:30', $data['startTime']);
        $this->assertSame(90, $data['durationMinutes']);
        $this->assertSame('CarTech', $data['title']);
        $this->assertTrue($data['onCall']);
        $this->assertSame(1, $job->getOnCall());
    }

    public function testDefaults(): void {
        $job = new DutyJob();
        $data = $job->jsonSerialize();
        $this->assertNull($data['startTime']);
        $this->assertNull($data['durationMinutes']);
        $this->assertNull($data['note']);
        $this->assertFalse($data['onCall']);
        $this->assertNull($data['date']);
    }
}
