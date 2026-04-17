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
		// Simulate the user_register() path for an admin-created user: meta is
		// already 'approved', so auto_approve_user should bail out early.
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
	 * Keeps normalised valid email-domain rules through sanitize().
	 *
	 * @covers ::sanitize
	 * @covers ::sanitize_auto_approve_rules
	 * @covers ::sanitize_email_domain
	 */
	public function test_sanitize_keeps_valid_rules_normalised() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->sanitize(
			array(
				'auto_approve_rules' => array(
					array(
						'type'  => 'email_domain',
						'value' => '@Example.Test',
					),
					array(
						'type'  => 'email_domain',
						'value' => '   CONTRACTOR.com  ',
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
				array(
					'type'  => 'email_domain',
					'value' => 'contractor.com',
				),
			),
			$result['auto_approve_rules']
		);
		$this->assertEmpty( get_settings_errors( 'wp-approve-user' ) );
	}

	/**
	 * Empty rows and invalid domains are dropped, with invalid ones surfaced
	 * via add_settings_error.
	 *
	 * @covers ::sanitize_auto_approve_rules
	 * @covers ::sanitize_email_domain
	 */
	public function test_sanitize_drops_empty_and_invalid_rules() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->sanitize(
			array(
				'auto_approve_rules' => array(
					array(
						'type'  => 'email_domain',
						'value' => '',
					),
					array(
						'type'  => 'email_domain',
						'value' => 'bad domain.com',
					),
					array(
						'type'  => 'email_domain',
						'value' => 'user@example.test',
					),
					array(
						'type'  => 'email_domain',
						'value' => 'nolonger',
					),
					array(
						'type'  => 'email_domain',
						'value' => 'valid.test',
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'valid.test',
				),
			),
			$result['auto_approve_rules']
		);

		$errors = get_settings_errors( 'wp-approve-user' );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'wpau_auto_approve_invalid', $errors[0]['code'] );
		$this->assertStringContainsString( 'bad domain.com', $errors[0]['message'] );
		$this->assertStringContainsString( 'user@example.test', $errors[0]['message'] );
		$this->assertStringContainsString( 'nolonger', $errors[0]['message'] );
	}

	/**
	 * Missing auto_approve_rules key in the submitted form input sanitizes to
	 * an empty array without surfacing errors.
	 *
	 * @covers ::sanitize
	 */
	public function test_sanitize_without_rules_input_is_empty_array() {
		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->sanitize( array() );

		$this->assertArrayHasKey( 'auto_approve_rules', $result );
		$this->assertSame( array(), $result['auto_approve_rules'] );
	}

	/**
	 * Returns an empty array when sanitize_auto_approve_rules() is given a
	 * non-array (e.g., a form value that never existed).
	 *
	 * @covers ::sanitize_auto_approve_rules
	 */
	public function test_sanitize_rules_non_array_input() {
		$instance = new Obenland_Wp_Approve_User();
		$this->assertSame( array(), $instance->sanitize_auto_approve_rules( 'nope' ) );
	}

	/**
	 * Unknown rule types are dropped and surfaced as invalid.
	 *
	 * @covers ::sanitize_auto_approve_rules
	 */
	public function test_sanitize_rules_rejects_unknown_type() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->sanitize_auto_approve_rules(
			array(
				array(
					'type'  => 'some_future_type',
					'value' => 'example.test',
				),
			)
		);

		$this->assertSame( array(), $result );
		$errors = get_settings_errors( 'wp-approve-user' );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * The settings UI registers a new section and rules field on admin_init.
	 *
	 * @covers ::admin_init
	 */
	public function test_admin_init_registers_auto_approve_section() {
		global $wp_registered_settings, $wp_settings_sections, $wp_settings_fields;
		$prev_settings = $wp_registered_settings;
		$prev_sections = $wp_settings_sections;
		$prev_fields   = $wp_settings_fields;

		$wp_registered_settings = array();
		$wp_settings_sections   = array();
		$wp_settings_fields     = array();

		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_init();

		$this->assertArrayHasKey( 'wp-approve-user', $wp_settings_sections );
		$this->assertArrayHasKey( 'wpau-auto-approve', $wp_settings_sections['wp-approve-user'] );
		$this->assertArrayHasKey( 'wpau-auto-approve', $wp_settings_fields['wp-approve-user'] );

		$wp_registered_settings = $prev_settings;
		$wp_settings_sections   = $prev_sections;
		$wp_settings_fields     = $prev_fields;
	}

	/**
	 * The repeatable rules callback renders a row per stored rule plus one
	 * trailing blank row for the no-JS add flow.
	 *
	 * @covers ::auto_approve_rules_cb
	 * @covers ::render_auto_approve_rule_row
	 * @covers ::auto_approve_section_description_cb
	 */
	public function test_auto_approve_rules_cb_renders_existing_plus_blank_row() {
		$this->store_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
			)
		);

		$instance = new Obenland_Wp_Approve_User();

		ob_start();
		$instance->auto_approve_section_description_cb();
		$desc = ob_get_clean();
		$this->assertStringContainsString( 'Matching new registrations', $desc );

		ob_start();
		$instance->auto_approve_rules_cb();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wpau-auto-approve-rules-list', $html );
		$this->assertStringContainsString( 'value="example.test"', $html );
		// Two rows: the stored rule + the trailing blank row.
		$this->assertSame( 2, substr_count( $html, 'class="wpau-auto-approve-rule"' ) );
		$this->assertStringContainsString( 'wpau_auto_approve_add_row', $html );
	}

	/**
	 * Exposes the email_domain type with a translatable label.
	 *
	 * @covers ::auto_approve_rule_types
	 */
	public function test_auto_approve_rule_types_includes_email_domain() {
		$instance = new Obenland_Wp_Approve_User();
		$types    = $instance->auto_approve_rule_types();

		$this->assertArrayHasKey( 'email_domain', $types );
		$this->assertNotEmpty( $types['email_domain'] );
	}
}
