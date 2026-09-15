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
 * Eine Auftragsvorlage («Standard-Auftrag») fuer den Dienstplan. Wird in den
 * Einstellungen gepflegt und in der Wochenansicht beliebig oft in eine Zelle
 * gezogen; daraus entsteht jedes Mal eine neue, unabhaengige DutyJob-Karte.
 * Die Vorlage selbst bleibt bestehen.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getStartTime()
 * @method void setStartTime(?string $startTime)
 * @method int|null getDurationMinutes()
 * @method void setDurationMinutes(?int $durationMinutes)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int getOnCall()
 * @method int getIsVisible()
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class DutyJobTemplate extends Entity implements JsonSerializable {

    protected string $title = '';
    protected ?string $startTime = null;
    protected ?int $durationMinutes = null;
    protected ?string $note = null;
    protected int $onCall = 0;
    protected int $isVisible = 1;
    protected int $sortOrder = 0;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('durationMinutes', 'integer');
        $this->addType('onCall', 'integer');
        $this->addType('isVisible', 'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    public function setOnCall(bool|int $onCall): void {
        $this->onCall = is_bool($onCall) ? ($onCall ? 1 : 0) : $onCall;
        $this->markFieldUpdated('onCall');
    }

    public function setIsVisible(bool|int $isVisible): void {
        $this->isVisible = is_bool($isVisible) ? ($isVisible ? 1 : 0) : $isVisible;
        $this->markFieldUpdated('isVisible');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'startTime' => $this->startTime,
            'durationMinutes' => $this->durationMinutes,
            'note' => $this->note,
            'onCall' => (bool)$this->onCall,
            'isVisible' => (bool)$this->isVisible,
            'sortOrder' => $this->sortOrder,
            'createdAt' => $this->createdAt?->format('c'),
            'updatedAt' => $this->updatedAt?->format('c'),
        ];
    }
}
