<template>
	<aside class="duty-template-sidebar">
		<h3 class="duty-template-sidebar__title">{{ t('zeitwerk', 'Vorlagen') }}</h3>

		<p v-if="templates.length" class="duty-template-sidebar__hint">
			{{ t('zeitwerk', 'In eine Zelle ziehen, um den Auftrag einzuplanen. Die Vorlage bleibt hier stehen.') }}
		</p>

		<div class="duty-template-sidebar__list">
			<div v-for="template in templates"
				:key="template.id"
				class="duty-template"
				:class="{ 'duty-template--on-call': template.onCall }"
				draggable="true"
				:title="template.note || ''"
				@dragstart="onDragStart($event, template)">
				<span class="duty-template__time">{{ template.startTime || '–' }}</span>
				<span class="duty-template__title">{{ template.title }}</span>
				<span v-if="duration(template)" class="duty-template__duration">{{ duration(template) }}</span>
			</div>
		</div>

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
import { formatDuration, DUTY_TEMPLATE_MIME } from '../utils/dutyRoster.js'

export default {
	name: 'DutyTemplateSidebar',
	props: {
		templates: { type: Array, required: true },
	},
	computed: {
		...mapGetters('permissions', ['canManageSettings']),
	},
	methods: {
		duration(template) {
			return formatDuration(template.durationMinutes)
		},
		onDragStart(event, template) {
			event.dataTransfer.setData('text/plain', String(template.id))
			event.dataTransfer.setData(DUTY_TEMPLATE_MIME, String(template.id))
			event.dataTransfer.effectAllowed = 'copy'
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
.duty-template-sidebar__title { margin: 0 0 6px; font-size: 15px; }
.duty-template-sidebar__hint,
.duty-template-sidebar__empty { font-size: 12px; color: var(--color-text-maxcontrast); }
.duty-template-sidebar__hint { margin: 0 0 10px; }
.duty-template {
	display: flex;
	align-items: baseline;
	gap: 6px;
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
