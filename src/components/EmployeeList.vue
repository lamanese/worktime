<template>
    <div class="employee-list">
        <table v-if="employees.length > 0" class="employees-table">
            <thead>
                <tr>
                    <th>{{ t('zeitwerk', 'Name') }}</th>
                    <th>{{ t('zeitwerk', 'Personalnr.') }}</th>
                    <th class="text-right">{{ t('zeitwerk', 'Wochenstd.') }}</th>
                    <th class="text-right">{{ t('zeitwerk', 'Urlaubstage') }}</th>
                    <th>{{ t('zeitwerk', 'Bundesland') }}</th>
                    <th>{{ t('zeitwerk', 'Status') }}</th>
                    <th class="actions-col">{{ t('zeitwerk', 'Aktionen') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="employee in employees" :key="employee.id">
                    <td>
                        <strong>{{ employee.fullName }}</strong>
                        <div v-if="employee.email" class="employee-email">{{ employee.email }}</div>
                    </td>
                    <td>{{ employee.personnelNumber || '-' }}</td>
                    <td class="text-right">{{ employee.weeklyHours }}</td>
                    <td class="text-right">{{ employee.vacationDays }}</td>
                    <td>{{ employee.federalStateName }}</td>
                    <td>
                        <span :class="['status-badge', employee.isActive ? 'active' : 'inactive']">
                            {{ employee.isActive ? t('zeitwerk', 'Aktiv') : t('zeitwerk', 'Inaktiv') }}
                        </span>
                    </td>
                    <td class="actions-col">
                        <NcButton type="tertiary"
                            :aria-label="t('zeitwerk', 'Korrigieren')"
                            :title="t('zeitwerk', 'Zeiten/Abwesenheiten dieses Mitarbeiters korrigieren')"
                            @click="$emit('correct', employee)">
                            <template #icon>
                                <Wrench :size="20" />
                            </template>
                        </NcButton>
                        <NcButton type="tertiary"
                            :aria-label="t('zeitwerk', 'Bearbeiten')"
                            @click="$emit('edit', employee)">
                            <template #icon>
                                <Pencil :size="20" />
                            </template>
                        </NcButton>
                        <NcButton type="tertiary"
                            :aria-label="t('zeitwerk', 'Löschen')"
                            @click="confirmDelete(employee)">
                            <template #icon>
                                <Close :size="20" />
                            </template>
                        </NcButton>
                    </td>
                </tr>
            </tbody>
        </table>

        <NcEmptyContent v-else
            :name="t('zeitwerk', 'Keine Mitarbeiter')"
            :description="t('zeitwerk', 'Legen Sie Mitarbeiter an, um die Zeiterfassung zu nutzen.')">
            <template #icon>
                <AccountGroup :size="64" />
            </template>
        </NcEmptyContent>

        <NcDialog v-if="showDeleteDialog"
            :name="t('zeitwerk', 'Mitarbeiter löschen?')"
            @close="showDeleteDialog = false">
            <p>{{ t('zeitwerk', 'Möchten Sie den Mitarbeiter "{name}" wirklich löschen?', { name: employeeToDelete?.fullName }) }}</p>
            <p class="delete-warning">{{ t('zeitwerk', 'Diese Aktion kann nicht rückgängig gemacht werden.') }}</p>
            <template #actions>
                <NcButton type="tertiary" @click="showDeleteDialog = false">
                    {{ t('zeitwerk', 'Abbrechen') }}
                </NcButton>
                <NcButton type="error" @click="deleteConfirmed">
                    {{ t('zeitwerk', 'Löschen') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Close from 'vue-material-design-icons/Close.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'

export default {
    name: 'EmployeeList',
    components: {
        NcButton,
        NcEmptyContent,
        NcDialog,
        Pencil,
        Close,
        AccountGroup,
        Wrench,
    },
    props: {
        employees: {
            type: Array,
            required: true,
        },
    },
    data() {
        return {
            showDeleteDialog: false,
            employeeToDelete: null,
        }
    },
    methods: {
        confirmDelete(employee) {
            this.employeeToDelete = employee
            this.showDeleteDialog = true
        },
        deleteConfirmed() {
            this.$emit('delete', this.employeeToDelete)
            this.showDeleteDialog = false
            this.employeeToDelete = null
        },
    },
}
</script>

<style scoped>
.employee-list {
    margin-top: 16px;
}

.employees-table {
    width: 100%;
    border-collapse: collapse;
}

.employees-table th,
.employees-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.employees-table th {
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
    border-bottom: 2px solid var(--color-border-dark, var(--color-border));
}

.employees-table tbody tr:hover {
    background: var(--color-background-hover);
}

.employee-email {
    font-size: 0.85em;
    color: var(--color-text-maxcontrast);
}

.text-right {
    text-align: right;
}

th.actions-col {
    width: 6.5rem;
    text-align: center;
}

td.actions-col {
    display: flex;
    justify-content: center;
    gap: 4px;
}

.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.85em;
}

.status-badge.active {
    background: var(--wt-vacation, #4a9d63);
    color: white;
}

.status-badge.inactive {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}

.delete-warning {
    color: var(--color-error-text);
    font-size: 0.9em;
}
</style>
