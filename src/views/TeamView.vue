<template>
    <div class="team-view">
        <div class="view-header">
            <h2>{{ t('worktime', 'Team') }}</h2>
            <YearPicker :year="year"
                @update="onYearChange" />
        </div>

        <NcLoadingIcon v-if="loading" :size="44" />

        <TeamYearTable v-else-if="teamReport.length > 0"
            :report="teamReport"
            :year="year"
            @approved="loadTeamReport" />

        <NcEmptyContent v-else
            :name="t('worktime', 'Kein Team')">
            <template #icon>
                <AccountGroupIcon />
            </template>
            <template #description>
                {{ t('worktime', 'Sie haben keine Teammitglieder.') }}
            </template>
        </NcEmptyContent>
    </div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import YearPicker from '../components/YearPicker.vue'
import TeamYearTable from '../components/TeamYearTable.vue'
import ReportService from '../services/ReportService.js'
import { getCurrentYear } from '../utils/dateUtils.js'

export default {
    name: 'TeamView',
    components: {
        NcLoadingIcon,
        NcEmptyContent,
        AccountGroupIcon,
        YearPicker,
        TeamYearTable,
    },
    data() {
        return {
            year: getCurrentYear(),
            teamReport: [],
            loading: false,
        }
    },
    created() {
        this.loadTeamReport()
    },
    methods: {
        async loadTeamReport() {
            this.loading = true
            try {
                this.teamReport = await ReportService.getTeamYear(this.year) || []
            } catch (error) {
                console.error('Failed to load team report:', error)
                this.teamReport = []
            } finally {
                this.loading = false
            }
        },
        onYearChange(year) {
            this.year = year
            this.loadTeamReport()
        },
    },
}
</script>

<style scoped>
.team-view {
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
