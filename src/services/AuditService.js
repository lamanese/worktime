import api, { handleApiError } from './api.js'

export default {
	async getFiltered({ action = '', entityType = '', from = '', to = '', limit = 200, offset = 0, userId = '' } = {}) {
		try {
			const params = { limit, offset }
			if (action) params.action = action
			if (entityType) params.entityType = entityType
			if (from) params.from = from
			if (to) params.to = to
			if (userId) params.userId = userId
			const response = await api.get('/audit-logs', { params })
			return response.data
		} catch (error) {
			handleApiError(error)
		}
	},
}
