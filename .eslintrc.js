module.exports = {
	root: true,
	extends: ['@nextcloud'],
	rules: {
		// Dieses Projekt nutzt durchgaengig 4 Leerzeichen und eine eigene,
		// gewachsene Template-Formatierung. Das NC-Preset erzwingt Tabs und
		// eine abweichende HTML-Formatierung. Statt den gesamten Code zu
		// reformatieren, deaktivieren wir die reinen Formatierungsregeln und
		// lassen den Linter sich auf echte Fehler (Bugs, ungenutzte Variablen,
		// Vue-Korrektheit) konzentrieren.
		indent: 'off',
		'vue/html-indent': 'off',
		'vue/singleline-html-element-content-newline': 'off',
		'vue/multiline-html-element-content-newline': 'off',
		'vue/first-attribute-linebreak': 'off',
		'vue/max-attributes-per-line': 'off',
		'vue/html-self-closing': 'off',
		'no-multiple-empty-lines': 'off',
		'comma-dangle': 'off',
		'operator-linebreak': 'off',
		'quote-props': 'off',
		// Bestehende JSDoc nicht nachtraeglich erzwingen.
		'jsdoc/require-jsdoc': 'off',
		'jsdoc/require-param': 'off',
		'jsdoc/require-param-description': 'off',
		'jsdoc/check-tag-names': 'off',
		'jsdoc/check-types': 'off',
		// False positive bei Datums-Iterationen der Form
		// `for (let cur = start; cur <= end; cur.setDate(cur.getDate() + 1))`:
		// die Schleifenvariable wird per Methode mutiert, nicht neu zugewiesen,
		// was die Regel nicht erkennt. Die Loops sind korrekt.
		'no-unmodified-loop-condition': 'off',
	},
}
