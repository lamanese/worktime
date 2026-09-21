<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Eine Auftragskarte im Dienstplan (Wochenplan): Mitarbeiter x Tag, optionale
 * Startzeit und Dauer, Freitext-Titel. Hat keinen Bezug zur Zeiterfassung.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getEmployeeId()
 * @method void setEmployeeId(int $employeeId)
 * @method DateTime|null getJobDate()
 * @method void setJobDate(?DateTime $jobDate)
 * @method string|null getStartTime()
 * @method void setStartTime(?string $startTime)
 * @method int|null getDurationMinutes()
 * @method void setDurationMinutes(?int $durationMinutes)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int getOnCall()
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class DutyJob extends Entity implements JsonSerializable {

    protected int $employeeId = 0;
    protected ?DateTime $jobDate = null;
    protected ?string $startTime = null;
    protected ?int $durationMinutes = null;
    protected string $title = '';
    protected ?string $note = null;
    protected int $onCall = 0;
    /** Vorlage, aus der die Karte entstand (null = frei angelegt; kann verwaist sein). */
    protected ?int $templateId = null;
    protected string $createdBy = '';
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('employeeId', 'integer');
        $this->addType('jobDate', 'datetime');
        $this->addType('durationMinutes', 'integer');
        $this->addType('onCall', 'integer');
        $this->addType('templateId', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    public function setOnCall(bool|int $onCall): void {
        $value = is_bool($onCall) ? ($onCall ? 1 : 0) : $onCall;
        $this->onCall = $value;
        $this->markFieldUpdated('onCall');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'employeeId' => $this->employeeId,
            'date' => $this->jobDate?->format('Y-m-d'),
            'startTime' => $this->startTime,
            'durationMinutes' => $this->durationMinutes,
            'title' => $this->title,
            'note' => $this->note,
            'onCall' => (bool)$this->onCall,
            'templateId' => $this->templateId,
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt?->format('c'),
            'updatedAt' => $this->updatedAt?->format('c'),
        ];
    }
}
