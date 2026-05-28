<template>
    <div class="day-list">
        <div class="dl-head">
            <div>{{ t('worktime', 'Tag') }}</div>
            <div>{{ t('worktime', 'Zeiten') }}</div>
            <div class="dl-r">{{ t('worktime', 'Pause') }}</div>
            <div class="dl-r">{{ t('worktime', 'Stunden') }}</div>
        </div>
        <div class="dl-body">
            <template v-for="row in rows">
                <!-- Weekend group -->
                <div v-if="row.type === 'weekend'" :key="row.key" class="dl-day weekend">
                    <div class="dl-d">{{ row.label }}</div>
                    <div class="dl-mut">{{ t('worktime', 'Wochenende') }}</div>
                    <div></div>
                    <div></div>
                </div>

                <!-- Day -->
                <div v-else
                    :key="row.key"
                    class="dl-day"
                    :class="[dayStateClass(row.day), { sel: row.day.date === selectedDate, weekend: row.day.isWeekend }]"
                    tabindex="0"
                    role="button"
                    @click="$emit('select', row.day.date)"
                    @keydown.enter="$emit('select', row.day.date)"
                    @keydown.space.prevent="$emit('select', row.day.date)">
                    <div class="dl-d">
                        {{ weekday(row.day) }} {{ pad(row.day.day) }}.
                        <small>{{ monthShort }}</small>
                    </div>

                    <div class="dl-mid">
                        <template v-if="row.day.holiday">
                            <span class="dl-tag"><span class="dot holiday" /> {{ row.day.holiday.name }}</span>
                        </template>
                        <template v-else-if="row.day.absence">
                            <span class="dl-tag">
                                <span class="dot" :class="absenceColorClass(row.day.absence.type)" />
                                {{ row.day.absence.typeName }}
                            </span>
                        </template>
                        <template v-else-if="row.day.entries.length">
                            <span class="dl-times">{{ row.day.firstStart }} – {{ row.day.lastEnd }}</span>
                            <span v-if="row.day.entries.length > 1" class="dl-count">
                                {{ t('worktime', '{n} Einträge', { n: row.day.entries.length }) }}
                            </span>
                        </template>
                        <span v-else class="dl-mut">{{ t('worktime', '— noch nichts erfasst —') }}</span>
                    </div>

                    <div class="dl-r dl-pause">{{ pauseLabel(row.day) }}</div>
                    <div class="dl-r dl-hh" :class="{ 'dl-mut': !row.day.entries.length }">{{ hoursLabel(row.day) }}</div>
                </div>
            </template>
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
        rows() {
            const rows = []
            let weekendGroup = []
            const flushWeekend = () => {
                if (!weekendGroup.length) return
                const first = weekendGroup[0]
                const last = weekendGroup[weekendGroup.length - 1]
                const label = weekendGroup.length === 1
                    ? `${this.weekday(first)} ${this.pad(first.day)}.`
                    : `${this.weekday(first)} ${this.pad(first.day)}. / ${this.weekday(last)} ${this.pad(last.day)}.`
                rows.push({ type: 'weekend', key: 'we-' + first.date, label })
                weekendGroup = []
            }
            for (const day of this.days) {
                const empty = !day.entries.length && !day.absence && !day.holiday
                if (day.isWeekend && empty) {
                    weekendGroup.push(day)
                    continue
                }
                flushWeekend()
                rows.push({ type: 'day', key: day.date, day })
            }
            flushWeekend()
            return rows
        },
    },
    methods: {
        pad(n) {
            return n < 10 ? '0' + n : '' + n
        },
        weekday(day) {
            return getDayName(day.dayOfWeek)
        },
        dayStateClass(day) {
            if (day.holiday) return 'ho'
            if (day.absence) return this.absenceColorClass(day.absence.type) + '-bg'
            return ''
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

.dl-day.weekend {
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
    cursor: default;
}

.dl-day.weekend:hover {
    background: var(--color-background-hover);
}

.dl-day.ho {
    background: #fbf2e3;
}

.dl-day.vacation-bg {
    background: #e8f6ec;
}

.dl-day.sick-bg {
    background: #fbebeb;
}

.dl-day.other-bg {
    background: var(--color-primary-element-light);
}

.dl-day.ho.sel,
.dl-day.vacation-bg.sel,
.dl-day.sick-bg.sel,
.dl-day.other-bg.sel {
    box-shadow: inset 3px 0 0 var(--color-primary-element);
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
}

.dl-times {
    font-variant-numeric: tabular-nums;
}

.dl-count {
    margin-left: 8px;
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
    font-style: italic;
    font-size: 13px;
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
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
    display: inline-block;
}

.dot.vacation {
    background: #46ba61;
}

.dot.sick {
    background: #e9322d;
}

.dot.holiday {
    background: #e8a33d;
}

.dot.other {
    background: var(--color-primary-element);
}
</style>
