<template>
    <div class="monthly-report-view">
        <div class="view-header">
            <h2>{{ t('worktime', 'Monatsübersicht') }}</h2>
            <div class="header-actions">
                <NcButton v-if="approvalRequired && hasSubmittableEntries"
                    type="primary"
                    @click="confirmSubmitMonth">
                    <template #icon>
                        <SendIcon :size="20" />
                    </template>
                    {{ t('worktime', 'Monat einreichen') }}
                </NcButton>
                <span v-else-if="approvalRequired && allEntriesSubmitted && report?.timeEntries?.length > 0" class="status-info">
                    <CheckIcon :size="20" />
                    {{ t('worktime', 'Eingereicht') }}
                </span>
                <NcButton type="secondary" @click="downloadPdf">
                    <template #icon>
                        <DownloadIcon :size="20" />
                    </template>
                    {{ t('worktime', 'PDF herunterladen') }}
                </NcButton>
                <MonthPicker :year="year"
                    :month="month"
                    @update="onMonthChange" />
            </div>
        </div>

        <NcLoadingIcon v-if="loading" :size="44" />

        <div v-else-if="report" class="report-content">
            <div class="report-section">
                <h3>{{ t('worktime', 'Zusammenfassung') }}</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="stat-label">{{ t('worktime', 'Arbeitstage') }}</span>
                        <span class="stat-value">{{ report.statistics.workingDays }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">{{ t('worktime', 'Feiertage') }}</span>
                        <span class="stat-value">{{ report.statistics.holidayCount }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">{{ t('worktime', 'Abwesenheitstage') }} <InfoIcon>{{ t('worktime', 'Tage mit genehmigten Abwesenheiten (Urlaub, Krankheit etc.). Bezahlte Abwesenheiten zählen als geleistete Arbeitszeit.') }}</InfoIcon></span>
                        <span class="stat-value">{{ report.statistics.absenceDays }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">{{ t('worktime', 'Einträge') }}</span>
                        <span class="stat-value">{{ report.statistics.entryCount }}</span>
                    </div>
                </div>
            </div>

            <OvertimeSummary :target-minutes="report.statistics.targetMinutes"
                :actual-minutes="report.statistics.actualMinutes"
                :overtime-minutes="report.statistics.overtimeMinutes"
                :statistics="report.statistics" />

            <div class="report-section">
                <h3>{{ t('worktime', 'Zeiteinträge') }}</h3>
                <TimeEntryList :entries="report.timeEntries" readonly />
            </div>

            <div v-if="report.absences.length > 0" class="report-section">
                <h3>{{ t('worktime', 'Abwesenheiten') }}</h3>
                <table class="absence-table">
                    <thead>
                        <tr>
                            <th>{{ t('worktime', 'Zeitraum') }}</th>
                            <th>{{ t('worktime', 'Art') }}</th>
                            <th>{{ t('worktime', 'Tage') }}</th>
                            <th>{{ t('worktime', 'Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="absence in report.absences" :key="absence.id">
                            <td>{{ formatDate(absence.startDate) }} - {{ formatDate(absence.endDate) }}</td>
                            <td>{{ getAbsenceTypeLabel(absence.type) }}</td>
                            <td>{{ absence.days }}</td>
                            <td>{{ getStatusLabel(absence.status) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="report.holidays.length > 0" class="report-section">
                <h3>{{ t('worktime', 'Feiertage') }}</h3>
                <ul class="holiday-list">
                    <li v-for="holiday in report.holidays" :key="holiday.id">
                        {{ formatDate(holiday.date) }} - {{ holiday.name }}
                    </li>
                </ul>
            </div>
        </div>

        <NcEmptyContent v-else
            :name="t('worktime', 'Keine Daten')">
            <template #description>
                {{ t('worktime', 'Für diesen Monat liegen keine Daten vor.') }}
            </template>
        </NcEmptyContent>

        <div v-if="!loading && employeeId" class="report-section year-overview-section">
            <YearOverviewTable :months="yearlyMonths"
                :year="year"
                :min-year="minYear"
                :max-year="maxYear"
                :carryover-minutes="carryoverMinutes"
                @previous="changeYear(-1)"
                @next="changeYear(1)" />
        </div>
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmAction } from '../utils/errorHandler.js'
import { mapGetters } from 'vuex'
import MonthPicker from '../components/MonthPicker.vue'
import OvertimeSummary from '../components/OvertimeSummary.vue'
import TimeEntryList from '../components/TimeEntryList.vue'
import YearOverviewTable from '../components/YearOverviewTable.vue'
import ReportService from '../services/ReportService.js'
import TimeEntryService from '../services/TimeEntryService.js'
import { getCurrentYear, getCurrentMonth } from '../utils/dateUtils.js'
import { formatDate, getStatusLabel, getAbsenceTypeLabel } from '../utils/formatters.js'
import InfoIcon from '../components/InfoIcon.vue'

export default {
    name: 'MonthlyReportView',
    components: {
        InfoIcon,
        NcButton,
        NcLoadingIcon,
        NcEmptyContent,
        DownloadIcon,
        SendIcon,
        CheckIcon,
        MonthPicker,
        OvertimeSummary,
        TimeEntryList,
        YearOverviewTable,
    },
    data() {
        return {
            year: getCurrentYear(),
            month: getCurrentMonth(),
            report: null,
            loading: false,
            yearlyMonths: [],
            carryoverMinutes: 0,
        }
    },
    computed: {
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
            if (!this.report?.timeEntries) return false
            return this.report.timeEntries.some(e => e.status === 'draft' || e.status === 'rejected')
        },
        allEntriesSubmitted() {
            if (!this.report?.timeEntries?.length) return false
            return this.report.timeEntries.every(e => e.status !== 'draft' && e.status !== 'rejected')
        },
    },
    watch: {
        employeeId: {
            immediate: true,
            handler() {
                if (this.employeeId) {
                    this.applyRouteQuery()
                    this.loadReport()
                    this.loadOvertime()
                }
            },
        },
        '$route.query'() {
            if (this.employeeId && this.applyRouteQuery()) {
                this.loadReport()
                this.loadOvertime()
            }
        },
    },
    methods: {
        getAbsenceTypeLabel,
        applyRouteQuery() {
            const q = this.$route.query
            let changed = false
            const qYear = parseInt(q.year, 10)
            const qMonth = parseInt(q.month, 10)
            if (qYear && qYear !== this.year) {
                this.year = qYear
                changed = true
            }
            if (qMonth && qMonth !== this.month) {
                this.month = qMonth
                changed = true
            }
            return changed
        },
        async loadReport() {
            if (!this.employeeId) return
            this.loading = true
            try {
                this.report = await ReportService.getMonthly(this.employeeId, this.year, this.month)
            } catch (error) {
                console.error('Failed to load report:', error)
                this.report = null
            } finally {
                this.loading = false
            }
        },
        async loadOvertime() {
            if (!this.employeeId) return
            try {
                const overtime = await ReportService.getOvertime(this.employeeId, this.year)
                this.yearlyMonths = overtime?.monthly || []
                this.carryoverMinutes = overtime?.carryoverMinutes || 0
            } catch (error) {
                console.error('Failed to load yearly overview:', error)
            }
        },
        async changeYear(delta) {
            this.year += delta
            await Promise.all([this.loadReport(), this.loadOvertime()])
        },
        onMonthChange({ year, month }) {
            const yearChanged = year !== this.year
            this.year = year
            this.month = month
            this.loadReport()
            if (yearChanged) {
                this.loadOvertime()
            }
        },
        formatDate(date) {
            return formatDate(date)
        },
        getStatusLabel(status) {
            return getStatusLabel(status)
        },
        downloadPdf() {
            if (!this.employeeId) return
            ReportService.downloadPdf(this.employeeId, this.year, this.month)
        },
        async confirmSubmitMonth() {
            const locale = document.documentElement.lang || navigator.language || 'de-DE'
            const monthName = new Date(this.year, this.month - 1).toLocaleDateString(locale, { month: 'long', year: 'numeric' })

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
                const result = await TimeEntryService.submitMonth(this.employeeId, this.year, this.month)
                showSuccess(this.t('worktime', '{count} Einträge wurden eingereicht.', { count: result.submitted }))
                await this.loadReport()
            } catch (error) {
                console.error('Failed to submit month:', error)
                showError(this.t('worktime', 'Fehler beim Einreichen des Monats.'))
            }
        },
    },
}
</script>

<style scoped>
.monthly-report-view {
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

.report-content {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.report-section h3 {
    margin: 24px 0 8px 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.report-section:first-child h3 {
    margin-top: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 16px;
}

.stat-card {
    padding: 20px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.stat-label {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.stat-value {
    font-size: 15px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.absence-table {
    width: 100%;
    border-collapse: collapse;
}

.absence-table th,
.absence-table td {
    padding: 10px 12px;
    text-align: left;
    font-variant-numeric: tabular-nums;
}

.absence-table th {
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    border-bottom: 2px solid var(--color-border);
}

.absence-table td {
    border-bottom: 1px solid var(--color-border);
}

.holiday-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.holiday-list li {
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border);
    font-size: 15px;
}

.status-info {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-success-text);
    font-weight: 500;
}

.year-overview-section {
    margin-top: 24px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 20px;
}
</style>
