<?php
/**
 * Cron events for WP Approve User.
 *
 * @package WP Approve User
 */

/**
 * Allow-lists 100 users per cron run.
 *
 * @param int $processed Number of users processed.
 */
function wpau_allowlist_users( $processed = 0 ) {
	$users = get_users(
		array(
			'fields' => 'ID',
			'number' => 100,
			'offset' => $processed,
		)
	);

	foreach ( $users as $user_id ) {
		update_user_meta( $user_id, 'wp-approve-user', 'approved' );
		update_user_meta( $user_id, 'wp-approve-user-mail-sent', true );
	}

	$processed += count( $users );
	$count      = count_users();

	if ( $processed < $count['total_users'] ) {
		wp_schedule_single_event( time() + 5, 'wpau_allowlist_users_cron', array( $processed ) );
	}
}
add_action( 'wpau_allowlist_users_cron', 'wpau_allowlist_users' );
