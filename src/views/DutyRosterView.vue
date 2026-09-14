<template>
	<div class="duty-roster-view">
		<div class="view-header">
			<h2>{{ t('zeitwerk', 'Dienstplan') }}</h2>
			<div class="view-header__nav">
				<NcButton type="tertiary" :aria-label="t('zeitwerk', 'Vorherige Woche')" @click="prevWeek">
					<template #icon><ChevronLeftIcon :size="20" /></template>
				</NcButton>
				<span class="week-label">{{ weekLabel }}</span>
				<NcButton type="tertiary" :aria-label="t('zeitwerk', 'Nächste Woche')" @click="nextWeek">
					<template #icon><ChevronRightIcon :size="20" /></template>
				</NcButton>
				<input class="week-date" type="date" :value="weekStart" :aria-label="t('zeitwerk', 'Woche wählen')" @change="onDatePick">
				<NcButton type="secondary" @click="goToDate(today)">
					{{ t('zeitwerk', 'Heute') }}
				</NcButton>
			</div>
		</div>

		<div v-if="canManage" class="view-toolbar">
			<NcButton type="secondary" @click="confirmCopyWeek">
				<template #icon><ContentCopyIcon :size="18" /></template>
				{{ t('zeitwerk', 'Woche in nächste Woche kopieren') }}
			</NcButton>
			<NcButton type="tertiary" @click="print">
				<template #icon><PrinterIcon :size="18" /></template>
				{{ t('zeitwerk', 'Drucken') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading && !week" :size="44" />

		<NcEmptyContent v-else-if="error" :name="t('zeitwerk', 'Dienstplan konnte nicht geladen werden')">
			<template #icon><AlertIcon /></template>
			<template #description>{{ error }}</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="rows.length === 0" :name="t('zeitwerk', 'Keine Mitarbeitenden im Dienstplan')">
			<template #icon><CalendarWeekIcon /></template>
			<template #description>
				{{ t('zeitwerk', 'Setzen Sie im Mitarbeiterprofil das Häkchen «Im Dienstplan», damit die Person hier als Zeile erscheint.') }}
			</template>
		</NcEmptyContent>

		<div v-else class="roster-card">
			<div class="roster-grid" :style="{ '--day-count': 7 }">
				<div class="roster-corner" />
				<div v-for="day in days"
					:key="day.date"
					class="roster-day-header"
					:class="{ weekend: day.isWeekend, today: day.isToday }">
					<span class="roster-day-name">{{ dayName(day.date) }}</span>
					<span class="roster-day-date">{{ dayDate(day.date) }}</span>
				</div>

				<template v-for="row in rows">
					<div :key="'name-' + row.employee.id" class="roster-name" :style="rowStyle(row)">
						<NcAvatar :user="row.employee.userId" :display-name="row.employee.fullName" :size="28" :show-user-status="false" />
						<span>{{ row.employee.fullName }}</span>
					</div>
					<div v-for="day in days"
						:key="row.employee.id + '-' + day.date"
						class="roster-cell"
						:class="[cellClass(row, day), { weekend: day.isWeekend, today: day.isToday, over: isOver(row, day) }]"
						:style="rowStyle(row)"
						@dragover="onDragOver($event, row, day)"
						@dragleave="onDragLeave(row, day)"
						@drop="onDrop($event, row, day)">
						<div v-if="cellInfo(row, day).label" class="roster-cell__label">{{ cellInfo(row, day).label }}</div>
						<DutyJobCard v-for="job in jobsFor(row, day)"
							:key="job.id"
							:job="job"
							:draggable="canManage"
							:dimmed="cellInfo(row, day).state === 'absent'"
							@edit="openEdit(row, day, $event)" />
						<button v-if="canManage" class="roster-add" type="button" @click="openCreate(row, day)">
							+
						</button>
					</div>
				</template>
			</div>
		</div>

		<DutyJobForm v-if="form.open"
			:job="form.job"
			:employee-id="form.employeeId"
			:date="form.date"
			:employees="employeeOptions"
			@saved="closeForm(true)"
			@deleted="closeForm(false)"
			@cancel="closeForm(false)" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import { usernameToColor } from '@nextcloud/vue/dist/Functions/usernameToColor.js'
import { DialogBuilder } from '@nextcloud/dialogs'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import PrinterIcon from 'vue-material-design-icons/Printer.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import CalendarWeekIcon from 'vue-material-design-icons/CalendarWeek.vue'
import { mapGetters, mapActions } from 'vuex'
import DutyJobCard from '../components/DutyJobCard.vue'
import DutyJobForm from '../components/DutyJobForm.vue'
import { cellState, sortJobs, formatWeekLabel, parseLocalDate, toDateString } from '../utils/dutyRoster.js'
import { showErrorMessage, showSuccessMessage } from '../utils/errorHandler.js'

export default {
	name: 'DutyRosterView',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcAvatar,
		ChevronLeftIcon,
		ChevronRightIcon,
		ContentCopyIcon,
		PrinterIcon,
		AlertIcon,
		CalendarWeekIcon,
		DutyJobCard,
		DutyJobForm,
	},
	data() {
		return {
			form: { open: false, job: null, employeeId: 0, date: '' },
			overKey: null,
		}
	},
	computed: {
		...mapGetters('dutyRoster', ['weekStart', 'week', 'rows', 'days', 'canManage', 'loading', 'error']),
		today() {
			return toDateString(new Date())
		},
		weekLabel() {
			return formatWeekLabel(this.weekStart)
		},
		employeeOptions() {
			return this.rows.map(r => ({ id: r.employee.id, fullName: r.employee.fullName }))
		},
	},
	created() {
		this.loadWeek()
	},
	methods: {
		...mapActions('dutyRoster', ['loadWeek', 'prevWeek', 'nextWeek', 'goToDate', 'moveJob', 'copyToNextWeek']),
		dayName(date) {
			return parseLocalDate(date).toLocaleDateString('de-DE', { weekday: 'short' })
		},
		dayDate(date) {
			return parseLocalDate(date).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })
		},
		onDatePick(event) {
			if (event.target.value) this.goToDate(event.target.value)
		},
		rowStyle(row) {
			const c = usernameToColor(row.employee.userId)
			return { '--duty-row-color': `rgb(${c.r}, ${c.g}, ${c.b})` }
		},
		jobsFor(row, day) {
			return sortJobs(row.jobs.filter(j => j.date === day.date))
		},
		cellInfo(row, day) {
			return cellState(
				row.absences.filter(a => a.date === day.date),
				row.holidays.filter(h => h.date === day.date),
			)
		},
		cellClass(row, day) {
			return 'roster-cell--' + this.cellInfo(row, day).state
		},
		// --- drag and drop ---
		cellKey(row, day) {
			return row.employee.id + '|' + day.date
		},
		isOver(row, day) {
			return this.overKey === this.cellKey(row, day)
		},
		onDragOver(event, row, day) {
			if (!this.canManage) return
			event.preventDefault()
			event.dataTransfer.dropEffect = 'move'
			this.overKey = this.cellKey(row, day)
		},
		onDragLeave(row, day) {
			if (this.overKey === this.cellKey(row, day)) this.overKey = null
		},
		async onDrop(event, row, day) {
			this.overKey = null
			if (!this.canManage) return
			event.preventDefault()
			const id = Number(event.dataTransfer.getData('text/plain'))
			if (!id) return
			const source = this.rows.flatMap(r => r.jobs).find(j => j.id === id)
			if (!source || (source.employeeId === row.employee.id && source.date === day.date)) return
			try {
				await this.moveJob({ id, employeeId: row.employee.id, date: day.date })
				this.notifyAbsence(row, day)
			} catch (error) {
				showErrorMessage(error.message)
			}
		},
		notifyAbsence(row, day) {
			const info = this.cellInfo(row, day)
			if (info.state === 'absent') {
				showSuccessMessage(this.t('zeitwerk', 'Gespeichert. Hinweis: {name} ist an diesem Tag abwesend ({reason}).', { name: row.employee.fullName, reason: info.label }))
			}
		},
		// --- form ---
		openCreate(row, day) {
			this.form = { open: true, job: null, employeeId: row.employee.id, date: day.date }
		},
		openEdit(row, day, job) {
			if (!this.canManage) return
			this.form = { open: true, job, employeeId: row.employee.id, date: day.date }
		},
		closeForm(saved) {
			const { employeeId, date } = this.form
			this.form = { open: false, job: null, employeeId: 0, date: '' }
			if (saved) {
				const row = this.rows.find(r => r.employee.id === employeeId)
				const day = this.days.find(d => d.date === date)
				if (row && day) this.notifyAbsence(row, day)
			}
		},
		confirmCopyWeek() {
			const dialog = new DialogBuilder()
				.setName(this.t('zeitwerk', 'Woche kopieren'))
				.setText(this.t('zeitwerk', 'Alle Aufträge dieser Woche in die nächste Woche übernehmen? Bereits vorhandene identische Aufträge werden übersprungen.'))
				.setButtons([
					{ label: this.t('zeitwerk', 'Abbrechen'), type: 'secondary', callback: () => {} },
					{
						label: this.t('zeitwerk', 'Kopieren'),
						type: 'primary',
						callback: async () => {
							try {
								const created = await this.copyToNextWeek()
								showSuccessMessage(this.t('zeitwerk', '{count} Aufträge kopiert', { count: created }))
							} catch (error) {
								showErrorMessage(error.message)
							}
						},
					},
				])
				.build()
			dialog.show()
		},
		print() {
			window.print()
		},
	},
}
</script>

