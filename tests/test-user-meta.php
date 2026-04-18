<?php
/**
 * User meta test file.
 *
 * @package wp-approve-user
 */

/**
 * User meta related tests.
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
 */
class User_Meta extends WP_UnitTestCase {

	/**
	 * Admin user object.
	 *
	 * @var WP_User
	 */
	public static $admin;

	/**
	 * Setup before class.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		static::$admin = $factory->user->create_and_get( array( 'role' => 'administrator' ) );
	}

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( static::$admin->ID );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		delete_metadata( 'user', 0, 'wp-approve-user', '', true );
		delete_metadata( 'user', 0, 'wp-approve-user-mail-sent', '', true );
		delete_metadata( 'user', 0, 'wp-approve-user-new-registration', '', true );

		parent::tear_down();
	}

	/**
	 * Tests user_register.
	 *
	 * @covers ::user_register
	 */
	public function test_user_register_admin_single_site() {
		$user_id = get_current_user_id();
		$class   = new Obenland_Wp_Approve_User();

		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		$class->user_register( $user_id );

		$this->assertSame( 'approved', get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests user_register.
	 *
	 * @covers ::user_register
	 */
	public function test_user_register_admin_multisite() {
		$this->skipWithoutMultisite();

		$user_id = get_current_user_id();
		$class   = new Obenland_Wp_Approve_User();

		$class->user_register( $user_id );

		$this->assertSame( 'pending', get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests user_register.
	 *
	 * @covers ::user_register
	 */
	public function test_user_register_subscriber() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );

		$class = new Obenland_Wp_Approve_User();

		$class->user_register( $user->ID );

		$this->assertSame( 'pending', get_user_meta( $user->ID, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user->ID, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests wp_authenticate_user.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );
		$class = new Obenland_Wp_Approve_User();

		// Returns WP_Error if there's an error.
		$error  = new WP_Error( 'test_error', 'Test Error' );
		$result = $class->wp_authenticate_user( $error );
		$this->assertWPError( $result );
		$this->assertSame( 'test_error', $error->get_error_code() );

		// Returns WP_Error if they're not approved.
		$result = $class->wp_authenticate_user( $user );
		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );
	}

	/**
	 * Tests that users with no wp-approve-user meta at all can log in —
	 * i.e. pre-plugin-install users or users orphaned by an incomplete
	 * activation cron.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_missing_meta_is_treated_as_approved() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		// No meta set at all — this is the pre-plugin-install state.
		$this->assertSame( '', get_user_meta( $user->ID, 'wp-approve-user', true ) );

		$class  = new Obenland_Wp_Approve_User();
		$result = $class->wp_authenticate_user( $user );

		$this->assertSame( $user, $result );
	}

	/**
	 * Legacy pre-v12 installs stored a `false` boolean (persisted as an
	 * empty string) to represent pending users. Those rows still exist
	 * in the database until wpau_upgrade_to_12() migrates them, so the
	 * login gate must distinguish "meta row missing" (approved) from
	 * "meta row present but empty" (still pending).
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_empty_legacy_meta_is_still_blocked() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', false );
		$this->assertSame( '', get_user_meta( $user->ID, 'wp-approve-user', true ) );
		$this->assertTrue( metadata_exists( 'user', $user->ID, 'wp-approve-user' ) );

		$class  = new Obenland_Wp_Approve_User();
		$result = $class->wp_authenticate_user( $user );

		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );
	}

	/**
	 * Unapproved users remain blocked — the missing-meta fix must not
	 * accidentally unblock users who were explicitly unapproved.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_unapproved_is_still_blocked() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', 'unapproved' );

		$class  = new Obenland_Wp_Approve_User();
		$result = $class->wp_authenticate_user( $user );

		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );
	}

	/**
	 * Tests wp_authenticate_user on single sites.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_simple_site() {
		$this->skipWithMultisite();
		$class = new Obenland_Wp_Approve_User();

		// Returns WP_User for admins, even if they're unapproved.
		update_user_meta( static::$admin->ID, 'wp-approve-user', 'unapproved' );
		$result = $class->wp_authenticate_user( static::$admin );
		$this->assertSame( static::$admin, $result );
	}

	/**
	 * Tests wp_authenticate_user on multisite.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_multisite() {
		$this->skipWithoutMultisite();
		$class = new Obenland_Wp_Approve_User();

		// Returns WP_Error for admins when they're unapproved.
		update_user_meta( static::$admin->ID, 'wp-approve-user', 'unapproved' );
		$result = $class->wp_authenticate_user( static::$admin );
		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );

		// Returns WP_User for super admins, even if they're unapproved.
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		grant_super_admin( $user->ID );
		update_user_meta( $user->ID, 'wp-approve-user', 'unapproved' );

		$result = $class->wp_authenticate_user( $user );
		$this->assertSame( $user, $result );
	}
}
