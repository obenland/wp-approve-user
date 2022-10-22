<?php
/**
 * Activation functionality.
 *
 * @package wp-approve-user
 */

/**
 * Plugin activation.
 */
function wp_approve_user_activation() {
	if ( ! wp_next_scheduled( 'wp_approve_user_activation' ) ) {
		add_option( 'wp-approve-user-activation-offset', 0, '', 'no' );

		wp_schedule_event( time(), 'every_minute', 'wp_approve_user_activation' );
	}

	wp_approve_user_populate_meta();
}
register_activation_hook( __FILE__, 'wp_approve_user_activation' );

/**
 * Populates user metadata needed for the plugin to work.
 */
function wp_approve_user_populate_meta() {
	$number = 50;
	$offset = (int) get_option( 'wp-approve-user-activation-offset', 0 );

	$user_search = new WP_User_Query(
		array(
			'blog_id'     => '',
			'count_total' => false,
			'fields'      => 'ID',
			'number'      => $number,
			'offset'      => $offset,
			'orderby'     => 'ID',
		)
	);

	$user_ids = $user_search->get_results();

	foreach ( $user_ids as $user_id ) {
		add_user_meta( $user_id, 'wp-approve-user', true, true );
		add_user_meta( $user_id, 'wp-approve-user-mail-sent', true, true );
	}

	if ( count( $user_ids ) < $number ) {
		wp_unschedule_event( wp_next_scheduled( 'wp_approve_user_activation' ), 'wp_approve_user_activation' );
		delete_option( 'wp-approve-user-activation-offset' );
	} else {
		update_option( 'wp-approve-user-activation-offset', $offset + $number, 'no' );
	}
}
add_action( 'wp_approve_user_activation', 'wp_approve_user_populate_meta' );

/**
 * Adds a custom cron schedule for this plugin to populate user meta.
 *
 * @param array $schedules The list of WordPress cron schedules prior to this filter.
 * @return array
 */
function wp_approve_user_cron_schedule( $schedules ) {
	if ( get_option( 'wp-approve-user-activation-offset' ) ) {
		$schedules['every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			/* translators: Cron schedule interval */
			'display'  => __( 'Every minute', 'wp-approve-user' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'wp_approve_user_cron_schedule' ); //phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
