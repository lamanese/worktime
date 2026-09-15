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
 * Sperre einer ISO-Woche im Dienstplan. Existiert die Zeile, ist die Woche
 * gesperrt: keine Karte darf angelegt, geaendert, verschoben oder geloescht
 * werden. `weekStart` ist immer der Montag.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method DateTime getWeekStart()
 * @method void setWeekStart(DateTime $weekStart)
 * @method string getLockedBy()
 * @method void setLockedBy(string $lockedBy)
 * @method DateTime getLockedAt()
 * @method void setLockedAt(DateTime $lockedAt)
 */
class DutyWeekLock extends Entity implements JsonSerializable {

    protected ?DateTime $weekStart = null;
    protected string $lockedBy = '';
    protected ?DateTime $lockedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('weekStart', 'datetime');
        $this->addType('lockedAt', 'datetime');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'weekStart' => $this->weekStart?->format('Y-m-d'),
            'lockedBy' => $this->lockedBy,
            'lockedAt' => $this->lockedAt?->format('c'),
        ];
    }
}
