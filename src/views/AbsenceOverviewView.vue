<template>
	<div class="absence-overview">
		<div class="absence-overview__header">
			<h2>{{ t('worktime', 'Abwesenheitsübersicht') }}</h2>
			<MonthPicker
				:year="year"
				:month="month"
				@update:year="year = $event"
				@update:month="month = $event" />
		</div>

		<div v-if="loading" class="absence-overview__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<AbsenceTimeline
			v-else
			:employees="overview"
			:year="year"
			:month="month"
			:holidays="holidays" />
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import MonthPicker from '../components/MonthPicker.vue'
import AbsenceTimeline from '../components/AbsenceTimeline.vue'
import AbsenceService from '../services/AbsenceService.js'
import HolidayService from '../services/HolidayService.js'

export default {
	name: 'AbsenceOverviewView',
	components: {
		NcLoadingIcon,
		MonthPicker,
		AbsenceTimeline,
	},
	data() {
		const now = new Date()
		return {
			year: now.getFullYear(),
			month: now.getMonth() + 1,
			overview: [],
			holidays: [],
			loading: false,
		}
	},
	watch: {
		year() {
			this.fetchData()
		},
		month() {
			this.fetchData()
		},
	},
	created() {
		this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const [overviewRes, holidaysRes] = await Promise.all([
					AbsenceService.getOverview(this.year, this.month),
					HolidayService.getByYear(this.year),
				])
				this.overview = Array.isArray(overviewRes) ? overviewRes : (overviewRes?.data || [])
				const holidayData = Array.isArray(holidaysRes) ? holidaysRes : (holidaysRes?.data || [])
				this.holidays = holidayData.map(h => ({
					date: h.date,
					name: h.name,
				}))
			} catch (error) {
				console.error('Failed to load absence overview', error)
				this.overview = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.absence-overview {
	padding: 20px 30px;
}

.absence-overview__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
}

.absence-overview__header h2 {
	font-size: 20px;
	font-weight: 600;
	margin: 0;
}

.absence-overview__loading {
	display: flex;
	justify-content: center;
	padding: 60px;
}
</style>
