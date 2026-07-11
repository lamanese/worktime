import api, { handleApiError } from './api.js'

export default {
    async getByMonth(employeeId, year, month) {
        try {
            const response = await api.get('/daily-km', { params: { employeeId, year, month } })
            return response.data
        } catch (error) {
            handleApiError(error)
        }
    },

    async upsert(employeeId, date, kilometers) {
        try {
            const response = await api.put('/daily-km', { employeeId, date, kilometers })
            return response.data
        } catch (error) {
            handleApiError(error)
        }
    },
}
