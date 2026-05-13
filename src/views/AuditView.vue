<template>
    <div class="audit-view">
        <div class="view-header">
            <h2>{{ t('worktime', 'Audit-Log') }}</h2>
            <div class="view-header__controls">
                <NcSelect v-model="filterAction"
                    :options="actionOptions"
                    :placeholder="t('worktime', 'Alle Aktionen')"
                    :clearable="true"
                    label="label" />
                <NcSelect v-model="filterEntityType"
                    :options="entityTypeOptions"
                    :placeholder="t('worktime', 'Alle Typen')"
                    :clearable="true"
                    label="label" />
                <NcDateTimePicker v-model="filterFrom"
                    type="date"
                    :format="'DD.MM.YYYY'"
                    :placeholder="t('worktime', 'Von')" />
                <NcDateTimePicker v-model="filterTo"
                    type="date"
                    :format="'DD.MM.YYYY'"
                    :placeholder="t('worktime', 'Bis')" />
                <NcButton type="secondary" @click="load">
                    {{ t('worktime', 'Filtern') }}
                </NcButton>
            </div>
        </div>

        <div v-if="loading" class="loading-hint">
            {{ t('worktime', 'Wird geladen…') }}
        </div>

        <NcEmptyContent v-else-if="entries.length === 0"
            :name="t('worktime', 'Keine Einträge')">
            <template #icon>
                <ShieldIcon />
            </template>
        </NcEmptyContent>

        <table v-else class="audit-table">
            <thead>
                <tr>
                    <th>{{ t('worktime', 'Zeitpunkt') }}</th>
                    <th>{{ t('worktime', 'Benutzer') }}</th>
                    <th>{{ t('worktime', 'Aktion') }}</th>
                    <th>{{ t('worktime', 'Typ') }}</th>
                    <th>{{ t('worktime', 'ID') }}</th>
                    <th>{{ t('worktime', 'Änderung') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in entries" :key="entry.id" class="audit-row">
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
                        <span v-if="entry.action === 'delete' && entry.oldValues" class="diff-old">
                            {{ formatValues(entry.oldValues) }}
                        </span>
                        <span v-else-if="entry.action === 'create' && entry.newValues" class="diff-new">
                            {{ formatValues(entry.newValues) }}
                        </span>
                        <template v-else-if="entry.oldValues || entry.newValues">
                            <span v-if="entry.oldValues" class="diff-old">{{ formatValues(entry.oldValues) }}</span>
                            <span v-if="entry.newValues" class="diff-new">{{ formatValues(entry.newValues) }}</span>
                        </template>
                        <span v-else>-</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="entries.length >= 200" class="limit-hint">
            {{ t('worktime', 'Es werden maximal 200 Einträge angezeigt. Bitte Filter verwenden um die Ergebnisse einzuschränken.') }}
        </p>
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcDateTimePicker from '@nextcloud/vue/dist/Components/NcDateTimePicker.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import AuditService from '../services/AuditService.js'
import { formatDateISO } from '../utils/dateUtils.js'

export default {
    name: 'AuditView',
    components: {
        NcButton,
        NcSelect,
        NcDateTimePicker,
        NcEmptyContent,
        ShieldIcon,
    },
    data() {
        return {
            entries: [],
            loading: false,
            filterAction: null,
            filterEntityType: null,
            filterFrom: null,
            filterTo: null,
        }
    },
    computed: {
        actionOptions() {
            return [
                { id: 'create', label: this.t('worktime', 'Erstellt') },
                { id: 'update', label: this.t('worktime', 'Bearbeitet') },
                { id: 'delete', label: this.t('worktime', 'Gelöscht') },
                { id: 'submit', label: this.t('worktime', 'Eingereicht') },
                { id: 'approve', label: this.t('worktime', 'Genehmigt') },
                { id: 'reject', label: this.t('worktime', 'Abgelehnt') },
            ]
        },
        entityTypeOptions() {
            return [
                { id: 'time_entry', label: this.t('worktime', 'Zeiteintrag') },
                { id: 'absence', label: this.t('worktime', 'Abwesenheit') },
                { id: 'employee', label: this.t('worktime', 'Mitarbeiter') },
                { id: 'project', label: this.t('worktime', 'Projekt') },
                { id: 'setting', label: this.t('worktime', 'Einstellung') },
            ]
        },
    },
    created() {
        this.load()
    },
    methods: {
        async load() {
            this.loading = true
            this.entries = await AuditService.getFiltered({
                action: this.filterAction?.id || '',
                entityType: this.filterEntityType?.id || '',
                from: this.filterFrom ? formatDateISO(this.filterFrom) : '',
                to: this.filterTo ? formatDateISO(this.filterTo) : '',
            }) || []
            this.loading = false
        },
        translateAction(action) {
            const map = {
                create: this.t('worktime', 'Erstellt'),
                update: this.t('worktime', 'Bearbeitet'),
                delete: this.t('worktime', 'Gelöscht'),
                submit: this.t('worktime', 'Eingereicht'),
                approve: this.t('worktime', 'Genehmigt'),
                reject: this.t('worktime', 'Abgelehnt'),
            }
            return map[action] || action
        },
        translateEntityType(type) {
            const map = {
                time_entry: this.t('worktime', 'Zeiteintrag'),
                absence: this.t('worktime', 'Abwesenheit'),
                employee: this.t('worktime', 'Mitarbeiter'),
                project: this.t('worktime', 'Projekt'),
                setting: this.t('worktime', 'Einstellung'),
            }
            return map[type] || type
        },
        formatDateTime(iso) {
            if (!iso) return '-'
            const d = new Date(iso)
            const locale = document.documentElement.lang || 'de-DE'
            return d.toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' })
        },
        formatValues(values) {
            if (!values || typeof values !== 'object') return '-'
            return Object.entries(values)
                .filter(([, v]) => v !== null && v !== '')
                .map(([k, v]) => `${k}: ${v}`)
                .join(', ')
        },
    },
}
</script>

<style scoped>
.audit-view {
    padding: 20px;
    max-width: 1400px;
}

.view-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.view-header h2 {
    margin: 0;
}

.view-header__controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.audit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.audit-table th {
    text-align: left;
    padding: 10px 12px;
    border-bottom: 2px solid var(--color-border);
    font-weight: 600;
    white-space: nowrap;
}

.audit-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
}

.audit-row:hover {
    background: var(--color-background-hover);
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

.action-create { background: #16a34a; color: #fff; }
.action-update { background: #2563eb; color: #fff; }
.action-delete { background: #dc2626; color: #fff; }
.action-submit { background: #d97706; color: #fff; }
.action-approve { background: #16a34a; color: #fff; }
.action-reject { background: #dc2626; color: #fff; }

.diff-cell {
    max-width: 500px;
    font-size: 0.85em;
    font-family: monospace;
}

.diff-old {
    display: block;
    color: var(--color-error);
    text-decoration: line-through;
    opacity: 0.8;
}

.diff-new {
    display: block;
    color: var(--color-success);
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
