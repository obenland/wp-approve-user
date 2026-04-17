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

	if ( $wpau_current_db_version < 13 ) {
		wpau_upgrade_to_13();
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

/**
 * Stamps users with no wp-approve-user meta as 'approved'.
 *
 * Covers users who existed before the plugin was installed (or before
 * the activation cron finished) — without this they're invisible to the
 * pending/unapproved admin views and can no longer log in.
 */
function wpau_upgrade_to_13() {
	$user_ids = get_users(
		array(
			'fields'      => 'ID',
			'blog_id'     => 0,
			'number'      => -1,
			'count_total' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'  => array(
				array(
					'key'     => 'wp-approve-user',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	foreach ( $user_ids as $user_id ) {
		update_user_meta( $user_id, 'wp-approve-user', 'approved' );
	}
}
