/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Reine Hilfsfunktionen fuer die Land-Region-Kaskade (Mitarbeiterformular,
 * Standard-Region, Feiertagsfilter). `countries` ist die Antwort von
 * GET /api/holidays/regions: [{ code, name, regionLabel, regions: [{ code, name }] }].
 * Regionscodes sind ISO 3166-2 mit Laenderpraefix (DE-BY, CH-ZH).
 */

/** Laendercode aus einem Regionscode, null wenn kein Praefix. */
export function countryOf(code) {
	if (typeof code !== 'string') {
		return null
	}
	const index = code.indexOf('-')
	return index > 0 ? code.slice(0, index) : null
}

function findCountry(countries, countryCode) {
	return (countries || []).find(c => c.code === countryCode) || null
}

/** NcSelect-Optionen der Laender. */
export function countryOptions(countries) {
	return (countries || []).map(c => ({ id: c.code, label: c.name }))
}

/** NcSelect-Optionen der Regionen eines Landes. */
export function regionOptions(countries, countryCode) {
	const country = findCountry(countries, countryCode)
	return country ? country.regions.map(r => ({ id: r.code, label: r.name })) : []
}

/** Bezeichnung der Regionsebene («Bundesland», «Kanton»), Fallback «Region». */
export function regionLabelFor(countries, countryCode, fallback = 'Region') {
	const country = findCountry(countries, countryCode)
	return country?.regionLabel || fallback
}

/** Anzeigename einer Region ueber alle Laender, Fallback ist der Code. */
export function regionName(countries, code) {
	for (const country of countries || []) {
		const region = country.regions.find(r => r.code === code)
		if (region) {
			return region.name
		}
	}
	return code || ''
}

/** Erste Region eines Landes (Vorbelegung nach Landwechsel), null wenn unbekannt. */
export function firstRegionOf(countries, countryCode) {
	const options = regionOptions(countries, countryCode)
	return options.length ? options[0].id : null
}

/** Alle Regionscodes eines Landes, oder aller Laender wenn kein Land angegeben. */
export function regionCodesOf(countries, countryCode) {
	const list = countryCode ? [findCountry(countries, countryCode)].filter(Boolean) : (countries || [])
	return list.flatMap(c => c.regions.map(r => r.code))
}
