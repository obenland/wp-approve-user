<?php
/**
 * User meta test file.
 *
 * @package wp-approve-user
 */

// I shouldn't need to require them here.
require_once dirname( __DIR__ ) . '/obenland-wp-plugins.php';
require_once dirname( __DIR__ ) . '/class-obenland-wp-approve-user.php';

/**
 * User meta related tests.
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
 */
class User_Meta extends WP_UnitTestCase {

	/**
	 * Setup before class.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		$user = $factory->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user->ID );
	}

	/**
	 * Tests user_register.
	 *
	 * @covers ::user_register
	 */
	public function test_user_register_admin() {
		$user_id = get_current_user_id();
		$class   = new Obenland_Wp_Approve_User();

		$class->user_register( $user_id );

		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests user_register.
	 *
	 * @covers ::user_register
	 */
	public function test_user_register_subscriber() {
		$user  = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$class = new Obenland_Wp_Approve_User();

		$class->user_register( $user->ID );
var_dump($user->id,  get_current_user_id(), get_user_meta( $user->ID, 'wp-approve-user', true ) );
		$this->assertSame( '0', get_user_meta( $user->ID, 'wp-approve-user', true ) );
	}
}
