const wpPlugin = require( '@wordpress/eslint-plugin' );

module.exports = [
	{
		ignores: [
			'**/*.min.js',
			'**/node_modules/**',
			'**/vendor/**',
			'playwright-report/**',
			'test-results/**',
			'tests/e2e/.auth/**',
		],
	},
	...wpPlugin.configs.recommended,
	{
		languageOptions: {
			globals: {
				wp_approve_user: 'readonly',
				wp_approve_user_dashboard: 'readonly',
			},
		},
		rules: {
			/*
			 * `wp_approve_user*` are snake_case handles registered via
			 * wp_localize_script(), and `user_id` is a snake_case AJAX payload
			 * key expected by the WP AJAX handlers.
			 */
			camelcase: [
				'error',
				{
					allow: [ '^wp_approve_user' ],
					properties: 'never',
				},
			],
		},
	},
	{
		// CLI/Node scripts: Playwright harness and the screenshot driver.
		files: [
			'scripts/**/*.js',
			'tests/e2e/**/*.js',
			'playwright.config.js',
		],
		languageOptions: {
			sourceType: 'commonjs',
			globals: {
				require: 'readonly',
				module: 'readonly',
				process: 'readonly',
				console: 'readonly',
				__dirname: 'readonly',
				__filename: 'readonly',
			},
		},
		rules: {
			'no-console': 'off',
		},
	},
];
