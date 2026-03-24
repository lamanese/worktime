import WorkScheduleService from '../../services/WorkScheduleService.js'

const state = {
	schedules: [],
	loading: false,
	error: null,
}

const getters = {
	schedules: (state) => state.schedules,
	loading: (state) => state.loading,
	error: (state) => state.error,
}

const mutations = {
	SET_SCHEDULES(state, schedules) {
		state.schedules = schedules
	},
	SET_LOADING(state, loading) {
		state.loading = loading
	},
	SET_ERROR(state, error) {
		state.error = error
	},
	ADD_SCHEDULE(state, schedule) {
		state.schedules.push(schedule)
		// Sort by validFrom descending (newest first)
		state.schedules.sort((a, b) => b.validFrom.localeCompare(a.validFrom))
	},
	UPDATE_SCHEDULE(state, schedule) {
		const index = state.schedules.findIndex((s) => s.id === schedule.id)
		if (index !== -1) {
			state.schedules.splice(index, 1, schedule)
		}
	},
	REMOVE_SCHEDULE(state, id) {
		state.schedules = state.schedules.filter((s) => s.id !== id)
	},
}

const actions = {
	async fetchSchedules({ commit }, employeeId) {
		commit('SET_LOADING', true)
		commit('SET_ERROR', null)
		try {
			const schedules = await WorkScheduleService.getAll(employeeId)
			commit('SET_SCHEDULES', schedules)
		} catch (error) {
			commit('SET_ERROR', error.message)
		} finally {
			commit('SET_LOADING', false)
		}
	},

	async createSchedule({ commit }, { employeeId, data }) {
		const schedule = await WorkScheduleService.create(employeeId, data)
		commit('ADD_SCHEDULE', schedule)
		return schedule
	},

	async updateSchedule({ commit }, { employeeId, id, data }) {
		const schedule = await WorkScheduleService.update(employeeId, id, data)
		commit('UPDATE_SCHEDULE', schedule)
		return schedule
	},

	async deleteSchedule({ commit }, { employeeId, id }) {
		await WorkScheduleService.delete(employeeId, id)
		commit('REMOVE_SCHEDULE', id)
	},
}

export default {
	namespaced: true,
	state,
	getters,
	mutations,
	actions,
}
