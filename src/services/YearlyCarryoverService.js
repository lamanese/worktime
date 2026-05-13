import api, { handleApiError } from './api.js'

export default {
	async getByYear(year) {
		try {
			const response = await api.get(`/carryover/${year}`)
			return response.data
		} catch (error) {
			handleApiError(error)
		}
	},

	async getByEmployeeAndYear(employeeId, year) {
		try {
			const response = await api.get(`/carryover/${year}/${employeeId}`)
			return response.data
		} catch (error) {
			handleApiError(error)
		}
	},

	async upsert(employeeId, year, overtimeMinutes, vacationDays, note) {
		try {
			const response = await api.put('/carryover', {
				employeeId,
				year,
				overtimeMinutes,
				vacationDays,
				note,
			})
			return response.data
		} catch (error) {
			handleApiError(error)
		}
	},

	async lock(id) {
		try {
			const response = await api.post(`/carryover/${id}/lock`)
			return response.data
		} catch (error) {
			handleApiError(error)
		}
	},

	async cancel(id, overtimeMinutes, vacationDays, reason) {
		try {
			const response = await api.post(`/carryover/${id}/cancel`, {
				overtimeMinutes,
				vacationDays,
				reason,
			})
			return response.data
		} catch (error) {
			handleApiError(error)
		}
	},
}
