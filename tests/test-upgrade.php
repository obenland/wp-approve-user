<?php
/**
 * Tests for upgrade.php.
 *
 * @package wp-approve-user
 */

/**
 * Covers the upgrade routines.
 */
class WPAU_Upgrade_Test extends WP_UnitTestCase {

	/**
	 * Reset db version before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_site_option( 'wpau_db_version' );
	}

	/**
	 * Runs the v12 meta migration and stamps the current wpau_db_version.
	 *
	 * @covers ::wpau_upgrade_all
	 * @covers ::wpau_upgrade_to_12
	 */
	public function test_upgrade_all_runs_migration_and_sets_version() {
		global $wpau_db_version;

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'wp-approve-user', true );

		wpau_upgrade_all();

		$this->assertSame( $wpau_db_version, (int) get_site_option( 'wpau_db_version' ) );
		$this->assertSame( 'approved', get_user_meta( $user_id, 'wp-approve-user', true ) );
	}

	/**
	 * Skips the migration when wpau_db_version already matches the current version.
	 *
	 * @covers ::wpau_upgrade_all
	 */
	public function test_upgrade_all_bails_when_up_to_date() {
		global $wpau_db_version;

		update_site_option( 'wpau_db_version', $wpau_db_version );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'wp-approve-user', true );

		wpau_upgrade_all();

		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user', true ) );
	}

	/**
	 * Bails when db_version comes back from the database as a string.
	 *
	 * Regression test for https://github.com/obenland/wp-approve-user/issues/54.
	 *
	 * @covers ::wpau_upgrade_all
	 */
	public function test_upgrade_all_bails_when_version_is_string_from_db() {
		global $wpau_db_version;

		update_site_option( 'wpau_db_version', $wpau_db_version );
		wp_cache_flush();

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'wp-approve-user', true );

		$filter = function ( $value, $option ) {
			if ( 'wpau_db_version' === $option ) {
				$this->fail( 'update_site_option should not be called when already up to date.' );
			}
			return $value;
		};
		add_filter( 'pre_update_option', $filter, 10, 2 );

		wpau_upgrade_all();

		remove_filter( 'pre_update_option', $filter );

		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user', true ) );
	}

	/**
	 * Migrates legacy boolean-false meta to the string "pending".
	 *
	 * @covers ::wpau_upgrade_to_12
	 */
	public function test_upgrade_to_12_migrates_false_to_pending() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'wp-approve-user', false );

		wpau_upgrade_to_12();

		$this->assertSame( 'pending', get_user_meta( $user_id, 'wp-approve-user', true ) );
	}

	/**
	 * Stamps users without any wp-approve-user meta as approved so they
	 * are no longer invisible to the admin views or locked out at login.
	 *
	 * @covers ::wpau_upgrade_to_13
	 */
	public function test_upgrade_to_13_stamps_missing_meta_users_as_approved() {
		$missing    = self::factory()->user->create();
		$approved   = self::factory()->user->create();
		$pending    = self::factory()->user->create();
		$unapproved = self::factory()->user->create();

		update_user_meta( $approved, 'wp-approve-user', 'approved' );
		update_user_meta( $pending, 'wp-approve-user', 'pending' );
		update_user_meta( $unapproved, 'wp-approve-user', 'unapproved' );

		wpau_upgrade_to_13();

		$this->assertSame( 'approved', get_user_meta( $missing, 'wp-approve-user', true ) );
		$this->assertSame( 'approved', get_user_meta( $approved, 'wp-approve-user', true ) );
		$this->assertSame( 'pending', get_user_meta( $pending, 'wp-approve-user', true ) );
		$this->assertSame( 'unapproved', get_user_meta( $unapproved, 'wp-approve-user', true ) );
	}

	/**
	 * The 12 → 13 upgrade path runs both migrations on a fresh install
	 * and stamps the current version.
	 *
	 * @covers ::wpau_upgrade_all
	 * @covers ::wpau_upgrade_to_13
	 */
	public function test_upgrade_all_runs_13_migration() {
		global $wpau_db_version;

		$legacy  = self::factory()->user->create();
		$missing = self::factory()->user->create();

		update_user_meta( $legacy, 'wp-approve-user', true );

		wpau_upgrade_all();

		$this->assertSame( $wpau_db_version, (int) get_site_option( 'wpau_db_version' ) );
		$this->assertSame( 'approved', get_user_meta( $legacy, 'wp-approve-user', true ) );
		$this->assertSame( 'approved', get_user_meta( $missing, 'wp-approve-user', true ) );
	}

	/**
	 * When we're already past v12, only the v13 migration runs — legacy
	 * boolean values (which would normally be migrated in wpau_upgrade_to_12)
	 * are left alone.
	 *
	 * @covers ::wpau_upgrade_all
	 */
	public function test_upgrade_all_skips_older_migrations_when_db_version_is_12() {
		update_site_option( 'wpau_db_version', 12 );

		$legacy  = self::factory()->user->create();
		$missing = self::factory()->user->create();

		update_user_meta( $legacy, 'wp-approve-user', true );

		wpau_upgrade_all();

		// v12 migration did NOT run; true stays as '1'.
		$this->assertSame( '1', get_user_meta( $legacy, 'wp-approve-user', true ) );
		// v13 migration DID run; missing-meta user is now approved.
		$this->assertSame( 'approved', get_user_meta( $missing, 'wp-approve-user', true ) );
	}
}
