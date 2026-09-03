<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Workflow status of one calendar month per employee (Monatsabschluss).
 *
 * Single source of truth for "Entwurf / eingereicht / genehmigt / zurueckgewiesen"
 * so that months without time entries (only absences) can be submitted and
 * approved. Time entries still carry their own status for display/PDF/audit.
 *
 * @method int getEmployeeId()
 * @method void setEmployeeId(int $employeeId)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method int getMonth()
 * @method void setMonth(int $month)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getSubmittedAt()
 * @method void setSubmittedAt(?DateTime $submittedAt)
 * @method int|null getSubmittedBy()
 * @method void setSubmittedBy(?int $submittedBy)
 * @method DateTime|null getApprovedAt()
 * @method void setApprovedAt(?DateTime $approvedAt)
 * @method int|null getApprovedBy()
 * @method void setApprovedBy(?int $approvedBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 */
class MonthStatus extends Entity implements JsonSerializable {

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected int $employeeId = 0;
    protected int $year = 0;
    protected int $month = 0;
    protected string $status = self::STATUS_DRAFT;
    protected ?DateTime $submittedAt = null;
    protected ?int $submittedBy = null;
    protected ?DateTime $approvedAt = null;
    protected ?int $approvedBy = null;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('employeeId', 'integer');
        $this->addType('year', 'integer');
        $this->addType('month', 'integer');
        $this->addType('submittedBy', 'integer');
        $this->addType('approvedBy', 'integer');
        $this->addType('submittedAt', 'datetime');
        $this->addType('approvedAt', 'datetime');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    /**
     * Derive the month status from a per-entry status summary
     * (TimeEntryMapper::getMonthlyStatusSummary). Used by the backfill migration
     * and by the legacy per-entry endpoints.
     *
     * @param array{draft?: int, submitted?: int, approved?: int, rejected?: int} $summary
     */
    public static function deriveFromSummary(array $summary): string {
        $draft = (int)($summary[self::STATUS_DRAFT] ?? 0);
        $submitted = (int)($summary[self::STATUS_SUBMITTED] ?? 0);
        $approved = (int)($summary[self::STATUS_APPROVED] ?? 0);
        $rejected = (int)($summary[self::STATUS_REJECTED] ?? 0);
        $total = $draft + $submitted + $approved + $rejected;

        if ($total === 0) {
            return self::STATUS_DRAFT;
        }
        if ($approved === $total) {
            return self::STATUS_APPROVED;
        }
        if ($draft === 0 && $rejected === 0) {
            return self::STATUS_SUBMITTED;
        }
        if ($submitted === 0 && $rejected > 0) {
            return self::STATUS_REJECTED;
        }
        return self::STATUS_DRAFT;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'employeeId' => $this->getEmployeeId(),
            'year' => $this->getYear(),
            'month' => $this->getMonth(),
            'status' => $this->getStatus(),
            'submittedAt' => $this->getSubmittedAt()?->format('Y-m-d H:i:s'),
            'submittedBy' => $this->getSubmittedBy(),
            'approvedAt' => $this->getApprovedAt()?->format('Y-m-d H:i:s'),
            'approvedBy' => $this->getApprovedBy(),
        ];
    }
}
