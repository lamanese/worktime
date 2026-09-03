/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Month workflow status as shown in the time tracking view.
 *
 * Since 0.17.0 the backend owns the month status (zw_month_status) and the
 * monthly report delivers `monthStatus` + `canSubmitMonth`. The entry-based
 * derivation stays as a fallback while the report is still loading.
 */

export function resolveMonthStatus(reportStatus, entries) {
    if (reportStatus) return reportStatus
    if (!entries.length) return 'draft'
    if (entries.every(e => e.status === 'approved')) return 'approved'
    if (entries.every(e => e.status !== 'draft' && e.status !== 'rejected')) return 'submitted'
    return 'draft'
}

export function resolveCanSubmit(reportCanSubmit, status, entries) {
    if (typeof reportCanSubmit === 'boolean') return reportCanSubmit
    if (status === 'approved') return false
    return entries.some(e => e.status === 'draft' || e.status === 'rejected')
}
