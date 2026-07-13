<template>
    <div class="audit-view">
        <div class="view-header">
            <h2>{{ t('zeitwerk', 'Audit-Log') }}</h2>
        </div>

        <div class="view-toolbar">
            <div class="view-header__controls">
                <NcSelect v-model="filterEmployee"
                    :options="employeeOptions"
                    :placeholder="t('zeitwerk', 'Alle Mitarbeiter')"
                    :clearable="true"
                    label="label" />
                <NcSelect v-model="filterAction"
                    :options="actionOptions"
                    :placeholder="t('zeitwerk', 'Alle Aktionen')"
                    :clearable="true"
                    label="label" />
                <NcSelect v-model="filterEntityType"
                    :options="entityTypeOptions"
                    :placeholder="t('zeitwerk', 'Alle Typen')"
                    :clearable="true"
                    label="label" />
                <NcDateTimePicker v-model="filterFrom"
                    type="date"
                    :format="'DD.MM.YYYY'"
                    :placeholder="t('zeitwerk', 'Von')" />
                <NcDateTimePicker v-model="filterTo"
                    type="date"
                    :format="'DD.MM.YYYY'"
                    :placeholder="t('zeitwerk', 'Bis')" />
                <NcButton type="secondary" @click="load">
                    {{ t('zeitwerk', 'Filtern') }}
                </NcButton>
            </div>
        </div>

        <div v-if="loading" class="loading-hint">
            {{ t('zeitwerk', 'Wird geladen…') }}
        </div>

        <NcEmptyContent v-else-if="entries.length === 0"
            :name="t('zeitwerk', 'Keine Einträge')">
            <template #icon>
                <ShieldIcon />
            </template>
        </NcEmptyContent>

        <div v-else class="audit-card">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>{{ t('zeitwerk', 'Zeitpunkt') }}</th>
                        <th>{{ t('zeitwerk', 'Benutzer') }}</th>
                        <th>{{ t('zeitwerk', 'Aktion') }}</th>
                        <th>{{ t('zeitwerk', 'Typ') }}</th>
                        <th>{{ t('zeitwerk', 'ID') }}</th>
                        <th>{{ t('zeitwerk', 'Änderung') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries"
                        :key="entry.id"
                        class="audit-row"
                        role="button"
                        tabindex="0"
                        :aria-label="t('zeitwerk', 'Details anzeigen')"
                        @click="openDetail(entry)"
                        @keydown.enter="openDetail(entry)"
                        @keydown.space.prevent="openDetail(entry)">
                        <td class="nowrap">{{ formatDateTime(entry.createdAt) }}</td>
                        <td>{{ entry.userId }}</td>
                        <td>
                            <span class="action-badge" :class="'action-' + entry.action">
                                {{ translateAction(entry.action) }}
                            </span>
                        </td>
                        <td>{{ translateEntityType(entry.entityType) }}</td>
                        <td>{{ entry.entityId || '-' }}</td>
                        <td class="diff-cell">
                            <template v-if="entry.action === 'update'">
                                <div v-for="d in updateDiff(entry)" :key="d.key" class="diff-item">
                                    <span class="diff-key">{{ d.key }}:</span>
                                    <span class="diff-old-val">{{ d.old }}</span>
                                    <span class="diff-arrow">→</span>
                                    <span class="diff-new-val">{{ d.new }}</span>
                                </div>
                            </template>
                            <span v-else-if="entry.action === 'delete' && entry.oldValues" class="diff-old">
                                {{ formatValues(entry.oldValues) }}
                            </span>
                            <span v-else-if="entry.action === 'create' && entry.newValues" class="diff-new">
                                {{ formatValues(entry.newValues) }}
                            </span>
                            <span v-else>-</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="entries.length >= 200" class="limit-hint">
            {{ t('zeitwerk', 'Es werden maximal 200 Einträge angezeigt. Bitte Filter verwenden um die Ergebnisse einzuschränken.') }}
        </p>

        <AuditDetailModal v-if="selectedEntry"
            :entry="selectedEntry"
            :action-label="translateAction(selectedEntry.action)"
            :entity-label="translateEntityType(selectedEntry.entityType)"
            :date-label="formatDateTime(selectedEntry.createdAt)"
            @close="selectedEntry = null" />
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcDateTimePicker from '@nextcloud/vue/dist/Components/NcDateTimePicker.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import AuditService from '../services/AuditService.js'
import AuditDetailModal from '../components/AuditDetailModal.vue'
import { formatDateISO, getLocale } from '../utils/dateUtils.js'
import { mapGetters, mapActions } from 'vuex'

export default {
    name: 'AuditView',
    components: {
        NcButton,
        NcSelect,
        NcDateTimePicker,
        NcEmptyContent,
        ShieldIcon,
        AuditDetailModal,
    },
    data() {
        return {
            entries: [],
            loading: false,
            filterEmployee: null,
            filterAction: null,
            filterEntityType: null,
            filterFrom: null,
            filterTo: null,
            selectedEntry: null,
        }
    },
    computed: {
        ...mapGetters('employees', ['employees']),
        employeeOptions() {
            return this.employees.map((e) => ({
                id: e.userId,
                label: e.displayName || (e.firstName + ' ' + e.lastName).trim() || e.userId,
            }))
        },
        actionOptions() {
            return [
                { id: 'create', label: this.t('zeitwerk', 'Erstellt') },
                { id: 'update', label: this.t('zeitwerk', 'Bearbeitet') },
                { id: 'delete', label: this.t('zeitwerk', 'Gelöscht') },
                { id: 'submit', label: this.t('zeitwerk', 'Eingereicht') },
                { id: 'approve', label: this.t('zeitwerk', 'Genehmigt') },
                { id: 'reject', label: this.t('zeitwerk', 'Abgelehnt') },
            ]
        },
        entityTypeOptions() {
            return [
                { id: 'time_entry', label: this.t('zeitwerk', 'Zeiteintrag') },
                { id: 'absence', label: this.t('zeitwerk', 'Abwesenheit') },
                { id: 'employee', label: this.t('zeitwerk', 'Mitarbeiter') },
                { id: 'project', label: this.t('zeitwerk', 'Projekt') },
                { id: 'setting', label: this.t('zeitwerk', 'Einstellung') },
            ]
        },
    },
    created() {
        this.fetchEmployees()
        this.load()
    },
    methods: {
        ...mapActions('employees', ['fetchEmployees']),
        openDetail(entry) {
            this.selectedEntry = entry
        },
        async load() {
            this.loading = true
            this.entries = await AuditService.getFiltered({
                action: this.filterAction?.id || '',
                entityType: this.filterEntityType?.id || '',
                from: this.filterFrom ? formatDateISO(this.filterFrom) : '',
                to: this.filterTo ? formatDateISO(this.filterTo) : '',
                userId: this.filterEmployee?.id || '',
            }) || []
            this.loading = false
        },
        translateAction(action) {
            const map = {
                create: this.t('zeitwerk', 'Erstellt'),
                update: this.t('zeitwerk', 'Bearbeitet'),
                delete: this.t('zeitwerk', 'Gelöscht'),
                submit: this.t('zeitwerk', 'Eingereicht'),
                approve: this.t('zeitwerk', 'Genehmigt'),
                reject: this.t('zeitwerk', 'Abgelehnt'),
            }
            return map[action] || action
        },
        translateEntityType(type) {
            const map = {
                time_entry: this.t('zeitwerk', 'Zeiteintrag'),
                absence: this.t('zeitwerk', 'Abwesenheit'),
                employee: this.t('zeitwerk', 'Mitarbeiter'),
                project: this.t('zeitwerk', 'Projekt'),
                setting: this.t('zeitwerk', 'Einstellung'),
            }
            return map[type] || type
        },
        formatDateTime(iso) {
            if (!iso) return '-'
            const d = new Date(iso)
            return d.toLocaleString(getLocale(), { dateStyle: 'short', timeStyle: 'short' })
        },
        updateDiff(entry) {
            const skip = new Set(['id', 'employeeId', 'createdAt', 'updatedAt', 'userId'])
            const old = entry.oldValues || {}
            const neu = entry.newValues || {}
            const allKeys = [...new Set([...Object.keys(old), ...Object.keys(neu)])].filter(k => !skip.has(k))
            return allKeys
                .filter(k => String(old[k] ?? '') !== String(neu[k] ?? ''))
                .map(k => ({ key: k, old: old[k] ?? '—', new: neu[k] ?? '—' }))
        },
        formatValues(values) {
            if (!values || typeof values !== 'object') return '-'
            const skip = new Set(['id', 'employeeId', 'createdAt', 'updatedAt', 'userId'])
            return Object.entries(values)
                .filter(([k, v]) => !skip.has(k) && v !== null && v !== '')
                .map(([k, v]) => `${k}: ${v}`)
                .join(', ') || '-'
        },
    },
}
</script>

<style scoped>
.audit-view {
    padding: 20px;
    padding-left: 50px;
    max-width: 1600px;
}

.audit-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border-dark, var(--color-border));
    border-radius: var(--border-radius-large, 12px);
    padding: 8px 16px;
    overflow-x: hidden;
}

