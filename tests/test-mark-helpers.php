<?php
/**
 * Tests for the shared approve/unapprove helpers on the main plugin class.
 *
 * @package wp-approve-user
 */

/**
 * Covers Obenland_Wp_Approve_User::mark_approved() and mark_unapproved().
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
 */
class WPAU_Mark_Helpers_Test extends WP_UnitTestCase {

	/**
	 * Subscriber fixture.
	 *
	 * @var WP_User
	 */
	public static $user;

	/**
	 * Sets up the shared fixture.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$user = $factory->user->create_and_get( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Flips the three-state meta to `approved` and fires `wpau_approve`.
	 *
	 * @covers ::mark_approved
	 */
	public function test_mark_approved_sets_meta_and_fires_action() {
		update_user_meta( self::$user->ID, 'wp-approve-user', 'pending' );

		$fired    = array();
		$listener = static function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'wpau_approve', $listener );

		try {
			Obenland_Wp_Approve_User::mark_approved( self::$user->ID );
		} finally {
			remove_action( 'wpau_approve', $listener );
		}

		$this->assertSame( 'approved', get_user_meta( self::$user->ID, 'wp-approve-user', true ) );
		$this->assertSame( array( self::$user->ID ), $fired );
	}

	/**
	 * Documents the current contract: mark_approved() always fires
	 * `wpau_approve`, even when the user is already approved.
	 *
	 * The helper performs no short-circuit on existing meta, so downstream
	 * listeners must tolerate repeated invocations. If a future change adds
	 * idempotency, update this test to assert the new contract.
	 *
	 * @covers ::mark_approved
	 */
	public function test_mark_approved_fires_action_even_when_already_approved() {
		update_user_meta( self::$user->ID, 'wp-approve-user', 'approved' );

		$fired    = 0;
		$listener = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'wpau_approve', $listener );

		try {
			Obenland_Wp_Approve_User::mark_approved( self::$user->ID );
		} finally {
			remove_action( 'wpau_approve', $listener );
		}

		$this->assertSame( 1, $fired );
		$this->assertSame( 'approved', get_user_meta( self::$user->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Documents the current contract: mark_unapproved() always fires
	 * `wpau_unapprove`, even when the user is already unapproved.
	 *
	 * @covers ::mark_unapproved
	 */
	public function test_mark_unapproved_fires_action_even_when_already_unapproved() {
		update_user_meta( self::$user->ID, 'wp-approve-user', 'unapproved' );

		$fired    = 0;
		$listener = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'wpau_unapprove', $listener );

		try {
			Obenland_Wp_Approve_User::mark_unapproved( self::$user->ID );
		} finally {
			remove_action( 'wpau_unapprove', $listener );
		}

		$this->assertSame( 1, $fired );
		$this->assertSame( 'unapproved', get_user_meta( self::$user->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Flips meta to `unapproved`, destroys sessions, and fires `wpau_unapprove`.
	 *
	 * @covers ::mark_unapproved
	 */
	public function test_mark_unapproved_clears_sessions_and_fires_action() {
		update_user_meta( self::$user->ID, 'wp-approve-user', 'approved' );

		$sessions = WP_Session_Tokens::get_instance( self::$user->ID );
		$sessions->create( time() + 3600 );
		$this->assertNotEmpty( $sessions->get_all() );

		$fired    = array();
		$listener = static function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'wpau_unapprove', $listener );

		try {
			Obenland_Wp_Approve_User::mark_unapproved( self::$user->ID );
		} finally {
			remove_action( 'wpau_unapprove', $listener );
		}

		$this->assertSame( 'unapproved', get_user_meta( self::$user->ID, 'wp-approve-user', true ) );
		$this->assertEmpty( WP_Session_Tokens::get_instance( self::$user->ID )->get_all() );
		$this->assertSame( array( self::$user->ID ), $fired );
	}
}
