/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Groups holidays for the settings table: one row per date, name and scope.
 *
 * Scope is part of the key on purpose. Since 0.18.0 the same holiday can be a
 * half day in one region and a full day in another (Solothurn, 1 May). The
 * group edit applies one scope to every holiday of the group, so a mixed
 * group would overwrite the correct scopes on save.
 */
export function groupHolidays(holidays) {
    const groups = {}
    for (const holiday of holidays) {
        const scope = holiday.scope ?? 1.0
        const key = `${holiday.date}_${holiday.name}_${scope}`
        if (!groups[key]) {
            groups[key] = {
                key,
                date: holiday.date,
                name: holiday.name,
                scope,
                isManual: holiday.isManual,
                states: [],
                holidays: [],
            }
        }
        groups[key].states.push(holiday.federalState)
        groups[key].holidays.push(holiday)
        // If any holiday in the group is manual, mark the group as manual
        if (holiday.isManual) {
            groups[key].isManual = true
        }
    }
    return Object.values(groups).sort((a, b) => a.date.localeCompare(b.date))
}
