/**
 * Pure logic for the duty roster (Dienstplan) week view. No Vue, no DOM — kept
 * here so Jest can lock it down (tests/frontend/dutyRoster.spec.js).
 *
 * All dates are 'YYYY-MM-DD' strings parsed as LOCAL dates. Never use
 * `new Date('YYYY-MM-DD')` here: that is UTC and shifts the week in CET.
 */
import { getISOWeek } from './dateUtils.js'

const pad = (n) => String(n).padStart(2, '0')

export function parseLocalDate(str) {
	const [y, m, d] = str.split('-').map(Number)
	return new Date(y, m - 1, d)
}

export function toDateString(date) {
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function addDays(str, n) {
	const d = parseLocalDate(str)
	d.setDate(d.getDate() + n)
	return toDateString(d)
}

/**
 * Monday (Y-m-d) of ISO week `week` in ISO year `year`, or null when the week
 * does not exist in that year (e.g. week 53 in a 52-week year). ISO rule:
 * 4 January is always in week 1.
 *
 * @param {number} year ISO year
 * @param {number} week ISO week 1..53
 * @return {string|null} Monday as Y-m-d
 */
export function mondayOfIsoWeek(year, week) {
	if (!Number.isInteger(year) || !Number.isInteger(week) || week < 1 || week > 53) return null
	const jan4 = new Date(year, 0, 4)
	const monday = new Date(year, 0, 4 - ((jan4.getDay() + 6) % 7) + (week - 1) * 7)
	const str = toDateString(monday)
	return getISOWeek(monday) === week && isoYear(str) === year ? str : null
}

/** ISO year of the week containing the date (year of that week's Thursday). */
export function isoYear(str) {
	const thursday = parseLocalDate(addDays(getWeekStart(str), 3))
	return thursday.getFullYear()
}

/** Monday of the ISO week containing the date. */
export function getWeekStart(str) {
	const d = parseLocalDate(str)
	const offset = (d.getDay() + 6) % 7 // Mon=0 ... Sun=6
	d.setDate(d.getDate() - offset)
	return toDateString(d)
}

export function getWeekDays(mondayStr) {
	return Array.from({ length: 7 }, (_, i) => addDays(mondayStr, i))
}

/** 'KW 38 · 14.09. – 20.09.2026' */
export function formatWeekLabel(mondayStr, prefix = 'KW') {
	const monday = parseLocalDate(mondayStr)
	const sunday = parseLocalDate(addDays(mondayStr, 6))
	const week = getISOWeek(monday)
	const dm = (d) => `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.`
	return `${prefix} ${week} · ${dm(monday)} – ${dm(sunday)}${sunday.getFullYear()}`
}

/** Timed cards first (by time), untimed last, ties by title. Returns a copy. */
export function sortJobs(jobs) {
	return [...jobs].sort((a, b) => {
		if (a.startTime && !b.startTime) return -1
		if (!a.startTime && b.startTime) return 1
		if (a.startTime && b.startTime && a.startTime !== b.startTime) {
			return a.startTime < b.startTime ? -1 : 1
		}
		return (a.title || '').localeCompare(b.title || '')
	})
}

/** 45 -> '45 min', 60 -> '1 h', 90 -> '1 h 30', 125 -> '2 h 05' */
export function formatDuration(minutes) {
	if (!minutes || minutes <= 0) return ''
	const h = Math.floor(minutes / 60)
	const m = minutes % 60
	if (h === 0) return `${m} min`
	if (m === 0) return `${h} h`
	return `${h} h ${pad(m)}`
}

/**
 * Visual state of one cell (employee x day). Returns no user-facing text —
 * the view composes the label via t() (see DutyRosterView.cellLabel).
 * Precedence: holiday > approved full day > approved half day > pending > normal.
 *
 * @param {Array<{type:string,typeName:string,status:string,scope:number}>} absences absences of that employee on that day
 * @param {Array<{date:string,name:string}>} holidays holidays of that employee's region on that day
 * @return {{state: 'normal'|'absent'|'absent-half'|'pending', labelKind: 'holiday'|'absence'|'half'|'pending'|null, labelText: string|null}}
 */
export function cellState(absences, holidays) {
	if (holidays.length > 0) {
		return { state: 'absent', labelKind: 'holiday', labelText: holidays[0].name }
	}
	const approved = absences.filter(a => a.status === 'approved')
	const full = approved.find(a => Number(a.scope) >= 1)
	if (full) {
		return { state: 'absent', labelKind: 'absence', labelText: full.typeName }
	}
	const half = approved.find(a => Number(a.scope) < 1)
	if (half) {
		return { state: 'absent-half', labelKind: 'half', labelText: half.typeName }
	}
	const pending = absences.find(a => a.status === 'pending')
	if (pending) {
		return { state: 'pending', labelKind: 'pending', labelText: pending.typeName }
	}
	return { state: 'normal', labelKind: null, labelText: null }
}

/** MIME types used by the duty roster drag sources. */
export const DUTY_JOB_MIME = 'application/x-zeitwerk-duty-job'
export const DUTY_TEMPLATE_MIME = 'application/x-zeitwerk-duty-template'

/**
 * Tells a cell drop apart: an existing card (move) or a sidebar template
 * (create). Pure so Jest can lock the precedence down; the view only reacts to
 * the result. Foreign drops answer {kind: null, id: 0} and must be ignored.
 *
 * @param {DataTransfer|null} dataTransfer the drop event's dataTransfer
 * @return {{kind: ('job'|'template'|null), id: number}} what was dropped
 */
export function resolveDrop(dataTransfer) {
	const none = { kind: null, id: 0 }
	if (!dataTransfer || typeof dataTransfer.getData !== 'function') {
		return none
	}
	const read = (type) => {
		const id = Number(dataTransfer.getData(type))
		return Number.isInteger(id) && id > 0 ? id : 0
	}
	const jobId = read(DUTY_JOB_MIME)
	if (jobId) {
		return { kind: 'job', id: jobId }
	}
	const templateId = read(DUTY_TEMPLATE_MIME)
	if (templateId) {
		return { kind: 'template', id: templateId }
	}
	return none
}

/**
 * Short weekday name for an ISO weekday (1 = Monday ... 7 = Sunday).
 *
 * @param {number} isoDay ISO weekday
 * @param {string} [locale] BCP 47 locale, browser default when omitted
 * @return {string} e.g. «Mo»
 */
export function weekdayShortName(isoDay, locale) {
	// 2024-01-01 is a Monday.
	return new Date(2024, 0, isoDay).toLocaleDateString(locale, { weekday: 'short' }).replace(/\.$/, '')
}

/**
 * Splits the sidebar templates into the three groups of spec §12: fixed-weekday
 * templates that still miss days (`open`), templates without fixed weekdays
 * (`any`) and templates that are through for this week (`done`): fixed ones
 * that are complete or ignored, free ones marked «Erledigt». Each entry
 * carries its coverage. A template without a coverage entry (stale week data)
 * is treated as untouched, so nothing silently disappears.
 *
 * @param {Array<object>} templates visible templates
 * @param {Array<object>} coverage `templateCoverage` of the week response
 * @return {{open: Array<object>, any: Array<object>, done: Array<object>}} grouped entries
 */
export function splitTemplates(templates, coverage) {
	const byId = new Map((coverage || []).map(c => [c.templateId, c]))
	const groups = { open: [], any: [], done: [] }
	for (const template of templates || []) {
		const weekdays = template.weekdays || []
		const cov = byId.get(template.id)
			|| { templateId: template.id, title: template.title, weekdays, doneDays: [], openDays: weekdays, holidayDays: [], skipped: false }
		if (weekdays.length === 0) {
			groups[cov.skipped ? 'done' : 'any'].push({ template, coverage: cov })
			continue
		}
		const complete = cov.skipped || cov.openDays.length === 0
		groups[complete ? 'done' : 'open'].push({ template, coverage: cov })
	}
	return groups
}

/**
 * ISO weekday (1 = Monday ... 7 = Sunday) of a YYYY-MM-DD date string.
 *
 * @param {string} date local date
 * @return {number} ISO weekday
 */
export function isoWeekday(date) {
	return parseLocalDate(date).getDay() || 7
}

/**
 * ISO weekdays a template must not be dropped on: a template with fixed
 * weekdays is limited to them unless «auch an anderen Tagen erlaubt» is set.
 *
 * @param {object} template sidebar template
 * @return {number[]} blocked ISO weekdays (empty = every day allowed)
 */
export function blockedWeekdays(template) {
	const weekdays = template.weekdays || []
	if (weekdays.length === 0 || template.allowOtherDays) return []
	return [1, 2, 3, 4, 5, 6, 7].filter(d => !weekdays.includes(d))
}
