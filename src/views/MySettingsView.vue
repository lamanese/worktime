<template>
    <div class="my-settings-view">
        <div class="view-header">
            <h2>{{ t('zeitwerk', 'Meine Einstellungen') }}</h2>
        </div>

        <NcSettingsSection :name="t('zeitwerk', 'Standard-Arbeitszeiten')"
            :description="t('zeitwerk', 'Diese Zeiten werden beim Anlegen neuer Zeiteinträge vorausgefüllt.')">

            <div class="settings-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="defaultStartTime">{{ t('zeitwerk', 'Arbeitsbeginn') }}</label>
                        <input id="defaultStartTime"
                            v-model="form.defaultStartTime"
                            type="time"
                            class="time-input"
                            :placeholder="t('zeitwerk', 'z.B. 08:00')"
                            @change="saveWorkTimes">
                    </div>

                    <div class="form-group">
                        <label for="defaultEndTime">{{ t('zeitwerk', 'Arbeitsende') }}</label>
                        <input id="defaultEndTime"
                            v-model="form.defaultEndTime"
                            type="time"
                            class="time-input"
                            :placeholder="t('zeitwerk', 'z.B. 17:00')"
                            @change="saveWorkTimes">
                    </div>

                    <div class="save-indicator">
                        <NcLoadingIcon v-if="savingWorkTimes" :size="20" />
                        <span v-if="workTimesSaved" class="saved-hint">{{ t('zeitwerk', 'Gespeichert') }}</span>
                    </div>
                </div>

                <p class="hint">
                    {{ t('zeitwerk', 'Leer lassen für Standardwerte (08:00 - 17:00).') }}
                </p>
            </div>
        </NcSettingsSection>

        <NcSettingsSection v-if="allowDefaultProject || allowDefaultDescription"
            :name="t('zeitwerk', 'Standard-Vorgaben für Zeiteinträge')"
            :description="t('zeitwerk', 'Diese Werte werden beim Anlegen neuer Zeiteinträge vorausgefüllt und lassen sich dort jederzeit ändern.')">

            <div class="settings-form">
                <div v-if="allowDefaultProject" class="form-group">
                    <label for="defaultProject">{{ t('zeitwerk', 'Standard-Projekt') }}</label>
                    <div class="visibility-row">
                        <NcSelect id="defaultProject"
                            v-model="selectedDefaultProject"
                            :options="projectOptions"
                            :clearable="true"
                            :placeholder="t('zeitwerk', 'Kein Standard-Projekt')"
                            :disabled="savingDefaultProject"
                            class="visibility-select"
                            @input="saveDefaultProject" />
                        <NcLoadingIcon v-if="savingDefaultProject" :size="20" />
                        <span v-if="defaultProjectSaved" class="saved-hint">{{ t('zeitwerk', 'Gespeichert') }}</span>
                    </div>
                </div>

                <div v-if="allowDefaultDescription" class="form-group">
                    <label for="defaultDescription">{{ t('zeitwerk', 'Standard-Beschreibung') }}</label>
                    <div class="visibility-row">
                        <input id="defaultDescription"
                            v-model="form.defaultDescription"
                            type="text"
                            maxlength="500"
                            class="description-input"
                            :placeholder="t('zeitwerk', 'z.B. Support und Wartung')"
                            @change="saveDefaultDescription">
                        <NcLoadingIcon v-if="savingDefaultDescription" :size="20" />
                        <span v-if="defaultDescriptionSaved" class="saved-hint">{{ t('zeitwerk', 'Gespeichert') }}</span>
                    </div>
                    <p class="hint">
                        {{ t('zeitwerk', 'Leer lassen, um keine Beschreibung vorauszufüllen.') }}
                    </p>
                </div>
            </div>
        </NcSettingsSection>

        <NcSettingsSection :name="t('zeitwerk', 'Datenschutz')"
            :description="t('zeitwerk', 'Legen Sie fest, wer Ihre Abwesenheiten in der Abwesenheitsübersicht sehen kann. Vorgesetzte und HR sehen Ihre Abwesenheiten immer.')">

            <div class="settings-form">
                <div class="form-group">
                    <label for="absenceVisibility">{{ t('zeitwerk', 'Abwesenheiten sichtbar für') }}</label>
                    <div class="visibility-row">
                        <NcSelect id="absenceVisibility"
                            v-model="selectedVisibility"
                            :options="visibilityOptions"
                            :clearable="false"
                            :disabled="savingVisibility"
                            class="visibility-select"
                            @input="saveVisibility" />
                        <NcLoadingIcon v-if="savingVisibility" :size="20" />
                        <span v-if="visibilitySaved" class="saved-hint">{{ t('zeitwerk', 'Gespeichert') }}</span>
                    </div>
                </div>

                <div v-if="form.absenceVisibility !== 'none'" class="form-group">
                    <label for="absenceDetail">{{ t('zeitwerk', 'Detailgrad') }} <InfoIcon>{{ t('zeitwerk', 'Legt fest, ob Kollegen in der Abwesenheitsübersicht Ihren Abwesenheitsgrund sehen (z.B. Urlaub) oder nur Abwesend. Vorgesetzte und HR sehen immer den Grund.') }}</InfoIcon></label>
                    <div class="visibility-row">
                        <NcSelect id="absenceDetail"
                            v-model="selectedDetail"
                            :options="detailOptions"
                            :clearable="false"
                            :disabled="savingDetail"
                            class="visibility-select"
                            @input="saveDetail" />
                        <NcLoadingIcon v-if="savingDetail" :size="20" />
                        <span v-if="detailSaved" class="saved-hint">{{ t('zeitwerk', 'Gespeichert') }}</span>
                    </div>
                </div>
            </div>
        </NcSettingsSection>
    </div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcSettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import { mapGetters, mapActions } from 'vuex'
