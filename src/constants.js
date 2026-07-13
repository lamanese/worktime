/**
 * Zeitwerk Application Constants
 *
 * Zentrale Konstanten-Definitionen für die gesamte Frontend-Anwendung.
 * Diese Konstanten spiegeln die Backend-Konstanten wider und sorgen
 * für Konsistenz zwischen Frontend und Backend.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Time Entry Status Values
 * Entspricht TimeEntry::STATUS_* in PHP
 */
export const ENTRY_STATUS = {
    DRAFT: 'draft',
    SUBMITTED: 'submitted',
    APPROVED: 'approved',
    REJECTED: 'rejected',
}

/**
 * Absence Type Values
 * Entspricht Absence::TYPE_* in PHP
 */
export const ABSENCE_TYPES = {
    VACATION: 'vacation',
    SICK: 'sick',
    CHILD_SICK: 'child_sick',
    SPECIAL: 'special',
    TRAINING: 'training',
    COMPENSATORY: 'compensatory',
    UNPAID: 'unpaid',
    // #15 Stufe 2: nur zentral über Betriebsferien setzbar, nicht beantragbar
    COMPANY_CLOSURE: 'company_closure',
}

/**
 * Absence Status Values
 * Entspricht Absence::STATUS_* in PHP
 */
export const ABSENCE_STATUS = {
    PENDING: 'pending',
    APPROVED: 'approved',
    REJECTED: 'rejected',
    CANCELLED: 'cancelled',
}

/**
 * Status Labels (translated)
 * Returns translated labels — must be a function because t() resolves at runtime
 * @returns {Object}
 */
export function STATUS_LABELS() {
    return {
        // Time Entry Status
        [ENTRY_STATUS.DRAFT]: t('zeitwerk', 'Entwurf'),
        [ENTRY_STATUS.SUBMITTED]: t('zeitwerk', 'Eingereicht'),
        [ENTRY_STATUS.APPROVED]: t('zeitwerk', 'Genehmigt'),
        [ENTRY_STATUS.REJECTED]: t('zeitwerk', 'Abgelehnt'),
        // Absence Status (same values but different semantics)
        [ABSENCE_STATUS.PENDING]: t('zeitwerk', 'Ausstehend'),
        [ABSENCE_STATUS.CANCELLED]: t('zeitwerk', 'Storniert'),
    }
}

/**
 * Absence Type Labels (translated)
 * @returns {Object}
 */
export function ABSENCE_TYPE_LABELS() {
    return {
        [ABSENCE_TYPES.VACATION]: t('zeitwerk', 'Urlaub'),
        [ABSENCE_TYPES.SICK]: t('zeitwerk', 'Krankheit'),
        [ABSENCE_TYPES.CHILD_SICK]: t('zeitwerk', 'Kind krank'),
        [ABSENCE_TYPES.SPECIAL]: t('zeitwerk', 'Sonderurlaub'),
        [ABSENCE_TYPES.TRAINING]: t('zeitwerk', 'Fortbildung'),
        [ABSENCE_TYPES.COMPENSATORY]: t('zeitwerk', 'Freizeitausgleich'),
        [ABSENCE_TYPES.UNPAID]: t('zeitwerk', 'Unbezahlter Urlaub'),
        [ABSENCE_TYPES.COMPANY_CLOSURE]: t('zeitwerk', 'Betriebsschließung'),
    }
}

/**
 * Federal States (German Bundesländer, translated)
 * Entspricht Employee::FEDERAL_STATES in PHP
 * @returns {Object}
 */
export function FEDERAL_STATES() {
    return {
        BW: t('zeitwerk', 'Baden-Württemberg'),
        BY: t('zeitwerk', 'Bayern'),
        BE: t('zeitwerk', 'Berlin'),
        BB: t('zeitwerk', 'Brandenburg'),
        HB: t('zeitwerk', 'Bremen'),
        HH: t('zeitwerk', 'Hamburg'),
        HE: t('zeitwerk', 'Hessen'),
        MV: t('zeitwerk', 'Mecklenburg-Vorpommern'),
        NI: t('zeitwerk', 'Niedersachsen'),
        NW: t('zeitwerk', 'Nordrhein-Westfalen'),
        RP: t('zeitwerk', 'Rheinland-Pfalz'),
        SL: t('zeitwerk', 'Saarland'),
        SN: t('zeitwerk', 'Sachsen'),
        ST: t('zeitwerk', 'Sachsen-Anhalt'),
        SH: t('zeitwerk', 'Schleswig-Holstein'),
        TH: t('zeitwerk', 'Thüringen'),
    }
}

/**
 * Default Values
 */
export const DEFAULTS = {
    WEEKLY_HOURS: 40.0,
    VACATION_DAYS: 30,
    FEDERAL_STATE: 'BY',
    BREAK_MINUTES_6H: 30,
    BREAK_MINUTES_9H: 45,
    MAX_DAILY_HOURS: 10,
}

export default {
    ENTRY_STATUS,
    ABSENCE_TYPES,
    ABSENCE_STATUS,
    STATUS_LABELS,
    ABSENCE_TYPE_LABELS,
    FEDERAL_STATES,
    DEFAULTS,
}
