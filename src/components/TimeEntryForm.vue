<template>
    <div class="time-entry-form" :class="{ embedded }">
        <h3 v-if="!embedded">{{ isEdit ? t('worktime', 'Eintrag bearbeiten') : t('worktime', 'Neuer Eintrag') }}</h3>

        <div v-if="!embedded || isEdit" class="form-group">
            <label for="date">{{ t('worktime', 'Datum') }}</label>
            <NcDateTimePicker id="date"
                v-model="form.date"
                type="date"
                :format="'DD.MM.YYYY'" />
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="startTime">{{ t('worktime', 'Beginn') }}</label>
                <input id="startTime"
                    v-model="form.startTime"
                    type="time"
                    class="time-input"
                    @change="onTimeChange">
            </div>

            <div class="form-group">
                <label for="endTime">{{ t('worktime', 'Ende') }}</label>
                <input id="endTime"
                    v-model="form.endTime"
                    type="time"
                    class="time-input"
                    @change="onTimeChange">
            </div>
        </div>

        <div class="form-group">
            <label for="breakMinutes">{{ t('worktime', 'Pause (Minuten)') }}</label>
            <input id="breakMinutes"
                v-model.number="form.breakMinutes"
                type="number"
                min="0"
                class="break-input">
            <p v-if="requiredBreak > 0" class="break-hint">
                {{ t('worktime', 'Mindestpause: {minutes} min (§4 ArbZG)', { minutes: requiredBreak }) }} <InfoIcon>{{ t('worktime', 'Gesetzliche Pausenregelung: Ab 6 Stunden Arbeitszeit mindestens 30 Minuten, ab 9 Stunden mindestens 45 Minuten Pause.') }}</InfoIcon>
            </p>
        </div>

        <div class="form-group">
            <label for="project">{{ t('worktime', 'Projekt') }}<span v-if="projectRequired"> *</span></label>
            <NcSelect id="project"
                v-model="selectedProject"
                :options="projectOptions"
                :placeholder="t('worktime', 'Projekt auswählen')"
                :clearable="true"
                :class="{ 'input-error': projectMissing }" />
            <p v-if="projectMissing" class="field-hint field-hint--error">
                {{ t('worktime', 'Projekt ist erforderlich.') }}
            </p>
        </div>

        <div class="form-group">
            <label for="description">{{ t('worktime', 'Beschreibung') }}<span v-if="requireDescription"> *</span></label>
            <textarea id="description"
                v-model="form.description"
                :class="['description-input', { 'input-error': descriptionMissing }]"
                rows="2" />
            <p v-if="descriptionMissing" class="field-hint field-hint--error">
                {{ t('worktime', 'Beschreibung ist erforderlich.') }}
            </p>
        </div>

        <div v-if="calculatedWorkMinutes > 0" class="form-info">
            {{ t('worktime', 'Arbeitszeit: {hours}', { hours: formatMinutes(calculatedWorkMinutes) }) }}
        </div>

        <div class="form-actions">
            <NcButton type="tertiary" @click="cancel">
                {{ t('worktime', 'Abbrechen') }}
            </NcButton>
            <NcButton type="primary" :disabled="!isValid" @click="save">
                {{ t('worktime', 'Speichern') }}
            </NcButton>
        </div>

        <CorrectionReasonModal v-if="showReasonModal"
            @confirm="onReasonConfirm"
            @close="showReasonModal = false" />
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcDateTimePicker from '@nextcloud/vue/dist/Components/NcDateTimePicker.js'
import { mapGetters, mapActions } from 'vuex'
import { formatDateISO } from '../utils/dateUtils.js'
import { formatMinutesWithUnit, calculateWorkMinutes, suggestBreak as suggestBreakUtil } from '../utils/timeUtils.js'
import { showErrorMessage } from '../utils/errorHandler.js'
import SettingsService from '../services/SettingsService.js'
import InfoIcon from '../components/InfoIcon.vue'
import CorrectionReasonModal from '../components/CorrectionReasonModal.vue'

