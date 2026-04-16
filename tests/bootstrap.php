<?php
/**
 * PHPUnit bootstrap file for setting up WordPress testing.
 *
 * @package wp-approve-user
 */

define( 'TESTS_PLUGIN_DIR', dirname( __DIR__ ) );

// Determine correct location for plugins directory to use.
if ( false !== getenv( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', getenv( 'WP_PLUGIN_DIR' ) );
} else {
	define( 'WP_PLUGIN_DIR', dirname( TESTS_PLUGIN_DIR ) );
}

// Load Composer dependencies if applicable.
if ( file_exists( TESTS_PLUGIN_DIR . '/vendor/autoload.php' ) ) {
	require_once TESTS_PLUGIN_DIR . '/vendor/autoload.php';
}

// Detect where to load the WordPress tests environment from.
if ( false !== getenv( 'WP_TESTS_DIR' ) ) {
	$_tests_dir = getenv( 'WP_TESTS_DIR' );
} elseif ( false !== getenv( 'WP_DEVELOP_DIR' ) ) {
	$_tests_dir = getenv( 'WP_DEVELOP_DIR' ) . '/tests/phpunit';
} elseif ( false !== getenv( 'WP_PHPUNIT__DIR' ) ) {
	$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
} elseif ( file_exists( TESTS_PLUGIN_DIR . '/../../../../tests/phpunit/includes/functions.php' ) ) {
	$_tests_dir = TESTS_PLUGIN_DIR . '/../../../../tests/phpunit';
} else { // Fallback.
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Force plugin to be active.
$GLOBALS['wp_tests_options'] = array(
	'active_plugins' => array( basename( TESTS_PLUGIN_DIR ) . '/load.php' ),
);

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually loads the plugin under test into the WP test harness.
 *
 * @throws RuntimeException When the `users_can_register` gate bypass fails
 *                          and the plugin entry file silently falls back
 *                          to the noop path.
 */
function _manually_load_plugin() {
	global $wpau_db_version;

	$root = dirname( __DIR__ );

	require_once $root . '/noop.php';
	require_once $root . '/class-obenland-wp-plugins-v5.php';
	require_once $root . '/class-obenland-wp-approve-user.php';
	require_once $root . '/tests/class-wpau-redirect-exception.php';

	/*
	 * Force the `users_can_register` gate in the plugin entry file to
	 * evaluate truthy so PHPUnit collects coverage for wp-approve-user.php,
	 * cron-events.php and upgrade.php. A short-circuit filter avoids calling
	 * update_option() this early (user functions aren't available yet).
	 */
	add_filter( 'pre_option_users_can_register', '__return_true' );
	require_once $root . '/wp-approve-user.php';
	remove_filter( 'pre_option_users_can_register', '__return_true' );

	/*
	 * Hard-fail if the gate bypass silently dropped us back into the noop path.
	 * Without this check, a future refactor of wp-approve-user.php could leave
	 * the suite running against an empty plugin and every test would still pass.
	 */
	if ( ! function_exists( 'wp_approve_user_instantiate' ) ) {
		throw new RuntimeException(
			'wp-approve-user.php did not register — the users_can_register gate bypass is broken.'
		);
	}

	/*
	 * The entry file also queues wp_approve_user_instantiate on plugins_loaded.
	 * Keeping that hook would globally register the main class' filters, which
	 * would change user_register behaviour for every test. Each test that needs
	 * the class creates its own instance explicitly, so we can unhook it here.
	 */
	remove_action( 'plugins_loaded', 'wp_approve_user_instantiate', 0 );
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
