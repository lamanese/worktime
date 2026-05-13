import api, { handleApiError } from './api.js'

export default {
	async getFiltered({ action = '', entityType = '', from = '', to = '', limit = 200, offset = 0 } = {}) {
		try {
			const params = { limit, offset }
			if (action) params.action = action
			if (entityType) params.entityType = entityType
			if (from) params.from = from
			if (to) params.to = to
			const response = await api.get('/audit-logs', { params })
			return response.data
		} catch (error) {
			handleApiError(error)
		}
	},
}
