/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

jest.mock('../../src/services/DutyRosterService.js', () => ({
	__esModule: true,
	default: {
		getWeek: jest.fn(),
		createJob: jest.fn(),
		moveJob: jest.fn(),
		copyWeek: jest.fn(),
	},
}))

jest.mock('../../src/services/DutyJobTemplateService.js', () => ({
	__esModule: true,
	default: {
		getVisible: jest.fn(),
	},
}))

import DutyRosterService from '../../src/services/DutyRosterService.js'
import DutyJobTemplateService from '../../src/services/DutyJobTemplateService.js'
import dutyRoster from '../../src/store/modules/dutyRoster.js'

const run = async (action, state, payload) => {
	const commits = []
	const commit = (type, p) => { commits.push([type, p]); dutyRoster.mutations[type](state, p) }
	const dispatch = (name, p) => dutyRoster.actions[name]({ state, commit, dispatch }, p)
	const result = await dutyRoster.actions[action]({ state, commit, dispatch }, payload)
	return { commits, result }
}

describe('dutyRoster store', () => {
	let state
	beforeEach(() => {
		state = { ...dutyRoster.state() }
		jest.clearAllMocks()
	})

	it('loadWeek normalizes to Monday and stores payload', async () => {
		DutyRosterService.getWeek.mockResolvedValue({ weekStart: '2026-09-14', rows: [], days: [], canManage: true })
		await run('loadWeek', state, '2026-09-17')
		expect(DutyRosterService.getWeek).toHaveBeenCalledWith('2026-09-14')
		expect(state.weekStart).toBe('2026-09-14')
		expect(state.week.canManage).toBe(true)
		expect(state.loading).toBe(false)
	})

	it('nextWeek / prevWeek step by seven days and reload', async () => {
		DutyRosterService.getWeek.mockResolvedValue({ rows: [], days: [] })
		state.weekStart = '2026-09-14'
		await run('nextWeek', state)
		expect(DutyRosterService.getWeek).toHaveBeenLastCalledWith('2026-09-21')
		await run('prevWeek', state)
		expect(DutyRosterService.getWeek).toHaveBeenLastCalledWith('2026-09-14')
	})

	it('moveJob reloads the current week', async () => {
		DutyRosterService.moveJob.mockResolvedValue({})
		DutyRosterService.getWeek.mockResolvedValue({ rows: [], days: [] })
		state.weekStart = '2026-09-14'
		await run('moveJob', state, { id: 5, employeeId: 2, date: '2026-09-16' })
		expect(DutyRosterService.moveJob).toHaveBeenCalledWith(5, 2, '2026-09-16')
		expect(DutyRosterService.getWeek).toHaveBeenCalledWith('2026-09-14')
	})

	it('copyToNextWeek returns the count and jumps to the target week', async () => {
		DutyRosterService.copyWeek.mockResolvedValue({ created: 3 })
		DutyRosterService.getWeek.mockResolvedValue({ rows: [], days: [] })
		state.weekStart = '2026-09-14'
		const { result } = await run('copyToNextWeek', state)
		expect(result).toBe(3)
		expect(DutyRosterService.copyWeek).toHaveBeenCalledWith('2026-09-14', '2026-09-21')
		expect(state.weekStart).toBe('2026-09-21')
	})

	it('loadWeek stores the error message on failure', async () => {
		DutyRosterService.getWeek.mockRejectedValue(new Error('kaputt'))
		await run('loadWeek', state, '2026-09-14')
		expect(state.error).toBe('kaputt')
		expect(state.loading).toBe(false)
	})
})

describe('dutyRoster templates', () => {
	let state
	beforeEach(() => {
		state = { ...dutyRoster.state() }
		jest.clearAllMocks()
	})

	it('starts empty', () => {
		expect(state.templates).toEqual([])
		expect(dutyRoster.getters.templates(state)).toEqual([])
	})

	it('loadTemplates stores the visible templates', async () => {
		DutyJobTemplateService.getVisible.mockResolvedValue([{ id: 1, title: 'HU', isVisible: true }])
		await run('loadTemplates', state)
		expect(DutyJobTemplateService.getVisible).toHaveBeenCalled()
		expect(dutyRoster.getters.templates(state)).toHaveLength(1)
	})

	it('loadTemplates keeps the week usable when the call fails', async () => {
		DutyJobTemplateService.getVisible.mockRejectedValue(new Error('403'))
		await run('loadTemplates', state)
		expect(state.templates).toEqual([])
	})
})
