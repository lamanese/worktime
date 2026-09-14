/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import api from './api.js'

/**
 * Same contract as DutyRosterService: validation errors keep their per-field
 * map so the settings form can show them next to the inputs.
 *
 * @param {object} error axios error
 */
function rethrow(error) {
	if (error.response) {
		const data = error.response.data || {}
		const err = new Error(data.error || data.message || 'Ein Fehler ist aufgetreten')
		err.status = error.response.status
		err.errors = data.errors && typeof data.errors === 'object' ? data.errors : null
		throw err
	}
	throw error
}

export default {
	/** All templates, visible and hidden (settings, admin only). */
	async getAll() {
		try {
			const response = await api.get('/duty-roster/templates')
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	/** Only the visible ones, for the week-view sidebar (planners). */
	async getVisible() {
		try {
			const response = await api.get('/duty-roster/templates/visible')
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async create(data) {
		try {
			const response = await api.post('/duty-roster/templates', data)
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async update(id, data) {
		try {
			const response = await api.put(`/duty-roster/templates/${id}`, data)
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async delete(id) {
		try {
			await api.delete(`/duty-roster/templates/${id}`)
		} catch (error) {
			rethrow(error)
		}
	},
}
