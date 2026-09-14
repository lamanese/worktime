import { isValidTimeString, normalizeTimeInput } from '../../src/utils/timeUtils.js'

describe('normalizeTimeInput', () => {
	it('normalizes hours-only input', () => {
		expect(normalizeTimeInput('8')).toBe('08:00')
		expect(normalizeTimeInput('23')).toBe('23:00')
	})

	it('normalizes colon/dot separated input', () => {
		expect(normalizeTimeInput('8:30')).toBe('08:30')
		expect(normalizeTimeInput('08:30')).toBe('08:30')
		expect(normalizeTimeInput('8.30')).toBe('08:30')
	})

	it('normalizes digits-only input (last two digits are minutes)', () => {
		expect(normalizeTimeInput('830')).toBe('08:30')
		expect(normalizeTimeInput('1330')).toBe('13:30')
	})

	it('trims surrounding whitespace', () => {
		expect(normalizeTimeInput('  8:30  ')).toBe('08:30')
	})

	it('returns empty string for empty input', () => {
		expect(normalizeTimeInput('')).toBe('')
		expect(normalizeTimeInput('   ')).toBe('')
	})

	it('returns trimmed input unchanged when invalid', () => {
		expect(normalizeTimeInput('8.5')).toBe('8.5')
		expect(normalizeTimeInput('24:00')).toBe('24:00')
		expect(normalizeTimeInput('7:5')).toBe('7:5')
	})
})

describe('isValidTimeString', () => {
	it('accepts valid HH:MM times', () => {
		expect(isValidTimeString('08:00')).toBe(true)
		expect(isValidTimeString('23:59')).toBe(true)
		expect(isValidTimeString('00:00')).toBe(true)
	})

	it('rejects out-of-range hours/minutes', () => {
		expect(isValidTimeString('25:99')).toBe(false)
		expect(isValidTimeString('24:00')).toBe(false)
	})

	it('rejects malformed or empty input', () => {
		expect(isValidTimeString('')).toBe(false)
		expect(isValidTimeString('8:00')).toBe(false)
		expect(isValidTimeString('08-00')).toBe(false)
	})
})
