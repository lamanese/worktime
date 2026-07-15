<template>
    <div class="project-form">
        <div class="project-form-header">
            <h3>{{ isEdit ? t('zeitwerk', 'Projekt bearbeiten') : t('zeitwerk', 'Neues Projekt') }}</h3>
            <NcCheckboxRadioSwitch :checked.sync="form.isActive" type="switch">
                {{ t('zeitwerk', 'Aktiv') }}
            </NcCheckboxRadioSwitch>
        </div>
        <p class="header-hint">{{ t('zeitwerk', 'Inaktive Projekte stehen nicht mehr zur Auswahl.') }}</p>

        <div class="form-row">
            <div class="form-group">
                <label for="projectName">{{ t('zeitwerk', 'Name') }} *</label>
                <input id="projectName"
                    v-model="form.name"
                    type="text"
                    class="input-field"
                    required>
            </div>
            <div class="form-group">
                <label for="projectCode">{{ t('zeitwerk', 'Projektcode') }}</label>
                <input id="projectCode"
                    v-model="form.code"
                    type="text"
                    class="input-field"
                    :placeholder="t('zeitwerk', 'z.B. PRJ-001')">
            </div>
        </div>

        <div class="form-group">
            <label for="projectCustomer">{{ t('zeitwerk', 'Kunde') }}</label>
            <input id="projectCustomer"
                v-model="form.customer"
                type="text"
                class="input-field"
                :placeholder="t('zeitwerk', 'Optional, z.B. für die Auswertung')">
        </div>

        <div class="form-group">
            <label for="projectDescription">{{ t('zeitwerk', 'Beschreibung') }}</label>
            <textarea id="projectDescription"
                v-model="form.description"
                class="input-field textarea-field"
                rows="3" />
        </div>

        <div class="form-group">
            <label for="projectColor">{{ t('zeitwerk', 'Farbe') }}</label>
            <div class="color-picker-row">
                <input id="projectColor"
                    v-model="form.color"
                    type="color"
                    class="color-input">
                <NcButton v-if="form.color"
                    type="tertiary"
                    @click="form.color = null">
                    {{ t('zeitwerk', 'Zurücksetzen') }}
                </NcButton>
            </div>
        </div>

        <div class="form-group">
            <label class="form-group-label">{{ t('zeitwerk', 'Aussendienst & Extern') }}</label>
            <NcCheckboxRadioSwitch :checked.sync="form.isFieldWork" type="switch">
                {{ t('zeitwerk', 'Aussendienst (Spesen)') }}
            </NcCheckboxRadioSwitch>
            <p class="header-hint">{{ t('zeitwerk', 'Tage mit Buchung auf diesem Projekt lösen ab der eingestellten Stundenschwelle die Spesen-Pauschale aus.') }}</p>
            <NcCheckboxRadioSwitch :checked.sync="form.isExtern" type="switch">
                {{ t('zeitwerk', 'Extern (Kilometer)') }}
            </NcCheckboxRadioSwitch>
            <p class="header-hint">{{ t('zeitwerk', 'An Tagen mit Buchung auf diesem Projekt können gefahrene Kilometer erfasst werden.') }}</p>
        </div>

        <div class="form-group">
            <label class="form-group-label">{{ t('zeitwerk', 'Buchungsberechtigung') }}</label>
            <NcCheckboxRadioSwitch :checked.sync="bookingMode"
                value="all"
                name="project-booking"
                type="radio">
                {{ t('zeitwerk', 'Alle Mitarbeitenden') }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch :checked.sync="bookingMode"
                value="selected"
                name="project-booking"
                type="radio">
                {{ t('zeitwerk', 'Nur ausgewählte Mitarbeitende') }}
            </NcCheckboxRadioSwitch>

            <div v-if="bookingMode === 'selected'" class="member-select">
                <NcSelect id="projectMembers"
                    v-model="selectedMembers"
                    :options="employeeOptions"
                    :multiple="true"
                    :close-on-select="false"
                    :placeholder="t('zeitwerk', 'Mitarbeitende auswählen')" />
            </div>
        </div>

        <div class="form-actions">
            <NcButton type="tertiary" @click="cancel">
                {{ t('zeitwerk', 'Abbrechen') }}
            </NcButton>
            <NcButton type="primary" :disabled="!isValid" @click="save">
                {{ t('zeitwerk', 'Speichern') }}
            </NcButton>
        </div>
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import { mapActions, mapGetters } from 'vuex'
import { showSuccessMessage, showErrorMessage } from '../utils/errorHandler.js'

export default {
    name: 'ProjectForm',
    components: {
        NcButton,
        NcCheckboxRadioSwitch,
        NcSelect,
    },
    props: {
        project: {
            type: Object,
            default: null,
        },
    },
    data() {
        return {
            form: {
                name: '',
                code: null,
                description: null,
                customer: null,
                color: null,
                isActive: true,
                isBillable: true,
                allEmployees: true,
                isFieldWork: false,
                isExtern: false,
                memberIds: [],
            },
        }
    },
    computed: {
        ...mapGetters('employees', ['employees']),
        isEdit() {
            return !!this.project
        },
        isValid() {
            return this.form.name.trim().length > 0
        },
        employeeOptions() {
            return this.employees.map(e => ({
                id: e.id,
                label: `${e.firstName} ${e.lastName}`.trim() || e.userId,
            }))
        },
        selectedMembers: {
            get() {
                return this.employeeOptions.filter(o => this.form.memberIds.includes(o.id))
            },
            set(value) {
                this.form.memberIds = (value || []).map(o => o.id)
            },
        },
        bookingMode: {
            get() {
                return this.form.allEmployees ? 'all' : 'selected'
            },
            set(value) {
                this.form.allEmployees = value === 'all'
            },
        },
    },
    watch: {
        project: {
            immediate: true,
            handler(project) {
                if (project) {
                    this.form = {
                        name: project.name || '',
                        code: project.code || null,
                        description: project.description || null,
                        customer: project.customer || null,
                        color: project.color || null,
                        isActive: project.isActive ?? true,
                        isBillable: project.isBillable ?? true,
                        allEmployees: project.allEmployees ?? true,
                        isFieldWork: project.isFieldWork ?? false,
                        isExtern: project.isExtern ?? false,
                        memberIds: Array.isArray(project.memberIds) ? [...project.memberIds] : [],
                    }
                } else {
                    this.resetForm()
                }
            },
        },
    },
    created() {
        // Employees are needed for the assignment multi-select.
        if (!this.employees.length) {
            this.$store.dispatch('employees/fetchEmployees')
        }
    },
    methods: {
        ...mapActions('projects', ['createProject', 'updateProject']),
        resetForm() {
            this.form = {
                name: '',
                code: null,
                description: null,
                customer: null,
                color: null,
                isActive: true,
                isBillable: true,
                allEmployees: true,
                isFieldWork: false,
                isExtern: false,
                memberIds: [],
            }
        },
        cancel() {
            this.$emit('cancel')
        },
        async save() {
            try {
                const data = {
                    name: this.form.name.trim(),
                    code: this.form.code?.trim() || null,
                    description: this.form.description?.trim() || null,
                    customer: this.form.customer?.trim() || null,
                    color: this.form.color || null,
                    isActive: this.form.isActive,
                    isBillable: this.form.isBillable,
                    allEmployees: this.form.allEmployees,
                    isFieldWork: this.form.isFieldWork,
                    isExtern: this.form.isExtern,
                    memberIds: this.form.allEmployees ? [] : this.form.memberIds,
                }

                if (this.isEdit) {
                    await this.updateProject({ id: this.project.id, data })
                    showSuccessMessage(this.t('zeitwerk', 'Projekt aktualisiert'))
                } else {
                    await this.createProject(data)
                    showSuccessMessage(this.t('zeitwerk', 'Projekt erstellt'))
                }

                this.$emit('saved')
            } catch (error) {
                showErrorMessage(error.message || this.t('zeitwerk', 'Fehler beim Speichern'))
            }
        },
    },
}
</script>

<style scoped>
.project-form {
    padding: 20px;
}

.project-form-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 4px;
}

.project-form-header h3 {
    margin: 0;
}

.header-hint {
    margin: 0 0 20px;
    font-size: 0.85em;
    color: var(--color-text-maxcontrast);
}

.form-group-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
}

.member-select {
    margin-top: 8px;
    padding-left: 28px;
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
    align-items: flex-start;
}

.form-row .form-group {
    flex: 1;
}

.input-field {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
}

.textarea-field {
    resize: vertical;
    font-family: inherit;
}

.color-picker-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.color-input {
    width: 48px;
    height: 36px;
    padding: 2px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    cursor: pointer;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 16px;
}
</style>
