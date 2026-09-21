import {
	getWeekStart, getWeekDays, addDays, formatWeekLabel, sortJobs, formatDuration, cellState, resolveDrop, mondayOfIsoWeek, isoYear, splitTemplates, weekdayShortName, isoWeekday, blockedWeekdays,
} from '../../src/utils/dutyRoster.js'

describe('week helpers (local dates, no UTC shift)', () => {
	it('getWeekStart returns Monday for any weekday', () => {
		expect(getWeekStart('2026-09-14')).toBe('2026-09-14') // Monday itself
		expect(getWeekStart('2026-09-20')).toBe('2026-09-14') // Sunday
		expect(getWeekStart('2026-01-01')).toBe('2025-12-29') // year boundary
	})

	it('getWeekDays returns seven consecutive days', () => {
		const days = getWeekDays('2026-09-14')
		expect(days).toHaveLength(7)
		expect(days[0]).toBe('2026-09-14')
		expect(days[6]).toBe('2026-09-20')
	})

	it('addDays crosses month boundaries', () => {
		expect(addDays('2026-09-30', 1)).toBe('2026-10-01')
		expect(addDays('2026-03-01', -1)).toBe('2026-02-28')
	})

	it('formatWeekLabel shows ISO week and range', () => {
		expect(formatWeekLabel('2026-09-14')).toBe('KW 38 · 14.09. – 20.09.2026')
		expect(formatWeekLabel('2025-12-29')).toBe('KW 1 · 29.12. – 04.01.2026')
	})

	it('mondayOfIsoWeek resolves KW/year incl. year boundaries', () => {
		expect(mondayOfIsoWeek(2026, 38)).toBe('2026-09-14')
		expect(mondayOfIsoWeek(2026, 1)).toBe('2025-12-29') // week 1 starts in the old year
		expect(mondayOfIsoWeek(2027, 13)).toBe('2027-03-29')
		expect(mondayOfIsoWeek(2026, 53)).toBe('2026-12-28') // 2026 has 53 weeks
		expect(mondayOfIsoWeek(2027, 53)).toBeNull() // 2027 has 52
		expect(mondayOfIsoWeek(2026, 0)).toBeNull()
		expect(mondayOfIsoWeek(2026, 54)).toBeNull()
		expect(mondayOfIsoWeek(NaN, 3)).toBeNull()
	})

	it('isoYear follows the Thursday rule', () => {
		expect(isoYear('2026-01-01')).toBe(2026)
		expect(isoYear('2025-12-29')).toBe(2026)
		expect(isoYear('2027-01-03')).toBe(2026) // Sunday of week 53/2026
	})

	it('formatWeekLabel accepts a custom prefix', () => {
		expect(formatWeekLabel('2026-09-14', 'Wk')).toBe('Wk 38 · 14.09. – 20.09.2026')
	})
})

describe('sortJobs', () => {
	it('orders by time, untimed last, then title', () => {
		const jobs = [
			{ id: 1, startTime: null, title: 'Zulu' },
			{ id: 2, startTime: '13:00', title: 'B' },
			{ id: 3, startTime: '08:30', title: 'C' },
			{ id: 4, startTime: null, title: 'Alpha' },
			{ id: 5, startTime: '08:30', title: 'A' },
		]
		expect(sortJobs(jobs).map(j => j.id)).toEqual([5, 3, 2, 4, 1])
	})

	it('does not mutate the input', () => {
		const jobs = [{ id: 1, startTime: '10:00', title: 'x' }, { id: 2, startTime: '09:00', title: 'y' }]
		sortJobs(jobs)
		expect(jobs[0].id).toBe(1)
	})
})

describe('formatDuration', () => {
	it.each([
		[45, '45 min'], [60, '1 h'], [90, '1 h 30'], [120, '2 h'], [125, '2 h 05'],
	])('%i minutes -> %s', (min, label) => {
		expect(formatDuration(min)).toBe(label)
	})
	it('returns empty string for null/0', () => {
		expect(formatDuration(null)).toBe('')
		expect(formatDuration(0)).toBe('')
	})
})

