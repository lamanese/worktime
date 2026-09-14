/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import DutyRosterService from '../../services/DutyRosterService.js'
import { getWeekStart, addDays, toDateString } from '../../utils/dutyRoster.js'

// `state` is a function so tests can build fresh copies.
const state = () => ({
	weekStart: getWeekStart(toDateString(new Date())),
	week: null,
	loading: false,
	error: null,
})

const getters = {
	weekStart: (state) => state.weekStart,
	week: (state) => state.week,
	rows: (state) => state.week?.rows ?? [],
	days: (state) => state.week?.days ?? [],
	canManage: (state) => !!state.week?.canManage,
	loading: (state) => state.loading,
	error: (state) => state.error,
}

const mutations = {
	SET_WEEK_START(state, weekStart) {
		state.weekStart = weekStart
	},
	SET_WEEK(state, week) {
		state.week = week
	},
	SET_LOADING(state, loading) {
		state.loading = loading
	},
	SET_ERROR(state, error) {
		state.error = error
	},
}

const actions = {
	async loadWeek({ state, commit }, start) {
		const weekStart = getWeekStart(start || state.weekStart)
		commit('SET_WEEK_START', weekStart)
		commit('SET_LOADING', true)
		commit('SET_ERROR', null)
		try {
			commit('SET_WEEK', await DutyRosterService.getWeek(weekStart))
		} catch (error) {
			commit('SET_ERROR', error.message)
		} finally {
			commit('SET_LOADING', false)
		}
	},
	prevWeek({ state, dispatch }) {
		return dispatch('loadWeek', addDays(state.weekStart, -7))
	},
	nextWeek({ state, dispatch }) {
		return dispatch('loadWeek', addDays(state.weekStart, 7))
	},
	goToDate({ dispatch }, date) {
		return dispatch('loadWeek', date)
	},
	async createJob({ state, dispatch }, data) {
		const job = await DutyRosterService.createJob(data)
		await dispatch('loadWeek', state.weekStart)
		return job
	},
	async updateJob({ state, dispatch }, { id, data }) {
		const job = await DutyRosterService.updateJob(id, data)
		await dispatch('loadWeek', state.weekStart)
		return job
	},
	async moveJob({ state, dispatch }, { id, employeeId, date }) {
		await DutyRosterService.moveJob(id, employeeId, date)
		await dispatch('loadWeek', state.weekStart)
	},
	async deleteJob({ state, dispatch }, id) {
		await DutyRosterService.deleteJob(id)
		await dispatch('loadWeek', state.weekStart)
	},
	async copyToNextWeek({ state, dispatch }) {
		const target = addDays(state.weekStart, 7)
		const { created } = await DutyRosterService.copyWeek(state.weekStart, target)
		await dispatch('loadWeek', target)
		return created
	},
}

export default {
	namespaced: true,
	state,
	getters,
	mutations,
	actions,
}
