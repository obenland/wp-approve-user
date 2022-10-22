<?php
/**
 * Plugin Name: WP Approve User
 * Plugin URI:  http://en.wp.obenland.it/wp-approve-user/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description: Adds action links to user table to approve or unapprove user registrations.
 * Version:     9
 * Author:      Konstantin Obenland
 * Author URI:  http://en.wp.obenland.it/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Text Domain: wp-approve-user
 * Domain Path: /lang
 * License:     GPLv2
 *
 * @package WP Approve User
 */

if ( ! get_option( 'users_can_register' ) ) {
	require_once 'noop.php';
	return;
}


if ( ! class_exists( 'Obenland_Wp_Plugins_V4' ) ) {
	require_once 'obenland-wp-plugins.php';
}

require_once 'activation.php';
require_once 'class-obenland-wp-approve-user.php';

/**
 * Instantiates Obenland_Wp_Approve_User.
 */
function wp_approve_user_instantiate() {
	new Obenland_Wp_Approve_User();
}
add_action( 'plugins_loaded', 'wp_approve_user_instantiate', 0 );
