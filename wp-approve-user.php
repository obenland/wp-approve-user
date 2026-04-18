<?php
/**
 * Plugin Name: WP Approve User
 * Plugin URI:  http://en.wp.obenland.it/wp-approve-user/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description: Adds action links to user table to approve or unapprove user registrations.
 * Version:     13
 * Author:      Konstantin Obenland
 * Author URI:  http://en.wp.obenland.it/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Text Domain: wp-approve-user
 * Domain Path: /lang
 * Requires at least: 4.7
 * Requires PHP: 7.4
 * License:     GPLv2
 *
 * @package WP Approve User
 */

if ( ! get_option( 'users_can_register' ) ) {
	require_once __DIR__ . '/noop.php';
	return;
}

// Define the current version for upgrades.
$wpau_db_version = 13;

if ( ! class_exists( 'Obenland_Wp_Plugins_V5' ) ) {
	require_once __DIR__ . '/class-obenland-wp-plugins-v5.php';
}

require_once __DIR__ . '/class-obenland-wp-approve-user.php';
require_once __DIR__ . '/class-wpau-dashboard-widget.php';
require_once __DIR__ . '/class-wpau-settings.php';
require_once __DIR__ . '/cron-events.php';
require_once __DIR__ . '/upgrade.php';
require_once __DIR__ . '/abilities.php';

/**
 * Instantiates Obenland_Wp_Approve_User.
 */
function wp_approve_user_instantiate() {
	Obenland_Wp_Approve_User::get_instance();
}
add_action( 'plugins_loaded', 'wp_approve_user_instantiate', 0 );

/**
 * Actions to take on plugin activation.
 */
function wp_approve_user_activate() {
	wpau_allowlist_users();
}
register_activation_hook( __FILE__, 'wp_approve_user_activate' );
