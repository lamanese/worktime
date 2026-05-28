<template>
    <div class="time-tracking-view">
        <div class="view-header">
            <h2>{{ t('worktime', 'Zeiterfassung') }}</h2>
            <div class="header-actions">
                <NcButton type="primary" @click="startCreate">
                    <template #icon>
                        <PlusIcon :size="20" />
                    </template>
                    {{ t('worktime', 'Neuer Eintrag') }}
                </NcButton>
                <NcButton v-if="approvalRequired && hasSubmittableEntries"
                    type="secondary"
                    @click="confirmSubmitMonth">
                    <template #icon>
                        <SendIcon :size="20" />
                    </template>
                    {{ t('worktime', 'Monat einreichen') }}
                </NcButton>
                <span v-else-if="approvalRequired && allEntriesSubmitted" class="status-info">
                    <CheckIcon :size="20" />
                    {{ t('worktime', 'Eingereicht') }}
                </span>
                <NcButton type="secondary" @click="downloadPdf">
                    <template #icon>
                        <DownloadIcon :size="20" />
                    </template>
                    {{ t('worktime', 'PDF herunterladen') }}
                </NcButton>
                <MonthPicker :year="selectedMonth.year"
                    :month="selectedMonth.month"
                    @update="onMonthChange" />
            </div>
        </div>

        <OvertimeSummary v-if="statistics"
            :target-minutes="statistics.adjustedTargetMinutes"
            :actual-minutes="statistics.actualMinutes"
            :overtime-minutes="statistics.overtimeMinutes"
            :vacation-remaining="vacationRemaining"
            :statistics="statistics" />

        <NcLoadingIcon v-if="loading" :size="44" />

        <TimeEntryList v-else
            ref="entryList"
            :entries="timeEntries"
            :absences="reportAbsences"
            :holidays="reportHolidays"
            :filter-year="selectedMonth.year"
            :filter-month="selectedMonth.month"
            @refresh="loadData" />

        <div class="year-overview-block">
            <NcButton type="tertiary" @click="showYearOverview = !showYearOverview">
                <template #icon>
                    <ChevronUp v-if="showYearOverview" :size="20" />
                    <ChevronDown v-else :size="20" />
                </template>
                {{ t('worktime', 'Jahresübersicht') }}
            </NcButton>
            <div v-if="showYearOverview" class="year-overview-card">
                <YearOverviewTable :months="yearlyMonths"
                    :year="overviewYear"
                    :min-year="minYear"
                    :max-year="maxYear"
                    :carryover-minutes="carryoverMinutes"
                    @previous="changeOverviewYear(-1)"
                    @next="changeOverviewYear(1)"
                    @select-month="selectMonth" />
            </div>
        </div>
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import { mapGetters, mapActions, mapState } from 'vuex'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmAction } from '../utils/errorHandler.js'
import { getCurrentYear } from '../utils/dateUtils.js'
import MonthPicker from '../components/MonthPicker.vue'
import OvertimeSummary from '../components/OvertimeSummary.vue'
import TimeEntryList from '../components/TimeEntryList.vue'
import YearOverviewTable from '../components/YearOverviewTable.vue'
import ReportService from '../services/ReportService.js'
import AbsenceService from '../services/AbsenceService.js'
import TimeEntryService from '../services/TimeEntryService.js'

