<template>
    <div class="team-year-table">
        <div v-for="member in report"
            :key="member.employee.id"
            class="member-card">
            <!-- Header: Name + weekly hours -->
            <div class="member-card__header">
                <NcAvatar :user="member.employee.userId"
                    :display-name="member.employee.fullName"
                    :size="44" />
                <span class="member-card__name">{{ member.employee.fullName }}</span>
                <span class="member-card__hours">{{ member.employee.weeklyHours }} {{ t('worktime', 'Std./Woche') }}</span>
            </div>

            <!-- Data table -->
            <table>
                <thead>
                    <tr class="month-header">
                        <th class="col-label">{{ t('worktime', 'Art') }}</th>
                        <th v-for="m in 12"
                            :key="m"
                            class="col-month">
                            {{ getMonthNameShort(m) }}
                        </th>
                        <th class="col-total">{{ t('worktime', 'Gesamt') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Row 1: Vacation -->
                    <tr class="row-data">
                        <td class="col-label">{{ t('worktime', 'Urlaub') }}</td>
                        <td v-for="m in member.months"
                            :key="m.month"
                            class="col-month">
                            <span v-if="m.vacationDays > 0" class="vacation-days">{{ m.vacationDays }}</span>
                        </td>
                        <td class="col-total">
                            <strong>{{ member.vacationStats.used }}/{{ member.vacationStats.total }}</strong>
                        </td>
                    </tr>
                    <!-- Row 2: Overtime -->
                    <tr class="row-data">
                        <td class="col-label">{{ t('worktime', 'Stunden') }}</td>
                        <td v-for="m in member.months"
                            :key="m.month"
                            class="col-month"
                            :class="overtimeClass(m.overtimeMinutes)">
                            <span v-if="m.overtimeMinutes !== null">{{ formatOvertimeShort(m.overtimeMinutes) }}</span>
                        </td>
                        <td class="col-total" :class="overtimeClass(member.totalOvertimeMinutes)">
                            <strong>{{ formatOvertimeShort(member.totalOvertimeMinutes) }}</strong>
                        </td>
                    </tr>
                    <!-- Row 3: Status -->
                    <tr class="row-data">
                        <td class="col-label">{{ t('worktime', 'Status') }}</td>
                        <td v-for="m in member.months"
                            :key="m.month"
                            class="col-month">
                            <CheckCircleIcon v-if="m.status === 'approved'"
                                :size="20"
                                class="status-icon status-approved"
                                :title="t('worktime', 'Genehmigt')" />
                            <ClockOutlineIcon v-else-if="m.status === 'submitted'"
                                :size="20"
                                class="status-icon status-submitted"
                                :class="{ clickable: m.canApprove }"
                                :title="m.canApprove ? t('worktime', 'Klicken zum Genehmigen') : t('worktime', 'Eingereicht')"
                                @click="m.canApprove ? onApproveClick(member, m.month) : null" />
                            <CloseCircleIcon v-else-if="m.status === 'rejected'"
                                :size="20"
                                class="status-icon status-rejected"
                                :title="t('worktime', 'Abgelehnt')" />
                        </td>
                        <td class="col-total" />
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Approval Dialog -->
        <NcDialog v-if="approveDialog.show"
            :name="t('worktime', 'Monat genehmigen')"
            @closing="approveDialog.show = false">
            <p>
                {{ getMonthName(approveDialog.month) }} {{ t('worktime', 'für') }}
                <strong>{{ approveDialog.employeeName }}</strong> {{ t('worktime', 'genehmigen?') }}
            </p>
            <template #actions>
                <NcButton type="tertiary" @click="approveDialog.show = false">
                    {{ t('worktime', 'Abbrechen') }}
                </NcButton>
                <NcButton type="primary"
                    :disabled="approveDialog.loading"
                    @click="confirmApprove">
                    <template v-if="approveDialog.loading" #icon>
                        <NcLoadingIcon :size="20" />
                    </template>
                    {{ t('worktime', 'Genehmigen') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import { getMonthNameShort, getMonthName } from '../utils/dateUtils.js'
import { formatMinutes } from '../utils/timeUtils.js'
import TimeEntryService from '../services/TimeEntryService.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
    name: 'TeamYearTable',
    components: {
        NcAvatar,
        NcButton,
        NcDialog,
        NcLoadingIcon,
        CheckCircleIcon,
        ClockOutlineIcon,
        CloseCircleIcon,
    },
    props: {
        report: {
            type: Array,
            required: true,
        },
        year: {
            type: Number,
            required: true,
        },
    },
    data() {
        return {
            approveDialog: {
                show: false,
                employeeId: null,
                employeeName: '',
                month: null,
                loading: false,
            },
        }
    },
    methods: {
        getMonthNameShort,
        getMonthName,
        formatOvertimeShort(minutes) {
            if (minutes === null || minutes === undefined) return '--'
            const sign = minutes >= 0 ? '+' : ''
            return sign + formatMinutes(minutes)
        },
        overtimeClass(minutes) {
            if (minutes === null || minutes === undefined) return ''
            if (minutes > 0) return 'positive'
            if (minutes < 0) return 'negative'
            return ''
        },
        onApproveClick(member, month) {
            this.approveDialog = {
                show: true,
                employeeId: member.employee.id,
                employeeName: member.employee.fullName,
                month,
                loading: false,
            }
        },
        async confirmApprove() {
            this.approveDialog.loading = true
            try {
                const result = await TimeEntryService.approveMonth(
                    this.approveDialog.employeeId,
                    this.year,
                    this.approveDialog.month,
                )
                showSuccess(t('worktime', '{count} Einträge genehmigt', { count: result.approved }))
                this.approveDialog.show = false
                this.$emit('approved')
            } catch (error) {
                console.error('Failed to approve month:', error)
                showError(t('worktime', 'Fehler beim Genehmigen'))
            } finally {
                this.approveDialog.loading = false
            }
        },
    },
}
</script>

<style scoped>
.team-year-table {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Member card – same style as stat-card in MonthlyReportView */
.member-card {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    overflow-x: auto;
}

.member-card__header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px;
}

.member-card__name {
    font-weight: 600;
    font-size: 1.15em;
}

.member-card__hours {
    color: var(--color-text-maxcontrast);
    font-size: 0.95em;
}

table {
    width: calc(100% - 32px);
    margin: 0 16px 16px;
    border-collapse: collapse;
}

/* Month column headers – same style as other app tables */
.month-header th {
    padding: 8px 4px;
    text-align: center;
    font-size: 0.85em;
    font-weight: 600;
    color: var(--color-main-text);
    background: var(--color-background-dark);
    border-bottom: 1px solid var(--color-border);
    white-space: nowrap;
}

/* Top-left corner cell: same grey as header */
.month-header .col-label {
    background: var(--color-background-dark);
}

/* Label column */
.col-label {
    text-align: left;
    padding-left: 8px !important;
    font-size: 0.85em;
    font-weight: 600;
    color: var(--color-main-text);
    white-space: nowrap;
    min-width: 60px;
}

/* Month data columns */
.col-month {
    text-align: center;
    min-width: 58px;
    padding: 8px 4px;
    font-size: 0.9em;
}

/* Total column */
.col-total {
    text-align: center;
    min-width: 75px;
    padding: 8px 4px;
    font-size: 0.9em;
    border-left: 1px solid var(--color-border);
}

/* Data rows */
.row-data td {
    padding-top: 8px;
    padding-bottom: 8px;
}

.row-data:not(:last-child) td {
    border-bottom: 1px solid var(--color-border-dark, rgba(0, 0, 0, 0.05));
}

/* Vacation */
.vacation-days {
    font-weight: 600;
}

/* Overtime colors */
.positive {
    color: var(--color-success-text);
}

.negative {
    color: var(--color-error-text);
}

/* Status icons */
.status-icon {
    display: inline-flex;
}

.status-approved {
    color: #2d8c3c;
}

.status-submitted {
    color: #c98a07;
}

.status-submitted.clickable {
    cursor: pointer;
}

.status-submitted.clickable:hover {
    color: #a06d00;
}

.status-rejected {
    color: #c9302c;
}

.muted {
    color: var(--color-text-maxcontrast);
}
</style>
