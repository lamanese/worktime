<template>
    <div class="month-cal card">
        <div class="cal-grid cal-dow">
            <div v-for="d in dayNames" :key="d" class="dow">{{ d }}</div>
        </div>
        <div class="cal-grid">
            <div v-for="(cell, idx) in cells"
                :key="idx"
                class="cell"
                :class="cellClasses(cell)"
                :tabindex="cell.clickable ? 0 : -1"
                :role="cell.clickable ? 'button' : null"
                @click="onCellClick(cell)"
                @keydown.enter="onCellClick(cell)"
                @keydown.space.prevent="onCellClick(cell)">
                <div class="n">{{ cell.num }}</div>
                <template v-if="cell.day">
                    <div v-if="cell.day.holiday" class="mi h">{{ shortName(cell.day.holiday.name) }}</div>
                    <div v-else-if="cell.day.absence" class="mi" :class="absMiClass(cell.day.absence.type)">
                        {{ cell.day.absence.typeName }}
                    </div>
                    <div v-else-if="cell.day.entries.length" class="hh">{{ hoursLabel(cell.day) }}</div>
                </template>
            </div>
        </div>
        <div class="leg">
            <span><span class="dot vacation" /> {{ t('worktime', 'Urlaub') }}</span>
            <span><span class="dot holiday" /> {{ t('worktime', 'Feiertag') }}</span>
            <span><span class="dot sick" /> {{ t('worktime', 'Krank') }}</span>
            <span><span class="dot other" /> {{ t('worktime', 'Erfasst') }}</span>
        </div>
    </div>
</template>

<script>
import { getDaysInMonth } from '../utils/dateUtils.js'
import { formatHoursDecimal } from '../utils/timeUtils.js'

export default {
    name: 'MonthCalendar',
    props: {
        days: {
            type: Array,
            default: () => [],
        },
        year: {
            type: Number,
            required: true,
        },
        month: {
            type: Number,
            required: true,
        },
        selectedDate: {
            type: String,
            default: null,
        },
    },
    emits: ['select'],
    computed: {
        dayNames() {
            return [
                this.t('worktime', 'Mo'), this.t('worktime', 'Di'), this.t('worktime', 'Mi'),
                this.t('worktime', 'Do'), this.t('worktime', 'Fr'), this.t('worktime', 'Sa'),
                this.t('worktime', 'So'),
            ]
        },
        cells() {
            const cells = []
            const firstDow = new Date(this.year, this.month - 1, 1).getDay() // 0=Sun
            const lead = (firstDow + 6) % 7 // Mon-start leading count
            const prevMonth = this.month === 1 ? 12 : this.month - 1
            const prevYear = this.month === 1 ? this.year - 1 : this.year
            const prevDays = getDaysInMonth(prevYear, prevMonth)

            // Leading days (previous month, out)
            for (let i = lead - 1; i >= 0; i--) {
                cells.push({ num: prevDays - i, out: true, clickable: false })
            }

            // In-month days
            for (const day of this.days) {
                const hasData = day.entries.length > 0 || !!day.absence || !!day.holiday
                const clickable = !day.isWeekend || hasData
                cells.push({ num: day.day, day, clickable })
            }

            // Trailing days (next month, out) to complete the last week
            while (cells.length % 7 !== 0) {
                cells.push({ num: cells.length % 7, out: true, clickable: false, trailing: true })
            }

            // Fix trailing numbering (1,2,3...)
            let t = 1
            for (const c of cells) {
                if (c.trailing) c.num = t++
            }

            return cells
        },
    },
    methods: {
        hoursLabel(day) {
            return formatHoursDecimal(day.totalMinutes)
        },
        shortName(name) {
            return name.length > 11 ? name.slice(0, 9) + '.' : name
        },
        absenceColorClass(type) {
            if (type === 'vacation') return 'vacation'
            if (type === 'sick' || type === 'child_sick') return 'sick'
            return 'other'
        },
        absMiClass(type) {
            return this.absenceColorClass(type)
        },
        cellClasses(cell) {
            if (cell.out) return 'out'
            const classes = []
            if (cell.day.isWeekend) classes.push('we')
            if (cell.day.holiday) classes.push('ho')
            else if (cell.day.absence) classes.push(this.absenceColorClass(cell.day.absence.type) + '-bg')
            if (cell.day.date === this.selectedDate) classes.push('sel')
            return classes
        },
        onCellClick(cell) {
            if (cell.clickable && cell.day) {
                this.$emit('select', cell.day.date)
            }
        },
    },
}
</script>

<style scoped>
.month-cal.card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    padding: 16px;
}

.cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
}

.cal-dow {
    margin-bottom: 6px;
}

.dow {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    text-align: center;
    padding: 2px 0;
}

.cell {
    aspect-ratio: 1 / 0.92;
    border: 1px solid var(--color-border-light, var(--color-border));
    border-radius: var(--border-radius);
    padding: 7px;
    font-size: 12px;
    display: flex;
    flex-direction: column;
    cursor: pointer;
    background: var(--color-main-background);
}

.cell:hover {
    border-color: var(--color-primary-element);
}

.cell:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.cell.out {
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
    cursor: default;
    opacity: 0.55;
}

.cell.out:hover {
    border-color: var(--color-border-light, var(--color-border));
}

.cell.we {
    background: var(--color-background-hover);
    cursor: default;
}

.cell.ho {
    background: #fbf2e3;
}

.cell.vacation-bg {
    background: #e8f6ec;
}

.cell.sick-bg {
    background: #fbebeb;
}

.cell.other-bg {
    background: var(--color-primary-element-light);
}

.cell.sel {
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
    background: var(--color-primary-element-light);
}

.cell .n {
    font-weight: 600;
}

.cell .hh {
    margin-top: auto;
    font-weight: 600;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
}

.cell .mi {
    margin-top: auto;
    font-size: 11px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cell .mi.vacation {
    color: #2a8a44;
}

.cell .mi.sick {
    color: #c0322d;
}

.cell .mi.h {
    color: #a9710c;
}

.cell .mi.other {
    color: var(--color-primary-element);
}

.leg {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 13px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.leg span {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
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
