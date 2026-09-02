/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Jahresuebertrag: Ist-Werte des Vorjahres per Button «Eintragen» in die
 * Uebertrags-Felder uebernehmen. Reine Logik ohne Vue, damit sie testbar
 * bleibt (SettingsView verdrahtet sie).
 */

const isNumber = (v) => typeof v === 'number' && Number.isFinite(v)

/**
 * Whether the «Eintragen» button is shown for a row: the row must still be
 * editable, the employee must have been active in the source year (so actuals
 * exist), and at least one field must differ from its actual — once the fields
 * hold the actuals the button has nothing left to do. A saved but not yet
 * carried-out row may be overwritten on purpose (explicit click by HR).
 * @param {object} row carryover table row (see SettingsView.loadCarryovers)
 * @return {boolean}
 */
export function canTakeOverActuals(row) {
	if (!row || row.isLocked || !row.wasActive) {
		return false
	}
	const overtimeDiffers = isNumber(row.actualOvertimeHours) && row.overtimeHours !== row.actualOvertimeHours
	const vacationDiffers = isNumber(row.actualVacationRemaining) && row.vacationDays !== row.actualVacationRemaining
	return overtimeDiffers || vacationDiffers
}

/**
 * The field values after taking the actuals over: copied exactly (no rounding,
 * half days and decimals stay), a missing actual keeps the current field value.
 * @param {object} row carryover table row
 * @return {{overtimeHours: number, vacationDays: number}}
 */
export function takeOverActuals(row) {
	return {
		overtimeHours: isNumber(row.actualOvertimeHours) ? row.actualOvertimeHours : row.overtimeHours,
		vacationDays: isNumber(row.actualVacationRemaining) ? row.actualVacationRemaining : row.vacationDays,
	}
}

/**
 * «Eintragen» end to end: fill the fields with the actuals and save. When the
 * save fails (the caller has shown the error), the fields would hold unsaved
 * actuals with no button left to retry — «Eintragen» hides because the fields
 * equal the actuals, «Durchführen» needs a saved row. So the previous values
 * are restored, unless HR edited the fields while the request was running.
 * @param {object} row carryover table row
 * @param {(row: object) => Promise<boolean>} save persists the row, resolves true on success
 * @param {(row: object) => object} currentRow the row object currently shown for this row
 *   (rows are rebuilt on every load); defaults to the row itself
 * @return {Promise<boolean>} true when the actuals are saved
 */
export async function takeOverAndSave(row, save, currentRow = (r) => r) {
	const before = { overtimeHours: row.overtimeHours, vacationDays: row.vacationDays }
	const values = takeOverActuals(row)
	Object.assign(row, values)
	if (await save(row)) {
		return true
	}
	const shown = currentRow(row)
	if (shown.overtimeHours === values.overtimeHours && shown.vacationDays === values.vacationDays) {
		Object.assign(shown, before)
	}
	return false
}