describe('cellState precedence: holiday > approved full > approved half > pending > normal', () => {
	const approved = (type, typeName, scope = 1) => ({ type, typeName, status: 'approved', scope })
	const pending = (type, typeName) => ({ type, typeName, status: 'pending', scope: 1 })

	it('normal when nothing', () => {
		expect(cellState([], [])).toEqual({ state: 'normal', labelKind: null, labelText: null })
	})
	it('holiday wins over everything', () => {
		const r = cellState([approved('vacation', 'Urlaub', 0.5), pending('sick', 'Krank')], [{ date: 'x', name: 'Bettag' }])
		expect(r.state).toBe('absent')
		expect(r.labelKind).toBe('holiday')
		expect(r.labelText).toBe('Bettag')
	})
	it('approved full day dims', () => {
		expect(cellState([approved('sick', 'Krank')], [])).toEqual({ state: 'absent', labelKind: 'absence', labelText: 'Krank' })
	})
	it('approved beats pending', () => {
		const r = cellState([pending('vacation', 'Urlaub'), approved('sick', 'Krank')], [])
		expect(r).toEqual({ state: 'absent', labelKind: 'absence', labelText: 'Krank' })
	})
	it('half day', () => {
		expect(cellState([approved('vacation', 'Urlaub', 0.5)], [])).toEqual({ state: 'absent-half', labelKind: 'half', labelText: 'Urlaub' })
	})
	it('full beats half', () => {
		const r = cellState([approved('vacation', 'Urlaub', 0.5), approved('training', 'Weiterbildung', 1)], [])
		expect(r.state).toBe('absent')
	})
	it('pending only', () => {
		expect(cellState([pending('vacation', 'Urlaub')], [])).toEqual({ state: 'pending', labelKind: 'pending', labelText: 'Urlaub' })
	})
	it('masked absence for non-planners', () => {
		expect(cellState([approved('absent', 'Abwesend')], [])).toEqual({ state: 'absent', labelKind: 'absence', labelText: 'Abwesend' })
	})
	it('returns no user-facing text (labels are composed via t() in the view)', () => {
		const r = cellState([approved('vacation', 'Urlaub', 0.5)], [])
		expect(r.labelText).not.toMatch(/½|beantragt|Feiertag/)
	})
})

describe('resolveDrop', () => {
	const transfer = (map) => ({ getData: (type) => map[type] ?? '' })

	it('recognises a dragged job card', () => {
		expect(resolveDrop(transfer({ 'application/x-zeitwerk-duty-job': '42', 'text/plain': '42' })))
			.toEqual({ kind: 'job', id: 42 })
	})

	it('recognises a dragged template', () => {
		expect(resolveDrop(transfer({ 'application/x-zeitwerk-duty-template': '7' })))
			.toEqual({ kind: 'template', id: 7 })
	})

	it('recognises a dragged template with the text/plain fallback set (sidebar payload)', () => {
		expect(resolveDrop(transfer({
			'text/plain': '7',
			'application/x-zeitwerk-duty-template': '7',
		}))).toEqual({ kind: 'template', id: 7 })
	})

	it('prefers the job marker when both are present', () => {
		expect(resolveDrop(transfer({
			'application/x-zeitwerk-duty-job': '42',
			'application/x-zeitwerk-duty-template': '7',
		}))).toEqual({ kind: 'job', id: 42 })
	})

	it('ignores foreign drops, empty and non-numeric markers', () => {
		expect(resolveDrop(transfer({ 'text/plain': 'irgendwas' }))).toEqual({ kind: null, id: 0 })
		expect(resolveDrop(transfer({ 'application/x-zeitwerk-duty-job': '' }))).toEqual({ kind: null, id: 0 })
		expect(resolveDrop(transfer({ 'application/x-zeitwerk-duty-template': 'abc' }))).toEqual({ kind: null, id: 0 })
		expect(resolveDrop(transfer({ 'application/x-zeitwerk-duty-job': '0' }))).toEqual({ kind: null, id: 0 })
		expect(resolveDrop(null)).toEqual({ kind: null, id: 0 })
	})
})

