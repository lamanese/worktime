/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import DutyRosterService from '../../services/DutyRosterService.js'
import DutyJobTemplateService from '../../services/DutyJobTemplateService.js'
import { getWeekStart, addDays, toDateString } from '../../utils/dutyRoster.js'

// `state` is a function so tests can build fresh copies.
const state = () => ({
	weekStart: getWeekStart(toDateString(new Date())),
	week: null,
	loading: false,
	error: null,
	templates: [],
})

const getters = {
	weekStart: (state) => state.weekStart,
	week: (state) => state.week,
	rows: (state) => state.week?.rows ?? [],
	days: (state) => state.week?.days ?? [],
	canManage: (state) => !!state.week?.canManage,
	// Template sidebar: planner AND company setting «Vorlagen-Leiste anzeigen».
	showTemplates: (state) => !!state.week?.showTemplates,
	loading: (state) => state.loading,
	error: (state) => state.error,
	templates: (state) => state.templates,
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
	SET_TEMPLATES(state, templates) {
		state.templates = templates
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
	/**
	 * Visible templates for the sidebar. Only planners may call the endpoint;
	 * a failure must not break the week view, so it falls back to an empty list.
	 */
	async loadTemplates({ commit }) {
		try {
			commit('SET_TEMPLATES', await DutyJobTemplateService.getVisible() || [])
		} catch (error) {
			commit('SET_TEMPLATES', [])
		}
	},
}

export default {
	namespaced: true,
	state,
	getters,
	mutations,
	actions,
}
