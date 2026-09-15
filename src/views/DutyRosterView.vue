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
				<NcButton v-if="canManage && !locked"
					type="tertiary"
					:disabled="templatesBusy"
					:aria-label="showTemplates ? t('zeitwerk', 'Vorlagen-Leiste ausblenden') : t('zeitwerk', 'Vorlagen-Leiste einblenden')"
					:title="showTemplates ? t('zeitwerk', 'Vorlagen-Leiste ausblenden') : t('zeitwerk', 'Vorlagen-Leiste einblenden')"
					@click="toggleTemplates">
					<template #icon>
						<EyeOutlineIcon v-if="showTemplates" :size="20" />
						<EyeOffOutlineIcon v-else :size="20" />
					</template>
				</NcButton>
				<NcButton v-if="canManage"
					:type="locked ? 'warning' : 'tertiary'"
					:disabled="lockBusy || (locked && !canUnlock)"
					:aria-label="lockButtonLabel"
					:title="lockButtonLabel"
					@click="toggleLock">
					<template #icon>
						<LockOutlineIcon v-if="locked" :size="20" />
						<LockOpenVariantOutlineIcon v-else :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="locked" type="warning" class="lock-banner">
			{{ lockBannerText }}
		</NcNoteCard>

		<div v-if="canManage" class="view-toolbar">
			<NcButton type="secondary" @click="openCopyTo">
				<template #icon><ContentCopyIcon :size="18" /></template>
				{{ t('zeitwerk', 'Woche kopieren nach …') }}
			</NcButton>
			<NcButton type="secondary" :disabled="pdfBusy" @click="exportPdf">
				<template #icon><FilePdfBoxIcon :size="18" /></template>
				{{ t('zeitwerk', 'Als PDF ins Archiv') }}
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
					<div class="roster-corner">
						<span class="roster-corner__kw">{{ t('zeitwerk', 'KW') }}{{ weekNumber }}</span>
						<span class="roster-corner__year">{{ weekIsoYear }}</span>
					</div>
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
								:draggable="canEdit"
								:dimmed="cellMap[row.employee.id + '|' + day.date].state === 'absent'"
								@edit="openEdit(row, day, $event)" />
							<button v-if="canEdit"
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

			<DutyTemplateSidebar v-if="showTemplates && !locked" :templates="templates" />
		</div>

		<NcModal v-if="copyTo.open" :name="t('zeitwerk', 'Woche kopieren nach …')" size="small" @close="copyTo.open = false">
			<form class="copy-to" @submit.prevent="submitCopyTo">
				<h3>{{ t('zeitwerk', 'Woche kopieren nach …') }}</h3>
				<p class="copy-to__hint">{{ t('zeitwerk', 'Alle Aufträge dieser Woche in die Zielwoche übernehmen. Bereits vorhandene identische Aufträge werden übersprungen.') }}</p>
				<p class="copy-to__source">{{ t('zeitwerk', 'Quelle: {label}', { label: weekLabel }) }}</p>
				<div class="copy-to__row">
					<div class="copy-to__field">
						<label for="copy-to-week">{{ t('zeitwerk', 'KW') }}</label>
						<input id="copy-to-week" v-model.number="copyTo.week" type="number" min="1" max="53" required>
					</div>
					<div class="copy-to__field">
						<label for="copy-to-year">{{ t('zeitwerk', 'Jahr') }}</label>
						<input id="copy-to-year" v-model.number="copyTo.year" type="number" min="2000" max="2100" required>
					</div>
				</div>
				<p v-if="copyToTarget" class="copy-to__preview">{{ t('zeitwerk', 'Ziel: {label}', { label: copyToLabel }) }}</p>
				<p v-else class="copy-to__preview copy-to__preview--error">{{ t('zeitwerk', 'Diese Kalenderwoche gibt es in dem Jahr nicht.') }}</p>
				<p v-if="copyToTarget === weekStart" class="copy-to__preview copy-to__preview--error">{{ t('zeitwerk', 'Ziel und Quelle sind dieselbe Woche.') }}</p>
				<div class="copy-to__actions">
					<NcButton type="secondary" :disabled="copyTo.busy" @click="copyTo.open = false">
						{{ t('zeitwerk', 'Abbrechen') }}
					</NcButton>
					<NcButton type="primary" native-type="submit" :disabled="copyTo.busy || !copyToTarget || copyToTarget === weekStart">
						{{ t('zeitwerk', 'Kopieren') }}
					</NcButton>
				</div>
			</form>
		</NcModal>

		<DutyJobForm v-if="form.open"
			:job="form.job"
			:employee-id="form.employeeId"
			:date="form.date"
			:employees="employeeOptions"
			:readonly="locked"
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
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import { usernameToColor } from '@nextcloud/vue/dist/Functions/usernameToColor.js'
import { DialogBuilder } from '@nextcloud/dialogs'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import PrinterIcon from 'vue-material-design-icons/Printer.vue'
import FilePdfBoxIcon from 'vue-material-design-icons/FilePdfBox.vue'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutlineIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import LockOpenVariantOutlineIcon from 'vue-material-design-icons/LockOpenVariantOutline.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import CalendarWeekIcon from 'vue-material-design-icons/CalendarWeek.vue'
import { mapGetters, mapActions } from 'vuex'
import DutyJobCard from '../components/DutyJobCard.vue'
import DutyJobForm from '../components/DutyJobForm.vue'
import DutyTemplateSidebar from '../components/DutyTemplateSidebar.vue'
import DutyRosterService from '../services/DutyRosterService.js'
import { cellState, sortJobs, formatWeekLabel, parseLocalDate, toDateString, resolveDrop, addDays, mondayOfIsoWeek, isoYear, DUTY_TEMPLATE_MIME } from '../utils/dutyRoster.js'
import { showErrorMessage, showSuccessMessage } from '../utils/errorHandler.js'
import { getLocale, getISOWeek } from '../utils/dateUtils.js'

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
		NcNoteCard,
		NcModal,
		FilePdfBoxIcon,
		EyeOutlineIcon,
		EyeOffOutlineIcon,
		LockOutlineIcon,
		LockOpenVariantOutlineIcon,
	},
	data() {
		return {
			form: { open: false, job: null, employeeId: 0, date: '' },
			overKey: null,
			lockBusy: false,
			pdfBusy: false,
			templatesBusy: false,
			copyTo: { open: false, week: 1, year: 2026, busy: false },
		}
	},
	computed: {
		...mapGetters('dutyRoster', ['weekStart', 'week', 'rows', 'days', 'canManage', 'canEdit', 'showTemplates', 'locked', 'lockedBy', 'lockedAt', 'canUnlock', 'loading', 'error', 'templates']),
		lockButtonLabel() {
			if (!this.locked) return this.t('zeitwerk', 'Woche sperren')
			return this.canUnlock
				? this.t('zeitwerk', 'Woche entsperren')
				: this.t('zeitwerk', 'Woche ist gesperrt. Entsperren können nur Admin und HR.')
		},
		lockBannerText() {
			const week = getISOWeek(parseLocalDate(this.weekStart))
			const when = this.lockedAt
				? new Date(this.lockedAt).toLocaleString(getLocale(), { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
				: ''
			return this.t('zeitwerk', 'KW {week} ist gesperrt (von {name} am {date}). Aufträge können nur angesehen werden.', {
				week, name: this.lockedBy || '–', date: when,
			})
		},
		today() {
			return toDateString(new Date())
		},
		weekLabel() {
			return formatWeekLabel(this.weekStart, this.t('zeitwerk', 'KW'))
		},
		weekNumber() {
			return getISOWeek(parseLocalDate(this.weekStart))
		},
		weekIsoYear() {
			return isoYear(this.weekStart)
		},
		/** Monday of the chosen target week, or null when KW/year is invalid. */
		copyToTarget() {
			return mondayOfIsoWeek(this.copyTo.year, this.copyTo.week)
		},
		copyToLabel() {
			return this.copyToTarget ? formatWeekLabel(this.copyToTarget, this.t('zeitwerk', 'KW')) : ''
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
		// showTemplates (Planer + Firmeneinstellung) kommt aus der Wochenantwort, darum erst hier.
		if (this.showTemplates) {
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
		...mapActions('dutyRoster', ['loadWeek', 'prevWeek', 'nextWeek', 'goToDate', 'moveJob', 'createJob', 'copyToWeek', 'loadTemplates', 'lockWeek', 'unlockWeek', 'setTemplatesSidebar']),
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
			if (!this.canEdit) return
			event.preventDefault()
			event.dataTransfer.dropEffect = event.dataTransfer.types.includes(DUTY_TEMPLATE_MIME) ? 'copy' : 'move'
			this.overKey = this.cellKey(row, day)
		},
		onDragLeave(row, day) {
			if (this.overKey === this.cellKey(row, day)) this.overKey = null
		},
		async onDrop(event, row, day) {
			this.overKey = null
			if (!this.canEdit) return
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
		// --- template sidebar (eye): per-user override of the company default ---
		async toggleTemplates() {
			this.templatesBusy = true
			try {
				await this.setTemplatesSidebar(!this.showTemplates)
			} catch (error) {
				showErrorMessage(error.message)
			} finally {
				this.templatesBusy = false
			}
		},
		// --- copy into any week (KW + year, year boundary safe) ---
		openCopyTo() {
			const next = addDays(this.weekStart, 7)
			this.copyTo = { open: true, week: getISOWeek(parseLocalDate(next)), year: isoYear(next), busy: false }
		},
		async submitCopyTo() {
			const target = this.copyToTarget
			if (!target || target === this.weekStart) return
			this.copyTo.busy = true
			try {
				const created = await this.copyToWeek(target)
				this.copyTo.open = false
				showSuccessMessage(this.t('zeitwerk', '{count} Aufträge kopiert', { count: created }))
			} catch (error) {
				showErrorMessage(error.message)
			} finally {
				this.copyTo.busy = false
			}
		},
		// --- PDF export: archive in Nextcloud (no browser download, Ahmad 2026-09-15) ---
		async exportPdf() {
			this.pdfBusy = true
			try {
				const { archive, path } = await DutyRosterService.archivePdf(this.weekStart)
				if (archive === 'saved') {
					showSuccessMessage(this.t('zeitwerk', 'PDF gespeichert unter {path}', { path }))
				} else if (archive === 'skipped') {
					showErrorMessage(this.t('zeitwerk', 'Kein PDF-Archiv konfiguriert. Bitte in den Einstellungen unter «PDF-Archiv» einen Ablagepfad setzen.'))
				} else {
					showErrorMessage(this.t('zeitwerk', 'Die Ablage in Nextcloud ist fehlgeschlagen. Details stehen im Nextcloud-Log.'))
				}
			} catch (error) {
				showErrorMessage(error.message)
			} finally {
				this.pdfBusy = false
			}
		},
		// --- week lock ---
		async toggleLock() {
			if (this.locked) {
				if (this.canUnlock) this.confirmUnlock()
				return
			}
			this.lockBusy = true
			try {
				await this.lockWeek()
				showSuccessMessage(this.t('zeitwerk', 'Woche gesperrt'))
			} catch (error) {
				showErrorMessage(error.message)
			} finally {
				this.lockBusy = false
			}
		},
		confirmUnlock() {
			const dialog = new DialogBuilder()
				.setName(this.t('zeitwerk', 'Woche entsperren'))
				.setText(this.t('zeitwerk', 'Die Woche wieder zur Bearbeitung freigeben? Änderungen sind danach für alle Planer möglich, bis die Woche erneut gesperrt wird.'))
				.setButtons([
					{ label: this.t('zeitwerk', 'Abbrechen'), type: 'secondary', callback: () => {} },
					{
						label: this.t('zeitwerk', 'Entsperren'),
						type: 'primary',
						callback: async () => {
							this.lockBusy = true
							try {
								await this.unlockWeek()
								showSuccessMessage(this.t('zeitwerk', 'Woche entsperrt'))
							} catch (error) {
								showErrorMessage(error.message)
							} finally {
								this.lockBusy = false
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
.lock-banner { margin: 0 0 16px; }

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
.roster-corner { display: flex; flex-direction: column; justify-content: center; line-height: 1; }
.roster-corner__kw { font-size: 30px; font-weight: 800; letter-spacing: -0.5px; }
.roster-corner__year { font-size: 12px; font-weight: 600; color: var(--color-text-maxcontrast); margin-top: 3px; }
.copy-to { padding: 16px 20px 20px; display: flex; flex-direction: column; gap: 12px; }
.copy-to h3 { margin: 0; }
.copy-to__source, .copy-to__preview, .copy-to__hint { margin: 0; color: var(--color-text-maxcontrast); }
.copy-to__preview--error { color: var(--color-error); }
.copy-to__row { display: flex; gap: 12px; }
.copy-to__field { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.copy-to__field input { width: 100%; }
.copy-to__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
.roster-day-header { display: flex; flex-direction: column; font-weight: 600; font-size: 14px; color: var(--color-text-maxcontrast); }
.roster-day-header.today { color: var(--color-primary-element); }
.roster-day-date { font-weight: 400; font-size: 12px; }
.roster-name {
	display: flex; align-items: center; gap: 8px;
	padding: 8px 10px; font-weight: 600; font-size: 13px;
	border-bottom: 1px solid color-mix(in srgb, var(--color-border-maxcontrast) 40%, var(--color-border-dark));
	border-left: 4px solid var(--duty-row-color, transparent);
	/* gleiche dezente Mitarbeiterfarbe wie die Zellen der Zeile, etwas kraeftiger */
	background-color: rgba(var(--duty-row-rgb, 0, 0, 0), 0.12);
}
.roster-cell {
	position: relative;
	min-height: 76px;
	padding: 5px;
	border-left: 1px solid var(--color-border-dark, var(--color-border));
	border-bottom: 1px solid color-mix(in srgb, var(--color-border-maxcontrast) 40%, var(--color-border-dark));
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
