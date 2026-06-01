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
	public function set_up() {
		parent::set_up();

		wp_set_current_user( static::$admin->ID );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		delete_metadata( 'user', 0, 'wp-approve-user', '', true );
		delete_metadata( 'user', 0, 'wp-approve-user-mail-sent', '', true );
		delete_metadata( 'user', 0, 'wp-approve-user-new-registration', '', true );

		parent::tear_down();
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

		$this->assertSame( 'approved', Obenland_Wp_Approve_User::read_status_raw( $user_id ) );
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

		$this->assertSame( 'pending', Obenland_Wp_Approve_User::read_status_raw( $user_id ) );
		$this->assertSame( '1', get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests user_register.
	 *
	 * @covers ::user_register
	 */
	public function test_user_register_subscriber() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );

		$class = new Obenland_Wp_Approve_User();

		$class->user_register( $user->ID );

		$this->assertSame( 'pending', Obenland_Wp_Approve_User::read_status_raw( $user->ID ) );
		$this->assertSame( '1', get_user_meta( $user->ID, 'wp-approve-user-new-registration', true ) );
	}

	/**
	 * Tests wp_authenticate_user.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );
		$class = new Obenland_Wp_Approve_User();

		// Returns WP_Error if there's an error.
		$error  = new WP_Error( 'test_error', 'Test Error' );
		$result = $class->wp_authenticate_user( $error );
		$this->assertWPError( $result );
		$this->assertSame( 'test_error', $error->get_error_code() );

		// Returns WP_Error if they're not approved.
		$result = $class->wp_authenticate_user( $user );
		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );
	}

	/**
	 * Tests that users with no wp-approve-user meta at all can log in —
	 * i.e. pre-plugin-install users or users orphaned by an incomplete
	 * activation cron.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_missing_meta_is_treated_as_approved() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		// No meta set at all — this is the pre-plugin-install state.
		$this->assertSame( '', Obenland_Wp_Approve_User::read_status_raw( $user->ID ) );

		$class  = new Obenland_Wp_Approve_User();
		$result = $class->wp_authenticate_user( $user );

		$this->assertSame( $user, $result );
	}

	/**
	 * Legacy pre-v12 installs stored a `false` boolean (persisted as an
	 * empty string) to represent pending users. Those rows still exist
	 * in the database until wpau_upgrade_to_12() migrates them, so the
	 * login gate must distinguish "meta row missing" (approved) from
	 * "meta row present but empty" (still pending).
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_empty_legacy_meta_is_still_blocked() {
		global $wpdb;

		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		/*
		 * Bypass the sanitize filter — on V12+ installs `update_user_meta()`
		 * now coerces `false` to `'pending'`, so the legacy DB state has to
		 * be written straight to the usermeta table.
		 */
		delete_user_meta( $user->ID, 'wp-approve-user' );
		// phpcs:disable WordPress.DB
		$wpdb->insert(
			$wpdb->usermeta,
			array(
				'user_id'    => $user->ID,
				'meta_key'   => 'wp-approve-user',
				'meta_value' => '',
			)
		);
		// phpcs:enable WordPress.DB
		wp_cache_delete( $user->ID, 'user_meta' );

		$this->assertSame( '', Obenland_Wp_Approve_User::read_status_raw( $user->ID ) );
		$this->assertTrue( metadata_exists( 'user', $user->ID, 'wp-approve-user' ) );

		$class  = new Obenland_Wp_Approve_User();
		$result = $class->wp_authenticate_user( $user );

		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );
	}

	/**
	 * Unapproved users remain blocked — the missing-meta fix must not
	 * accidentally unblock users who were explicitly unapproved.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_unapproved_is_still_blocked() {
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', 'unapproved' );

		$class  = new Obenland_Wp_Approve_User();
		$result = $class->wp_authenticate_user( $user );

		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );
	}

	/**
	 * Legacy third-party integrations (e.g. Restrict Content Pro's
	 * approve button) still call `update_user_meta( $id, 'wp-approve-user', true )`
	 * with a boolean. Ensure the sanitize filter coerces that to the
	 * canonical `'approved'` string so the login gate recognises the user.
	 *
	 * @covers ::sanitize_status_meta
	 */
	public function test_sanitize_coerces_legacy_boolean_true_to_approved() {
		// Instantiation registers the user meta sanitize callback.
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', true );

		$this->assertSame( 'approved', Obenland_Wp_Approve_User::read_status_raw( $user->ID ) );
	}

	/**
	 * The `false` side of the legacy boolean API needs to land as
	 * `'pending'` to match `wpau_upgrade_to_12()`'s one-shot migration.
	 *
	 * @covers ::sanitize_status_meta
	 */
	public function test_sanitize_coerces_legacy_boolean_false_to_pending() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', false );

		$this->assertSame( 'pending', Obenland_Wp_Approve_User::read_status_raw( $user->ID ) );
	}

	/**
	 * Integer and numeric-string variants of the legacy API should get
	 * the same treatment as the raw booleans.
	 *
	 * @covers ::sanitize_status_meta
	 */
	public function test_sanitize_coerces_numeric_variants() {
		new Obenland_Wp_Approve_User();

		$one_int    = static::factory()->user->create();
		$one_string = static::factory()->user->create();
		$zero_int   = static::factory()->user->create();
		$zero_str   = static::factory()->user->create();

		update_user_meta( $one_int, 'wp-approve-user', 1 );
		update_user_meta( $one_string, 'wp-approve-user', '1' );
		update_user_meta( $zero_int, 'wp-approve-user', 0 );
		update_user_meta( $zero_str, 'wp-approve-user', '0' );

		$this->assertSame( 'approved', Obenland_Wp_Approve_User::read_status_raw( $one_int ) );
		$this->assertSame( 'approved', Obenland_Wp_Approve_User::read_status_raw( $one_string ) );
		$this->assertSame( 'pending', Obenland_Wp_Approve_User::read_status_raw( $zero_int ) );
		$this->assertSame( 'pending', Obenland_Wp_Approve_User::read_status_raw( $zero_str ) );
	}

	/**
	 * Canonical three-state strings must pass through the sanitize filter
	 * untouched — the filter only normalizes legacy values.
	 *
	 * @covers ::sanitize_status_meta
	 */
	public function test_sanitize_passes_canonical_values_through() {
		new Obenland_Wp_Approve_User();

		$approved   = static::factory()->user->create();
		$unapproved = static::factory()->user->create();
		$pending    = static::factory()->user->create();

		update_user_meta( $approved, 'wp-approve-user', 'approved' );
		update_user_meta( $unapproved, 'wp-approve-user', 'unapproved' );
		update_user_meta( $pending, 'wp-approve-user', 'pending' );

		$this->assertSame( 'approved', Obenland_Wp_Approve_User::read_status_raw( $approved ) );
		$this->assertSame( 'unapproved', Obenland_Wp_Approve_User::read_status_raw( $unapproved ) );
		$this->assertSame( 'pending', Obenland_Wp_Approve_User::read_status_raw( $pending ) );
	}

	/**
	 * Unexpected scalars (neither canonical three-state strings nor the
	 * legacy boolean API) pass through untouched so data bugs surface
	 * instead of being silently rewritten.
	 *
	 * @covers ::sanitize_status_meta
	 */
	public function test_sanitize_passes_unknown_values_through() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();
		update_user_meta( $user, 'wp-approve-user', 'banana' );

		$this->assertSame( 'banana', Obenland_Wp_Approve_User::read_status_raw( $user ) );
	}

	/**
	 * End-to-end: a legacy integration that approves via boolean `true`
	 * should unlock the login gate.
	 *
	 * @covers ::sanitize_status_meta
	 * @covers ::wp_authenticate_user
	 */
	public function test_legacy_boolean_true_unlocks_login_gate() {
		$class = new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		// Simulate a third-party integration approving via the boolean API.
		update_user_meta( $user->ID, 'wp-approve-user', true );

		$this->assertSame( $user, $class->wp_authenticate_user( $user ) );
	}

	/**
	 * Tests wp_authenticate_user on single sites.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_simple_site() {
		$this->skipWithMultisite();
		$class = new Obenland_Wp_Approve_User();

		// Returns WP_User for admins, even if they're unapproved.
		update_user_meta( static::$admin->ID, 'wp-approve-user', 'unapproved' );
		$result = $class->wp_authenticate_user( static::$admin );
		$this->assertSame( static::$admin, $result );
	}

	/**
	 * Tests wp_authenticate_user on multisite.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_multisite() {
		$this->skipWithoutMultisite();
		$class = new Obenland_Wp_Approve_User();

		// Returns WP_Error for admins when they're unapproved.
		update_user_meta( static::$admin->ID, 'wp-approve-user', 'unapproved' );
		$result = $class->wp_authenticate_user( static::$admin );
		$this->assertWPError( $result );
		$this->assertSame( 'wpau_confirmation_error', $result->get_error_code() );

		// Returns WP_User for super admins, even if they're unapproved.
		$user = static::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		grant_super_admin( $user->ID );
		update_user_meta( $user->ID, 'wp-approve-user', 'unapproved' );

		$result = $class->wp_authenticate_user( $user );
		$this->assertSame( $user, $result );
	}

	/**
	 * Third-party integrations (most notably Restrict Content Pro) read the
	 * meta with `! get_user_meta( $id, 'wp-approve-user', true )` to decide
	 * whether a user is pending. With raw three-state strings every state is
	 * truthy, so pending/unapproved users sneak past the gate. The read
	 * filter has to translate canonical strings back to legacy booleans so
	 * that consumer pattern still works.
	 *
	 * @covers ::translate_status_read
	 */
	public function test_read_filter_translates_pending_to_false() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();
		update_user_meta( $user, 'wp-approve-user', 'pending' );

		$this->assertFalse( get_user_meta( $user, 'wp-approve-user', true ) );
	}

	/**
	 * Unapproved users must also surface as legacy-falsy so RCP's
	 * `is_pending()` check blocks them from restricted content.
	 *
	 * @covers ::translate_status_read
	 */
	public function test_read_filter_translates_unapproved_to_false() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();
		update_user_meta( $user, 'wp-approve-user', 'unapproved' );

		$this->assertFalse( get_user_meta( $user, 'wp-approve-user', true ) );
	}

	/**
	 * Approved users must surface as legacy-truthy.
	 *
	 * @covers ::translate_status_read
	 */
	public function test_read_filter_translates_approved_to_true() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();
		update_user_meta( $user, 'wp-approve-user', 'approved' );

		$this->assertTrue( get_user_meta( $user, 'wp-approve-user', true ) );
	}

	/**
	 * Users without any meta row must continue to return the empty default
	 * `get_user_meta()` produces — the filter only translates canonical
	 * values, it doesn't synthesize state.
	 *
	 * @covers ::translate_status_read
	 */
	public function test_read_filter_passes_missing_meta_through() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();

		$this->assertSame( '', get_user_meta( $user, 'wp-approve-user', true ) );
	}

	/**
	 * Other meta keys must be untouched by the wp-approve-user read filter.
	 *
	 * @covers ::translate_status_read
	 */
	public function test_read_filter_ignores_other_meta_keys() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();
		update_user_meta( $user, 'wp-approve-user-mail-sent', 'pending' );

		$this->assertSame( 'pending', get_user_meta( $user, 'wp-approve-user-mail-sent', true ) );
	}

	/**
	 * The `read_status_raw()` helper must bypass the legacy boolean filter
	 * so plugin internals keep seeing the canonical three-state string.
	 *
	 * @covers ::read_status_raw
	 */
	public function test_read_status_raw_returns_canonical_string() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();
		update_user_meta( $user, 'wp-approve-user', 'pending' );

		$this->assertSame( 'pending', Obenland_Wp_Approve_User::read_status_raw( $user ) );
	}

	/**
	 * The `read_status_raw()` helper returns an empty string when no meta
	 * row exists, matching `get_user_meta()`'s contract for missing keys.
	 *
	 * @covers ::read_status_raw
	 */
	public function test_read_status_raw_returns_empty_string_for_missing_meta() {
		new Obenland_Wp_Approve_User();

		$user = static::factory()->user->create();

		$this->assertSame( '', Obenland_Wp_Approve_User::read_status_raw( $user ) );
	}
}
