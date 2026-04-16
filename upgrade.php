<?php
/**
 * Upgrade routines.
 *
 * @package WP Approve User
 */

/**
 * Upgrade routine.
 */
function wpau_upgrade_all() {
	global $wpau_db_version;

	$wpau_current_db_version = get_site_option( 'wpau_db_version', 0 );

	if ( (int) $wpau_current_db_version === $wpau_db_version ) {
		return;
	}

	if ( $wpau_current_db_version < 12 ) {
		wpau_upgrade_to_12();
	}

	update_site_option( 'wpau_db_version', $wpau_db_version );
}
add_action( 'admin_init', 'wpau_upgrade_all' );

/**
 * Updates all user meta values from true to 'approved'.
 */
function wpau_upgrade_to_12() {
	global $wpdb;

	// phpcs:disable WordPress.DB
	$wpdb->update(
		$wpdb->usermeta,
		array( 'meta_value' => 'approved' ),
		array(
			'meta_key'   => 'wp-approve-user',
			'meta_value' => true,
		)
	);

	$wpdb->update(
		$wpdb->usermeta,
		array( 'meta_value' => 'pending' ),
		array(
			'meta_key'   => 'wp-approve-user',
			'meta_value' => false,
		)
	);
	// phpcs:enable WordPress.DB
}
