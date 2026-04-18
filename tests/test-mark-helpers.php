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
		$listener = function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'wpau_approve', $listener );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->mark_approved( self::$user->ID );
		} finally {
			remove_action( 'wpau_approve', $listener );
		}

		$this->assertSame( 'approved', get_user_meta( self::$user->ID, 'wp-approve-user', true ) );
		$this->assertSame( array( self::$user->ID ), $fired );
	}

	/**
	 * The constructor hydrates options and, on admin, the pending/unapproved counts.
	 *
	 * @covers ::__construct
	 * @covers ::get_options
	 * @covers ::get_pending_count_cached
	 */
	public function test_constructor_hydrates_options_and_counts() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => true,
				'wpau-send-unapprove-email' => false,
				'wpau-approve-email'        => 'Hi USERNAME',
				'wpau-unapprove-email'      => '',
				'auto_approve_rules'        => array(),
			)
		);

		set_current_screen( 'dashboard' );
		Obenland_Wp_Approve_User::$instance = null;
		$instance                           = new Obenland_Wp_Approve_User();

		$options = $instance->get_options();
		$this->assertTrue( $options['wpau-send-approve-email'] );
		$this->assertSame( 'Hi USERNAME', $options['wpau-approve-email'] );
		$this->assertIsInt( $instance->get_pending_count_cached() );
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
		$listener = function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'wpau_unapprove', $listener );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->mark_unapproved( self::$user->ID );
		} finally {
			remove_action( 'wpau_unapprove', $listener );
		}

		$this->assertSame( 'unapproved', get_user_meta( self::$user->ID, 'wp-approve-user', true ) );
		$this->assertEmpty( WP_Session_Tokens::get_instance( self::$user->ID )->get_all() );
		$this->assertSame( array( self::$user->ID ), $fired );
	}
}
