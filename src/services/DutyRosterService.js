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

	/**
	 * Saves the week as PDF in the Nextcloud archive AND returns it for download.
	 * With responseType 'blob' an error body arrives as a Blob, so it is parsed
	 * before rethrow() can read the message.
	 *
	 * @param {string} start any day of the week (Y-m-d)
	 * @return {Promise<{blob: Blob, filename: string, archive: string, path: string}>} pdf and archive outcome
	 */
	async downloadPdf(start) {
		try {
			const response = await api.post('/duty-roster/pdf', { start }, { responseType: 'blob' })
			const disposition = response.headers['content-disposition'] || ''
			const match = disposition.match(/filename="?([^";]+)"?/)
			return {
				blob: response.data,
				filename: match ? match[1] : 'Dienstplan.pdf',
				archive: response.headers['x-zeitwerk-archive'] || 'skipped',
				path: response.headers['x-zeitwerk-archive-path'] || '',
			}
		} catch (error) {
			if (error.response && error.response.data instanceof Blob) {
				try {
					error.response.data = JSON.parse(await error.response.data.text())
				} catch (e) {
					error.response.data = {}
				}
			}
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
