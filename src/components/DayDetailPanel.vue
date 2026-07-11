<template>
    <div class="day-panel">
        <h3 class="dp-title">{{ dayTitle }}</h3>
        <p class="dp-sub">{{ subtitle }}</p>

        <!-- Kontext-Hinweis: Feiertag / Abwesenheit (Erfassen bleibt möglich) -->
        <div v-if="day.holiday" class="dp-note holiday">
            <CalendarStarIcon :size="18" />
            {{ t('worktime', 'Feiertag') }}: {{ day.holiday.name }}
        </div>
        <template v-else-if="day.absence">
            <div class="dp-note" :class="absenceColorClass(day.absence.type)">
                {{ day.absence.typeName }}<span v-if="day.absence.scope < 1"> ({{ scopeLabel }})</span>
            </div>
            <NcButton type="tertiary" class="dp-open-abs" @click="goToAbsence">
                {{ t('worktime', 'In „Abwesenheit" öffnen') }}
            </NcButton>
        </template>

        <!-- Pausenkontrolle / Max-Tagesstunden (#338): nicht-blockierende Warnung auf Tagesebene -->
        <div v-for="(warning, i) in (day.warnings || [])" :key="'warn-' + i" class="dp-note warning">
            <AlertIcon :size="18" />
            {{ warning }}
        </div>

        <!-- Erfassung: immer möglich (auch an Feiertag/Wochenende/Abwesenheit) -->
        <div v-if="formMode" class="dp-form">
            <TimeEntryForm embedded
                :entry="editingEntry"
                :preset-date="day.date"
                :prefill-start="prefillStart"
                :prefill-end="prefillEnd"
                @saved="onSaved"
                @cancel="closeForm" />
        </div>
        <template v-else>
            <ul v-if="day.entries.length" class="dp-entries">
                <li v-for="entry in day.entries" :key="entry.id" class="dp-entry">
                    <div class="dp-entry-main">
                        <div class="dp-entry-time">{{ entry.startTime }} – {{ entry.endTime }}</div>
                        <div class="dp-entry-meta">
                            <span>{{ hoursLabel(entry.workMinutes) }}</span>
                            <span class="dp-dot-sep">·</span>
                            <span>{{ t('worktime', '{min} Min Pause', { min: entry.breakMinutes }) }}</span>
                            <template v-if="projectName(entry.projectId)">
                                <span class="dp-dot-sep">·</span>
                                <span>{{ projectName(entry.projectId) }}</span>
                            </template>
                        </div>
                        <div v-if="entry.description" class="dp-entry-desc">{{ entry.description }}</div>
                    </div>
                    <div v-if="!readonly" class="dp-entry-actions">
                        <NcButton type="tertiary"
                            :aria-label="t('worktime', 'Bearbeiten')"
                            @click="startEdit(entry)">
                            <template #icon><PencilIcon :size="18" /></template>
                        </NcButton>
                        <NcButton type="tertiary"
                            :aria-label="t('worktime', 'Löschen')"
                            @click="confirmDelete(entry)">
                            <template #icon><DeleteIcon :size="18" /></template>
                        </NcButton>
                    </div>
                </li>
            </ul>
            <p v-else-if="!day.holiday && !day.absence && !readonly" class="dp-empty">
                {{ t('worktime', 'Noch nichts erfasst.') }}
            </p>

            <div v-if="readonly" class="dp-locked">
                <LockIcon :size="16" />
                {{ lockedMessage }}
            </div>
            <NcButton v-else type="primary" wide class="dp-add" @click="startAdd">
                <template #icon><PlusIcon :size="20" /></template>
                {{ t('worktime', 'Eintrag hinzufügen') }}
            </NcButton>
        </template>

        <!-- Extern-Kilometer: nur an Tagen mit Extern-Projekt oder externem Abwesenheitstyp -->
        <div v-if="externEligible" class="dp-km">
            <label :for="'dp-km-' + day.date" class="dp-km-label">
                {{ t('worktime', 'Gefahrene Kilometer (Extern)') }}
            </label>

            <!-- Gespeicherte km als Eintrags-Zeile (wie die Zeiteinträge), mit Bearbeiten/Löschen -->
            <div v-if="day.kilometers > 0 && !kmEditing" class="dp-entry dp-km-entry">
                <div class="dp-entry-main">
                    <div class="dp-entry-time">{{ day.kilometers }} km</div>
                </div>
                <div v-if="!readonly" class="dp-entry-actions">
                    <NcButton type="tertiary"
                        :aria-label="t('worktime', 'Bearbeiten')"
                        @click="startKmEdit">
                        <template #icon><PencilIcon :size="18" /></template>
                    </NcButton>
                    <NcButton type="tertiary"
                        :aria-label="t('worktime', 'Löschen')"
                        @click="confirmKmDelete">
                        <template #icon><DeleteIcon :size="18" /></template>
                    </NcButton>
                </div>
            </div>

            <!-- Erfassen/Bearbeiten: erst der Button macht den Wert wirksam -->
            <div v-else-if="!readonly" class="dp-km-row">
                <input :id="'dp-km-' + day.date"
                    v-model.number="kmValue"
                    type="number"
                    min="0"
                    step="1"
                    class="dp-km-input"
                    @keyup.enter="saveKilometers">
                <span class="dp-km-unit">km</span>
                <NcButton type="primary"
                    :disabled="!kmValue || kmValue <= 0"
                    @click="saveKilometers">
                    {{ kmEditing ? t('worktime', 'Speichern') : t('worktime', 'Hinzufügen') }}
                </NcButton>
                <NcButton v-if="kmEditing" type="tertiary" @click="cancelKmEdit">
                    {{ t('worktime', 'Abbrechen') }}
                </NcButton>
            </div>
        </div>

        <CorrectionReasonModal v-if="pendingDeleteEntry"
            @confirm="onDeleteReasonConfirm"
            @close="pendingDeleteEntry = null" />
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import CalendarStarIcon from 'vue-material-design-icons/CalendarStar.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import { mapActions, mapGetters } from 'vuex'
import TimeEntryForm from './TimeEntryForm.vue'
import CorrectionReasonModal from './CorrectionReasonModal.vue'
import DailyKmService from '../services/DailyKmService.js'
import { formatDateWithWeekday, getToday } from '../utils/dateUtils.js'
import { formatMinutes, getCurrentTime } from '../utils/timeUtils.js'
import { getAbsenceColorClass } from '../utils/formatters.js'
import { confirmAction, showErrorMessage, showSuccessMessage } from '../utils/errorHandler.js'

export default {
    name: 'DayDetailPanel',
    components: {
        NcButton,
        PlusIcon,
        PencilIcon,
        DeleteIcon,
        LockIcon,
        CalendarStarIcon,
        AlertIcon,
        TimeEntryForm,
        CorrectionReasonModal,
    },
    props: {
        day: {
            type: Object,
            required: true,
        },
        projects: {
            type: Array,
            default: () => [],
        },
        monthStatus: {
            type: String,
            default: null,
        },
        employeeId: {
            type: Number,
            default: null,
        },
        externAbsenceTypes: {
            type: Array,
            default: () => [],
        },
    },
    emits: ['refresh'],
    data() {
        return {
            formMode: null, // 'add' | 'edit' | null
            editingEntry: null,
            pendingDeleteEntry: null,
            kmValue: 0,
            kmEditing: false,
        }
    },
    computed: {
        ...mapGetters('permissions', ['isCorrectionMode']),
        dayTitle() {
            return formatDateWithWeekday(this.day.date)
        },
        subtitle() {
            if (this.day.entries.length) {
                return this.t('worktime', 'Erfasst: {hours}', { hours: this.hoursLabel(this.day.totalMinutes) })
            }
            return this.t('worktime', 'Tag ausgewählt')
        },
        scopeLabel() {
            const scope = this.day.absence?.scope ?? 1
            return scope === 0.5
                ? this.t('worktime', 'Halber Tag')
                : this.t('worktime', '{scope} Tage', { scope })
        },
        readonly() {
            // In HR correction mode the lock is bypassed (a reason is required on save).
            if (this.isCorrectionMode) {
                return false
            }
            return this.monthStatus === 'submitted' || this.monthStatus === 'approved'
        },
        lockedMessage() {
            if (this.monthStatus === 'approved') {
                return this.t('worktime', 'Monat genehmigt – gesperrt. Korrektur nur durch HR.')
            }
            return this.t('worktime', 'Eingereicht – Bearbeitung erst nach Genehmigung oder Ablehnung möglich.')
        },
        // Smart-Prefill (#340): Vorschlag fuer einen Folge-Eintrag.
        // Start = Ende des spaetesten Eintrags des Tages (null beim ersten Eintrag).
        prefillStart() {
            const entries = this.day.entries
            if (!entries.length) return null
            return entries.reduce((latest, e) => (e.endTime > latest ? e.endTime : latest), entries[0].endTime)
        },
        // Ende = aktuelle Uhrzeit nur wenn der Tag heute ist; an vergangenen Tagen
        // leer ('') lassen; beim ersten Eintrag kein Vorschlag (null → Standard).
        prefillEnd() {
            if (!this.day.entries.length) return null
            return this.day.date === getToday() ? getCurrentTime() : ''
        },
        // Tag ist "extern" (km-fähig). Autorität ist das Backend (day.externEligible,
        // kennt auch inaktive Extern-Projekte); die lokale Prüfung deckt frisch
        // erfasste Buchungen/Abwesenheiten vor dem nächsten Reload ab — sie sieht
        // aber nur die aktiven Projekte des Mitarbeiters.
        externEligible() {
            if (this.day.externEligible) return true
            const hasExternProject = this.day.entries.some(e => {
                const project = this.projects.find(p => p.id === e.projectId)
                return project && project.isExtern
            })
            if (hasExternProject) return true
            return !!this.day.absence && this.externAbsenceTypes.includes(this.day.absence.type)
        },
    },
    watch: {
        // Beim Wechsel des ausgewählten Tages offene Formulare schließen
        'day.date'() {
            this.closeForm()
            this.kmEditing = false
        },
        // Kilometerwert aus dem (neu geladenen) Tag übernehmen — aber nicht,
        // während der Wert gerade bearbeitet wird.
        day: {
            immediate: true,
            handler() {
                if (!this.kmEditing) {
                    this.kmValue = this.day.kilometers || 0
                }
            },
        },
    },
    methods: {
        ...mapActions('timeEntries', ['deleteTimeEntry']),
        hoursLabel(minutes) {
            return `${formatMinutes(minutes)} h`
        },
        projectName(projectId) {
            if (!projectId) return ''
            const project = this.projects.find(p => p.id === projectId)
            return project?.name || project?.displayName || ''
        },
        absenceColorClass: getAbsenceColorClass,
        startKmEdit() {
            this.kmValue = this.day.kilometers
            this.kmEditing = true
        },
        cancelKmEdit() {
            this.kmEditing = false
            this.kmValue = this.day.kilometers || 0
        },
        async saveKilometers() {
            if (this.readonly || !this.employeeId) return
            const km = Math.max(0, Math.round(this.kmValue || 0))
            if (km <= 0) return
            try {
                await DailyKmService.upsert(this.employeeId, this.day.date, km)
                this.kmEditing = false
                showSuccessMessage(this.t('worktime', 'Kilometer gespeichert'))
                this.$emit('refresh')
            } catch (error) {
                showErrorMessage(error.message || this.t('worktime', 'Fehler beim Speichern der Kilometer'))
            }
        },
        async confirmKmDelete() {
            if (this.readonly || !this.employeeId) return
            const confirmed = await confirmAction(
                this.t('worktime', 'Möchten Sie die erfassten Kilometer wirklich löschen?'),
                this.t('worktime', 'Kilometer löschen'),
                this.t('worktime', 'Löschen'),
                true,
            )
            if (!confirmed) return
            try {
                await DailyKmService.upsert(this.employeeId, this.day.date, 0)
                this.kmValue = 0
                showSuccessMessage(this.t('worktime', 'Kilometer gelöscht'))
                this.$emit('refresh')
            } catch (error) {
                showErrorMessage(error.message || this.t('worktime', 'Fehler beim Speichern der Kilometer'))
            }
        },
        startAdd() {
            this.editingEntry = null
            this.formMode = 'add'
        },
        startEdit(entry) {
            this.editingEntry = entry
            this.formMode = 'edit'
        },
        closeForm() {
            this.formMode = null
            this.editingEntry = null
        },
        onSaved() {
            this.closeForm()
            this.$emit('refresh')
        },
        async confirmDelete(entry) {
            // In HR correction mode, capture a mandatory reason instead of the plain confirm.
            if (this.isCorrectionMode) {
                this.pendingDeleteEntry = entry
                return
            }
            const confirmed = await confirmAction(
                this.t('worktime', 'Möchten Sie diesen Eintrag wirklich löschen?'),
                this.t('worktime', 'Eintrag löschen'),
                this.t('worktime', 'Löschen'),
                true,
            )
            if (!confirmed) return
            this.doDelete(entry, null)
        },
        onDeleteReasonConfirm(reason) {
            const entry = this.pendingDeleteEntry
            this.pendingDeleteEntry = null
            if (entry) {
                this.doDelete(entry, reason)
            }
        },
        async doDelete(entry, reason) {
            try {
                await this.deleteTimeEntry({ id: entry.id, reason })
                showSuccessMessage(this.t('worktime', 'Eintrag gelöscht'))
                this.$emit('refresh')
            } catch (error) {
                console.error('Failed to delete entry:', error)
                showErrorMessage(error.message || this.t('worktime', 'Fehler beim Löschen'))
            }
        },
        goToAbsence() {
            this.$router.push('/absences')
        },
    },
}
</script>

<style scoped>
.day-panel {
    display: flex;
    flex-direction: column;
}

.dp-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
}

