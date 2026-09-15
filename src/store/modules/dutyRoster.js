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
	// Week lock («Schluessel»): a locked week is read-only for everyone.
	locked: (state) => !!state.week?.locked,
	lockedBy: (state) => state.week?.lockedBy ?? null,
	lockedAt: (state) => state.week?.lockedAt ?? null,
	canUnlock: (state) => !!state.week?.canUnlock,
	// Planner AND week open: only then cards may be created, moved, edited, deleted.
	canEdit: (state) => !!state.week?.canManage && !state.week?.locked,
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
	async lockWeek({ state, dispatch }) {
		await DutyRosterService.lockWeek(state.weekStart)
		await dispatch('loadWeek', state.weekStart)
	},
	async unlockWeek({ state, dispatch }) {
		await DutyRosterService.unlockWeek(state.weekStart)
		await dispatch('loadWeek', state.weekStart)
	},
	/** Copies the current week into the week of `target` (any day) and jumps there. */
	async copyToWeek({ state, dispatch }, target) {
		const monday = getWeekStart(target)
		const { created } = await DutyRosterService.copyWeek(state.weekStart, monday)
		await dispatch('loadWeek', monday)
		return created
	},
	/** Eye toggle: per-user override, then reload so showTemplates comes from the server. */
	async setTemplatesSidebar({ state, dispatch }, visible) {
		await DutyRosterService.setTemplatesSidebar(visible)
		await dispatch('loadWeek', state.weekStart)
		if (visible) await dispatch('loadTemplates')
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
