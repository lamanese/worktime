<template>
	<aside class="duty-template-sidebar"
		:class="{ 'duty-template-sidebar--trash': trashOver }"
		@dragover="onJobDragOver"
		@dragleave="onJobDragLeave"
		@drop="onJobDrop">
		<div v-if="trashOver" class="duty-template-sidebar__trash">
			<TrashCanOutlineIcon :size="32" />
			<span>{{ t('zeitwerk', 'Loslassen, um den Auftrag aus dem Plan zu entfernen') }}</span>
		</div>
		<h3 class="duty-template-sidebar__title">{{ t('zeitwerk', 'Vorlagen') }}</h3>

		<p v-if="templates.length" class="duty-template-sidebar__hint">
			{{ t('zeitwerk', 'In eine Zelle ziehen, um den Auftrag einzuplanen. Die Vorlage bleibt hier stehen.') }}
		</p>
		<p class="duty-template-sidebar__hint">
			{{ t('zeitwerk', 'Einen Auftrag aus dem Plan hierher ziehen, um ihn zu entfernen.') }}
		</p>

		<template v-if="groups.open.length">
			<h4 class="duty-template-sidebar__group duty-template-sidebar__group--open">
				{{ t('zeitwerk', 'Feste Tage – noch offen') }}
			</h4>
			<div class="duty-template-sidebar__list">
				<div v-for="entry in groups.open"
					:key="entry.template.id"
					class="duty-template duty-template--fixed duty-template--open"
					:class="{ 'duty-template--on-call': entry.template.onCall }"
					draggable="true"
					:title="entry.template.note || ''"
					@dragstart="onDragStart($event, entry)"
					@dragend="$emit('drag-end')">
					<div class="duty-template__main">
						<span class="duty-template__time">{{ entry.template.startTime || '–' }}</span>
						<span class="duty-template__title">{{ entry.template.title }}</span>
						<span v-if="duration(entry.template)" class="duty-template__duration">{{ duration(entry.template) }}</span>
					</div>
					<div class="duty-template__days">
						<span v-for="day in entry.coverage.weekdays"
							:key="day"
							class="duty-day-chip"
							:class="'duty-day-chip--' + chipState(entry.coverage, day)"
							:title="chipTitle(entry.coverage, day)">
							{{ dayLabel(day) }}
						</span>
						<button class="duty-template__skip"
							type="button"
							:disabled="busyId === entry.template.id"
							:title="t('zeitwerk', 'Diese Woche ignorieren')"
							@click="setSkipped(entry.template, true)">
							{{ t('zeitwerk', 'Ignorieren') }}
						</button>
					</div>
				</div>
			</div>
		</template>

		<template v-if="groups.any.length">
			<h4 v-if="groups.open.length || groups.done.length" class="duty-template-sidebar__group">
				{{ t('zeitwerk', 'Beliebig oft') }}
			</h4>
			<div class="duty-template-sidebar__list">
				<div v-for="entry in groups.any"
					:key="entry.template.id"
					class="duty-template"
					:class="{ 'duty-template--on-call': entry.template.onCall }"
					draggable="true"
					:title="entry.template.note || ''"
					@dragstart="onDragStart($event, entry)"
					@dragend="$emit('drag-end')">
					<div class="duty-template__main">
						<span class="duty-template__time">{{ entry.template.startTime || '–' }}</span>
						<span class="duty-template__title">{{ entry.template.title }}</span>
						<span v-if="duration(entry.template)" class="duty-template__duration">{{ duration(entry.template) }}</span>
						<button class="duty-template__skip"
							type="button"
							:disabled="busyId === entry.template.id"
							:title="t('zeitwerk', 'Für diese Woche als erledigt ausblenden')"
							@click="setSkipped(entry.template, true)">
							{{ t('zeitwerk', 'Erledigt') }}
						</button>
					</div>
				</div>
			</div>
		</template>

		<template v-if="groups.done.length">
			<button class="duty-template-sidebar__done-toggle"
				type="button"
				:aria-expanded="showDone ? 'true' : 'false'"
				@click="showDone = !showDone">
				{{ showDone ? '▾' : '▸' }} {{ t('zeitwerk', '{count} erledigt', { count: groups.done.length }) }}
			</button>
			<div v-if="showDone" class="duty-template-sidebar__list">
				<div v-for="entry in groups.done"
					:key="entry.template.id"
					class="duty-template duty-template--fixed duty-template--done"
					:class="{ 'duty-template--on-call': entry.template.onCall }"
					draggable="true"
					:title="entry.template.note || ''"
					@dragstart="onDragStart($event, entry)"
					@dragend="$emit('drag-end')">
					<div class="duty-template__main">
						<span class="duty-template__time">{{ entry.template.startTime || '–' }}</span>
						<span class="duty-template__title">{{ entry.template.title }}</span>
					</div>
					<div class="duty-template__days">
						<template v-if="entry.coverage.skipped">
							<span class="duty-template__skipped">
								{{ entry.coverage.weekdays.length ? t('zeitwerk', 'Diese Woche ignoriert') : t('zeitwerk', 'Diese Woche erledigt') }}
							</span>
							<button class="duty-template__skip"
								type="button"
								:disabled="busyId === entry.template.id"
								@click="setSkipped(entry.template, false)">
								{{ t('zeitwerk', 'Rückgängig') }}
							</button>
						</template>
						<template v-else>
							<span v-for="day in entry.coverage.weekdays"
								:key="day"
								class="duty-day-chip"
								:class="'duty-day-chip--' + chipState(entry.coverage, day)"
								:title="chipTitle(entry.coverage, day)">
								{{ dayLabel(day) }}
							</span>
						</template>
					</div>
				</div>
			</div>
		</template>

		<div v-if="!templates.length" class="duty-template-sidebar__empty">
			<p>{{ t('zeitwerk', 'Keine Vorlagen') }}</p>
			<router-link v-if="canManageSettings" :to="{ name: 'settings', query: { sec: 'sec-dienstplan-vorlagen' } }">
				{{ t('zeitwerk', 'In den Einstellungen anlegen') }}
			</router-link>
			<p v-else>
				{{ t('zeitwerk', 'Vorlagen legt die Administration in den Einstellungen an.') }}
			</p>
		</div>
	</aside>
