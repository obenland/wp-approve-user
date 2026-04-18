<?php
/**
 * Tests for the rule-based auto-approval feature.
 *
 * @package wp-approve-user
 */

/**
 * Covers auto_approve_user, sanitize_auto_approve_rules, and the
 * wpau_auto_approve_rules filter surface.
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
 */
class WPAU_Auto_Approval_Test extends WP_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var WP_User
	 */
	public static $admin;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin = $factory->user->create_and_get( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( self::$admin->ID );
		}
	}

	/**
	 * Resets the plugin option and the logged-in user before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'wp-approve-user' );
		wp_set_current_user( 0 );
	}

	/**
	 * Cleans up the plugin option after each test.
	 */
	public function tear_down() {
		delete_option( 'wp-approve-user' );
		parent::tear_down();
	}

	/**
	 * Builds a subscriber user for auto-approval tests.
	 *
	 * @param string $email Email address to assign.
	 * @return WP_User
	 */
	protected function make_subscriber( $email ) {
		return self::factory()->user->create_and_get(
			array(
				'role'       => 'subscriber',
				'user_email' => $email,
			)
		);
	}

	/**
	 * Writes a pre-sanitized rules array straight to the option so the
	 * constructor picks it up.
	 *
	 * @param array $rules List of rule arrays to store.
	 */
	protected function store_rules( $rules ) {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-send-unapprove-email' => false,
				'wpau-approve-email'        => '',
				'wpau-unapprove-email'      => '',
				'auto_approve_rules'        => $rules,
			)
		);
	}

	/**
	 * Flips the meta to 'approved' and fires wpau_approve when the email
	 * domain rule matches.
	 *
	 * @covers ::auto_approve_user
	 * @covers ::auto_approve_rule_matches
	 */
	public function test_matching_email_domain_rule_approves_and_fires_action() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
			)
		);

		$user = $this->make_subscriber( 'new-hire@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$fired    = array();
		$listener = function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'wpau_approve', $listener );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->auto_approve_user( $user->ID );

			$this->assertSame( 'approved', get_user_meta( $user->ID, 'wp-approve-user', true ) );
			$this->assertSame( array( $user->ID ), $fired );
		} finally {
			remove_action( 'wpau_approve', $listener );
		}
	}

	/**
	 * Email domain comparison is case-insensitive and ignores sub-addresses.
	 *
	 * @covers ::auto_approve_rule_matches
	 */
	public function test_email_domain_match_is_case_insensitive() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
			)
		);

		$user = $this->make_subscriber( 'someone@EXAMPLE.TEST' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$instance = new Obenland_Wp_Approve_User();
		$instance->auto_approve_user( $user->ID );

		$this->assertSame( 'approved', get_user_meta( $user->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Non-matching domains leave the user pending.
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_non_matching_rule_leaves_user_pending() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'mycompany.com',
				),
			)
		);

		$user = $this->make_subscriber( 'stranger@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$instance = new Obenland_Wp_Approve_User();
		$instance->auto_approve_user( $user->ID );

		$this->assertSame( 'pending', get_user_meta( $user->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Rules added via the wpau_auto_approve_rules filter are honoured.
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_filter_added_rule_is_evaluated() {
		// Nothing stored in the option — filter is the only source.
		$this->store_rules( array() );

		$user = $this->make_subscriber( 'dev@filter.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$filter = function () {
			return array(
				array(
					'type'  => 'email_domain',
					'value' => 'filter.test',
				),
			);
		};
		add_filter( 'wpau_auto_approve_rules', $filter );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->auto_approve_user( $user->ID );

			$this->assertSame( 'approved', get_user_meta( $user->ID, 'wp-approve-user', true ) );
		} finally {
			remove_filter( 'wpau_auto_approve_rules', $filter );
		}
	}

	/**
	 * When multiple rules match the first one wins and later rules are not
	 * evaluated (proved indirectly by asserting wpau_approve only fires once).
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_first_matching_rule_wins() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
			)
		);

		$user = $this->make_subscriber( 'first-match@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$count    = 0;
		$listener = function () use ( &$count ) {
			++$count;
		};
		add_action( 'wpau_approve', $listener );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->auto_approve_user( $user->ID );

			$this->assertSame( 1, $count );
			$this->assertSame( 'approved', get_user_meta( $user->ID, 'wp-approve-user', true ) );
		} finally {
			remove_action( 'wpau_approve', $listener );
		}
	}

	/**
	 * An empty (or missing) rules list is a safe no-op.
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_empty_rules_list_is_noop() {
		$this->store_rules( array() );

		$user = $this->make_subscriber( 'nobody@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$instance = new Obenland_Wp_Approve_User();
		$instance->auto_approve_user( $user->ID );

		$this->assertSame( 'pending', get_user_meta( $user->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Admin-created users already carry 'approved' meta when auto_approve_user
	 * runs — the handler must not re-fire wpau_approve for them (which would
	 * double-send the welcome email).
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_admin_created_user_is_skipped() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
			)
		);

		$user = $this->make_subscriber( 'admin-hire@example.test' );

		/*
		 * Simulate the user_register() path for an admin-created user: meta is
		 * already 'approved', so auto_approve_user should bail out early.
		 */
		update_user_meta( $user->ID, 'wp-approve-user', 'approved' );

		$fired    = 0;
		$listener = function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'wpau_approve', $listener );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->auto_approve_user( $user->ID );

			$this->assertSame( 0, $fired );
			$this->assertSame( 'approved', get_user_meta( $user->ID, 'wp-approve-user', true ) );
		} finally {
			remove_action( 'wpau_approve', $listener );
		}
	}

	/**
	 * Malformed rule entries (non-array, missing keys) are ignored without
	 * throwing and without approving.
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_malformed_rule_entries_are_ignored() {
		$this->store_rules(
			array(
				'not-an-array',
				array( 'type' => 'email_domain' ),
				array( 'value' => 'example.test' ),
				array(
					'type'  => 'unknown_type',
					'value' => 'example.test',
				),
			)
		);

		$user = $this->make_subscriber( 'still-pending@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$instance = new Obenland_Wp_Approve_User();
		$instance->auto_approve_user( $user->ID );

		$this->assertSame( 'pending', get_user_meta( $user->ID, 'wp-approve-user', true ) );
	}

	/**
	 * The wpau_auto_approve_rules filter receives the user ID so developers
	 * can customise per-user.
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_filter_receives_user_id() {
		$this->store_rules( array() );

		$user = $this->make_subscriber( 'per-user@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$captured = null;
		$filter   = function ( $rules, $user_id ) use ( &$captured ) {
			$captured = $user_id;
			return $rules;
		};
		add_filter( 'wpau_auto_approve_rules', $filter, 10, 2 );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->auto_approve_user( $user->ID );

			$this->assertSame( $user->ID, $captured );
		} finally {
			remove_filter( 'wpau_auto_approve_rules', $filter, 10 );
		}
	}

	/**
	 * Default options include an empty auto_approve_rules array so the
	 * plugin option has a stable shape on new installs.
	 *
	 * @covers ::default_options
	 */
	public function test_default_options_include_auto_approve_rules_key() {
		$instance = new Obenland_Wp_Approve_User();
		$method   = new ReflectionMethod( $instance, 'default_options' );
		$method->setAccessible( true );
		$defaults = $method->invoke( $instance );

		$this->assertArrayHasKey( 'auto_approve_rules', $defaults );
		$this->assertSame( array(), $defaults['auto_approve_rules'] );
	}










	/**
	 * A ghost user id (pending meta but no user row) bails without firing approve.
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_auto_approve_user_bails_on_missing_user() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
			)
		);

		$ghost_id = 9000001;
		update_user_meta( $ghost_id, 'wp-approve-user', 'pending' );

		$fired = 0;
		add_action(
			'wpau_approve',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		$instance = new Obenland_Wp_Approve_User();
		$instance->auto_approve_user( $ghost_id );

		delete_user_meta( $ghost_id, 'wp-approve-user' );

		$this->assertSame( 0, $fired );
	}

	/**
	 * Empty rule values (after sanitize) return false from the matcher.
	 *
	 * @covers ::auto_approve_rule_matches
	 */
	public function test_rule_matches_returns_false_for_empty_domain_value() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => '@', /* Sanitizes to an empty string. */
				),
			)
		);

		$user = $this->make_subscriber( 'someone@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		$instance = new Obenland_Wp_Approve_User();
		$instance->auto_approve_user( $user->ID );

		$this->assertSame( 'pending', get_user_meta( $user->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Unknown rule types return false instead of throwing.
	 *
	 * @covers ::auto_approve_user
	 * @covers ::auto_approve_rule_matches
	 */
	public function test_rule_matches_returns_false_for_unknown_rule_type() {
		$filter = function () {
			return array(
				array(
					'type'  => 'nonsense',
					'value' => 'anything',
				),
			);
		};
		add_filter( 'wpau_auto_approve_rules', $filter );

		$user = $this->make_subscriber( 'someone@example.test' );
		update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$instance->auto_approve_user( $user->ID );
		} finally {
			remove_filter( 'wpau_auto_approve_rules', $filter );
		}

		$this->assertSame( 'pending', get_user_meta( $user->ID, 'wp-approve-user', true ) );
	}



	/**
	 * Sanitize_email_domain() returns an empty string for empty input.
	 *
	 * @covers ::sanitize_email_domain
	 */
	public function test_sanitize_email_domain_returns_empty_on_empty_input() {
		$this->assertSame( '', Obenland_Wp_Approve_User::sanitize_email_domain( '' ) );
		$this->assertSame( '', Obenland_Wp_Approve_User::sanitize_email_domain( '   ' ) );
	}

	/**
	 * Rejects values that don't look like a bare domain.
	 *
	 * @covers ::sanitize_email_domain
	 */
	public function test_sanitize_email_domain_rejects_malformed_values() {
		/* Internal whitespace. */
		$this->assertSame( '', Obenland_Wp_Approve_User::sanitize_email_domain( 'bad domain.test' ) );
		/* Still contains an @ after the leading-@ strip. */
		$this->assertSame( '', Obenland_Wp_Approve_User::sanitize_email_domain( 'user@example.test' ) );
		/* No dot at all — not a domain. */
		$this->assertSame( '', Obenland_Wp_Approve_User::sanitize_email_domain( 'localhost' ) );
	}

	/**
	 * Returns the normalized domain on the happy path.
	 *
	 * @covers ::sanitize_email_domain
	 */
	public function test_sanitize_email_domain_normalises_valid_input() {
		$this->assertSame(
			'example.test',
			Obenland_Wp_Approve_User::sanitize_email_domain( '  @Example.TEST ' )
		);
	}

	/**
	 * Auto_approve_user coerces a non-array stored rules value to an empty list.
	 *
	 * @covers ::auto_approve_user
	 */
	public function test_auto_approve_user_handles_non_array_rules() {
		$filter = function ( $defaults ) {
			unset( $defaults['auto_approve_rules'] );
			return $defaults;
		};
		add_filter( 'wpau_default_options', $filter );

		try {
			update_option(
				'wp-approve-user',
				array(
					'wpau-send-approve-email'   => false,
					'wpau-send-unapprove-email' => false,
					'wpau-approve-email'        => '',
					'wpau-unapprove-email'      => '',
					'auto_approve_rules'        => 'not-an-array',
				)
			);

			$user = $this->make_subscriber( 'nomatch@example.test' );
			update_user_meta( $user->ID, 'wp-approve-user', 'pending' );

			$instance = new Obenland_Wp_Approve_User();
			$instance->auto_approve_user( $user->ID );

			$this->assertSame( 'pending', get_user_meta( $user->ID, 'wp-approve-user', true ) );
		} finally {
			remove_filter( 'wpau_default_options', $filter );
		}
	}
}
