<?php
/**
 * Tests for wp-approve-user.php (the plugin entry file).
 *
 * @package wp-approve-user
 */

/**
 * Covers the plugin entry helpers.
 */
class WPAU_Plugin_Loader_Test extends WP_UnitTestCase {

	/**
	 * The plugins_loaded callback instantiates and stores the main-class singleton.
	 *
	 * @covers ::wp_approve_user_instantiate
	 */
	public function test_wp_approve_user_instantiate_returns_singleton() {
		Obenland_Wp_Approve_User::$instance = null;

		wp_approve_user_instantiate();

		$this->assertInstanceOf(
			Obenland_Wp_Approve_User::class,
			Obenland_Wp_Approve_User::$instance
		);
	}

	/**
	 * The register_activation_hook callback approves existing users via wpau_allowlist_users().
	 *
	 * @covers ::wp_approve_user_activate
	 */
	public function test_wp_approve_user_activate_allowlists_users() {
		$user_id = self::factory()->user->create();
		delete_user_meta( $user_id, 'wp-approve-user' );
		wp_clear_scheduled_hook( 'wpau_allowlist_users_cron' );

		wp_approve_user_activate();

		$this->assertSame( 'approved', get_user_meta( $user_id, 'wp-approve-user', true ) );

		/*
		 * wpau_allowlist_users() schedules with `array( $processed )` args, so
		 * wp_next_scheduled() with no args wouldn't detect it. Walk the cron
		 * array instead to catch any scheduled event for this hook.
		 */
		$cron_array = _get_cron_array();
		$scheduled  = false;
		foreach ( (array) $cron_array as $events ) {
			if ( isset( $events['wpau_allowlist_users_cron'] ) ) {
				$scheduled = true;
				break;
			}
		}
		$this->assertFalse( $scheduled, 'Small-site activation should not leave an orphan cron.' );
	}
}
