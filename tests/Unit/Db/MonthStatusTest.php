<?php

declare(strict_types=1);

namespace OCA\Zeitwerk\Tests\Unit\Db;

use OCA\Zeitwerk\Db\MonthStatus;
use PHPUnit\Framework\TestCase;

class MonthStatusTest extends TestCase {

    /** @dataProvider summaryProvider */
    public function testDeriveFromSummary(array $summary, string $expected): void {
        $this->assertSame($expected, MonthStatus::deriveFromSummary($summary));
    }

    public static function summaryProvider(): array {
        return [
            'empty month' => [['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0], MonthStatus::STATUS_DRAFT],
            'all approved' => [['draft' => 0, 'submitted' => 0, 'approved' => 3, 'rejected' => 0], MonthStatus::STATUS_APPROVED],
            'all submitted' => [['draft' => 0, 'submitted' => 2, 'approved' => 0, 'rejected' => 0], MonthStatus::STATUS_SUBMITTED],
            'submitted and approved mix' => [['draft' => 0, 'submitted' => 1, 'approved' => 2, 'rejected' => 0], MonthStatus::STATUS_SUBMITTED],
            'all rejected' => [['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 2], MonthStatus::STATUS_REJECTED],
            'rejected with drafts' => [['draft' => 1, 'submitted' => 0, 'approved' => 0, 'rejected' => 2], MonthStatus::STATUS_REJECTED],
            'submitted with leftover draft' => [['draft' => 1, 'submitted' => 2, 'approved' => 0, 'rejected' => 0], MonthStatus::STATUS_DRAFT],
            'only drafts' => [['draft' => 4, 'submitted' => 0, 'approved' => 0, 'rejected' => 0], MonthStatus::STATUS_DRAFT],
            'missing keys tolerated' => [['approved' => 1], MonthStatus::STATUS_APPROVED],
        ];
    }

    public function testJsonSerializeExposesWorkflowFields(): void {
        $status = new MonthStatus();
        $status->setEmployeeId(7);
        $status->setYear(2026);
        $status->setMonth(8);
        $status->setStatus(MonthStatus::STATUS_SUBMITTED);
        $status->setSubmittedAt(new \DateTime('2026-09-01 08:00:00'));
        $status->setSubmittedBy(7);

        $json = $status->jsonSerialize();

        $this->assertSame(7, $json['employeeId']);
        $this->assertSame(2026, $json['year']);
        $this->assertSame(8, $json['month']);
        $this->assertSame('submitted', $json['status']);
        $this->assertSame('2026-09-01 08:00:00', $json['submittedAt']);
        $this->assertSame(7, $json['submittedBy']);
        $this->assertNull($json['approvedAt']);
    }
}