export default {
    name: 'TimeEntryForm',
    components: {
        InfoIcon,
        NcButton,
        NcSelect,
        NcDateTimePicker,
        CorrectionReasonModal,
    },
    props: {
        entry: {
            type: Object,
            default: null,
        },
        presetDate: {
            type: [String, Date],
            default: null,
        },
        embedded: {
            type: Boolean,
            default: false,
        },
        // Smart-Prefill (#340): bei einem Folge-Eintrag das Vorausfüllen der
        // Zeitfelder. `null` = kein Vorschlag (Standard verwenden). prefillEnd
        // kann gezielt '' sein (vergangener Tag → Ende leer lassen).
        prefillStart: {
            type: String,
            default: null,
        },
        prefillEnd: {
            type: String,
            default: null,
        },
    },
    data() {
        return {
            break6h: 30,
            break9h: 45,
            form: {
                date: new Date(),
                startTime: '08:00',
                endTime: '17:00',
                breakMinutes: 30,
                projectId: null,
                description: '',
            },
            showReasonModal: false,
            pendingData: null,
        }
    },
    computed: {
        ...mapGetters('permissions', ['isCorrectionMode', 'requireProject', 'requireDescription', 'allowDefaultProject', 'allowDefaultDescription']),
        ...mapGetters('projects', ['activeProjects']),
        ...mapGetters('employees', ['currentEmployee']),
        requiredBreak() {
            if (!this.form.startTime || !this.form.endTime) return 0
            const grossMinutes = calculateWorkMinutes(this.form.startTime, this.form.endTime, 0)
            return suggestBreakUtil(grossMinutes, this.break6h, this.break9h)
        },
        isEdit() {
            return !!this.entry
        },
        projectOptions() {
            return this.activeProjects.map(p => ({
                id: p.id,
                label: p.displayName || p.name,
            }))
        },
        selectedProject: {
            get() {
                return this.projectOptions.find(p => p.id === this.form.projectId) || null
            },
            set(value) {
                this.form.projectId = value?.id || null
            },
        },
        calculatedWorkMinutes() {
            if (!this.form.startTime || !this.form.endTime) return 0
            return calculateWorkMinutes(this.form.startTime, this.form.endTime, this.form.breakMinutes)
        },
        projectRequired() {
            // "Projekt erforderlich" only applies when the employee actually has a
            // selectable project (#329 follow-up): otherwise they could not book at all.
            return this.requireProject && this.projectOptions.length > 0
        },
        projectMissing() {
            return this.projectRequired && !this.form.projectId
        },
        descriptionMissing() {
            return this.requireDescription && !(this.form.description && this.form.description.trim())
        },
        isValid() {
            return this.form.date && this.form.startTime && this.form.endTime
                && this.calculatedWorkMinutes > 0
                && !this.projectMissing
                && !this.descriptionMissing
        },
    },
    watch: {
        entry: {
            immediate: true,
            handler(entry) {
                if (entry) {
                    this.form = {
                        date: new Date(entry.date),
                        startTime: entry.startTime,
                        endTime: entry.endTime,
                        breakMinutes: entry.breakMinutes,
                        projectId: entry.projectId,
                        description: entry.description || '',
                    }
                } else {
                    this.resetForm()
                }
            },
        },
        // Update form defaults when employee data is loaded
        currentEmployee: {
            handler(employee) {
                // Only update if we're creating a new entry (not editing)
                if (!this.entry && employee) {
                    this.resetForm()
                }
            },
        },
        // Die Projektliste lädt asynchron: Standard-Projekt nachträglich
        // vorauswählen, sobald sie da ist (nur bei neuem, noch leerem Eintrag).
        activeProjects() {
            if (!this.entry) {
                this.applyDefaultProject()
            }
        },
    },
    async created() {
        this.$store.dispatch('projects/fetchProjects')
        try {
            const [b6h, b9h] = await Promise.all([
                SettingsService.get('min_break_minutes_6h'),
                SettingsService.get('min_break_minutes_9h'),
            ])
            if (b6h !== undefined) this.break6h = parseInt(b6h, 10)
            if (b9h !== undefined) this.break9h = parseInt(b9h, 10)
            // Pause neu berechnen mit geladenen Settings
            this.onTimeChange()
        } catch (e) {
            // Fallback bleibt bei 30/45
        }
    },
    methods: {
        ...mapActions('timeEntries', ['createTimeEntry', 'updateTimeEntry']),
        formatMinutes(minutes) {
            return formatMinutesWithUnit(minutes)
        },
        resetForm() {
            const defaultStart = this.currentEmployee?.defaultStartTime || '08:00'
            const defaultEnd = this.currentEmployee?.defaultEndTime || '17:00'
            // Smart-Prefill (#340): bei einem Folge-Eintrag Start = Ende des
            // letzten Eintrags, Ende = aktuelle Uhrzeit (nur heute), sonst leer.
            // `null` bedeutet „kein Vorschlag" → Standardwert verwenden.
            this.form = {
                date: this.presetDate ? new Date(this.presetDate) : new Date(),
                startTime: this.prefillStart || defaultStart,
                endTime: this.prefillEnd !== null ? this.prefillEnd : defaultEnd,
                breakMinutes: 30,
                projectId: null,
                description: this.defaultDescriptionPrefill(),
            }
            this.applyDefaultProject()
            // Recalculate break based on default times
            this.$nextTick(() => {
                this.onTimeChange()
            })
        },
        // Persönliche Standard-Beschreibung (nur solange vom Admin freigegeben).
        defaultDescriptionPrefill() {
            return (this.allowDefaultDescription && this.currentEmployee?.defaultDescription) || ''
        },
        // Persönliches Standard-Projekt vorauswählen — nur wenn freigegeben und
        // das Projekt (noch) in der buchbaren Liste ist. Die Projektliste lädt
        // asynchron; der activeProjects-Watcher holt die Vorauswahl dann nach.
        applyDefaultProject() {
            if (!this.allowDefaultProject || this.form.projectId) return
            const defaultId = this.currentEmployee?.defaultProjectId
            if (defaultId && this.activeProjects.some(p => p.id === defaultId)) {
                this.form.projectId = defaultId
            }
        },
        onTimeChange() {
            // Automatisch die konfigurierte Mindestpause eintragen
            if (this.form.startTime && this.form.endTime) {
                const grossMinutes = calculateWorkMinutes(this.form.startTime, this.form.endTime, 0)
                this.form.breakMinutes = suggestBreakUtil(grossMinutes, this.break6h, this.break9h)
            }
        },
        cancel() {
            this.$emit('cancel')
        },
        save() {
            const data = {
                date: formatDateISO(this.form.date),
                startTime: this.form.startTime,
                endTime: this.form.endTime,
                breakMinutes: this.form.breakMinutes,
                projectId: this.form.projectId,
                description: this.form.description || null,
            }
            // In HR correction mode, capture a mandatory reason before saving.
            if (this.isCorrectionMode) {
                this.pendingData = data
                this.showReasonModal = true
                return
            }
            this.persist(data)
        },
        onReasonConfirm(reason) {
            const data = this.pendingData
            this.showReasonModal = false
            this.pendingData = null
            if (data) {
                this.persist({ ...data, reason })
            }
        },
        async persist(data) {
            try {
                if (this.isEdit) {
                    await this.updateTimeEntry({ id: this.entry.id, data })
                } else {
                    await this.createTimeEntry(data)
                }
                this.$emit('saved')
            } catch (error) {
                console.error('Failed to save time entry:', error)
                showErrorMessage(error.message || this.t('worktime', 'Fehler beim Speichern'))
            }
        },
    },
}
</script>

<style scoped>
.time-entry-form {
    padding: 16px;
}

.time-entry-form.embedded {
    padding: 0;
}

.time-entry-form h3 {
    margin: 0 0 16px 0;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 4px;
    font-weight: 500;
}

.form-row {
    display: flex;
    gap: 16px;
}

.form-row .form-group {
    flex: 1;
}

.time-input,
.break-input,
.description-input {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
}

.field-hint {
    margin: 4px 0 0 0;
    font-size: 0.85em;
    color: var(--color-text-maxcontrast);
}

.field-hint--error {
    color: var(--color-error, #dc2626);
}

.input-error,
.input-error :deep(.vs__dropdown-toggle) {
    border-color: var(--color-error, #dc2626) !important;
}

.break-input {
    width: 6.5rem;
}

.break-hint {
    margin-top: 4px;
    font-size: 0.85em;
    color: var(--color-text-maxcontrast);
}



.form-info {
    margin: 16px 0;
    padding: 8px 12px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    font-weight: 500;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 16px;
}
</style>
