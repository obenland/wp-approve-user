<?php
/**
 * Tests for the pending-user admin email notification.
 *
 * @package wp-approve-user
 */

/**
 * Covers Obenland_Wp_Approve_User::wp_new_user_notification_email_admin().
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
 */
class WPAU_Pending_Notification_Test extends WP_UnitTestCase {

	/**
	 * Subscriber fixture used as the newly registered user.
	 *
	 * @var WP_User
	 */
	public static $user;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$user = $factory->user->create_and_get( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Builds the canonical email array WP core would pass to the filter.
	 *
	 * @return array
	 */
	protected function base_email() {
		return array(
			'to'      => 'admin@example.test',
			'subject' => '[Test] New User Registration',
			'message' => "New user registration on your site Test:\r\n\r\nUsername: bob\r\n\r\nEmail: bob@example.test\r\n",
			'headers' => '',
		);
	}

	/**
	 * Appends the pending-users URL to the admin email when the user is pending.
	 *
	 * @covers ::wp_new_user_notification_email_admin
	 */
	public function test_appends_pending_url_for_pending_user() {
		update_user_meta( self::$user->ID, 'wp-approve-user', 'pending' );

		$instance = new Obenland_Wp_Approve_User();
		$email    = $instance->wp_new_user_notification_email_admin( $this->base_email(), self::$user, 'Test' );

		$expected_url = is_multisite()
			? network_admin_url( 'users.php?role=wpau_pending' )
			: admin_url( 'users.php?role=wpau_pending' );

		$this->assertStringContainsString( 'Review pending users:', $email['message'] );
		$this->assertStringContainsString( $expected_url, $email['message'] );
	}

	/**
	 * Leaves the admin email untouched when the user is already approved.
	 *
	 * @covers ::wp_new_user_notification_email_admin
	 */
	public function test_leaves_email_untouched_for_approved_user() {
		update_user_meta( self::$user->ID, 'wp-approve-user', 'approved' );

		$base     = $this->base_email();
		$instance = new Obenland_Wp_Approve_User();
		$email    = $instance->wp_new_user_notification_email_admin( $base, self::$user, 'Test' );

		$this->assertSame( $base, $email );
		$this->assertStringNotContainsString( 'Review pending users:', $email['message'] );
	}

}
