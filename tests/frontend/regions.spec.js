/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import {
	countryOf,
	countryOptions,
	regionOptions,
	regionLabelFor,
	regionName,
	firstRegionOf,
	regionCodesOf,
} from '../../src/utils/regions.js'

describe('Regionen: Land-Region-Kaskade', () => {
	const countries = [
		{
			code: 'DE', name: 'Deutschland', regionLabel: 'Bundesland',
			regions: [{ code: 'DE-BW', name: 'Baden-Württemberg' }, { code: 'DE-BY', name: 'Bayern' }, { code: 'DE-BE', name: 'Berlin' }],
		},
		{
			code: 'CH', name: 'Schweiz', regionLabel: 'Kanton',
			regions: [{ code: 'CH-AG', name: 'Aargau' }, { code: 'CH-BE', name: 'Bern' }, { code: 'CH-ZH', name: 'Zürich' }],
		},
	]

	test('countryOf reads the prefix and tolerates junk', () => {
		expect(countryOf('DE-BY')).toBe('DE')
		expect(countryOf('CH-ZH')).toBe('CH')
		expect(countryOf('BY')).toBeNull()
		expect(countryOf('')).toBeNull()
		expect(countryOf(null)).toBeNull()
		expect(countryOf(undefined)).toBeNull()
	})

	test('countryOptions and regionOptions build NcSelect options', () => {
		expect(countryOptions(countries)).toEqual([{ id: 'DE', label: 'Deutschland' }, { id: 'CH', label: 'Schweiz' }])
		expect(regionOptions(countries, 'CH')).toEqual([
			{ id: 'CH-AG', label: 'Aargau' }, { id: 'CH-BE', label: 'Bern' }, { id: 'CH-ZH', label: 'Zürich' },
		])
		expect(regionOptions(countries, 'AT')).toEqual([])
		expect(regionOptions(undefined, 'DE')).toEqual([])
		expect(countryOptions(null)).toEqual([])
	})

	test('regionLabelFor returns the backend label with a fallback', () => {
		expect(regionLabelFor(countries, 'DE')).toBe('Bundesland')
		expect(regionLabelFor(countries, 'CH')).toBe('Kanton')
		expect(regionLabelFor(countries, 'AT')).toBe('Region')
		expect(regionLabelFor([], null)).toBe('Region')
	})

	test('regionName resolves across countries and falls back to the code', () => {
		expect(regionName(countries, 'DE-BE')).toBe('Berlin')
		expect(regionName(countries, 'CH-BE')).toBe('Bern')
		expect(regionName(countries, 'CH-XX')).toBe('CH-XX')
		expect(regionName(countries, null)).toBe('')
	})

	test('firstRegionOf and regionCodesOf', () => {
		expect(firstRegionOf(countries, 'CH')).toBe('CH-AG')
		expect(firstRegionOf(countries, 'AT')).toBeNull()
		expect(regionCodesOf(countries, 'DE')).toEqual(['DE-BW', 'DE-BY', 'DE-BE'])
		expect(regionCodesOf(countries, null)).toEqual(['DE-BW', 'DE-BY', 'DE-BE', 'CH-AG', 'CH-BE', 'CH-ZH'])
	})
})
