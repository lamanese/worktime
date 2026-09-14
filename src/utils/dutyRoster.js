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
