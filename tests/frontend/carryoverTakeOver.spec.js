/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { canTakeOverActuals, takeOverActuals, takeOverAndSave } from '../../src/utils/carryoverTakeOver.js'

describe('Jahresuebertrag: Ist-Werte des Vorjahres per Button eintragen', () => {
	const row = (over = {}) => ({
		carryoverId: null,
		wasActive: true,
		isLocked: false,
		actualOvertimeHours: -315.67,
		actualVacationRemaining: 17.5,
		overtimeHours: 0,
		vacationDays: 0,
		...over,
	})

	test('offers the button for an editable row whose fields differ from the actuals', () => {
		expect(canTakeOverActuals(row())).toBe(true)
		// a saved but not yet carried-out row may be overwritten on purpose
		expect(canTakeOverActuals(row({ carryoverId: 42, overtimeHours: 5 }))).toBe(true)
	})

	test('hides the button once the fields already hold the actuals', () => {
		expect(canTakeOverActuals(row({ overtimeHours: -315.67, vacationDays: 17.5 }))).toBe(false)
		// one field changed by hand: offer to take the actuals over again
		expect(canTakeOverActuals(row({ overtimeHours: -315.67, vacationDays: 17 }))).toBe(true)
		// a missing actual is ignored in the comparison
		expect(canTakeOverActuals(row({ actualVacationRemaining: null, overtimeHours: -315.67, vacationDays: 3 }))).toBe(false)
	})

	test('never offers it for locked rows or employees without a source year', () => {
		expect(canTakeOverActuals(row({ carryoverId: 42, isLocked: true }))).toBe(false)
		expect(canTakeOverActuals(row({ wasActive: false }))).toBe(false)
		expect(canTakeOverActuals(row({ actualOvertimeHours: null, actualVacationRemaining: null }))).toBe(false)
	})

	test('copies both actuals exactly, without rounding half days or decimals', () => {
		expect(takeOverActuals(row())).toEqual({ overtimeHours: -315.67, vacationDays: 17.5 })
	})

	test('keeps the current value when one actual is missing', () => {
		expect(takeOverActuals(row({ actualVacationRemaining: null, vacationDays: 3 })))
			.toEqual({ overtimeHours: -315.67, vacationDays: 3 })
		expect(takeOverActuals(row({ actualOvertimeHours: null, overtimeHours: 2 })))
			.toEqual({ overtimeHours: 2, vacationDays: 17.5 })
	})

	describe('takeOverAndSave', () => {
		test('fills the fields and reports success when the save succeeds', async () => {
			const r = row()
			const save = jest.fn(async () => true)
			await expect(takeOverAndSave(r, save)).resolves.toBe(true)
			expect(save).toHaveBeenCalledWith(r)
			expect(r).toMatchObject({ overtimeHours: -315.67, vacationDays: 17.5 })
		})

		test('restores the previous values when the save fails, so the button is offered again', async () => {
			const r = row({ overtimeHours: 2, vacationDays: 3 })
			await expect(takeOverAndSave(r, async () => false)).resolves.toBe(false)
			expect(r).toMatchObject({ overtimeHours: 2, vacationDays: 3 })
			expect(canTakeOverActuals(r)).toBe(true)
		})

		test('keeps an edit HR made while the failed save was running', async () => {
			const r = row()
			const save = async (saved) => {
				saved.vacationDays = 10 // typed during the request
				return false
			}
			await expect(takeOverAndSave(r, save)).resolves.toBe(false)
			expect(r).toMatchObject({ overtimeHours: -315.67, vacationDays: 10 })
		})

		test('restores on the row currently shown when rows were rebuilt meanwhile', async () => {
			const old = row({ overtimeHours: 1, vacationDays: 1 })
			const rebuilt = row({ overtimeHours: -315.67, vacationDays: 17.5 })
			await expect(takeOverAndSave(old, async () => false, () => rebuilt)).resolves.toBe(false)
			expect(rebuilt).toMatchObject({ overtimeHours: 1, vacationDays: 1 })
		})
	})
})
