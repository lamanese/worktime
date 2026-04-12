<template>
    <div class="absence-overview">
        <div class="view-header">
            <h2>{{ t('worktime', 'Abwesenheitsübersicht') }}</h2>
            <MonthPicker
                :year="year"
                :month="month"
                @update="onMonthChange" />
        </div>

        <NcLoadingIcon v-if="loading" :size="44" />

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
    created() {
        this.fetchData()
    },
    methods: {
        onMonthChange({ year, month }) {
            this.year = year
            this.month = month
            this.fetchData()
        },
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
    padding: 20px;
    padding-left: 50px;
    max-width: 1400px;
}

.view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.view-header h2 {
    margin: 0;
}
</style>
