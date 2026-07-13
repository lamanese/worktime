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
 * Tageweise gefahrene Kilometer eines Mitarbeiters (Extern-Erfassung). Ein
 * Datensatz pro Mitarbeiter und Tag; die Monatsverguetung ergibt sich aus der
 * Summe der Kilometer multipliziert mit dem konfigurierten Satz.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getEmployeeId()
 * @method void setEmployeeId(int $employeeId)
 * @method DateTime getWorkDate()
 * @method void setWorkDate(DateTime $workDate)
 * @method int getKilometers()
 * @method void setKilometers(int $kilometers)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class DailyKm extends Entity implements JsonSerializable {

    protected int $employeeId = 0;
    protected ?DateTime $workDate = null;
    protected int $kilometers = 0;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('employeeId', 'integer');
        $this->addType('workDate', 'date');
        $this->addType('kilometers', 'integer');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'employeeId' => $this->employeeId,
            'date' => $this->workDate?->format('Y-m-d'),
            'kilometers' => $this->kilometers,
            'createdAt' => $this->createdAt?->format('c'),
            'updatedAt' => $this->updatedAt?->format('c'),
        ];
    }
}
