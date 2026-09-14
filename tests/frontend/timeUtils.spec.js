import { isValidTimeString } from '../../src/utils/timeUtils.js'

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
