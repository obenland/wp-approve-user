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
	public function setUp() {
		parent::setUp();

		wp_set_current_user( static::$admin->ID );
	}

	/**
	 * Teardown.
	 */
	public function tearDown() {
		delete_metadata( 'user', 0, 'wp-approve-user', '', true );
		delete_metadata( 'user', 0, 'wp-approve-user-mail-sent', '', true );
		delete_metadata( 'user', 0, 'wp-approve-user-new-registration', '', true );

		parent::tearDown();
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

		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user', true ) );
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

		$this->assertEmpty( get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests user_register.
	 *
	 * @covers ::user_register
	 */
	public function test_user_register_subscriber() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );

		$class = new Obenland_Wp_Approve_User();

		$class->user_register( $user->ID );

		$this->assertEmpty( get_user_meta( $user->ID, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user->ID, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests wp_authenticate_user.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user() {
		$user  = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$class = new Obenland_Wp_Approve_User();

		// Returns WP_Error if there's an error.
		$error  = new WP_Error( 'test_error', 'Test Error' );
		$result = $class->wp_authenticate_user( $error );
		$this->assertWPError( $result );
		$this->assertSame( 'test_error', $error->get_error_code() );

		// Returns WP_User for admins, even if they're unapproved.
		update_user_meta( static::$admin->ID, 'wp-approve-user', false );
		$result = $class->wp_authenticate_user( static::$admin );
		$this->assertSame( static::$admin, $result );

		// Returns WP_Error if they're not approved.
		$result = $class->wp_authenticate_user( $user );
		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $error->get_error_code() );
	}

	/**
	 * Tests wp_authenticate_user on multisite.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_multisite() {
		$this->skipWithoutMultisite();

		$user  = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$class = new Obenland_Wp_Approve_User();

		grant_super_admin( $user->ID );

		// Returns WP_User for super admins, even if they're unapproved.
		update_user_meta( $user->ID, 'wp-approve-user', false );
		$result = $class->wp_authenticate_user( $user );
		$this->assertSame( $user, $result );
	}
}