.view-header {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}

.view-header h2 {
    margin: 0;
}

.view-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
}

.view-header__controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.audit-table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    font-size: 14px;
}

.audit-table th:nth-child(1) { width: 160px; } /* Zeitpunkt */
.audit-table th:nth-child(2) { width: 130px; } /* Benutzer */
.audit-table th:nth-child(3) { width: 110px; } /* Aktion */
.audit-table th:nth-child(4) { width: 120px; } /* Typ */
.audit-table th:nth-child(5) { width: 60px; }  /* ID */
/* Spalte 6 (Änderung) nimmt die Restbreite und bricht um */

.audit-table th {
    text-align: left;
    padding: 10px 12px;
    border-bottom: 2px solid var(--color-border-dark, var(--color-border));
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.audit-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
}

.audit-row {
    cursor: pointer;
}

.audit-row:hover {
    background: var(--color-background-hover);
}

.audit-row:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.nowrap {
    white-space: nowrap;
}

.action-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.82em;
    font-weight: 500;
}

.action-create { background: var(--wt-vacation, #4a9d63); color: #fff; }
.action-update { background: var(--color-primary-element, #2563eb); color: #fff; }
.action-delete { background: var(--wt-sick, #cc4b42); color: #fff; }
.action-submit { background: var(--wt-holiday, #c98b3a); color: #fff; }
.action-approve { background: var(--wt-vacation, #4a9d63); color: #fff; }
.action-reject { background: var(--wt-sick, #cc4b42); color: #fff; }

.diff-cell {
    font-size: 0.8em;
    font-family: monospace;
    white-space: normal;
    overflow-wrap: anywhere;
}

.diff-old {
    display: block;
    color: var(--color-error-text);
    text-decoration: line-through;
    white-space: normal;
}

.diff-new {
    display: block;
    color: var(--color-success-text);
    white-space: normal;
}

.diff-item {
    display: flex;
    align-items: baseline;
    gap: 4px;
    flex-wrap: wrap;
    margin-bottom: 2px;
}

.diff-key {
    font-weight: 600;
    color: var(--color-main-text);
    white-space: nowrap;
}

.diff-old-val {
    color: var(--color-error-text);
    text-decoration: line-through;
    overflow-wrap: anywhere;
    min-width: 0;
}

.diff-arrow {
    color: var(--color-text-maxcontrast);
}

.diff-new-val {
    color: var(--color-success-text);
    overflow-wrap: anywhere;
    min-width: 0;
}

.loading-hint {
    padding: 20px;
    color: var(--color-text-maxcontrast);
}

.limit-hint {
    margin-top: 12px;
    font-size: 0.85em;
    color: var(--color-text-maxcontrast);
}
</style>