.dp-sub {
    color: var(--color-main-text);
    font-size: 13px;
    margin: 2px 0 14px;
}

.dp-note {
    border-radius: var(--border-radius);
    padding: 12px 14px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 9px;
}

.dp-note.holiday {
    background: var(--color-background-hover);
    color: var(--wt-holiday);
}

.dp-note.vacation {
    background: var(--color-background-hover);
    color: var(--wt-vacation);
}

.dp-note.sick {
    background: var(--color-background-hover);
    color: var(--wt-sick);
}

.dp-note.other {
    background: var(--color-background-hover);
    color: var(--color-primary-element);
}

.dp-note.warning {
    background: var(--color-warning-element-light, #fdf6e3);
    border: 1px solid var(--color-warning, #c8932a);
    color: var(--color-warning-text, #8a6d00);
    align-items: flex-start;
}

.dp-open-abs {
    margin: -4px 0 12px;
}

.dp-entries {
    list-style: none;
    padding: 0;
    margin: 0 0 12px;
}

.dp-entry {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 0;
    border-bottom: 1px solid var(--color-border-light, var(--color-border));
}

.dp-entry:first-child {
    border-top: 1px solid var(--color-border-light, var(--color-border));
}

.dp-entry-main {
    flex: 1;
    min-width: 0;
}

.dp-entry-time {
    font-weight: 600;
    font-size: 14px;
    font-variant-numeric: tabular-nums;
}

.dp-entry-meta {
    color: var(--color-main-text);
    font-size: 13px;
    margin-top: 2px;
}

.dp-dot-sep {
    margin: 0 4px;
}

.dp-entry-desc {
    font-size: 12.5px;
    margin-top: 3px;
    color: var(--color-main-text);
}

.dp-entry-actions {
    display: flex;
    gap: 2px;
    flex-shrink: 0;
}

.dp-empty {
    color: var(--color-text-maxcontrast);
    font-style: italic;
    font-size: 13px;
    margin: 0 0 12px;
}

.dp-form {
    margin-top: 4px;
}

.dp-locked {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
    padding: 10px 12px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.dp-km {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--color-border);
}

.dp-km-label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    font-size: 0.9em;
}

.dp-km-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.dp-km-input {
    width: 100px;
    padding: 6px 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
}

.dp-km-unit {
    color: var(--color-text-maxcontrast);
}

/* Gespeicherte km als Zeile im Stil der Zeiteinträge */
.dp-km-entry {
    border-top: 1px solid var(--color-border-light, var(--color-border));
    align-items: center;
}
</style>
