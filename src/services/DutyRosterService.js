/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import api from './api.js'

/**
 * Validation errors keep their field map (unlike handleApiError, which
 * flattens): the form shows them per field, the message goes to a toast.
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
	async getWeek(start) {
		try {
			const response = await api.get('/duty-roster/week', { params: { start } })
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async getTitles(q) {
		try {
			const response = await api.get('/duty-roster/titles', { params: { q } })
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async createJob(data) {
		try {
			const response = await api.post('/duty-roster/jobs', data)
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async updateJob(id, data) {
		try {
			const response = await api.put(`/duty-roster/jobs/${id}`, data)
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async moveJob(id, employeeId, date) {
		try {
			const response = await api.put(`/duty-roster/jobs/${id}/move`, { employeeId, date })
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async deleteJob(id) {
		try {
			await api.delete(`/duty-roster/jobs/${id}`)
		} catch (error) {
			rethrow(error)
		}
	},

	async lockWeek(start) {
		try {
			const response = await api.post('/duty-roster/lock', { start })
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async unlockWeek(start) {
		try {
			const response = await api.post('/duty-roster/unlock', { start })
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},

	async copyWeek(from, to) {
		try {
			const response = await api.post('/duty-roster/copy-week', { from, to })
			return response.data
		} catch (error) {
			rethrow(error)
		}
	},
}
