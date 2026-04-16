<?php
/**
 * Tests for cron-events.php.
 *
 * @package wp-approve-user
 */

/**
 * Covers the cron allow-list.
 */
class WPAU_Cron_Events_Test extends WP_UnitTestCase {

	/**
	 * Approves every user and sets mail-sent meta without rescheduling when the user count fits in a single batch.
	 *
	 * @covers ::wpau_allowlist_users
	 */
	public function test_allowlist_users_approves_all_users_in_one_batch() {
		$user_one = self::factory()->user->create();
		$user_two = self::factory()->user->create();

		delete_user_meta( $user_one, 'wp-approve-user' );
		delete_user_meta( $user_two, 'wp-approve-user' );

		wp_clear_scheduled_hook( 'wpau_allowlist_users_cron' );
		wpau_allowlist_users();

		$this->assertSame( 'approved', get_user_meta( $user_one, 'wp-approve-user', true ) );
		$this->assertSame( 'approved', get_user_meta( $user_two, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user_one, 'wp-approve-user-mail-sent', true ) );
		$this->assertFalse( wp_next_scheduled( 'wpau_allowlist_users_cron' ) );
	}

	/**
	 * Reschedules the allow-list cron with the next offset when more users remain than were processed this run.
	 *
	 * Protects the batch-and-reschedule activation path: if the single-event
	 * reschedule disappears, site activation on a large install will process
	 * only the first 100 users and silently drop the rest.
	 *
	 * @covers ::wpau_allowlist_users
	 */
	public function test_allowlist_users_reschedules_when_batch_incomplete() {
		$user_id = self::factory()->user->create();
		wp_clear_scheduled_hook( 'wpau_allowlist_users_cron' );

		// Inflate the reported total so the function believes one batch is not enough.
		$inflate_count = static function () {
			return array(
				'total_users' => 500,
				'avail_roles' => array(),
			);
		};
		add_filter( 'pre_count_users', $inflate_count );

		$scheduled      = array();
		$capture_events = static function ( $event ) use ( &$scheduled ) {
			if ( 'wpau_allowlist_users_cron' === $event->hook ) {
				$scheduled[] = $event;
			}
			return $event;
		};
		add_filter( 'schedule_event', $capture_events );

		wpau_allowlist_users( 0 );

		remove_filter( 'pre_count_users', $inflate_count );
		remove_filter( 'schedule_event', $capture_events );
		wp_clear_scheduled_hook( 'wpau_allowlist_users_cron' );

		// Exactly one reschedule for this run.
		$this->assertCount( 1, $scheduled );

		// Processed arg must reflect the users actually handled so the next
		// run's offset picks up where this one stopped.
		$total_users = count( get_users( array( 'fields' => 'ID' ) ) );
		$this->assertSame( $total_users, $scheduled[0]->args[0] );

		// The single user that existed must actually be flagged approved.
		$this->assertSame( 'approved', get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user-mail-sent', true ) );
	}
}
