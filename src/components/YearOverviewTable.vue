<template>
    <div class="year-overview-card">
        <p v-if="isEmpty" class="empty-year">
            {{ t('zeitwerk', 'Keine Daten für dieses Jahr vorhanden.') }}
        </p>
        <table v-else class="year-table">
            <thead>
                <tr>
                    <th>{{ t('zeitwerk', 'Monat') }}</th>
                    <th class="text-right">{{ t('zeitwerk', 'Soll') }}</th>
                    <th class="text-right">{{ t('zeitwerk', 'Ist') }}</th>
                    <th class="text-right">
                        {{ t('zeitwerk', 'Überstunden') }}
                        <InfoIcon>{{ t('zeitwerk', 'Differenz zwischen Soll und Ist. Im laufenden Monat kann sich der Wert noch ändern.') }}</InfoIcon>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in allMonths"
                    :key="m.month"
                    :class="{ now: isCurrentMonth(m.month), future: isFutureMonth(m.month), clickable: !isFutureMonth(m.month) }"
                    :tabindex="!isFutureMonth(m.month) ? 0 : -1"
                    :role="!isFutureMonth(m.month) ? 'button' : null"
                    @click="onMonthClick(m.month)"
                    @keydown.enter="onMonthClick(m.month)"
                    @keydown.space.prevent="onMonthClick(m.month)">
                    <td class="m-cell">
                        <span class="m-name">{{ getMonthName(m.month) }}</span>
                        <span v-if="isCurrentMonth(m.month)" class="now-pill">{{ t('zeitwerk', 'Jetzt') }}</span>
                    </td>
                    <td class="text-right num">
                        <template v-if="!isFutureMonth(m.month)">{{ formatMin(m.targetMinutes) }}</template>
                        <template v-else>–</template>
                    </td>
                    <td class="text-right num">
                        <template v-if="!isFutureMonth(m.month)">{{ formatMin(m.actualMinutes) }}</template>
                        <template v-else>–</template>
                    </td>
                    <td class="text-right num">
                        <template v-if="!isFutureMonth(m.month)">
                            <span :class="overtimeClass(m.overtimeMinutes)">
                                {{ formatOvertime(m.overtimeMinutes) }}
                            </span>
                        </template>
                        <template v-else>–</template>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr v-if="carryoverMinutes !== 0" class="carryover-row">
                    <td>{{ t('zeitwerk', 'Übertrag Vorjahr') }}</td>
                    <td></td>
                    <td></td>
                    <td class="text-right num">
                        <span :class="overtimeClass(carryoverMinutes)">
                            {{ formatOvertime(carryoverMinutes) }}
                        </span>
                    </td>
                </tr>
                <tr class="total-row">
                    <td>{{ t('zeitwerk', 'Gesamt bis heute') }}</td>
                    <td class="text-right num">{{ formatMin(totalTarget) }}</td>
                    <td class="text-right num">{{ formatMin(totalActual) }}</td>
                    <td class="text-right num">
                        <span :class="overtimeClass(totalOvertime + carryoverMinutes)">
                            {{ formatOvertime(totalOvertime + carryoverMinutes) }}
                        </span>
                    </td>
                </tr>
            </tfoot>
        </table>
        <div class="year-foot-note">
            {{ t('zeitwerk', 'Klick auf einen Monat öffnet ihn in der Liste-Ansicht.') }}
        </div>
    </div>
</template>

<script>
import { getMonthName, getCurrentYear, getCurrentMonth } from '../utils/dateUtils.js'
import { formatMinutesWithUnit } from '../utils/timeUtils.js'
import InfoIcon from '../components/InfoIcon.vue'