describe('fixed-weekday templates (spec §12)', () => {
	const fixed = { id: 7, title: 'Reinigung', weekdays: [1, 3, 5] }
	const free = { id: 8, title: 'Frei', weekdays: [] }
	const legacy = { id: 9, title: 'Alt' } // payload without weekdays

	it('splitTemplates groups by open, any and done', () => {
		const groups = splitTemplates([fixed, free, legacy], [
			{ templateId: 7, title: 'Reinigung', weekdays: [1, 3, 5], doneDays: [1], openDays: [3, 5], skipped: false },
		])
		expect(groups.open.map(e => e.template.id)).toEqual([7])
		expect(groups.any.map(e => e.template.id)).toEqual([8, 9])
		expect(groups.done).toEqual([])
		expect(groups.open[0].coverage.openDays).toEqual([3, 5])
	})

	it('a complete or ignored template moves to done', () => {
		const complete = splitTemplates([fixed], [{ templateId: 7, weekdays: [1, 3, 5], doneDays: [1, 3, 5], openDays: [], skipped: false }])
		expect(complete.done).toHaveLength(1)
		const skipped = splitTemplates([fixed], [{ templateId: 7, weekdays: [1, 3, 5], doneDays: [], openDays: [1, 3, 5], skipped: true }])
		expect(skipped.done).toHaveLength(1)
		expect(skipped.open).toEqual([])
	})

	it('a fixed template without coverage stays visible as fully open', () => {
		const groups = splitTemplates([fixed], [])
		expect(groups.open).toHaveLength(1)
		expect(groups.open[0].coverage.openDays).toEqual([1, 3, 5])
	})

	it('weekdayShortName and isoWeekday agree on ISO numbering', () => {
		expect(weekdayShortName(1, 'de-CH')).toBe('Mo')
		expect(weekdayShortName(7, 'de-CH')).toBe('So')
		expect(isoWeekday('2026-09-21')).toBe(1) // Monday
		expect(isoWeekday('2026-09-27')).toBe(7) // Sunday
	})
})

describe('templates: done for the week, blocked days (spec §12)', () => {
	it('a free template marked «Erledigt» moves to done, otherwise stays in any', () => {
		const free = { id: 8, title: 'Frei', weekdays: [] }
		const cov = (skipped) => [{ templateId: 8, weekdays: [], doneDays: [], openDays: [], holidayDays: [], skipped }]
		expect(splitTemplates([free], cov(true)).done).toHaveLength(1)
		expect(splitTemplates([free], cov(false)).any).toHaveLength(1)
		expect(splitTemplates([free], []).any[0].coverage.skipped).toBe(false)
	})

	it('a fixed template whose only missing day is a holiday counts as done', () => {
		const fixed = { id: 7, title: 'Reinigung', weekdays: [1, 3] }
		const groups = splitTemplates([fixed], [{ templateId: 7, weekdays: [1, 3], doneDays: [3], openDays: [], holidayDays: [1], skipped: false }])
		expect(groups.done).toHaveLength(1)
	})

	it('blockedWeekdays limits fixed templates unless other days are allowed', () => {
		expect(blockedWeekdays({ weekdays: [1, 3, 5] })).toEqual([2, 4, 6, 7])
		expect(blockedWeekdays({ weekdays: [1, 3, 5], allowOtherDays: true })).toEqual([])
		expect(blockedWeekdays({ weekdays: [] })).toEqual([])
		expect(blockedWeekdays({})).toEqual([])
	})
})
