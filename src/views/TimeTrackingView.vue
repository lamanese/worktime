<template>
    <div class="time-tracking-view">
        <div class="view-header">
            <h2>{{ t('worktime', 'Zeiterfassung') }}</h2>
            <div class="header-actions">
                <div v-if="!isNarrow" class="layout-seg" role="group" :aria-label="t('worktime', 'Ansicht')">
                    <button class="seg-btn"
                        :class="{ active: layoutMode === 'list' }"
                        @click="setLayout('list')">
                        <FormatListBulletedIcon :size="18" />
                        {{ t('worktime', 'Liste') }}
                    </button>
                    <button class="seg-btn"
                        :class="{ active: layoutMode === 'calendar' }"
                        @click="setLayout('calendar')">
                        <CalendarIcon :size="18" />
                        {{ t('worktime', 'Kalender') }}
                    </button>
                </div>
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

        <div v-else class="zlayout" :class="{ narrow: isNarrow }">
            <div class="zlayout-main">
                <DayList v-if="effectiveLayout === 'list'"
                    :days="days"
                    :month="selectedMonth.month"
                    :selected-date="selectedDate"
                    @select="onSelectDay" />
                <MonthCalendar v-else
                    :days="days"
                    :year="selectedMonth.year"
                    :month="selectedMonth.month"
                    :selected-date="selectedDate"
                    @select="onSelectDay" />
            </div>

            <div v-if="!isNarrow && selectedDay" class="zlayout-panel card">
                <DayDetailPanel :day="selectedDay"
                    :projects="projects"
                    @refresh="loadData" />
            </div>
        </div>

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

        <NcModal v-if="isNarrow && showDayModal && selectedDay"
            size="small"
            @close="showDayModal = false">
            <div class="modal-panel">
                <DayDetailPanel :day="selectedDay"
                    :projects="projects"
                    @refresh="loadData" />
            </div>
        </NcModal>
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import SendIcon from 'vue-material-design-icons/Send.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import { mapGetters, mapActions, mapState } from 'vuex'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmAction } from '../utils/errorHandler.js'
import { getCurrentYear, getCurrentMonth, getMonthDays, getToday, formatDateISO } from '../utils/dateUtils.js'
import { getAbsenceTypeLabel } from '../utils/formatters.js'
import MonthPicker from '../components/MonthPicker.vue'
import OvertimeSummary from '../components/OvertimeSummary.vue'
import YearOverviewTable from '../components/YearOverviewTable.vue'
import DayList from '../components/DayList.vue'
import MonthCalendar from '../components/MonthCalendar.vue'
import DayDetailPanel from '../components/DayDetailPanel.vue'
import ReportService from '../services/ReportService.js'
import AbsenceService from '../services/AbsenceService.js'
import TimeEntryService from '../services/TimeEntryService.js'

