<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Db;

use OCA\Zeitwerk\Db\DutyJobTemplate;
use PHPUnit\Framework\TestCase;

class DutyJobTemplateTest extends TestCase {

    public function testJsonSerializeExposesBools(): void {
        $template = new DutyJobTemplate();
        $template->setId(4);
        $template->setTitle('HU Werkstatt Nord');
        $template->setStartTime('08:00');
        $template->setDurationMinutes(120);
        $template->setNote('Pruefplakette mitnehmen');
        $template->setOnCall(true);
        $template->setIsVisible(false);
        $template->setSortOrder(3);

        $data = $template->jsonSerialize();

        $this->assertSame(4, $data['id']);
        $this->assertSame('HU Werkstatt Nord', $data['title']);
        $this->assertSame('08:00', $data['startTime']);
        $this->assertSame(120, $data['durationMinutes']);
        $this->assertSame('Pruefplakette mitnehmen', $data['note']);
        $this->assertTrue($data['onCall']);
        $this->assertFalse($data['isVisible']);
        $this->assertSame(3, $data['sortOrder']);
        $this->assertSame(1, $template->getOnCall());
        $this->assertSame(0, $template->getIsVisible());
    }

    public function testDefaultsAreVisibleAndEmpty(): void {
        $template = new DutyJobTemplate();
        $data = $template->jsonSerialize();

        $this->assertNull($data['startTime']);
        $this->assertNull($data['durationMinutes']);
        $this->assertNull($data['note']);
        $this->assertFalse($data['onCall']);
        $this->assertTrue($data['isVisible']);
        $this->assertSame(0, $data['sortOrder']);
    }
}
