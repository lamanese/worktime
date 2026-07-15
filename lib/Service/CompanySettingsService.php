<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Service;

use DateTime;
use OCA\Zeitwerk\Db\CompanySetting;
use OCA\Zeitwerk\Db\CompanySettingMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class CompanySettingsService {

    public function __construct(
        private CompanySettingMapper $settingMapper,
        private AuditLogService $auditLogService,
    ) {
    }

    /**
     * Get all settings as an associative array
     *
     * @return array<string, string|null>
     */
    public function getAll(): array {
        return $this->settingMapper->getAllAsArray();
    }

    /**
     * Get a single setting value
     */
    public function get(string $key, ?string $default = null): ?string {
        return $this->settingMapper->getValue($key, $default);
    }

    /**
     * Get a setting value as boolean
     */
    public function getBool(string $key): bool {
        return $this->settingMapper->getValueAsBool($key);
    }

    /**
     * Get a setting value as integer
     */
    public function getInt(string $key): int {
        return $this->settingMapper->getValueAsInt($key);
    }

    /**
     * Get a setting value as float
     */
    public function getFloat(string $key): float {
        return $this->settingMapper->getValueAsFloat($key);
    }

    /**
     * Set a setting value
     */
    public function set(string $key, ?string $value, string $currentUserId = ''): CompanySetting {
        $oldValue = $this->get($key);

        try {
            $setting = $this->settingMapper->findByKey($key);
            $setting->setSettingValue($value);
            $setting->setUpdatedAt(new DateTime());
            $setting = $this->settingMapper->update($setting);
        } catch (DoesNotExistException) {
            $setting = new CompanySetting();
            $setting->setSettingKey($key);
            $setting->setSettingValue($value);
            $setting->setUpdatedAt(new DateTime());
            $setting = $this->settingMapper->insert($setting);
        }

        // Audit log
        if ($currentUserId) {
            $this->auditLogService->logUpdate(
                $currentUserId,
                'setting',
                $setting->getId(),
                ['key' => $key, 'value' => $oldValue],
                ['key' => $key, 'value' => $value]
            );
        }

        return $setting;
    }

    /**
     * Set multiple settings at once
     *
     * @param array<string, string|null> $settings
     */
    public function setMultiple(array $settings, string $currentUserId = ''): void {
        foreach ($settings as $key => $value) {
            $this->set($key, $value, $currentUserId);
        }
    }

    /**
     * Reset a setting to its default value
     */
    public function reset(string $key, string $currentUserId = ''): ?CompanySetting {
        $default = CompanySetting::DEFAULTS[$key] ?? null;

        if ($default !== null) {
            return $this->set($key, $default, $currentUserId);
        }

        return null;
    }

    /**
     * Reset all settings to defaults
     */
    public function resetAll(string $currentUserId = ''): void {
        foreach (CompanySetting::DEFAULTS as $key => $value) {
            $this->set($key, $value, $currentUserId);
        }
    }

    /**
     * Get company name
     */
    public function getCompanyName(): string {
        return $this->get(CompanySetting::KEY_COMPANY_NAME) ?? '';
    }

    /**
     * Get default federal state
     */
    public function getDefaultFederalState(): string {
        return $this->get(CompanySetting::KEY_DEFAULT_FEDERAL_STATE) ?? 'BY';
    }

    /**
     * Get default weekly hours
     */
    public function getDefaultWeeklyHours(): float {
        return $this->getFloat(CompanySetting::KEY_DEFAULT_WEEKLY_HOURS);
    }

    /**
     * Get default vacation days
     */
    public function getDefaultVacationDays(): int {
        return $this->getInt(CompanySetting::KEY_DEFAULT_VACATION_DAYS);
    }

    /**
     * Check if project is required for time entries
     */
    public function isProjectRequired(): bool {
        return $this->getBool(CompanySetting::KEY_REQUIRE_PROJECT);
    }

    /**
     * Check if description is required for time entries
     */
    public function isDescriptionRequired(): bool {
        return $this->getBool(CompanySetting::KEY_REQUIRE_DESCRIPTION);
    }

    /**
     * Check if approval is required for time entries
     */
    public function isApprovalRequired(): bool {
        return $this->getBool(CompanySetting::KEY_APPROVAL_REQUIRED);
    }

    /**
     * Get minimum break minutes for >6h work
     */
    public function getMinBreakMinutes6h(): int {
        return $this->getInt(CompanySetting::KEY_MIN_BREAK_MINUTES_6H);
    }

    /**
     * Get minimum break minutes for >9h work
     */
    public function getMinBreakMinutes9h(): int {
        return $this->getInt(CompanySetting::KEY_MIN_BREAK_MINUTES_9H);
    }

    /**
     * Get maximum daily hours
     */
    public function getMaxDailyHours(): float {
        return $this->getFloat(CompanySetting::KEY_MAX_DAILY_HOURS);
    }

    /**
     * Aussendienst-Spesen: Pauschale (EUR) pro qualifiziertem Tag.
     */
    public function getFieldworkAllowanceAmount(): float {
        return $this->getFloat(CompanySetting::KEY_FIELDWORK_ALLOWANCE_AMOUNT);
    }

    /**
     * Aussendienst-Spesen: Stundenschwelle pro Tag.
     */
    public function getFieldworkAllowanceThresholdHours(): float {
        return $this->getFloat(CompanySetting::KEY_FIELDWORK_ALLOWANCE_THRESHOLD_HOURS);
    }

    /**
     * Aussendienst-Spesen: Vergleichsoperator ('gte' = >=, 'gt' = >).
     */
    public function getFieldworkAllowanceOperator(): string {
        $value = $this->get(CompanySetting::KEY_FIELDWORK_ALLOWANCE_OPERATOR);
        return $value === CompanySetting::OPERATOR_GT
            ? CompanySetting::OPERATOR_GT
            : CompanySetting::OPERATOR_GTE;
    }

    /**
     * Aussendienst-Spesen: Berechnungsbasis ('gross' = inkl. Pause, 'net' = Arbeitszeit).
     */
    public function getFieldworkAllowanceBasis(): string {
        $value = $this->get(CompanySetting::KEY_FIELDWORK_ALLOWANCE_BASIS);
        return $value === CompanySetting::BASIS_NET
            ? CompanySetting::BASIS_NET
            : CompanySetting::BASIS_GROSS;
    }

    /**
     * Aussendienst-Spesen: greift die Pauschale auch an Tagen mit externem
     * Abwesenheitstyp (ohne Arbeitszeit)?
     */
    public function isFieldworkAllowanceOnExternAbsence(): bool {
        return $this->getBool(CompanySetting::KEY_FIELDWORK_ALLOWANCE_ON_EXTERN_ABSENCE);
    }

    /**
     * Extern-Kilometer: Vergütungssatz (EUR) pro km.
     */
    public function getMileageRate(): float {
        return $this->getFloat(CompanySetting::KEY_MILEAGE_RATE);
    }

    /**
     * Abwesenheitstyp-Keys, die als "extern" gelten (km-fähig, optional Spesen).
     *
     * @return string[]
     */
    public function getExternAbsenceTypes(): array {
        $raw = $this->get(CompanySetting::KEY_EXTERN_ABSENCE_TYPES) ?? '';
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $t) => $t !== ''));
    }

    /**
     * Dürfen Mitarbeiter ein persönliches Standard-Projekt festlegen?
     */
    public function isEmployeeDefaultProjectAllowed(): bool {
        return $this->getBool(CompanySetting::KEY_ALLOW_EMPLOYEE_DEFAULT_PROJECT);
    }

    /**
     * Dürfen Mitarbeiter eine persönliche Standard-Beschreibung festlegen?
     */
    public function isEmployeeDefaultDescriptionAllowed(): bool {
        return $this->getBool(CompanySetting::KEY_ALLOW_EMPLOYEE_DEFAULT_DESCRIPTION);
    }
}
