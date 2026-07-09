<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\WorkTime\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getSettingKey()
 * @method void setSettingKey(string $settingKey)
 * @method string|null getSettingValue()
 * @method void setSettingValue(?string $settingValue)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class CompanySetting extends Entity implements JsonSerializable {

    public const KEY_COMPANY_NAME = 'company_name';
    public const KEY_DEFAULT_FEDERAL_STATE = 'default_federal_state';
    public const KEY_DEFAULT_WEEKLY_HOURS = 'default_weekly_hours';
    public const KEY_DEFAULT_VACATION_DAYS = 'default_vacation_days';
    public const KEY_REQUIRE_PROJECT = 'require_project';
    public const KEY_REQUIRE_DESCRIPTION = 'require_description';
    public const KEY_ALLOW_FUTURE_ENTRIES = 'allow_future_entries';
    public const KEY_MAX_DAILY_HOURS = 'max_daily_hours';
    public const KEY_MIN_BREAK_MINUTES_6H = 'min_break_minutes_6h';
    public const KEY_MIN_BREAK_MINUTES_9H = 'min_break_minutes_9h';
    public const KEY_APPROVAL_REQUIRED = 'approval_required';
    public const KEY_PDF_ARCHIVE_PATH = 'pdf_archive_path';
    public const KEY_PDF_ARCHIVE_USER = 'pdf_archive_user';
    public const KEY_CHRISTMAS_EVE_HALF_DAY = 'christmas_eve_half_day';
    public const KEY_NEW_YEARS_EVE_HALF_DAY = 'new_years_eve_half_day';

    // Aussendienst-Spesen (an Tagen mit Aussendienst-Projektbuchung ab Schwelle)
    public const KEY_FIELDWORK_ALLOWANCE_AMOUNT = 'fieldwork_allowance_amount';
    public const KEY_FIELDWORK_ALLOWANCE_THRESHOLD_HOURS = 'fieldwork_allowance_threshold_hours';
    /** 'gte' = >= Schwelle, 'gt' = > Schwelle */
    public const KEY_FIELDWORK_ALLOWANCE_OPERATOR = 'fieldwork_allowance_operator';
    /** 'gross' = Tageszeit inkl. Pause, 'net' = reine Arbeitszeit */
    public const KEY_FIELDWORK_ALLOWANCE_BASIS = 'fieldwork_allowance_basis';
    /** 1 = Spesen-Pauschale auch an Tagen mit externem Abwesenheitstyp */
    public const KEY_FIELDWORK_ALLOWANCE_ON_EXTERN_ABSENCE = 'fieldwork_allowance_on_extern_absence';

    // Extern-Kilometer
    public const KEY_MILEAGE_RATE = 'mileage_rate';
    /** Komma-separierte Abwesenheitstyp-Keys, die als "extern" gelten (km-faehig) */
    public const KEY_EXTERN_ABSENCE_TYPES = 'extern_absence_types';

    public const OPERATOR_GTE = 'gte';
    public const OPERATOR_GT = 'gt';
    public const BASIS_GROSS = 'gross';
    public const BASIS_NET = 'net';

    public const DEFAULTS = [
        self::KEY_COMPANY_NAME => '',
        self::KEY_DEFAULT_FEDERAL_STATE => 'BY',
        self::KEY_DEFAULT_WEEKLY_HOURS => '40',
        self::KEY_DEFAULT_VACATION_DAYS => '30',
        self::KEY_REQUIRE_PROJECT => '0',
        self::KEY_REQUIRE_DESCRIPTION => '0',
        self::KEY_ALLOW_FUTURE_ENTRIES => '0',
        self::KEY_MAX_DAILY_HOURS => '10',
        self::KEY_MIN_BREAK_MINUTES_6H => '30',
        self::KEY_MIN_BREAK_MINUTES_9H => '45',
        self::KEY_APPROVAL_REQUIRED => '1',
        self::KEY_PDF_ARCHIVE_PATH => '/WorkTime/Archiv',
        self::KEY_PDF_ARCHIVE_USER => '',
        self::KEY_CHRISTMAS_EVE_HALF_DAY => '1',
        self::KEY_NEW_YEARS_EVE_HALF_DAY => '1',
        self::KEY_FIELDWORK_ALLOWANCE_AMOUNT => '14.00',
        self::KEY_FIELDWORK_ALLOWANCE_THRESHOLD_HOURS => '8',
        self::KEY_FIELDWORK_ALLOWANCE_OPERATOR => self::OPERATOR_GTE,
        self::KEY_FIELDWORK_ALLOWANCE_BASIS => self::BASIS_GROSS,
        self::KEY_FIELDWORK_ALLOWANCE_ON_EXTERN_ABSENCE => '0',
        self::KEY_MILEAGE_RATE => '0.30',
        self::KEY_EXTERN_ABSENCE_TYPES => '',
    ];

    protected string $settingKey = '';
    protected ?string $settingValue = null;
    protected ?DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('updatedAt', 'datetime');
    }

    public function getValueAsBool(): bool {
        return $this->settingValue === '1' || $this->settingValue === 'true';
    }

    public function getValueAsInt(): int {
        return (int)$this->settingValue;
    }

    public function getValueAsFloat(): float {
        return (float)$this->settingValue;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'key' => $this->settingKey,
            'value' => $this->settingValue,
            'updatedAt' => $this->updatedAt?->format('c'),
        ];
    }
}
