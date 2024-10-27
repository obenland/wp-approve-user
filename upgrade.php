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

	if ( $wpau_current_db_version === $wpau_db_version ) {
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
	// phpcs:enable WordPress.DB

	wpau_set_users_pending();
}

/**
 * Set 100 users to pending per cron run.
 *
 * @param int $processed Number of users processed.
 */
function wpau_set_users_pending( $processed = 0 ) {
	$users = get_users(
		array(
			'fields'     => 'ID',
			'number'     => 100,
			'offset'     => $processed,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => 'wp-approve-user',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'wp-approve-user',
					'value'   => '',
					'compare' => '=',
				),
			),
		)
	);
	$users = array_diff( $users, array( get_user_by( 'email', get_bloginfo( 'admin_email' ) )->ID ) );

	foreach ( $users as $user_id ) {
		update_user_meta( $user_id, 'wp-approve-user', 'pending' );
	}

	$processed += count( $users );

	if ( count( $users ) >= 99 ) {
		wp_schedule_single_event( time() + 5, 'wpau_pending_users_cron', array( $processed ) );
	}
}
add_action( 'wpau_pending_users_cron', 'wpau_set_users_pending' );
