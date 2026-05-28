<template>
    <div class="day-list">
        <div class="dl-head">
            <div>{{ t('worktime', 'Tag') }}</div>
            <div>{{ t('worktime', 'Zeiten') }}</div>
            <div class="dl-r">{{ t('worktime', 'Pause') }}</div>
            <div class="dl-r">{{ t('worktime', 'Stunden') }}</div>
        </div>
        <div class="dl-body">
            <div v-for="day in days"
                :key="day.date"
                class="dl-day"
                :class="{ sel: day.date === selectedDate, weekend: day.isWeekend, empty: !day.entries.length }"
                tabindex="0"
                role="button"
                @click="$emit('select', day.date)"
                @keydown.enter="$emit('select', day.date)"
                @keydown.space.prevent="$emit('select', day.date)">
                <div class="dl-d">
                    {{ weekday(day) }} {{ pad(day.day) }}.
                    <small>{{ monthShort }}</small>
                </div>

                <div class="dl-mid">
                    <template v-if="day.entries.length">
                        <span v-if="day.holiday || day.absence"
                            class="dot"
                            :class="day.holiday ? 'holiday' : absenceColorClass(day.absence.type)" />
                        <span class="dl-times">{{ day.firstStart }} – {{ day.lastEnd }}</span>
                        <span v-if="day.entries.length > 1" class="dl-count">
                            {{ t('worktime', '{n} Einträge', { n: day.entries.length }) }}
                        </span>
                    </template>
                    <template v-else-if="day.holiday">
                        <span class="dl-tag"><span class="dot holiday" /> {{ day.holiday.name }}</span>
                    </template>
                    <template v-else-if="day.absence">
                        <span class="dl-tag">
                            <span class="dot" :class="absenceColorClass(day.absence.type)" />
                            {{ day.absence.typeName }}
                        </span>
                    </template>
                    <span v-else class="dl-mut">–</span>
                </div>

                <div class="dl-r dl-pause">{{ pauseLabel(day) }}</div>
                <div class="dl-r dl-hh" :class="{ 'dl-mut': !day.entries.length }">{{ hoursLabel(day) }}</div>
            </div>
        </div>
    </div>
</template>

<script>
import { getDayName, getMonthNameShort } from '../utils/dateUtils.js'
import { formatHoursDecimal } from '../utils/timeUtils.js'

export default {
    name: 'DayList',
    props: {
        days: {
            type: Array,
            default: () => [],
        },
        selectedDate: {
            type: String,
            default: null,
        },
        month: {
            type: Number,
            default: null,
        },
    },
    emits: ['select'],
    computed: {
        monthShort() {
            return this.month ? getMonthNameShort(this.month) : ''
        },
    },
    methods: {
        pad(n) {
            return n < 10 ? '0' + n : '' + n
        },
        weekday(day) {
            return getDayName(day.dayOfWeek)
        },
        absenceColorClass(type) {
            if (type === 'vacation') return 'vacation'
            if (type === 'sick' || type === 'child_sick') return 'sick'
            return 'other'
        },
        pauseLabel(day) {
            if (!day.entries.length) return ''
            const total = day.entries.reduce((sum, e) => sum + (e.breakMinutes || 0), 0)
            return this.t('worktime', '{min} Min', { min: total })
        },
        hoursLabel(day) {
            if (!day.entries.length) return '–'
            return `${formatHoursDecimal(day.totalMinutes)} h`
        },
    },
}
</script>

<style scoped>
.day-list {
    font-size: 14px;
}

.dl-head,
.dl-day {
    display: grid;
    grid-template-columns: 116px 1fr 92px 84px;
    align-items: center;
    gap: 10px;
}

.dl-head {
    padding: 0 14px 7px;
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
}

.dl-body {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    overflow: hidden;
}

.dl-day {
    padding: 11px 14px;
    border-top: 1px solid var(--color-border-light, var(--color-border));
    background: var(--color-main-background);
    cursor: pointer;
}

.dl-day:first-child {
    border-top: none;
}

.dl-day:hover {
    background: var(--color-background-hover);
}

.dl-day:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.dl-day.sel {
    background: var(--color-primary-element-light);
}

/* Leere Tage (inkl. Wochenende/Feiertag ohne Eintrag) dezent und kompakt */
.dl-day.empty {
    padding-top: 8px;
    padding-bottom: 8px;
    font-size: 13px;
}

.dl-day.empty .dl-d,
.dl-day.empty .dl-mid {
    color: var(--color-text-maxcontrast);
}

.dl-day.empty.weekend {
    background: var(--color-background-hover);
}

.dl-day.empty.weekend.sel,
.dl-day.empty.sel {
    background: var(--color-primary-element-light);
}

.dl-d {
    font-weight: 600;
}

.dl-d small {
    display: block;
    font-size: 11.5px;
    font-weight: 400;
    color: var(--color-text-maxcontrast);
}

.dl-mid {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dl-times {
    font-variant-numeric: tabular-nums;
}

.dl-count {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    background: var(--color-background-dark);
    border-radius: 9999px;
    padding: 2px 8px;
}

.dl-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.dl-mut {
    color: var(--color-text-maxcontrast);
}

.dl-r {
    text-align: right;
}

.dl-pause {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.dl-hh {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    display: inline-block;
}

.dot.vacation {
    background: #4a9d63;
}

.dot.sick {
    background: #cc4b42;
}

.dot.holiday {
    background: #c98b3a;
}

.dot.other {
    background: var(--color-primary-element);
}
</style>