export default {
    name: 'YearOverviewTable',
    components: {
        InfoIcon,
    },
    props: {
        months: {
            type: Array,
            default: () => [],
        },
        year: {
            type: Number,
            required: true,
        },
        carryoverMinutes: {
            type: Number,
            default: 0,
        },
    },
    emits: ['select-month'],
    computed: {
        currentYear() {
            return getCurrentYear()
        },
        currentMonth() {
            return getCurrentMonth()
        },
        allMonths() {
            const result = []
            for (let i = 1; i <= 12; i++) {
                const existing = this.months.find(m => m.month === i)
                result.push(existing || { month: i, targetMinutes: 0, actualMinutes: 0, overtimeMinutes: 0 })
            }
            return result
        },
        isEmpty() {
            if (this.year >= this.currentYear) return false
            return this.months.every(m => (m.actualMinutes || 0) === 0)
        },
        pastMonths() {
            return this.allMonths.filter(m => !this.isFutureMonth(m.month))
        },
        totalTarget() {
            return this.pastMonths.reduce((sum, m) => sum + (m.targetMinutes || 0), 0)
        },
        totalActual() {
            return this.pastMonths.reduce((sum, m) => sum + (m.actualMinutes || 0), 0)
        },
        totalOvertime() {
            return this.pastMonths.reduce((sum, m) => sum + (m.overtimeMinutes || 0), 0)
        },
    },
    methods: {
        getMonthName(month) {
            return getMonthName(month)
        },
        isCurrentMonth(month) {
            return this.year === this.currentYear && month === this.currentMonth
        },
        isFutureMonth(month) {
            if (this.year < this.currentYear) return false
            if (this.year > this.currentYear) return true
            return month > this.currentMonth
        },
        onMonthClick(month) {
            if (this.isFutureMonth(month)) return
            this.$emit('select-month', month)
        },
        formatMin(minutes) {
            return formatMinutesWithUnit(minutes || 0)
        },
        formatOvertime(minutes) {
            if (!minutes) return formatMinutesWithUnit(0)
            const sign = minutes > 0 ? '+' : ''
            return sign + formatMinutesWithUnit(minutes)
        },
        overtimeClass(minutes) {
            if (minutes > 0) return 'positive'
            if (minutes < 0) return 'negative'
            return ''
        },
    },
}
</script>

<style scoped>
.year-overview-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border-dark, var(--color-border));
    border-radius: var(--border-radius-large, 12px);
    overflow: hidden;
}

.empty-year {
    color: var(--color-text-maxcontrast);
    text-align: center;
    padding: 36px 0;
}

.year-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.year-table thead th {
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    padding: 14px 18px 10px;
    border-bottom: 1px solid var(--color-border-dark, var(--color-border));
}

.year-table tbody td {
    padding: 14px 18px;
    border-top: 1px solid var(--color-border-light, var(--color-border));
    vertical-align: middle;
}

.year-table tbody tr:first-child td {
    border-top: none;
}

.year-table tbody tr.clickable {
    cursor: pointer;
}

.year-table tbody tr.clickable:hover {
    background: var(--color-background-hover);
}

.year-table tbody tr.clickable:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.year-table tbody tr.now {
    background: var(--color-primary-element-light);
}

.year-table tbody tr.now.clickable:hover {
    background: var(--color-primary-element-light-hover, var(--color-primary-element-light));
    filter: brightness(0.97);
}

.year-table tbody tr.future td {
    color: var(--color-text-maxcontrast);
}

.m-cell {
    display: flex;
    align-items: center;
    gap: 8px;
}

.m-name {
    font-weight: 600;
}

.now-pill {
    display: inline-block;
    padding: 1px 6px;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text, #ffffff);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.3px;
    border-radius: var(--border-radius-element, 6px);
    text-transform: uppercase;
}

.text-right {
    text-align: right;
}

.num {
    font-variant-numeric: tabular-nums;
}

.year-table tfoot td {
    padding: 14px 18px;
    font-weight: 700;
    background: var(--color-background-hover);
    border-top: 2px solid var(--color-border-dark, var(--color-border));
    font-variant-numeric: tabular-nums;
}

.year-table tfoot .carryover-row td {
    font-weight: 500;
    font-style: italic;
    color: var(--color-text-maxcontrast);
    background: var(--color-main-background);
    border-top: 1px solid var(--color-border-light, var(--color-border));
}

.positive {
    color: var(--color-success-text);
}

.negative {
    color: var(--color-error-text);
}

.year-foot-note {
    padding: 10px 18px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    border-top: 1px solid var(--color-border-light, var(--color-border));
    background: var(--color-background-hover);
}
</style>
