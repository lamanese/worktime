<template>
	<NcModal :name="title" size="small" @close="$emit('cancel')">
		<form class="duty-form" @submit.prevent="save">
			<h3>{{ title }}</h3>

			<div class="form-group">
				<label for="dj-employee">{{ t('zeitwerk', 'Mitarbeiter') }}</label>
				<select id="dj-employee" v-model.number="form.employeeId" required>
					<option v-for="e in employees" :key="e.id" :value="e.id">{{ e.fullName }}</option>
				</select>
				<p v-if="errors.employeeId" class="field-error">{{ errors.employeeId[0] }}</p>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label for="dj-date">{{ t('zeitwerk', 'Datum') }}</label>
					<input id="dj-date" v-model="form.date" type="date" required>
					<p v-if="errors.date" class="field-error">{{ errors.date[0] }}</p>
				</div>
				<div class="form-group">
					<label for="dj-time">{{ t('zeitwerk', 'Uhrzeit') }}</label>
					<input id="dj-time" v-model="form.startTime" type="time">
					<p v-if="errors.startTime" class="field-error">{{ errors.startTime[0] }}</p>
				</div>
			</div>

			<div class="form-group">
				<label for="dj-title">{{ t('zeitwerk', 'Titel') }}</label>
				<input id="dj-title"
					ref="titleInput"
					v-model="form.title"
					type="text"
					list="dj-title-suggestions"
					maxlength="200"
					required
					autocomplete="off"
					@input="onTitleInput">
				<datalist id="dj-title-suggestions">
					<option v-for="s in suggestions" :key="s" :value="s" />
				</datalist>
				<p v-if="errors.title" class="field-error">{{ errors.title[0] }}</p>
			</div>

			<div class="form-group">
				<label for="dj-duration">{{ t('zeitwerk', 'Dauer (Minuten)') }}</label>
				<div class="duration-row">
					<input id="dj-duration" v-model.number="form.durationMinutes" type="number" min="1" max="1440" step="1">
					<NcButton v-for="m in [30, 60, 90, 120]" :key="m" type="tertiary" @click="form.durationMinutes = m">
						{{ m }}
					</NcButton>
				</div>
				<p v-if="errors.durationMinutes" class="field-error">{{ errors.durationMinutes[0] }}</p>
			</div>

			<div class="form-group">
				<label for="dj-note">{{ t('zeitwerk', 'Notiz') }}</label>
				<textarea id="dj-note" v-model="form.note" rows="2" maxlength="500" />
				<p v-if="errors.note" class="field-error">{{ errors.note[0] }}</p>
			</div>

			<div class="form-group">
				<NcCheckboxRadioSwitch :checked.sync="form.onCall">
					{{ t('zeitwerk', 'Auf Abruf') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="form-actions">
				<NcButton v-if="isEdit" type="error" :disabled="saving" @click="remove">
					{{ t('zeitwerk', 'Löschen') }}
				</NcButton>
				<span class="spacer" />
				<NcButton type="secondary" :disabled="saving" @click="$emit('cancel')">
					{{ t('zeitwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton type="primary" native-type="submit" :disabled="saving">
					{{ t('zeitwerk', 'Speichern') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import { DialogBuilder } from '@nextcloud/dialogs'
import { mapActions } from 'vuex'
import DutyRosterService from '../services/DutyRosterService.js'
import { showErrorMessage, showSuccessMessage } from '../utils/errorHandler.js'

export default {
	name: 'DutyJobForm',
	components: { NcModal, NcButton, NcCheckboxRadioSwitch },
	props: {
		job: { type: Object, default: null },
		employeeId: { type: Number, required: true },
		date: { type: String, required: true },
		employees: { type: Array, required: true },
	},
	data() {
		return {
			form: {
				employeeId: this.job?.employeeId ?? this.employeeId,
				date: this.job?.date ?? this.date,
				startTime: this.job?.startTime ?? '',
				durationMinutes: this.job?.durationMinutes ?? null,
				title: this.job?.title ?? '',
				note: this.job?.note ?? '',
				onCall: !!this.job?.onCall,
			},
			errors: {},
			suggestions: [],
			suggestTimer: null,
			saving: false,
		}
	},
	computed: {
		isEdit() {
			return !!this.job
		},
		title() {
			return this.isEdit ? this.t('zeitwerk', 'Auftrag bearbeiten') : this.t('zeitwerk', 'Auftrag einplanen')
		},
	},
	mounted() {
		this.$nextTick(() => this.$refs.titleInput?.focus())
	},
	methods: {
		...mapActions('dutyRoster', ['createJob', 'updateJob', 'deleteJob']),
		onTitleInput() {
			clearTimeout(this.suggestTimer)
			const q = this.form.title.trim()
			if (q.length < 1) {
				this.suggestions = []
				return
			}
			this.suggestTimer = setTimeout(async () => {
				try {
					this.suggestions = await DutyRosterService.getTitles(q)
				} catch (e) {
					this.suggestions = []
				}
			}, 250)
		},
		payload() {
			return {
				employeeId: this.form.employeeId,
				date: this.form.date,
				startTime: this.form.startTime || null,
				durationMinutes: this.form.durationMinutes || null,
				title: this.form.title.trim(),
				note: this.form.note.trim() || null,
				onCall: this.form.onCall,
			}
		},
		async save() {
			this.saving = true
			this.errors = {}
			try {
				const job = this.isEdit
					? await this.updateJob({ id: this.job.id, data: this.payload() })
					: await this.createJob(this.payload())
				this.$emit('saved', job)
			} catch (error) {
				if (error.errors) {
					this.errors = error.errors
				}
				showErrorMessage(error.message)
			} finally {
				this.saving = false
			}
		},
		remove() {
			const dialog = new DialogBuilder()
				.setName(this.t('zeitwerk', 'Auftrag löschen'))
				.setText(this.t('zeitwerk', 'Diesen Auftrag aus dem Dienstplan entfernen?'))
				.setButtons([
					{ label: this.t('zeitwerk', 'Abbrechen'), type: 'secondary', callback: () => {} },
					{
						label: this.t('zeitwerk', 'Löschen'),
						type: 'error',
						callback: async () => {
							try {
								await this.deleteJob(this.job.id)
								showSuccessMessage(this.t('zeitwerk', 'Auftrag gelöscht'))
								this.$emit('deleted')
							} catch (error) {
								showErrorMessage(error.message)
							}
						},
					},
				])
				.build()
			dialog.show()
		},
	},
}
</script>

<style scoped>
.duty-form { padding: 16px 20px 20px; display: flex; flex-direction: column; gap: 12px; }
.duty-form h3 { margin: 0 0 4px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-row { display: flex; gap: 12px; }
.form-row .form-group { flex: 1; }
.duration-row { display: flex; gap: 6px; align-items: center; }
.duration-row input { width: 90px; }
.field-error { color: var(--color-error); font-size: 12px; margin: 0; }
.form-actions { display: flex; gap: 8px; margin-top: 8px; }
.spacer { flex: 1; }
input, select, textarea { width: 100%; }
</style>
