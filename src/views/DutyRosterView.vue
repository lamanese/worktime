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

		<div v-else class="board">
			<div class="roster-card">
				<div class="roster-grid" :style="{ '--day-count': days.length }">
					<div class="roster-corner" />
					<div v-for="day in days"
						:key="day.date"
						class="roster-day-header"
						:class="{ weekend: day.isWeekend, today: day.isToday }">
						<span class="roster-day-name">{{ dayName(day.date) }}</span>
						<span class="roster-day-date">{{ dayDate(day.date) }}</span>
					</div>

					<template v-for="row in rows">
						<div :key="'name-' + row.employee.id" class="roster-name" :style="rowStyles[row.employee.id]">
							<NcAvatar :user="row.employee.userId" :display-name="row.employee.fullName" :size="24" :show-user-status="false" />
							<span>{{ row.employee.fullName }}</span>
						</div>
						<div v-for="day in days"
							:key="row.employee.id + '-' + day.date"
							class="roster-cell"
							:class="['roster-cell--' + cellMap[row.employee.id + '|' + day.date].state, { weekend: day.isWeekend, today: day.isToday, over: isOver(row, day) }]"
							:style="rowStyles[row.employee.id]"
							@dragover="onDragOver($event, row, day)"
							@dragleave="onDragLeave(row, day)"
							@drop="onDrop($event, row, day)">
							<div v-if="cellMap[row.employee.id + '|' + day.date].label" class="roster-cell__label">{{ cellMap[row.employee.id + '|' + day.date].label }}</div>
							<DutyJobCard v-for="job in cellMap[row.employee.id + '|' + day.date].jobs"
								:key="job.id"
								:job="job"
								:draggable="canManage"
								:dimmed="cellMap[row.employee.id + '|' + day.date].state === 'absent'"
								@edit="openEdit(row, day, $event)" />
							<button v-if="canManage"
								class="roster-add"
								type="button"
								:aria-label="t('zeitwerk', 'Auftrag für {name} am {date} hinzufügen', { name: row.employee.fullName, date: dayDate(day.date) })"
								@click="openCreate(row, day)">
								+
							</button>
						</div>
					</template>
				</div>
			</div>

			<DutyTemplateSidebar v-if="canManage" :templates="templates" />
		</div>

		<DutyJobForm v-if="form.open"
			:job="form.job"
			:employee-id="form.employeeId"
			:date="form.date"
			:employees="employeeOptions"
			@saved="closeForm"
			@deleted="closeForm"
			@cancel="closeForm" />
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
import DutyTemplateSidebar from '../components/DutyTemplateSidebar.vue'
import { cellState, sortJobs, formatWeekLabel, parseLocalDate, toDateString, resolveDrop, DUTY_TEMPLATE_MIME } from '../utils/dutyRoster.js'
import { showErrorMessage, showSuccessMessage } from '../utils/errorHandler.js'
import { getLocale } from '../utils/dateUtils.js'

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
		DutyTemplateSidebar,
	},
	data() {
		return {
			form: { open: false, job: null, employeeId: 0, date: '' },
			overKey: null,
		}
	},
	computed: {
		...mapGetters('dutyRoster', ['weekStart', 'week', 'rows', 'days', 'canManage', 'loading', 'error', 'templates']),
		today() {
			return toDateString(new Date())
		},
		weekLabel() {
			return formatWeekLabel(this.weekStart, this.t('zeitwerk', 'KW'))
		},
		employeeOptions() {
			return this.rows.map(r => ({ id: r.employee.id, fullName: r.employee.fullName }))
		},
		rowStyles() {
			const styles = {}
			for (const row of this.rows) {
				const c = usernameToColor(row.employee.userId)
				styles[row.employee.id] = {
					'--duty-row-color': `rgb(${c.r}, ${c.g}, ${c.b})`,
					'--duty-row-rgb': `${c.r}, ${c.g}, ${c.b}`,
				}
			}
			return styles
		},
		cellMap() {
			const map = {}
			for (const row of this.rows) {
				for (const day of this.days) {
					const key = row.employee.id + '|' + day.date
					const info = cellState(
						row.absences.filter(a => a.date === day.date),
						row.holidays.filter(h => h.date === day.date),
					)
					map[key] = {
						...info,
						label: this.cellLabel(info),
						jobs: sortJobs(row.jobs.filter(j => j.date === day.date)),
					}
				}
			}
			return map
		},
	},
	async created() {
		await this.loadWeek()
		// canManage kommt aus der Wochenantwort, darum erst hier.
		if (this.canManage) {
			await this.loadTemplates()
		}
	},
	mounted() {
		window.addEventListener('afterprint', this.onAfterPrint)
	},
	beforeDestroy() {
		window.removeEventListener('afterprint', this.onAfterPrint)
	},
	methods: {
		...mapActions('dutyRoster', ['loadWeek', 'prevWeek', 'nextWeek', 'goToDate', 'moveJob', 'createJob', 'copyToNextWeek', 'loadTemplates']),
		dayName(date) {
			return parseLocalDate(date).toLocaleDateString(getLocale(), { weekday: 'short' })
		},
		dayDate(date) {
			return parseLocalDate(date).toLocaleDateString(getLocale(), { day: '2-digit', month: '2-digit' })
		},
		onDatePick(event) {
			if (event.target.value) this.goToDate(event.target.value)
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
			event.dataTransfer.dropEffect = event.dataTransfer.types.includes(DUTY_TEMPLATE_MIME) ? 'copy' : 'move'
			this.overKey = this.cellKey(row, day)
		},
		onDragLeave(row, day) {
			if (this.overKey === this.cellKey(row, day)) this.overKey = null
		},
		async onDrop(event, row, day) {
			this.overKey = null
			if (!this.canManage) return
			event.preventDefault()
			const drop = resolveDrop(event.dataTransfer)
			if (drop.kind === 'job') {
				await this.dropJob(drop.id, row, day)
			} else if (drop.kind === 'template') {
				await this.dropTemplate(drop.id, row, day)
			}
		},
		/** Existing card moved into another cell. */
		async dropJob(id, row, day) {
			const source = this.rows.flatMap(r => r.jobs).find(j => j.id === id)
			if (!source || (source.employeeId === row.employee.id && source.date === day.date)) return
			try {
				await this.moveJob({ id, employeeId: row.employee.id, date: day.date })
				const freshRow = this.rows.find(r => r.employee.id === row.employee.id)
				if (freshRow) this.notifyAbsence(freshRow, day)
			} catch (error) {
				showErrorMessage(error.message)
			}
		},
		/**
		 * Template dropped into a cell: creates a new, independent card right
		 * away (no form). The template itself stays in the sidebar and can be
		 * dropped as often as wanted.
		 */
		async dropTemplate(id, row, day) {
			const template = this.templates.find(t => t.id === id)
			if (!template) return
			try {
				await this.createJob({
					employeeId: row.employee.id,
					date: day.date,
					startTime: template.startTime || null,
					durationMinutes: template.durationMinutes || null,
					title: template.title,
					note: template.note || null,
					onCall: !!template.onCall,
				})
				const freshRow = this.rows.find(r => r.employee.id === row.employee.id)
				if (freshRow && !this.notifyAbsence(freshRow, day)) {
					showSuccessMessage(this.t('zeitwerk', '«{title}» eingeplant', { title: template.title }))
				}
			} catch (error) {
				showErrorMessage(error.message)
			}
		},
		/**
		 * Composes the cell label from the translation-free cellState() result.
		 *
		 * @param {{labelKind: string|null, labelText: string|null}} cell cell state
		 * @return {string|null} translated label or null
		 */
		cellLabel(cell) {
			switch (cell.labelKind) {
			case 'holiday':
				return this.t('zeitwerk', 'Feiertag: {name}', { name: cell.labelText })
			case 'absence':
				return cell.labelText
			case 'half':
				return this.t('zeitwerk', '½ {type}', { type: cell.labelText })
			case 'pending':
				return this.t('zeitwerk', 'beantragt: {type}', { type: cell.labelText })
			default:
				return null
			}
		},
		/**
		 * Shows the absence hint for the target cell. Returns true when a toast
		 * was shown, so callers can skip their own success message.
		 *
		 * @param {object} row roster row
		 * @param {object} day day of the week
		 * @return {boolean} whether a toast was shown
		 */
		notifyAbsence(row, day) {
			const info = this.cellMap[row.employee.id + '|' + day.date]
			if (info && info.state === 'absent') {
				showSuccessMessage(this.t('zeitwerk', 'Gespeichert. Hinweis: {name} ist an diesem Tag abwesend ({reason}).', { name: row.employee.fullName, reason: info.label }))
				return true
			}
			return false
		},
		// --- form ---
		openCreate(row, day) {
			this.form = { open: true, job: null, employeeId: row.employee.id, date: day.date }
		},
		openEdit(row, day, job) {
			if (!this.canManage) return
			this.form = { open: true, job, employeeId: row.employee.id, date: day.date }
		},
		closeForm(job) {
			this.form = { open: false, job: null, employeeId: 0, date: '' }
			if (job) {
				const row = this.rows.find(r => r.employee.id === job.employeeId)
				const day = this.days.find(d => d.date === job.date)
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
			const style = document.createElement('style')
			style.id = 'zw-roster-print'
			style.textContent = '@page { size: landscape; }'
			document.head.appendChild(style)
			document.body.classList.add('zw-printing-roster')
			window.print()
		},
		onAfterPrint() {
			document.body.classList.remove('zw-printing-roster')
			document.getElementById('zw-roster-print')?.remove()
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

/* Wochenplan links (flexibel, scrollt bei Bedarf intern), Vorlagen-Leiste rechts
 * mit fester Breite — nie umbrechen, nie unter den Plan (Ahmad, 2026-09-14). */
.board { display: flex; gap: 12px; align-items: flex-start; flex-wrap: nowrap; }
.roster-card {
	flex: 1 1 auto;
	min-width: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	max-height: calc(100vh - 220px);
	overflow: auto;
}
.roster-grid {
	display: grid;
	grid-template-columns: 160px repeat(var(--day-count), minmax(140px, 1fr));
}
.roster-corner, .roster-day-header {
	position: sticky; top: 0; z-index: 1;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border-dark);
	padding: 8px 10px;
}
.roster-day-header { display: flex; flex-direction: column; font-weight: 600; font-size: 14px; color: var(--color-text-maxcontrast); }
.roster-day-header.today { color: var(--color-primary-element); }
.roster-day-date { font-weight: 400; font-size: 12px; }
.roster-name {
	display: flex; align-items: center; gap: 8px;
	padding: 8px 10px; font-weight: 600; font-size: 13px;
	border-bottom: 1px solid var(--color-border-light, var(--color-border));
	border-left: 4px solid var(--duty-row-color, transparent);
}
.roster-cell {
	position: relative;
	min-height: 76px;
	padding: 5px;
	border-left: 1px solid var(--color-border-light, var(--color-border));
	border-bottom: 1px solid var(--color-border-light, var(--color-border));
	background-color: rgba(var(--duty-row-rgb, 0, 0, 0), 0.06);
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
	color: var(--color-text-maxcontrast); cursor: pointer; opacity: .35; transition: opacity .15s;
}
.roster-cell:hover .roster-add, .roster-add:focus { opacity: 1; }
@media (hover: none) {
	.roster-add { opacity: 1; }
}

@media print {
	body.zw-printing-roster .view-header__nav,
	body.zw-printing-roster .view-toolbar,
	body.zw-printing-roster .roster-add { display: none !important; }
	body.zw-printing-roster .board { display: block; }
	body.zw-printing-roster .duty-roster-view { padding: 0; max-width: none; }
	body.zw-printing-roster .roster-card { border: none; overflow: visible; max-height: none; }
}
</style>

<style>
/*
 * @page cannot be scoped to this view. Its landscape orientation is added
 * dynamically via a <style id="zw-roster-print"> element in print() and
 * removed again in onAfterPrint(), so it only applies while this view
 * prints. The body class below is toggled the same way, so the chrome
 * hiding here likewise only applies to the duty roster print.
 */
@media print {
	body.zw-printing-roster #header,
	body.zw-printing-roster #app-navigation,
	body.zw-printing-roster #app-navigation-toggle,
	body.zw-printing-roster .app-navigation {
		display: none !important;
	}
	body.zw-printing-roster .app-content {
		padding: 0 !important;
		margin: 0 !important;
	}
}
</style>
