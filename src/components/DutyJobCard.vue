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
		<span v-if="duration" class="duty-job__duration">{{ duration }}</span>
	</div>
</template>

<script>
import { formatDuration, DUTY_JOB_MIME } from '../utils/dutyRoster.js'

export default {
	name: 'DutyJobCard',
	props: {
		job: { type: Object, required: true },
		draggable: { type: Boolean, default: false },
		dimmed: { type: Boolean, default: false },
	},
	computed: {
		duration() {
			return formatDuration(this.job.durationMinutes)
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
	padding: 2px 6px;
	margin-bottom: 3px;
	font-size: 12.5px;
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
.duty-job--on-call { border-style: dashed; font-style: italic; }
.duty-job--dimmed { opacity: 0.45; filter: grayscale(1); }
</style>
