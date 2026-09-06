/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { groupHolidays } from '../../src/utils/holidayGroups.js'

describe('Feiertagsgruppen: Datum, Name und Scope', () => {
	const holiday = (id, federalState, overrides = {}) => ({
		id,
		date: '2026-05-01',
		name: 'Tag der Arbeit',
		federalState,
		scope: 1.0,
		isManual: false,
		...overrides,
	})

	it('fasst gleiche Feiertage mit gleichem Scope zu einer Gruppe zusammen', () => {
		const groups = groupHolidays([
			holiday(1, 'CH-ZH'),
			holiday(2, 'CH-BE'),
			holiday(3, 'CH-AG'),
		])

		expect(groups).toHaveLength(1)
		expect(groups[0].states).toEqual(['CH-ZH', 'CH-BE', 'CH-AG'])
		expect(groups[0].holidays.map(h => h.id)).toEqual([1, 2, 3])
		expect(groups[0].scope).toBe(1.0)
		expect(groups[0].isManual).toBe(false)
	})

	it('trennt einen halben Tag (Solothurn) vom ganzen Tag der anderen Kantone', () => {
		const groups = groupHolidays([
			holiday(1, 'CH-ZH'),
			holiday(2, 'CH-SO', { scope: 0.5 }),
			holiday(3, 'CH-BE'),
		])

		expect(groups).toHaveLength(2)
		const full = groups.find(g => g.scope === 1.0)
		const half = groups.find(g => g.scope === 0.5)
		expect(full.states).toEqual(['CH-ZH', 'CH-BE'])
		expect(half.states).toEqual(['CH-SO'])
		expect(half.holidays.map(h => h.id)).toEqual([2])
		expect(full.key).not.toBe(half.key)
	})

	it('behandelt fehlenden Scope als ganzen Tag', () => {
		const groups = groupHolidays([
			holiday(1, 'DE-BW', { scope: undefined }),
			holiday(2, 'DE-BY', { scope: null }),
			holiday(3, 'DE-BE'),
		])

		expect(groups).toHaveLength(1)
		expect(groups[0].scope).toBe(1.0)
		expect(groups[0].states).toEqual(['DE-BW', 'DE-BY', 'DE-BE'])
	})

	it('markiert die Gruppe als manuell, sobald ein Eintrag manuell ist', () => {
		const groups = groupHolidays([
			holiday(1, 'DE-BW'),
			holiday(2, 'DE-BY', { isManual: true }),
		])

		expect(groups).toHaveLength(1)
		expect(groups[0].isManual).toBe(true)
	})

	it('sortiert die Gruppen nach Datum', () => {
		const groups = groupHolidays([
			holiday(1, 'DE-BW', { date: '2026-12-24', name: 'Heiligabend', scope: 0.5 }),
			holiday(2, 'DE-BW', { date: '2026-01-01', name: 'Neujahr' }),
			holiday(3, 'DE-BW', { date: '2026-05-01' }),
		])

		expect(groups.map(g => g.name)).toEqual(['Neujahr', 'Tag der Arbeit', 'Heiligabend'])
	})

	it('liefert bei leerer Liste ein leeres Array', () => {
		expect(groupHolidays([])).toEqual([])
	})
})
