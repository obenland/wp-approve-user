<?php
/**
 * Tests for the WordPress Abilities API integration.
 *
 * @package wp-approve-user
 */

/**
 * Covers abilities.php registration and callbacks.
 *
 * @coversNothing
 */
class WPAU_Abilities_Test extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	public static $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	public static $subscriber_id;

	/**
	 * Creates reusable fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		static::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		static::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Skips the entire class when the Abilities API is unavailable.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API is not available on this WordPress version.' );
		}

		/*
		 * Ensure abilities.php is loaded — tests/bootstrap.php requires wp-approve-user.php,
		 * which in turn requires abilities.php when the Abilities API is present, but
		 * load it directly here for safety in case the gate short-circuits in the harness.
		 */
		if ( ! function_exists( 'wpau_register_abilities' ) ) {
			require_once dirname( __DIR__ ) . '/abilities.php';
		}

		/*
		 * Requiring abilities.php only attaches wpau_register_abilities() to the
		 * wp_abilities_api_init hook. If that hook already fired during the test
		 * harness bootstrap, register the abilities directly so the assertions do
		 * not depend on hook timing.
		 */
		if (
			function_exists( 'wpau_register_abilities' )
			&& (
				! wp_has_ability( 'wp-approve-user/approve' )
				|| ! wp_has_ability( 'wp-approve-user/unapprove' )
			)
		) {
			wpau_register_abilities();
		}
	}

	/**
	 * Resets state between tests.
	 */
	public function tear_down() {
		delete_user_meta( static::$admin_id, 'wp-approve-user' );
		delete_user_meta( static::$subscriber_id, 'wp-approve-user' );

		parent::tear_down();
	}

	/**
	 * Both abilities should register into the core registry.
	 */
	public function test_abilities_are_registered() {
		$this->assertTrue( wp_has_ability( 'wp-approve-user/approve' ) );
		$this->assertTrue( wp_has_ability( 'wp-approve-user/unapprove' ) );
	}

	/**
	 * The permission callback denies users who can't promote_users.
	 */
	public function test_permission_callback_rejects_subscriber() {
		wp_set_current_user( static::$subscriber_id );

		$result = wpau_ability_permission_callback();
		$this->assertWPError( $result );
		$this->assertSame( 'wpau_rest_forbidden', $result->get_error_code() );
	}

	/**
	 * The permission callback allows users who can promote_users (admins).
	 */
	public function test_permission_callback_allows_admin() {
		wp_set_current_user( static::$admin_id );

		if ( is_multisite() ) {
			grant_super_admin( static::$admin_id );
		}

		$this->assertTrue( wpau_ability_permission_callback() );
	}

	/**
	 * The approve callback updates meta and fires wpau_approve.
	 */
	public function test_approve_callback_updates_meta_and_fires_action() {
		$fired = array();
		$spy   = function ( $user_id ) use ( &$fired ) {
			$fired[] = $user_id;
		};
		add_action( 'wpau_approve', $spy );

		$result = wpau_ability_approve_callback( array( 'user_id' => static::$subscriber_id ) );

		remove_action( 'wpau_approve', $spy );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( static::$subscriber_id, $result['user_id'] );
		$this->assertSame( 'approved', $result['status'] );
		$this->assertSame( 'approved', get_user_meta( static::$subscriber_id, 'wp-approve-user', true ) );
		$this->assertSame( array( static::$subscriber_id ), $fired );
	}

	/**
	 * The unapprove callback updates meta and fires wpau_unapprove.
	 */
	public function test_unapprove_callback_updates_meta_and_fires_action() {
		update_user_meta( static::$subscriber_id, 'wp-approve-user', 'approved' );

		$fired = array();
		$spy   = function ( $user_id ) use ( &$fired ) {
			$fired[] = $user_id;
		};
		add_action( 'wpau_unapprove', $spy );

		$result = wpau_ability_unapprove_callback( array( 'user_id' => static::$subscriber_id ) );

		remove_action( 'wpau_unapprove', $spy );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( static::$subscriber_id, $result['user_id'] );
		$this->assertSame( 'unapproved', $result['status'] );
		$this->assertSame( 'unapproved', get_user_meta( static::$subscriber_id, 'wp-approve-user', true ) );
		$this->assertSame( array( static::$subscriber_id ), $fired );
	}

	/**
	 * The callbacks return a WP_Error for unknown user IDs.
	 */
	public function test_approve_callback_rejects_unknown_user() {
		$result = wpau_ability_approve_callback( array( 'user_id' => 9_999_999 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wpau_invalid_user', $result->get_error_code() );
	}

	/**
	 * The callbacks refuse to modify the admin_email account to avoid accidental lockouts.
	 */
	public function test_callbacks_reject_admin_email_user() {
		$target       = get_userdata( static::$subscriber_id );
		$original     = get_option( 'admin_email' );
		$restore_mail = null;
		update_option( 'admin_email', $target->user_email );

		/*
		 * update_option( 'admin_email' ) on multisite defers the change until
		 * the user confirms via email, so force the change through for tests.
		 */
		if ( get_bloginfo( 'admin_email' ) !== $target->user_email ) {
			$restore_mail = function () use ( $target ) {
				return $target->user_email;
			};
			add_filter( 'pre_option_admin_email', $restore_mail );
		}

		try {
			$this->assertSame( $target->user_email, get_bloginfo( 'admin_email' ) );

			$approve = wpau_ability_approve_callback( array( 'user_id' => static::$subscriber_id ) );
			$this->assertWPError( $approve );
			$this->assertSame( 'wpau_cannot_edit_admin_email', $approve->get_error_code() );

			$unapprove = wpau_ability_unapprove_callback( array( 'user_id' => static::$subscriber_id ) );
			$this->assertWPError( $unapprove );
			$this->assertSame( 'wpau_cannot_edit_admin_email', $unapprove->get_error_code() );
		} finally {
			if ( null !== $restore_mail ) {
				remove_filter( 'pre_option_admin_email', $restore_mail );
			}
			update_option( 'admin_email', $original );
		}
	}

	/**
	 * The permission callback denies edits when the current user can promote but not edit the target.
	 */
	public function test_permission_callback_rejects_when_cannot_edit_target() {
		wp_set_current_user( static::$admin_id );

		if ( is_multisite() ) {
			grant_super_admin( static::$admin_id );
		}

		$blocker = function ( $allcaps, $caps, $args ) {
			if ( isset( $args[0], $args[2] ) && 'edit_user' === $args[0] && (int) $args[2] === static::$subscriber_id ) {
				$allcaps['edit_users'] = false;
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = false;
				}
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $blocker, 10, 3 );

		$result = wpau_ability_permission_callback( array( 'user_id' => static::$subscriber_id ) );

		remove_filter( 'user_has_cap', $blocker, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wpau_rest_forbidden', $result->get_error_code() );
	}

	/**
	 * The registered input schema rejects non-integer user IDs via rest_validate_value_from_schema().
	 */
	public function test_input_schema_rejects_non_integer_user_id() {
		$ability = wp_get_ability( 'wp-approve-user/approve' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertIsArray( $schema );

		$valid = rest_validate_value_from_schema( array( 'user_id' => 'not-an-int' ), $schema );
		$this->assertWPError( $valid );

		$ok = rest_validate_value_from_schema( array( 'user_id' => 42 ), $schema );
		$this->assertTrue( $ok );
	}
}
