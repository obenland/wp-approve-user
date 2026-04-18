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

	/**
	 * Honors a non-zero offset so rescheduled cron runs only touch the
	 * users that come after the offset — earlier users are left untouched.
	 *
	 * Protects the batch-and-reschedule activation path: if the offset
	 * argument is dropped, each cron run would re-process the first 100
	 * users forever and the tail of a large install would never get approved.
	 *
	 * @covers ::wpau_allowlist_users
	 */
	public function test_allowlist_users_respects_nonzero_offset() {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = self::factory()->user->create(
				array(
					'user_login'      => 'offset-' . $i . '-' . wp_generate_password( 6, false ),
					'user_email'      => 'offset-' . $i . '@example.test',
					'user_registered' => gmdate( 'Y-m-d H:i:s', time() - ( 3 - $i ) * 3600 ),
				)
			);
		}

		foreach ( $ids as $id ) {
			delete_user_meta( $id, 'wp-approve-user' );
			delete_user_meta( $id, 'wp-approve-user-mail-sent' );
		}

		/*
		 * Snapshot the wp-approve-user meta for every existing user before
		 * the run. get_users() ordering across test fixtures is not stable
		 * enough to hard-code which IDs land before/after the offset, so we
		 * diff the per-user meta after the call and assert that exactly the
		 * users at `offset=2..` flipped to approved.
		 */
		$all_ids      = get_users( array( 'fields' => 'ID' ) );
		$before_state = array();
		foreach ( $all_ids as $id ) {
			$before_state[ $id ] = get_user_meta( $id, 'wp-approve-user', true );
		}

		$expected_flipped = get_users(
			array(
				'fields' => 'ID',
				'number' => 100,
				'offset' => 2,
			)
		);

		wp_clear_scheduled_hook( 'wpau_allowlist_users_cron' );
		wpau_allowlist_users( 2 );

		foreach ( $expected_flipped as $id ) {
			$this->assertSame( 'approved', get_user_meta( $id, 'wp-approve-user', true ) );
		}

		foreach ( $all_ids as $id ) {
			if ( in_array( $id, $expected_flipped, true ) ) {
				continue;
			}
			/* Users before the offset are untouched by this run. */
			$this->assertSame( $before_state[ $id ], get_user_meta( $id, 'wp-approve-user', true ) );
		}

		wp_clear_scheduled_hook( 'wpau_allowlist_users_cron' );
	}
}