</template>

<script>
import { mapGetters } from 'vuex'
import TrashCanOutlineIcon from 'vue-material-design-icons/TrashCanOutline.vue'
import { formatDuration, splitTemplates, weekdayShortName, blockedWeekdays, DUTY_TEMPLATE_MIME, DUTY_JOB_MIME } from '../utils/dutyRoster.js'
import { getLocale } from '../utils/dateUtils.js'
import { showErrorMessage } from '../utils/errorHandler.js'

export default {
	name: 'DutyTemplateSidebar',
	components: { TrashCanOutlineIcon },
	props: {
		templates: { type: Array, required: true },
		// `templateCoverage` of the week response (spec §12).
		coverage: { type: Array, default: () => [] },
		// Monday of the week the coverage belongs to; «Ignorieren» acts on exactly this week.
		weekStart: { type: String, required: true },
	},
	data() {
		return { showDone: false, busyId: 0, trashOver: false }
	},
	computed: {
		...mapGetters('permissions', ['canManageSettings']),
		groups() {
			return splitTemplates(this.templates, this.coverage)
		},
	},
	methods: {
		duration(template) {
			return formatDuration(template.durationMinutes)
		},
		dayLabel(day) {
			return weekdayShortName(day, getLocale())
		},
		onDragStart(event, entry) {
			const id = String(entry.template.id)
			event.dataTransfer.setData('text/plain', id)
			event.dataTransfer.setData(DUTY_TEMPLATE_MIME, id)
			event.dataTransfer.effectAllowed = 'copy'
			// The view highlights the day columns this template still misses and
			// dims the ones it must not be dropped on.
			this.$emit('drag-start', {
				wanted: entry.coverage.skipped ? [] : entry.coverage.openDays,
				blocked: blockedWeekdays(entry.template),
			})
		},
		// --- a roster card dragged onto the sidebar is removed from the plan ---
		isJobDrag(event) {
			return Array.from(event.dataTransfer?.types || []).includes(DUTY_JOB_MIME)
		},
		onJobDragOver(event) {
			if (!this.isJobDrag(event)) return
			event.preventDefault()
			event.dataTransfer.dropEffect = 'move'
			this.trashOver = true
		},
		onJobDragLeave(event) {
			// dragleave also fires when moving over child elements
			if (!this.$el.contains(event.relatedTarget)) this.trashOver = false
		},
		onJobDrop(event) {
			this.trashOver = false
			if (!this.isJobDrag(event)) return
			event.preventDefault()
			const id = Number(event.dataTransfer.getData(DUTY_JOB_MIME))
			if (Number.isInteger(id) && id > 0) this.$emit('remove-job', id)
		},
		chipState(coverage, day) {
			if (coverage.openDays.includes(day)) return 'open'
			return (coverage.holidayDays || []).includes(day) ? 'holiday' : 'done'
		},
		chipTitle(coverage, day) {
			switch (this.chipState(coverage, day)) {
			case 'open':
				return this.t('zeitwerk', 'Noch nicht verteilt')
			case 'holiday':
				return this.t('zeitwerk', 'Feiertag – nicht nötig')
			default:
				return this.t('zeitwerk', 'Verteilt')
			}
		},
		async setSkipped(template, skipped) {
			this.busyId = template.id
			try {
				await this.$store.dispatch('dutyRoster/setTemplateSkipped', { id: template.id, skipped, weekStart: this.weekStart })
			} catch (error) {
				showErrorMessage(error.message)
			} finally {
				this.busyId = 0
			}
		},
	},
}
</script>