import { showError } from '@nextcloud/dialogs'
import InfoIcon from '../components/InfoIcon.vue'

export default {
    name: 'MySettingsView',
    components: {
        InfoIcon,
        NcLoadingIcon,
        NcSettingsSection,
        NcSelect,
    },
    data() {
        return {
            form: {
                defaultStartTime: '',
                defaultEndTime: '',
                defaultProjectId: null,
                defaultDescription: '',
                absenceVisibility: 'none',
                absenceDetail: 'hidden',
            },
            originalValues: {
                defaultStartTime: '',
                defaultEndTime: '',
                defaultProjectId: null,
                defaultDescription: '',
                absenceVisibility: 'none',
                absenceDetail: 'hidden',
            },
            savingWorkTimes: false,
            workTimesSaved: false,
            savingDefaultProject: false,
            defaultProjectSaved: false,
            savingDefaultDescription: false,
            defaultDescriptionSaved: false,
            savingVisibility: false,
            visibilitySaved: false,
            savingDetail: false,
            detailSaved: false,
        }
    },
    computed: {
        ...mapGetters('employees', ['currentEmployee']),
        ...mapGetters('permissions', ['allowDefaultProject', 'allowDefaultDescription']),
        ...mapGetters('projects', ['activeProjects']),
        projectOptions() {
            return this.activeProjects.map(p => ({
                id: p.id,
                label: p.displayName || p.name,
            }))
        },
        selectedDefaultProject: {
            get() {
                return this.projectOptions.find(o => o.id === this.form.defaultProjectId) || null
            },
            set(value) {
                this.form.defaultProjectId = value?.id || null
            },
        },
        visibilityOptions() {
            return [
                { id: 'none', label: this.t('zeitwerk', 'Niemand') },
                { id: 'team', label: this.t('zeitwerk', 'Mein Team') },
                { id: 'all', label: this.t('zeitwerk', 'Alle Mitarbeiter') },
            ]
        },
        detailOptions() {
            return [
                { id: 'hidden', label: this.t('zeitwerk', 'Nur „Abwesend" anzeigen') },
                { id: 'detailed', label: this.t('zeitwerk', 'Grund anzeigen (Urlaub, Fortbildung, ...)') },
            ]
        },
        selectedVisibility: {
            get() {
                return this.visibilityOptions.find(o => o.id === this.form.absenceVisibility) || null
            },
            set(value) {
                this.form.absenceVisibility = value?.id || 'none'
            },
        },
        selectedDetail: {
            get() {
                return this.detailOptions.find(o => o.id === this.form.absenceDetail) || null
            },
            set(value) {
                this.form.absenceDetail = value?.id || 'hidden'
            },
        },
    },
    watch: {
        currentEmployee: {
            immediate: true,
            handler(employee) {
                if (employee) {
                    this.loadFromEmployee(employee)
                }
            },
        },
    },
    created() {
        if (this.allowDefaultProject) {
            this.$store.dispatch('projects/fetchProjects')
        }
    },
    methods: {
        ...mapActions('employees', ['updateMyDefaults']),
        loadFromEmployee(employee) {
            this.form.defaultStartTime = employee.defaultStartTime || '08:00'
            this.form.defaultEndTime = employee.defaultEndTime || '17:00'
            this.form.defaultProjectId = employee.defaultProjectId || null
            this.form.defaultDescription = employee.defaultDescription || ''
            this.form.absenceVisibility = employee.absenceVisibility || 'none'
            this.form.absenceDetail = employee.absenceDetail || 'hidden'
            this.originalValues.defaultStartTime = this.form.defaultStartTime
            this.originalValues.defaultEndTime = this.form.defaultEndTime
            this.originalValues.defaultProjectId = this.form.defaultProjectId
            this.originalValues.defaultDescription = this.form.defaultDescription
            this.originalValues.absenceVisibility = this.form.absenceVisibility
            this.originalValues.absenceDetail = this.form.absenceDetail
        },
        async saveDefaultProject() {
            this.savingDefaultProject = true
            this.defaultProjectSaved = false
            try {
                // 0 = Standard-Projekt entfernen (null hiesse "unverändert")
                await this.updateMyDefaults({
                    defaultProjectId: this.form.defaultProjectId || 0,
                })
                this.originalValues.defaultProjectId = this.form.defaultProjectId
                this.defaultProjectSaved = true
                setTimeout(() => { this.defaultProjectSaved = false }, 2000)
            } catch (error) {
                console.error('Failed to save default project:', error)
                showError(t('zeitwerk', 'Fehler beim Speichern'))
                this.form.defaultProjectId = this.originalValues.defaultProjectId
            } finally {
                this.savingDefaultProject = false
            }
        },
        async saveDefaultDescription() {
            this.savingDefaultDescription = true
            this.defaultDescriptionSaved = false
            try {
                // '' = Standard-Beschreibung entfernen (null hiesse "unverändert")
                await this.updateMyDefaults({
                    defaultDescription: this.form.defaultDescription || '',
                })
                this.originalValues.defaultDescription = this.form.defaultDescription
                this.defaultDescriptionSaved = true
                setTimeout(() => { this.defaultDescriptionSaved = false }, 2000)
            } catch (error) {
                console.error('Failed to save default description:', error)
                showError(t('zeitwerk', 'Fehler beim Speichern'))
                this.form.defaultDescription = this.originalValues.defaultDescription
            } finally {
                this.savingDefaultDescription = false
            }
        },
        async saveWorkTimes() {
            this.savingWorkTimes = true
            this.workTimesSaved = false
            try {
                // '' = Wert löschen (null hiesse "unverändert")
                await this.updateMyDefaults({
                    defaultStartTime: this.form.defaultStartTime || '',
                    defaultEndTime: this.form.defaultEndTime || '',
                })
                this.originalValues.defaultStartTime = this.form.defaultStartTime
                this.originalValues.defaultEndTime = this.form.defaultEndTime
                this.workTimesSaved = true
                setTimeout(() => { this.workTimesSaved = false }, 2000)
            } catch (error) {
                console.error('Failed to save work times:', error)
                showError(t('zeitwerk', 'Fehler beim Speichern'))
            } finally {
                this.savingWorkTimes = false
            }
        },
        async saveVisibility() {
            this.savingVisibility = true
            this.visibilitySaved = false
            try {
                await this.updateMyDefaults({
                    absenceVisibility: this.form.absenceVisibility,
                })
                this.originalValues.absenceVisibility = this.form.absenceVisibility
                this.visibilitySaved = true
                setTimeout(() => { this.visibilitySaved = false }, 2000)
            } catch (error) {
                console.error('Failed to save visibility:', error)
                showError(t('zeitwerk', 'Fehler beim Speichern'))
                this.form.absenceVisibility = this.originalValues.absenceVisibility
            } finally {
                this.savingVisibility = false
            }
        },
        async saveDetail() {
            this.savingDetail = true
            this.detailSaved = false
            try {
                await this.updateMyDefaults({
                    absenceDetail: this.form.absenceDetail,
                })
                this.originalValues.absenceDetail = this.form.absenceDetail
                this.detailSaved = true
                setTimeout(() => { this.detailSaved = false }, 2000)
            } catch (error) {
                console.error('Failed to save detail:', error)
                showError(t('zeitwerk', 'Fehler beim Speichern'))
                this.form.absenceDetail = this.originalValues.absenceDetail
            } finally {
                this.savingDetail = false
            }
        },
    },
}
</script>

<style scoped>
.my-settings-view {
    padding: 20px;
    padding-left: 50px;
    max-width: 600px;
}

.view-header {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}

.my-settings-view h2 {
    margin: 0;
}

.settings-form {
    margin-top: 8px;
}

.form-row {
    display: flex;
    gap: 24px;
    align-items: flex-end;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 4px;
    font-weight: 500;
}

.time-input {
    width: 8rem;
    padding: 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
}

.hint {
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
    margin: 0 0 16px 0;
}

.save-indicator {
    display: flex;
    align-items: center;
    margin-bottom: 16px;
    min-height: 36px;
}

.visibility-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.visibility-select {
    min-width: 16rem;
}

.description-input {
    width: 24rem;
    max-width: 100%;
    padding: 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
}

.saved-hint {
    color: var(--color-success);
    font-size: 13px;
}


</style>
