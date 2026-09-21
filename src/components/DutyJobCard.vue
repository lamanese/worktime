<template>
	<div class="duty-job"
		:class="{ 'duty-job--on-call': job.onCall, 'duty-job--dimmed': dimmed, 'duty-job--draggable': draggable }"
		:draggable="draggable"
		:title="job.note || ''"
		role="button"
		tabindex="0"
		@click="$emit('edit', job)"
		@keydown.enter.prevent="$emit('edit', job)"
		@dragstart="onDragStart">
		<span class="duty-job__time">{{ job.startTime || '–' }}</span>
		<span class="duty-job__title">{{ job.title }}</span>
		<MinusCircleIcon v-if="offTargetTitle" class="duty-job__off-target" :size="18" :title="offTargetTitle" />
		<AlertIcon v-if="job.note" class="duty-job__note" :size="18" :title="job.note" />
		<span v-if="duration" class="duty-job__duration">{{ duration }}</span>
	</div>
</template>

<script>
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import MinusCircleIcon from 'vue-material-design-icons/MinusCircle.vue'
import { formatDuration, weekdayShortName, DUTY_JOB_MIME } from '../utils/dutyRoster.js'
import { getLocale } from '../utils/dateUtils.js'

export default {
	name: 'DutyJobCard',
	components: { AlertIcon, MinusCircleIcon },
	props: {
		job: { type: Object, required: true },
		draggable: { type: Boolean, default: false },
		dimmed: { type: Boolean, default: false },
	},
	computed: {
		duration() {
			return formatDuration(this.job.durationMinutes)
		},
		/** Card from a fixed-weekday template that lies on another day (planners only). */
		offTargetTitle() {
			const days = this.job.targetWeekdays
			if (!days || !days.length) return ''
			return this.t('zeitwerk', 'Nicht am vorgesehenen Tag – die Vorlage gilt für: {days}', {
				days: days.map(d => weekdayShortName(d, getLocale())).join(', '),
			})
		},
	},
	methods: {
		onDragStart(event) {
			if (!this.draggable) {
				event.preventDefault()
				return
			}
			event.dataTransfer.setData('text/plain', String(this.job.id))
			event.dataTransfer.setData(DUTY_JOB_MIME, String(this.job.id))
			event.dataTransfer.effectAllowed = 'move'
			this.$emit('dragstart', this.job)
		},
	},
}
</script>

<style scoped>
.duty-job {
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-left: 3px solid var(--duty-row-color, var(--color-primary-element));
	border-radius: var(--border-radius-element, 8px);
	padding: 4px 7px;
	margin-bottom: 4px;
	font-size: 13px;
	line-height: 1.3;
	cursor: pointer;
	user-select: none;
	display: flex; align-items: center; gap: 6px;
}
.duty-job--draggable { cursor: grab; }
.duty-job--draggable:active { cursor: grabbing; }
.duty-job:hover { background: var(--color-background-hover); }
/* Kompakt, eine Zeile: Zeit links, Titel, Dauer rechts (Notiz nur als Tooltip). */
.duty-job__time { font-weight: 600; flex: 0 0 auto; }
.duty-job__title { flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.duty-job__duration { color: var(--color-text-maxcontrast); font-size: 11px; flex: 0 0 auto; }
/* Notiz vorhanden: kleines Warnschild, Text weiterhin als Tooltip. */
/* Notiz vorhanden: gefuelltes Warndreieck in knalligem Rot, unabhaengig vom Theme. */
.duty-job__note { flex: 0 0 auto; color: #e60000; display: inline-flex; filter: drop-shadow(0 0 1px rgba(0, 0, 0, .35)); }
/* Falscher Wochentag: rundes rotes Schild mit weissem Balken («Einfahrt verboten»). */
.duty-job__off-target {
	flex: 0 0 auto;
	display: inline-flex;
	color: #e60000;
	background: radial-gradient(circle, #fff 0 45%, transparent 46%);
	border-radius: 50%;
	filter: drop-shadow(0 0 1px rgba(0, 0, 0, .35));
}
.duty-job--on-call { border-style: dashed; font-style: italic; }
.duty-job--dimmed { opacity: 0.45; filter: grayscale(1); }
</style>
