<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * «Diese Woche ignorieren» fuer eine Vorlage mit festen Wochentagen. Existiert
 * die Zeile, gilt die Vorlage in dieser ISO-Woche als erledigt. `weekStart`
 * ist immer der Montag.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTemplateId()
 * @method void setTemplateId(int $templateId)
 * @method DateTime getWeekStart()
 * @method void setWeekStart(DateTime $weekStart)
 * @method string getSkippedBy()
 * @method void setSkippedBy(string $skippedBy)
 * @method DateTime getSkippedAt()
 * @method void setSkippedAt(DateTime $skippedAt)
 */
class DutyTemplateWeekSkip extends Entity {

    protected int $templateId = 0;
    protected ?DateTime $weekStart = null;
    protected string $skippedBy = '';
    protected ?DateTime $skippedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('templateId', 'integer');
        $this->addType('weekStart', 'datetime');
        $this->addType('skippedAt', 'datetime');
    }
}