export default {
    name: 'TimeTrackingView',
    components: {
        NcButton,
        NcLoadingIcon,
        PlusIcon,
        SendIcon,
        CheckIcon,
        DownloadIcon,
        ChevronDown,
        ChevronUp,
        MonthPicker,
        OvertimeSummary,
        TimeEntryList,
        YearOverviewTable,
    },
    data() {
        return {
            statistics: null,
            reportAbsences: [],
            reportHolidays: [],
            vacationRemaining: null,
            yearlyMonths: [],
            carryoverMinutes: 0,
            overviewYear: getCurrentYear(),
            showYearOverview: false,
        }
    },
    computed: {
        ...mapState('timeEntries', ['selectedMonth']),
        ...mapGetters('timeEntries', ['timeEntries', 'loading']),
        ...mapGetters('permissions', ['employeeId', 'approvalRequired']),
        ...mapGetters('employees', ['currentEmployee']),
        minYear() {
            if (this.currentEmployee?.entryDate) {
                return new Date(this.currentEmployee.entryDate).getFullYear()
            }
            return getCurrentYear()
        },
        maxYear() {
            return getCurrentYear() + 1
        },
        hasSubmittableEntries() {
            return this.timeEntries.some(e => e.status === 'draft' || e.status === 'rejected')
        },
        allEntriesSubmitted() {
            return this.timeEntries.length > 0
                && this.timeEntries.every(e => e.status !== 'draft' && e.status !== 'rejected')
        },
    },
    watch: {
        selectedMonth: {
            immediate: true,
            handler() {
                this.loadData()
            },
        },
        employeeId(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.loadData()
            }
        },
    },
    mounted() {
        this.$store.dispatch('projects/fetchProjects')
    },
    methods: {
        ...mapActions('timeEntries', ['fetchTimeEntries', 'setSelectedMonth']),
        async loadData() {
            if (!this.employeeId) return
            this.overviewYear = this.selectedMonth.year
            await this.fetchTimeEntries()
            await this.loadStatistics()
            await this.loadVacationStats()
            await this.loadOvertime()
        },
        async loadOvertime() {
            if (!this.employeeId) return
            try {
                const overtime = await ReportService.getOvertime(this.employeeId, this.overviewYear)
                this.yearlyMonths = overtime?.monthly || []
                this.carryoverMinutes = overtime?.carryoverMinutes || 0
            } catch (error) {
                console.error('Failed to load yearly overview:', error)
            }
        },
        async loadStatistics() {
            if (!this.employeeId) return
            try {
                const report = await ReportService.getMonthly(
                    this.employeeId,
                    this.selectedMonth.year,
                    this.selectedMonth.month
                )
                this.statistics = report.statistics
                this.reportAbsences = (report.absences || []).filter(a => a.status !== 'cancelled')
                this.reportHolidays = report.holidays || []
            } catch (error) {
                console.error('Failed to load statistics:', error)
            }
        },
        async loadVacationStats() {
            if (!this.employeeId) return
            try {
                const stats = await AbsenceService.getVacationStats(this.employeeId, this.selectedMonth.year)
                this.vacationRemaining = stats?.remaining ?? null
            } catch (error) {
                console.error('Failed to load vacation stats:', error)
            }
        },
        onMonthChange({ year, month }) {
            this.setSelectedMonth({ year, month })
        },
        changeOverviewYear(delta) {
            this.overviewYear += delta
            this.loadOvertime()
        },
        selectMonth(month) {
            this.setSelectedMonth({ year: this.overviewYear, month })
        },
        downloadPdf() {
            if (!this.employeeId) return
            ReportService.downloadPdf(this.employeeId, this.selectedMonth.year, this.selectedMonth.month)
        },
        async confirmSubmitMonth() {
            const locale = document.documentElement.lang || navigator.language || 'de-DE'
            const monthName = new Date(this.selectedMonth.year, this.selectedMonth.month - 1)
                .toLocaleDateString(locale, { month: 'long', year: 'numeric' })

            const confirmed = await confirmAction(
                this.t('worktime', 'Möchten Sie die Zeiteinträge für {month} einreichen? Die eingereichten Einträge werden zur Genehmigung übermittelt.', { month: monthName }),
                this.t('worktime', 'Monat einreichen'),
                this.t('worktime', 'Einreichen'),
                false
            )
            if (!confirmed) {
                return
            }

            try {
                const result = await TimeEntryService.submitMonth(this.employeeId, this.selectedMonth.year, this.selectedMonth.month)
                showSuccess(this.t('worktime', '{count} Einträge wurden eingereicht.', { count: result.submitted }))
                await this.loadData()
            } catch (error) {
                console.error('Failed to submit month:', error)
                showError(this.t('worktime', 'Fehler beim Einreichen des Monats.'))
            }
        },
        startCreate() {
            this.$refs.entryList?.startCreate()
        },
    },
}
</script>

<style scoped>
.time-tracking-view {
    padding: 20px;
    padding-left: 50px;
    max-width: 1200px;
}

.view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.view-header h2 {
    margin: 0;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-actions :deep(.month-picker) {
    margin-left: auto;
}

.status-info {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-success-text);
    font-weight: 500;
}

.year-overview-block {
    margin-top: 24px;
}

.year-overview-card {
    margin-top: 12px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 20px;
}
</style>
