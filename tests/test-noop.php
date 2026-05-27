<?php
/**
 * Tests for noop.php (disabled-registration fallback).
 *
 * @package wp-approve-user
 */

/**
 * Covers noop.php functions.
 */
class WPAU_Noop_Test extends WP_UnitTestCase {

	/**
	 * Returns the filtered value unchanged when registration is being turned off.
	 *
	 * @covers ::wpau_whitelist_users
	 */
	public function test_whitelist_users_returns_value_unchanged_when_falsy() {
		$this->assertSame( 0, wpau_whitelist_users( 0 ) );
		$this->assertFalse( wpau_whitelist_users( false ) );
	}

	/**
	 * Marks every existing user as `'approved'` when registration is being enabled, so the main plugin's login gate recognises them on its next load.
	 *
	 * @covers ::wpau_whitelist_users
	 */
	public function test_whitelist_users_marks_all_users_approved() {
		$user_one = self::factory()->user->create();
		$user_two = self::factory()->user->create();

		delete_user_meta( $user_one, 'wp-approve-user' );
		delete_user_meta( $user_two, 'wp-approve-user' );

		$this->assertSame( 1, wpau_whitelist_users( 1 ) );
		$this->assertSame( 'approved', Obenland_Wp_Approve_User::read_status_raw( $user_one ) );
		$this->assertSame( 'approved', Obenland_Wp_Approve_User::read_status_raw( $user_two ) );
		$this->assertSame( '1', get_user_meta( $user_one, 'wp-approve-user-mail-sent', true ) );
	}

	/**
	 * Shows the "enable registration" admin notice outside the Options screens.
	 *
	 * @covers ::wpau_add_settings_error
	 */
	public function test_add_settings_error_outside_options_screen_registers_notice() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		set_current_screen( 'users' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		wpau_add_settings_error();

		$errors = get_settings_errors( 'wp-approve-user' );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'no-users-can-register', $errors[0]['code'] );
	}

	/**
	 * Suppresses the notice on the Options screens where the link would point back to itself.
	 *
	 * @covers ::wpau_add_settings_error
	 */
	public function test_add_settings_error_bails_on_options_screen() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		set_current_screen( 'options-general' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		wpau_add_settings_error();

		$this->assertEmpty( get_settings_errors( 'wp-approve-user' ) );
	}

	/**
	 * Does not show the notice to users who cannot manage_options.
	 *
	 * @covers ::wpau_add_settings_error
	 */
	public function test_add_settings_error_bails_without_manage_options_cap() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		set_current_screen( 'users' );

		wpau_add_settings_error();

		$this->assertEmpty( get_settings_errors( 'wp-approve-user' ) );
	}

	/**
	 * Prints notices registered under the plugin slug.
	 *
	 * @covers ::wpau_settings_errors
	 */
	public function test_settings_errors_prints_registered_notice() {
		add_settings_error( 'wp-approve-user', 'test', 'Test message', 'notice-info' );

		ob_start();
		wpau_settings_errors();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test message', $output );
	}
}
