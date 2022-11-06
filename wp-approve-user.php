<?php
/**
 * Plugin Name: WP Approve User
 * Plugin URI:  http://en.wp.obenland.it/wp-approve-user/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description: Adds action links to user table to approve or unapprove user registrations.
 * Version:     10
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


if ( ! class_exists( 'Obenland_Wp_Plugins_V5' ) ) {
	require_once 'class-obenland-wp-plugins-v5.php';
}

require_once 'class-obenland-wp-approve-user.php';

/**
 * Instantiates Obenland_Wp_Approve_User.
 */
function wp_approve_user_instantiate() {
	Obenland_Wp_Approve_User::get_instance();
}
add_action( 'plugins_loaded', 'wp_approve_user_instantiate', 0 );

/**
 * Approves all existing users.
 */
function wp_approve_user_activate() {
	$user_ids = get_users(
		array(
			'blog_id' => '',
			'fields'  => 'ID',
		)
	);

	foreach ( $user_ids as $user_id ) {
		add_user_meta( $user_id, 'wp-approve-user', true, true );
		add_user_meta( $user_id, 'wp-approve-user-mail-sent', true, true );
	}
}
register_activation_hook( __FILE__, 'wp_approve_user_activate' );
