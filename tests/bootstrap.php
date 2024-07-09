<?php
/**
 * PHPUnit bootstrap file for setting up WordPress testing.
 *
 * @package wp-approve-user
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh?"; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

// Detect PHPUnit version and load appropriate compatibility layer
$phpunit_version = PHPUnit\Runner\Version::id();
if (version_compare($phpunit_version, '6.0', '<')) {
	require_once $_tests_dir . '/includes/phpunit-compat.php';
} elseif (version_compare($phpunit_version, '7.0', '<')) {
	require_once $_tests_dir . '/includes/phpunit6-compat.php';
}

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	$root = dirname( __DIR__ );

	require_once $root . '/noop.php';
	require_once $root . '/class-obenland-wp-plugins-v5.php';
	require_once $root . '/class-obenland-wp-approve-user.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