<style scoped>
.duty-roster-view { padding: 20px; padding-left: 50px; max-width: 1600px; }
.view-header { display: flex; align-items: center; margin-bottom: 12px; }
.view-header h2 { margin: 0; }
.view-header__nav { margin-left: auto; display: flex; align-items: center; gap: 6px; }
.week-label { font-weight: 600; min-width: 210px; text-align: center; }
.week-date { width: 150px; }
.view-toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }

.roster-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	overflow-x: auto;
}
.roster-grid {
	display: grid;
	grid-template-columns: 180px repeat(var(--day-count), minmax(150px, 1fr));
	min-width: 900px;
}
.roster-corner, .roster-day-header {
	position: sticky; top: 0; z-index: 1;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border-dark);
	padding: 10px 12px;
}
.roster-day-header { display: flex; flex-direction: column; font-weight: 600; font-size: 14px; color: var(--color-text-maxcontrast); }
.roster-day-header.today { color: var(--color-primary-element); }
.roster-day-date { font-weight: 400; font-size: 12px; }
.roster-name {
	display: flex; align-items: center; gap: 8px;
	padding: 10px 12px; font-weight: 600;
	border-bottom: 1px solid var(--color-border-light, var(--color-border));
	border-left: 4px solid var(--duty-row-color, transparent);
}
.roster-cell {
	position: relative;
	min-height: 96px;
	padding: 6px;
	border-left: 1px solid var(--color-border-light, var(--color-border));
	border-bottom: 1px solid var(--color-border-light, var(--color-border));
}
.roster-cell.weekend, .roster-day-header.weekend { background: var(--color-background-hover); }
.roster-cell.over { outline: 2px solid var(--color-primary-element); outline-offset: -2px; background: var(--color-primary-element-light); }
.roster-cell--absent { background: var(--color-background-dark); }
.roster-cell--absent-half {
	background: repeating-linear-gradient(135deg, transparent 0 8px, var(--color-background-dark) 8px 12px);
}
.roster-cell--pending { outline: 2px dashed var(--color-warning); outline-offset: -3px; }
.roster-cell__label {
	font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em;
	color: var(--color-text-maxcontrast); margin-bottom: 4px;
}
.roster-add {
	display: block; width: 100%; margin-top: 2px;
	background: none; border: 1px dashed var(--color-border-dark); border-radius: var(--border-radius-element, 8px);
	color: var(--color-text-maxcontrast); cursor: pointer; opacity: 0; transition: opacity .15s;
}
.roster-cell:hover .roster-add, .roster-add:focus { opacity: 1; }

@media print {
	@page { size: landscape; }
	.view-header__nav, .view-toolbar, .roster-add { display: none !important; }
	.duty-roster-view { padding: 0; max-width: none; }
	.roster-card { border: none; overflow: visible; }
}
</style>
