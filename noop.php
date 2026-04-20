<?php
/**
 * Handles environments where users can't register.
 *
 * @package WP Approve User
 */

/**
 * Whitelists all users.
 *
 * @author Konstantin Obenland
 * @since  2.2.0 - 30.03.2013
 *
 * @param bool $new_value New option value.
 * @return bool New option value.
 */
function wpau_whitelist_users( $new_value ) {
	if ( $new_value ) {
		$user_ids = get_users(
			array(
				'blog_id' => '',
				'fields'  => 'ID',
			)
		);

		foreach ( $user_ids as $user_id ) {
			update_user_meta( $user_id, 'wp-approve-user', 'approved' );
			update_user_meta( $user_id, 'wp-approve-user-mail-sent', true );
		}
	}

	return $new_value;
}
add_filter( 'pre_update_option_users_can_register', 'wpau_whitelist_users' );

/**
 * Register info message to turn on user registration.
 *
 * @author Konstantin Obenland
 * @since  4 - 27.07.2018
 */
function wpau_add_settings_error() {
	if ( ! current_user_can( 'manage_options' ) || 0 === stripos( get_current_screen()->id, 'options' ) ) {
		return;
	}

	$url = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );

	add_settings_error(
		'wp-approve-user',
		'no-users-can-register',
		sprintf(
			/* translators: Link to options page. */
			wp_kses_post( __( 'Please <a href="%s">enable user registrations</a> for WP Approve User to work.', 'wp-approve-user' ) ),
			esc_url( $url )
		),
		'notice-info'
	);
}
add_action( 'admin_head', 'wpau_add_settings_error' );

/**
 * Display all messages registered to this plugin.
 *
 * @author Konstantin Obenland
 * @since  4 - 27.07.2018
 */
function wpau_settings_errors() {
	settings_errors( 'wp-approve-user' );
}
add_action( 'all_admin_notices', 'wpau_settings_errors' );
