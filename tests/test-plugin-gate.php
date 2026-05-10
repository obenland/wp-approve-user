<?php
/**
 * Tests for the noop fallback loaded when `users_can_register` is disabled.
 *
 * @package wp-approve-user
 */

/**
 * Covers runtime invariants of the registration-disabled branch.
 */
class WPAU_Plugin_Gate_Test extends WP_UnitTestCase {

	/**
	 * Noop.php (loaded when registration is off) must define the
	 * mass-approval filter that enabling registration later depends on.
	 *
	 * If this function or its hook disappears, sites that flip
	 * `users_can_register` from off to on will no longer retroactively
	 * approve their existing users, and every one of those users will
	 * be locked out at login.
	 */
	public function test_noop_path_defines_allowlist_callback() {
		$this->assertTrue(
			function_exists( 'wpau_whitelist_users' ),
			'noop.php must define wpau_whitelist_users so the disabled-registration fallback can mass-approve users.'
		);
		$this->assertNotFalse(
			has_filter( 'pre_update_option_users_can_register', 'wpau_whitelist_users' ),
			'wpau_whitelist_users must be attached to pre_update_option_users_can_register so it fires when registration is enabled.'
		);
	}
}
