<?php
/**
 * Tests for uninstall.php.
 *
 * The file has no functions — its executable code only runs when the file is
 * included with `WP_UNINSTALL_PLUGIN` defined. We include it manually inside
 * tests (inside the transactional test case, so the deletes are rolled back).
 *
 * @package wp-approve-user
 */

/**
 * Covers uninstall.php.
 */
class WPAU_Uninstall_Test extends WP_UnitTestCase {

	/**
	 * Including uninstall.php without WP_UNINSTALL_PLUGIN defined bails via wp_die().
	 */
	public function test_uninstall_requires_wp_uninstall_plugin_constant() {
		if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			$this->markTestSkipped( 'WP_UNINSTALL_PLUGIN is already defined in this process.' );
		}

		$this->expectException( WPDieException::class );
		include dirname( __DIR__ ) . '/uninstall.php';
	}

	/**
	 * Including uninstall.php with the constant defined clears plugin options and user meta.
	 *
	 * Runs in a separate process because `WP_UNINSTALL_PLUGIN` is a
	 * process-wide constant that can't be unset once defined, so the
	 * rest of the suite would otherwise see the uninstall flag for every
	 * subsequent test in the same PHP process.
	 *
	 * @depends test_uninstall_requires_wp_uninstall_plugin_constant
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_clears_plugin_data() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'wp-approve-user', 'approved' );
		update_user_meta( $user_id, 'wp-approve-user-mail-sent', true );
		update_user_meta( $user_id, 'wp-approve-user-new-registration', true );
		update_user_meta( $user_id, 'wp-approve-user-ip', '203.0.113.7' );
		update_option( 'wp-approve-user', array( 'foo' => 'bar' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		include dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'wp-approve-user' ) );
		$this->assertSame( '', Obenland_Wp_Approve_User::read_status_raw( $user_id ) );
		$this->assertSame( '', get_user_meta( $user_id, 'wp-approve-user-mail-sent', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'wp-approve-user-ip', true ) );
	}
}
