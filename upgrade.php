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
 *
 * Uses a single INSERT…SELECT so sites with 100k+ users don't block
 * admin_init on an equivalent number of individual update_user_meta()
 * calls. The cache is flushed afterwards because we bypassed the meta
 * API and any already-populated 'user_meta' cache entries are now stale.
 */
function wpau_upgrade_to_13() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		"INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
			SELECT u.ID, 'wp-approve-user', 'approved'
			FROM {$wpdb->users} u
			LEFT JOIN {$wpdb->usermeta} um
				ON u.ID = um.user_id AND um.meta_key = 'wp-approve-user'
			WHERE um.umeta_id IS NULL"
	);

	wp_cache_flush();
}
