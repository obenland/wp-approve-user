<?php
/**
 * User meta test file.
 *
 * @package wp-approve-user
 */

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
	public function test_user_register() {
		$user_id = get_current_user_id();
		$class   = new Obenland_Wp_Approve_User();

		$class->user_register( $user_id );

		$this->assertTrue( get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertTrue( get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
	}
}