export default {
    name: 'TimeTrackingView',
    components: {
        NcButton,
        NcLoadingIcon,
        NcModal,
        SendIcon,
        CheckIcon,
        DownloadIcon,
        ChevronDown,
        ChevronUp,
        FormatListBulletedIcon,
        CalendarIcon,
        MonthPicker,
        OvertimeSummary,
        YearOverviewTable,
        DayList,
        MonthCalendar,
        DayDetailPanel,
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
            layoutMode: localStorage.getItem('worktime_tracking_layout') || 'list',
            selectedDate: null,
            isNarrow: false,
            showDayModal: false,
        }
    },
    computed: {
        ...mapState('timeEntries', ['selectedMonth']),
        ...mapGetters('timeEntries', ['timeEntries', 'loading']),
        ...mapGetters('permissions', ['employeeId', 'approvalRequired']),
        ...mapGetters('employees', ['currentEmployee']),
        ...mapGetters('projects', ['activeProjects']),
        projects() {
            return this.activeProjects
        },
        effectiveLayout() {
            return this.isNarrow ? 'list' : this.layoutMode
        },
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
        absenceByDate() {
            const map = {}
            const { year, month } = this.selectedMonth
            for (const absence of this.reportAbsences) {
                const [sy, sm, sd] = absence.startDate.split('-').map(Number)
                const [ey, em, ed] = absence.endDate.split('-').map(Number)
                const start = new Date(sy, sm - 1, sd)
                const end = new Date(ey, em - 1, ed)
                const typeName = getAbsenceTypeLabel(absence.type)
                for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                    if (d.getFullYear() !== year || (d.getMonth() + 1) !== month) continue
                    map[formatDateISO(d)] = {
                        type: absence.type || '',
                        typeName,
                        status: absence.status,
                        scope: absence.scope || 1,
                    }
                }
            }
            return map
        },
        days() {
            const { year, month } = this.selectedMonth
            const skeleton = getMonthDays(year, month)
            const entriesByDate = {}
            for (const entry of this.timeEntries) {
                if (!entriesByDate[entry.date]) entriesByDate[entry.date] = []
                entriesByDate[entry.date].push(entry)
            }
            const holidayByDate = {}
            for (const holiday of this.reportHolidays) {
                holidayByDate[holiday.date] = { name: holiday.name }
            }
            const todayStr = getToday()
            return skeleton.map(s => {
                const entries = (entriesByDate[s.date] || [])
                    .slice()
                    .sort((a, b) => a.startTime.localeCompare(b.startTime))
                const totalMinutes = entries.reduce((sum, e) => sum + (e.workMinutes || 0), 0)
                return {
                    ...s,
                    entries,
                    totalMinutes,
                    firstStart: entries.length ? entries[0].startTime : null,
                    lastEnd: entries.length ? entries[entries.length - 1].endTime : null,
                    absence: this.absenceByDate[s.date] || null,
                    holiday: holidayByDate[s.date] || null,
                    isToday: s.date === todayStr,
                    isFuture: s.date > todayStr,
                }
            })
        },
        selectedDay() {
            if (!this.days.length) return null
            return this.days.find(d => d.date === this.selectedDate) || this.days[0]
        },
    },
    watch: {
        selectedMonth: {
            immediate: true,
            handler({ year, month }) {
                this.ensureSelectedDate(year, month)
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
        this.updateIsNarrow()
        window.addEventListener('resize', this.updateIsNarrow)
    },
    beforeDestroy() {
        window.removeEventListener('resize', this.updateIsNarrow)
    },
    methods: {
        ...mapActions('timeEntries', ['fetchTimeEntries', 'setSelectedMonth']),
        updateIsNarrow() {
            this.isNarrow = window.innerWidth <= 920
        },
        ensureSelectedDate(year, month) {
            if (year === getCurrentYear() && month === getCurrentMonth()) {
                this.selectedDate = getToday()
            } else {
                this.selectedDate = formatDateISO(new Date(year, month - 1, 1))
            }
        },
        setLayout(mode) {
            this.layoutMode = mode
            localStorage.setItem('worktime_tracking_layout', mode)
        },
        onSelectDay(date) {
            this.selectedDate = date
            if (this.isNarrow) {
                this.showDayModal = true
            }
        },
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
                this.reportAbsences = (report.absences || []).filter(a => a.status === 'approved')
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

.layout-seg {
    display: flex;
    background: var(--color-background-dark);
    border-radius: 9999px;
    padding: 3px;
}

.seg-btn {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    background: none;
    border: none;
    padding: 6px 14px;
    border-radius: 9999px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.seg-btn.active {
    background: var(--color-main-background);
    color: var(--color-primary-element);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
}

.status-info {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-success-text);
    font-weight: 500;
}

.zlayout {
    display: grid;
    grid-template-columns: 1fr 330px;
    gap: 18px;
    align-items: start;
}

.zlayout.narrow {
    grid-template-columns: 1fr;
}

.zlayout-main {
    min-width: 0;
}

.zlayout-panel.card {
    position: sticky;
    top: 8px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 12px);
    padding: 18px;
}

.modal-panel {
    padding: 22px;
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
