import Vue from 'vue'
import App from './App.vue'
import store from './store/index.js'
import router from './router/index.js'
import { translate, translatePlural } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('worktime', 'js/')

Vue.prototype.t = translate
Vue.prototype.n = translatePlural

// Get permissions from Nextcloud Initial State API
const permissions = loadState('worktime', 'permissions', {})

// Initialize permissions in store
store.dispatch('permissions/initFromInitialState', permissions)

// Whether the approval workflow is active for this instance
const approvalRequired = loadState('worktime', 'approvalRequired', true)
store.dispatch('permissions/setApprovalRequired', approvalRequired)

// Company rules: required project / description on time entries (#329)
store.dispatch('permissions/setRequiredFields', {
	requireProject: loadState('worktime', 'requireProject', false),
	requireDescription: loadState('worktime', 'requireDescription', false),
})

// Freigaben für persönliche Standard-Vorgaben (Projekt/Beschreibung)
store.dispatch('permissions/setDefaultsAllowed', {
	allowDefaultProject: loadState('worktime', 'allowDefaultProject', false),
	allowDefaultDescription: loadState('worktime', 'allowDefaultDescription', false),
})

// Restore last view from localStorage (if not already on a valid route)
const savedView = localStorage.getItem('worktime_last_view')
if (savedView && savedView !== '/' && router.currentRoute.path === '/') {
	router.push(savedView).catch(() => {})
}

new Vue({
	router,
	store,
	render: h => h(App),
}).$mount('.app-worktime')
