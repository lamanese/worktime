/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import workSchedules from '../../src/store/modules/workSchedules.js'

// The store module only needs the API service at action time; the mutations
// under test are pure, so the service (and its axios dependency) is stubbed.
// (jest.mock is hoisted above the import by babel-jest.)
jest.mock('../../src/services/WorkScheduleService.js', () => ({}))

const { mutations } = workSchedules

describe('workSchedules store: list order after edits', () => {
	const sched = (id, validFrom) => ({ id, validFrom })

	test('UPDATE_SCHEDULE re-sorts by validFrom descending when the date moved', () => {
		const state = { schedules: [sched(2, '2026-08-01'), sched(1, '2026-07-20')] }

		// Profile 2 is moved back before profile 1.
		mutations.UPDATE_SCHEDULE(state, sched(2, '2026-07-01'))

		expect(state.schedules.map((s) => s.id)).toEqual([1, 2])
		expect(state.schedules[1].validFrom).toBe('2026-07-01')
	})

	test('UPDATE_SCHEDULE keeps a stable order when the date is unchanged', () => {
		const state = { schedules: [sched(2, '2026-08-01'), sched(1, '2026-07-20')] }

		mutations.UPDATE_SCHEDULE(state, { ...sched(1, '2026-07-20'), monHours: 7.7 })

		expect(state.schedules.map((s) => s.id)).toEqual([2, 1])
		expect(state.schedules[1].monHours).toBe(7.7)
	})
})