<style scoped>
.duty-template-sidebar {
	flex: 0 0 240px;
	min-width: 240px;
	align-self: flex-start;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	padding: 10px 12px;
}
/* Immer einspaltig und rechts vom Wochenplan — nie darunter (Ahmad, 2026-09-14). */
.duty-template-sidebar__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.duty-template-sidebar { position: relative; }
.duty-template-sidebar--trash { border-color: #e60000; box-shadow: 0 0 0 2px rgba(230, 0, 0, .35); }
.duty-template-sidebar__trash {
	position: absolute;
	inset: 0;
	z-index: 2;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 16px;
	text-align: center;
	font-weight: 600;
	color: #c00000;
	background: rgba(255, 235, 235, .94);
	border-radius: inherit;
	pointer-events: none;
}
.duty-template-sidebar__title { margin: 0 0 6px; font-size: 15px; }
.duty-template-sidebar__hint,
.duty-template-sidebar__empty { font-size: 12px; color: var(--color-text-maxcontrast); }
.duty-template-sidebar__hint { margin: 0 0 10px; }
.duty-template-sidebar__group {
	margin: 10px 0 4px;
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .04em;
	color: var(--color-text-maxcontrast);
}
.duty-template-sidebar__group--open { color: var(--color-error-text, #c00); }
.duty-template-sidebar__done-toggle {
	display: block;
	width: 100%;
	margin: 8px 0 4px;
	padding: 2px 0;
	border: 0;
	background: none;
	text-align: left;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.duty-template__main { display: flex; align-items: baseline; gap: 6px; min-width: 0; }
.duty-template__days { display: flex; flex-wrap: wrap; align-items: center; gap: 3px; margin-top: 4px; }
.duty-day-chip {
	font-size: 11px;
	font-weight: 700;
	line-height: 1;
	padding: 3px 5px;
	border-radius: 999px;
	border: 1px solid transparent;
}
.duty-day-chip--open { background: #e60000; color: #fff; }
.duty-day-chip--done {
	background: transparent;
	border-color: var(--color-border-dark);
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
	font-weight: 500;
}
.duty-day-chip--holiday {
	background: var(--color-background-dark);
	border-color: var(--color-border-dark);
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-weight: 500;
}
.duty-template__main .duty-template__skip { align-self: center; }
.duty-template__skip {
	margin: 0 0 0 auto;
	padding: 1px 6px;
	min-height: 0;
	border: 1px solid var(--color-border-dark);
	border-radius: 999px;
	background: var(--color-main-background);
	font-size: 11px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.duty-template__skip:hover:not(:disabled) { color: var(--color-main-text); border-color: var(--color-main-text); }
.duty-template__skipped { font-size: 11px; font-style: italic; color: var(--color-text-maxcontrast); }
.duty-template--open { border-left-color: #e60000; }
.duty-template--done { opacity: .7; }
.duty-template {
	display: flex;
	flex-direction: column;
	min-height: 0;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border-dark);
	border-left: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-element, 8px);
	padding: 4px 6px;
	margin-bottom: 6px;
	font-size: 13px;
	line-height: 1.3;
	cursor: grab;
	user-select: none;
	break-inside: avoid;
}
.duty-template:active { cursor: grabbing; }
.duty-template__time { font-weight: 600; flex: 0 0 auto; }
.duty-template__title { flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.duty-template__duration { color: var(--color-text-maxcontrast); font-size: 12px; flex: 0 0 auto; }
.duty-template--on-call { border-style: dashed; font-style: italic; }

@media print {
	body.zw-printing-roster .duty-template-sidebar { display: none !important; }
}
</style>
