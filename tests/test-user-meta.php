<?php
/**
 * User meta test file.
 *
 * @package wp-approve-user
 */

use PHPUnit\Framework\TestCase;

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
	public static function setUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		parent::setUpBeforeClass( $factory );

		new Obenland_Wp_Approve_User();

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
		$class   = Obenland_Wp_Approve_User::$instance;

		$class->user_register( $user_id );

		$this->assertTrue( get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertTrue( get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
	}
}
