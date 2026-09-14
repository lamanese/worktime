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
		<div class="duty-job__head">
			<span class="duty-job__time">{{ job.startTime || '–' }}</span>
			<span v-if="duration" class="duty-job__duration">{{ duration }}</span>
		</div>
		<div class="duty-job__title">{{ job.title }}</div>
		<div v-if="job.note" class="duty-job__note">{{ job.note }}</div>
	</div>
</template>

<script>
import { formatDuration } from '../utils/dutyRoster.js'

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
			event.dataTransfer.setData('application/x-zeitwerk-duty-job', String(this.job.id))
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
	padding: 4px 8px;
	margin-bottom: 4px;
	font-size: 13px;
	line-height: 1.3;
	cursor: pointer;
	user-select: none;
}
.duty-job--draggable { cursor: grab; }
.duty-job--draggable:active { cursor: grabbing; }
.duty-job:hover { background: var(--color-background-hover); }
.duty-job__head { display: flex; justify-content: space-between; gap: 6px; }
.duty-job__time { font-weight: 600; }
.duty-job__duration, .duty-job__note { color: var(--color-text-maxcontrast); font-size: 12px; }
.duty-job__note { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.duty-job--on-call { border-style: dashed; font-style: italic; }
.duty-job--dimmed { opacity: 0.45; filter: grayscale(1); }
</style>
