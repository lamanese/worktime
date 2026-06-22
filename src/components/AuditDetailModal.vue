<template>
    <NcModal :name="t('worktime', 'Audit-Eintrag')" @close="$emit('close')">
        <div class="audit-detail">
            <h3>{{ t('worktime', 'Audit-Eintrag') }}</h3>

            <!-- Metadaten -->
            <dl class="audit-detail__meta">
                <div class="meta-row">
                    <dt>{{ t('worktime', 'Zeitpunkt') }}</dt>
                    <dd>{{ dateLabel }}</dd>
                </div>
                <div class="meta-row">
                    <dt>{{ t('worktime', 'Benutzer') }}</dt>
                    <dd>{{ entry.userId || '-' }}</dd>
                </div>
                <div class="meta-row">
                    <dt>{{ t('worktime', 'Aktion') }}</dt>
                    <dd>
                        <span class="action-badge" :class="'action-' + entry.action">{{ actionLabel }}</span>
                    </dd>
                </div>
                <div class="meta-row">
                    <dt>{{ t('worktime', 'Typ') }}</dt>
                    <dd>{{ entityLabel }}</dd>
                </div>
                <div class="meta-row">
                    <dt>{{ t('worktime', 'ID') }}</dt>
                    <dd>{{ entry.entityId || '-' }}</dd>
                </div>
            </dl>

            <!-- Vollständige Änderung -->
            <h4 class="audit-detail__subhead">{{ t('worktime', 'Änderung') }}</h4>

            <table v-if="entry.action === 'update' && diffRows.length" class="audit-detail__changes">
                <thead>
                    <tr>
                        <th>{{ t('worktime', 'Feld') }}</th>
                        <th>{{ t('worktime', 'Vorher') }}</th>
                        <th>{{ t('worktime', 'Nachher') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in diffRows" :key="d.key">
                        <td class="change-key">{{ d.key }}</td>
                        <td class="change-old">{{ d.old }}</td>
                        <td class="change-new">{{ d.new }}</td>
                    </tr>
                </tbody>
            </table>

            <dl v-else-if="valueRows.length" class="audit-detail__values">
                <div v-for="v in valueRows" :key="v.key" class="value-row">
                    <dt>{{ v.key }}</dt>
                    <dd>{{ v.value }}</dd>
                </div>
            </dl>

            <p v-else class="audit-detail__empty">{{ t('worktime', 'Keine Detaildaten vorhanden.') }}</p>

            <div class="audit-detail__actions">
                <NcButton type="primary" @click="$emit('close')">
                    {{ t('worktime', 'Schließen') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

// Interne/technische Felder, die für den Anwender kein Signal tragen.
const SKIP_KEYS = new Set(['id', 'employeeId', 'createdAt', 'updatedAt', 'userId'])

export default {
    name: 'AuditDetailModal',
    components: {
        NcModal,
        NcButton,
    },
    props: {
        entry: {
            type: Object,
            required: true,
        },
        actionLabel: {
            type: String,
            default: '',
        },
        entityLabel: {
            type: String,
            default: '',
        },
        dateLabel: {
            type: String,
            default: '',
        },
    },
    emits: ['close'],
    computed: {
        // Vollständiger Vorher/Nachher-Vergleich (ungekürzt), wie in der Tabelle,
        // aber ohne Abschneiden langer Werte.
        diffRows() {
            const old = this.entry.oldValues || {}
            const neu = this.entry.newValues || {}
            const allKeys = [...new Set([...Object.keys(old), ...Object.keys(neu)])]
                .filter(k => !SKIP_KEYS.has(k))
            return allKeys
                .filter(k => String(old[k] ?? '') !== String(neu[k] ?? ''))
                .map(k => ({ key: k, old: this.display(old[k]), new: this.display(neu[k]) }))
        },
        // Für create (newValues) bzw. delete (oldValues): vollständige Werteliste.
        valueRows() {
            const values = this.entry.action === 'delete'
                ? this.entry.oldValues
                : this.entry.newValues
            if (!values || typeof values !== 'object') return []
            return Object.entries(values)
                .filter(([k, v]) => !SKIP_KEYS.has(k) && v !== null && v !== '')
                .map(([k, v]) => ({ key: k, value: this.display(v) }))
        },
    },
    methods: {
        display(value) {
            if (value === null || value === undefined || value === '') return '—'
            if (typeof value === 'object') return JSON.stringify(value, null, 2)
            return String(value)
        },
    },
}
</script>

<style scoped>
.audit-detail {
    padding: 20px 24px 24px;
}

.audit-detail h3 {
    margin: 0 0 16px;
}

.audit-detail__subhead {
    margin: 20px 0 8px;
    font-size: 15px;
    font-weight: 600;
}

.audit-detail__meta {
    margin: 0;
    display: grid;
    gap: 8px;
}

.meta-row {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 12px;
    align-items: baseline;
}

.meta-row dt {
    color: var(--color-text-maxcontrast);
    font-weight: 500;
}

.meta-row dd {
    margin: 0;
    overflow-wrap: anywhere;
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

.audit-detail__changes {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.audit-detail__changes th {
    text-align: left;
    padding: 8px 10px;
    border-bottom: 2px solid var(--color-border-dark, var(--color-border));
    font-weight: 600;
    color: var(--color-text-maxcontrast);
}

.audit-detail__changes td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.change-key {
    font-weight: 600;
    color: var(--color-main-text);
}

.change-old {
    color: var(--color-error-text);
}

.change-new {
    color: var(--color-success-text);
}

.audit-detail__values {
    margin: 0;
    display: grid;
    gap: 8px;
}

.value-row {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 12px;
    align-items: baseline;
}

.value-row dt {
    font-weight: 600;
    color: var(--color-main-text);
    overflow-wrap: anywhere;
}

.value-row dd {
    margin: 0;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.audit-detail__empty {
    color: var(--color-text-maxcontrast);
}

.audit-detail__actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 24px;
}
</style>
