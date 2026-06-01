<?php
/**
 * Tests for the WPAU_Settings class.
 *
 * @package wp-approve-user
 */

/**
 * Covers the settings page, sanitize pipeline, field callbacks, and menu wiring.
 *
 * @coversDefaultClass WPAU_Settings
 */
class WPAU_Settings_Test extends WP_UnitTestCase {

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
	 * Resets per-test state.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$admin->ID );
		delete_option( 'wp-approve-user' );
	}

	/**
	 * Cleans up the plugin option and the cached singleton after each test.
	 *
	 * Tests that mutate option state reach through `Obenland_Wp_Approve_User::
	 * get_instance()` to render fields, which means the instance's in-memory
	 * `$options` copy is stale until we bust the cache here.
	 */
	public function tear_down() {
		delete_option( 'wp-approve-user' );
		delete_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed' );
		Obenland_Wp_Approve_User::$instance = null;
		parent::tear_down();
	}

	/**
	 * Register_hooks wires the admin menu + settings + styles hooks.
	 *
	 * @covers ::register_hooks
	 */
	public function test_register_hooks_wires_expected_hooks() {
		$settings = new WPAU_Settings();
		$settings->register_hooks();

		$menu_hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
		$this->assertNotFalse( has_action( $menu_hook, array( $settings, 'register_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $settings, 'register_sections_and_fields' ) ) );
		$this->assertNotFalse(
			has_action(
				'load-settings_page_wp-approve-user',
				array( $settings, 'on_settings_page_load' )
			)
		);
		$this->assertNotFalse(
			has_action(
				'admin_print_styles-settings_page_wp-approve-user',
				array( $settings, 'print_styles' )
			)
		);
	}

	/**
	 * Register_menu adds the Users-menu bubble and the Approve User submenu.
	 *
	 * @covers ::register_menu
	 */
	public function test_register_menu_appends_count_and_submenu() {
		global $menu, $submenu;
		$prev_menu    = $menu;
		$prev_submenu = $submenu;

		try {
			$menu    = array();
			$submenu = array();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$menu[70] = array( 'Users', 'list_users', 'users.php' );

			$plugin  = Obenland_Wp_Approve_User::get_instance();
			$reflect = new ReflectionObject( $plugin );
			$prop    = $reflect->getProperty( 'pending_count' );
			$prop->setAccessible( true );
			$prop->setValue( $plugin, 4 );

			( new WPAU_Settings() )->register_menu();

			$this->assertStringContainsString( 'plugin-count">4', $menu[70][0] );
			$parent_slug = is_multisite() ? 'settings.php' : 'options-general.php';
			$this->assertArrayHasKey( $parent_slug, $submenu );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$menu    = $prev_menu;
			$submenu = $prev_submenu;
		}
	}

	/**
	 * Register_sections_and_fields wires the sanitize callback onto the option.
	 *
	 * @covers ::register_sections_and_fields
	 */
	public function test_register_sections_and_fields_wires_sanitize_callback() {
		global $wp_registered_settings, $wp_settings_sections, $wp_settings_fields;
		$prev_settings = $wp_registered_settings;
		$prev_sections = $wp_settings_sections;
		$prev_fields   = $wp_settings_fields;

		try {
			$wp_registered_settings = array();
			$wp_settings_sections   = array();
			$wp_settings_fields     = array();

			$settings = new WPAU_Settings();
			$settings->register_sections_and_fields();

			$this->assertArrayHasKey( 'wp-approve-user', $wp_registered_settings );
			$callback = $wp_registered_settings['wp-approve-user']['sanitize_callback'];
			$this->assertIsArray( $callback );
			$this->assertSame( $settings, $callback[0] );
			$this->assertSame( 'sanitize', $callback[1] );

			$sanitized = call_user_func( $callback, array( 'wpau-send-approve-email' => '1' ) );
			$this->assertTrue( $sanitized['wpau-send-approve-email'] );
			$this->assertFalse( $sanitized['wpau-send-unapprove-email'] );
		} finally {
			$wp_registered_settings = $prev_settings;
			$wp_settings_sections   = $prev_sections;
			$wp_settings_fields     = $prev_fields;
		}
	}

	/**
	 * Register_sections_and_fields adds the auto-approval section and field.
	 *
	 * @covers ::register_sections_and_fields
	 */
	public function test_register_sections_and_fields_registers_auto_approve_section() {
		global $wp_settings_sections, $wp_settings_fields;
		$prev_sections = $wp_settings_sections;
		$prev_fields   = $wp_settings_fields;

		try {
			$wp_settings_sections = array();
			$wp_settings_fields   = array();

			( new WPAU_Settings() )->register_sections_and_fields();

			$this->assertArrayHasKey( 'wpau-auto-approve', $wp_settings_sections['wp-approve-user'] );
			$this->assertArrayHasKey(
				'wp-approve-user[auto-approve-rules]',
				$wp_settings_fields['wp-approve-user']['wpau-auto-approve']
			);
		} finally {
			$wp_settings_sections = $prev_sections;
			$wp_settings_fields   = $prev_fields;
		}
	}

	/**
	 * Render_page outputs the form wrapper.
	 *
	 * @covers ::render_page
	 */
	public function test_render_page_outputs_form() {
		ob_start();
		( new WPAU_Settings() )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Approve User Settings', $html );
		$this->assertStringContainsString( '<form method="post" action="options.php">', $html );
	}

	/**
	 * Section_description_cb lists the placeholder tokens.
	 *
	 * @covers ::section_description_cb
	 */
	public function test_section_description_cb_lists_placeholders() {
		ob_start();
		( new WPAU_Settings() )->section_description_cb();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'USERNAME', $html );
		$this->assertStringContainsString( 'RESETLINK', $html );
	}

	/**
	 * Checkbox_cb renders a checked input when the underlying option is truthy.
	 *
	 * @covers ::checkbox_cb
	 */
	public function test_checkbox_cb_renders_checked_state_from_options() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => true,
				'wpau-send-unapprove-email' => false,
				'wpau-approve-email'        => '',
				'wpau-unapprove-email'      => '',
				'auto_approve_rules'        => array(),
			)
		);

		/* Force the singleton to re-read the option. */
		Obenland_Wp_Approve_User::$instance = null;
		Obenland_Wp_Approve_User::get_instance();

		ob_start();
		( new WPAU_Settings() )->checkbox_cb(
			array(
				'name'        => 'wpau-send-approve-email',
				'description' => 'Send it.',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
	}

	/**
	 * Textarea_cb renders a textarea pre-filled with the current option value.
	 *
	 * @covers ::textarea_cb
	 */
	public function test_textarea_cb_renders_stored_value() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-send-unapprove-email' => false,
				'wpau-approve-email'        => 'Welcome, USERNAME.',
				'wpau-unapprove-email'      => '',
				'auto_approve_rules'        => array(),
			)
		);

		Obenland_Wp_Approve_User::$instance = null;
		Obenland_Wp_Approve_User::get_instance();

		ob_start();
		( new WPAU_Settings() )->textarea_cb(
			array(
				'label_for' => 'wpau-approve-email',
				'name'      => 'wpau-approve-email',
				'setting'   => 'wpau-send-approve-email',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringContainsString( 'Welcome, USERNAME.', $html );
	}

	/**
	 * Auto_approve_section_description_cb prints the section helper copy.
	 *
	 * @covers ::auto_approve_section_description_cb
	 */
	public function test_auto_approve_section_description_cb_prints_help() {
		ob_start();
		( new WPAU_Settings() )->auto_approve_section_description_cb();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'auto-approved if any rule matches', $html );
	}

	/**
	 * Auto_approve_rules_cb renders stored rules plus one blank row.
	 *
	 * @covers ::auto_approve_rules_cb
	 * @covers ::render_auto_approve_rule_row
	 */
	public function test_auto_approve_rules_cb_renders_existing_plus_blank_row() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-send-unapprove-email' => false,
				'wpau-approve-email'        => '',
				'wpau-unapprove-email'      => '',
				'auto_approve_rules'        => array(
					array(
						'type'  => 'email_domain',
						'value' => 'example.test',
					),
				),
			)
		);

		Obenland_Wp_Approve_User::$instance = null;
		Obenland_Wp_Approve_User::get_instance();

		ob_start();
		( new WPAU_Settings() )->auto_approve_rules_cb();
		$html = ob_get_clean();

		$this->assertSame( 2, substr_count( $html, 'class="wpau-auto-approve-rule"' ) );
		$this->assertStringContainsString( 'value="example.test"', $html );
		$this->assertStringContainsString( 'name="wpau_auto_approve_add_row"', $html );
	}

	/**
	 * Auto_approve_rules_cb renders the placeholder row when nothing is stored.
	 *
	 * @covers ::auto_approve_rules_cb
	 */
	public function test_auto_approve_rules_cb_renders_placeholder_when_option_missing() {
		delete_option( 'wp-approve-user' );
		Obenland_Wp_Approve_User::$instance = null;
		Obenland_Wp_Approve_User::get_instance();

		ob_start();
		( new WPAU_Settings() )->auto_approve_rules_cb();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wpau-auto-approve-rule', $html );
	}

	/**
	 * A stored non-array auto_approve_rules value is coerced to an empty list.
	 *
	 * @covers ::auto_approve_rules_cb
	 */
	public function test_non_array_auto_approve_rules_coerced_to_empty() {
		$filter = static function ( $defaults ) {
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

			Obenland_Wp_Approve_User::$instance = null;
			Obenland_Wp_Approve_User::get_instance();

			ob_start();
			( new WPAU_Settings() )->auto_approve_rules_cb();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'wpau-auto-approve-rule', $html );
		} finally {
			remove_filter( 'wpau_default_options', $filter );
		}
	}

	/**
	 * Auto_approve_rule_types exposes at least email_domain.
	 *
	 * @covers ::auto_approve_rule_types
	 */
	public function test_auto_approve_rule_types_includes_email_domain() {
		$types = ( new WPAU_Settings() )->auto_approve_rule_types();

		$this->assertArrayHasKey( 'email_domain', $types );
		$this->assertArrayHasKey( 'email_suffix', $types );
		$this->assertArrayHasKey( 'ip_range', $types );
		$this->assertNotEmpty( $types['email_domain'] );
	}

	/**
	 * Auto_approve_rule_placeholders() returns a matching placeholder for every rule type.
	 *
	 * @covers ::auto_approve_rule_placeholders
	 */
	public function test_auto_approve_rule_placeholders_covers_every_type() {
		$settings     = new WPAU_Settings();
		$types        = $settings->auto_approve_rule_types();
		$placeholders = $settings->auto_approve_rule_placeholders();

		foreach ( array_keys( $types ) as $type_key ) {
			$this->assertArrayHasKey( $type_key, $placeholders );
			$this->assertNotEmpty( $placeholders[ $type_key ] );
		}
	}

	/**
	 * Sanitize_rule_value() dispatches to the per-type sanitizer and rejects unknown types.
	 *
	 * @covers ::sanitize_rule_value
	 */
	public function test_sanitize_rule_value_dispatches_per_type() {
		$settings = new WPAU_Settings();

		$method = new ReflectionMethod( $settings, 'sanitize_rule_value' );
		$method->setAccessible( true );

		$this->assertSame( 'example.com', $method->invoke( $settings, 'email_domain', '@Example.COM' ) );
		$this->assertSame( '.edu', $method->invoke( $settings, 'email_suffix', '.EDU' ) );
		$this->assertSame( '192.168.1.0/24', $method->invoke( $settings, 'ip_range', '192.168.1.0/24' ) );
		$this->assertSame( '', $method->invoke( $settings, 'not_a_real_type', 'whatever' ) );
	}

	/**
	 * Sanitize trims email bodies and coerces checkbox flags to booleans.
	 *
	 * @covers ::sanitize
	 */
	public function test_sanitize_trims_and_coerces() {
		$settings = new WPAU_Settings();
		$result   = $settings->sanitize(
			array(
				'wpau-send-approve-email' => '1',
				'wpau-approve-email'      => '  hello  ',
				'wpau-unapprove-email'    => 'bye',
			)
		);

		$this->assertTrue( $result['wpau-send-approve-email'] );
		$this->assertFalse( $result['wpau-send-unapprove-email'] );
		$this->assertSame( 'hello', $result['wpau-approve-email'] );
		$this->assertSame( 'bye', $result['wpau-unapprove-email'] );

		$empty = $settings->sanitize( array() );
		$this->assertSame( '', $empty['wpau-approve-email'] );
		$this->assertSame( '', $empty['wpau-unapprove-email'] );
	}

	/**
	 * Sanitize() forwards auto_approve_rules through sanitize_auto_approve_rules.
	 *
	 * @covers ::sanitize
	 */
	public function test_sanitize_forwards_auto_approve_rules() {
		$result = ( new WPAU_Settings() )->sanitize(
			array(
				'auto_approve_rules' => array(
					array(
						'type'  => 'email_domain',
						'value' => '  @EXAMPLE.test ',
					),
				),
			)
		);

		$this->assertCount( 1, $result['auto_approve_rules'] );
		$this->assertSame( 'example.test', $result['auto_approve_rules'][0]['value'] );
	}

	/**
	 * Sanitize() coerces non-array input to the canonical empty shape instead of
	 * fataling on array access.
	 *
	 * @covers ::sanitize
	 */
	public function test_sanitize_coerces_non_array_input() {
		$result = ( new WPAU_Settings() )->sanitize( 'not-an-array' );

		$this->assertFalse( $result['wpau-send-approve-email'] );
		$this->assertFalse( $result['wpau-send-unapprove-email'] );
		$this->assertSame( '', $result['wpau-approve-email'] );
		$this->assertSame( '', $result['wpau-unapprove-email'] );
		$this->assertSame( array(), $result['auto_approve_rules'] );
	}

	/**
	 * Sanitize_auto_approve_rules preserves valid rules and normalizes domains.
	 *
	 * @covers ::sanitize_auto_approve_rules
	 */
	public function test_sanitize_keeps_valid_rules_normalised() {
		$result = ( new WPAU_Settings() )->sanitize_auto_approve_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => '  @Example.Test  ',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame(
			array(
				'type'  => 'email_domain',
				'value' => 'example.test',
			),
			$result[0]
		);
	}

	/**
	 * Sanitize_auto_approve_rules drops empty rows silently and logs invalid ones.
	 *
	 * @covers ::sanitize_auto_approve_rules
	 */
	public function test_sanitize_drops_empty_and_invalid_rules() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$result = ( new WPAU_Settings() )->sanitize_auto_approve_rules(
			array(
				array(
					'type'  => 'email_domain',
					'value' => '',
				),
				array(
					'type'  => 'email_domain',
					'value' => 'not a domain',
				),
				array(
					'type'  => 'email_domain',
					'value' => 'ok.test',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'ok.test', $result[0]['value'] );

		$matched = false;
		foreach ( get_settings_errors( 'wp-approve-user' ) as $error ) {
			if ( 'wpau_auto_approve_invalid' === $error['code'] ) {
				$matched = true;
				$this->assertStringContainsString( 'not a domain', $error['message'] );
				break;
			}
		}
		$this->assertTrue( $matched, 'Expected wpau_auto_approve_invalid settings error to be recorded.' );
	}

	/**
	 * Sanitize_auto_approve_rules returns an empty array for non-array input.
	 *
	 * @covers ::sanitize_auto_approve_rules
	 */
	public function test_sanitize_rules_non_array_input() {
		$this->assertSame( array(), ( new WPAU_Settings() )->sanitize_auto_approve_rules( 'nope' ) );
	}

	/**
	 * Unknown rule types are rejected with a settings error.
	 *
	 * @covers ::sanitize_auto_approve_rules
	 */
	public function test_sanitize_rules_rejects_unknown_type() {
		$result = ( new WPAU_Settings() )->sanitize_auto_approve_rules(
			array(
				array(
					'type'  => 'mystery',
					'value' => 'ok.test',
				),
			)
		);

		$this->assertSame( array(), $result );
	}

	/**
	 * Non-array rule entries are skipped by the settings sanitizer.
	 *
	 * @covers ::sanitize_auto_approve_rules
	 */
	public function test_sanitize_skips_non_array_rules() {
		$result = ( new WPAU_Settings() )->sanitize_auto_approve_rules(
			array(
				'not-an-array',
				array(
					'type'  => 'email_domain',
					'value' => 'example.test',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'example.test', $result[0]['value'] );
	}

	/**
	 * Print_styles enqueues the stylesheet, the auto-approval script, and —
	 * since the hint shows by default for an admin — the from-address-hint
	 * dismissal script.
	 *
	 * @covers ::print_styles
	 */
	public function test_print_styles_enqueues_assets() {
		( new WPAU_Settings() )->print_styles();

		$this->assertTrue( wp_style_is( 'wp-approve-user', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wpau-auto-approval-rules', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wpau-from-address-hint', 'enqueued' ) );

		wp_dequeue_style( 'wp-approve-user' );
		wp_dequeue_script( 'wpau-auto-approval-rules' );
		wp_dequeue_script( 'wpau-from-address-hint' );
	}

	/**
	 * Print_styles skips the from-address-hint script when the hint is hidden.
	 *
	 * @covers ::print_styles
	 */
	public function test_print_styles_skips_hint_script_when_dismissed() {
		update_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed', 1 );

		( new WPAU_Settings() )->print_styles();

		$this->assertFalse( wp_script_is( 'wpau-from-address-hint', 'enqueued' ) );

		wp_dequeue_style( 'wp-approve-user' );
		wp_dequeue_script( 'wpau-auto-approval-rules' );
	}

	/**
	 * The From-address hint shows by default for an admin who hasn't dismissed it.
	 *
	 * @covers ::should_show_from_address_hint
	 */
	public function test_should_show_from_address_hint_defaults_to_visible() {
		$this->assertTrue( ( new WPAU_Settings() )->should_show_from_address_hint() );
	}

	/**
	 * A dismissed hint stays hidden for that user.
	 *
	 * @covers ::should_show_from_address_hint
	 */
	public function test_should_show_false_when_dismissed() {
		update_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed', 1 );

		$this->assertFalse( ( new WPAU_Settings() )->should_show_from_address_hint() );
	}

	/**
	 * The hint never shows to users who can't install plugins.
	 *
	 * @covers ::should_show_from_address_hint
	 */
	public function test_should_show_false_for_users_who_cannot_install_plugins() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		try {
			$this->assertFalse( ( new WPAU_Settings() )->should_show_from_address_hint() );
		} finally {
			wp_set_current_user( self::$admin->ID );
		}
	}

	/**
	 * When the plugin isn't installed, the name links to the info modal.
	 *
	 * @covers ::from_address_plugin_action
	 */
	public function test_from_address_plugin_action_links_to_modal_when_not_installed() {
		// Pin get_plugins() to an empty set so the assertion doesn't depend on
		// the real plugin scan or a cache another test may have seeded.
		wp_cache_set( 'plugins', array( '' => array() ), 'plugins' );

		try {
			$action = ( new WPAU_Settings() )->from_address_plugin_action();

			$this->assertTrue( $action['modal'] );
			$this->assertStringStartsWith( self_admin_url(), $action['url'] );
			$this->assertStringContainsString( 'tab=plugin-information', $action['url'] );
			$this->assertStringContainsString( 'plugin=change-from-address', $action['url'] );
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
		}
	}

	/**
	 * From_address_hint renders the dismissible notice, the modal-linked plugin
	 * name (companion plugin not installed), and the no-JS dismiss link, and
	 * enqueues Thickbox for the plugin-information modal.
	 *
	 * Thickbox is the reliable signal that the modal branch ran: plugin-install
	 * is registered only under is_admin() and isn't asserted here.
	 *
	 * @covers ::from_address_hint
	 */
	public function test_from_address_hint_renders_action_and_dismiss() {
		// Pin get_plugins() to empty so the name links to the modal.
		wp_cache_set( 'plugins', array( '' => array() ), 'plugins' );

		try {
			ob_start();
			( new WPAU_Settings() )->from_address_hint();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'wpau-from-address-hint', $html );
			$this->assertStringContainsString( 'is-dismissible', $html );
			$this->assertStringContainsString( 'open-plugin-details-modal', $html );
			$this->assertStringContainsString( 'Change From Address', $html );
			$this->assertStringContainsString( 'wpau_dismiss_from_address_hint', $html );
			$this->assertStringContainsString( 'wpau-dismiss-from-address-hint', $html );

			$this->assertTrue( wp_script_is( 'thickbox', 'enqueued' ) );
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
			wp_dequeue_script( 'thickbox' );
			wp_dequeue_script( 'plugin-install' );
		}
	}

	/**
	 * When the companion plugin is installed but inactive, the hint links the
	 * name to a plain activate URL and skips the modal-only Thickbox assets.
	 *
	 * @covers ::from_address_hint
	 */
	public function test_from_address_hint_skips_modal_assets_when_installed() {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'change-from-address/change-from-address.php' => array( 'Name' => 'Change From Address' ),
				),
			),
			'plugins'
		);

		try {
			ob_start();
			( new WPAU_Settings() )->from_address_hint();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'wpau-from-address-hint', $html );
			$this->assertStringNotContainsString( 'open-plugin-details-modal', $html );
			$this->assertStringContainsString( 'action=activate', $html );
			$this->assertFalse( wp_script_is( 'thickbox', 'enqueued' ) );
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
		}
	}

	/**
	 * From_address_hint renders nothing once dismissed.
	 *
	 * @covers ::from_address_hint
	 */
	public function test_from_address_hint_is_silent_when_dismissed() {
		update_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed', 1 );

		ob_start();
		( new WPAU_Settings() )->from_address_hint();
		$html = ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * From_address_hint renders nothing once the companion plugin is active —
	 * there's nothing left to nudge the admin toward.
	 *
	 * The WP test harness short-circuits get_option( 'active_plugins' ) via a
	 * pre_option_active_plugins filter, so we hook the same filter at a later
	 * priority to make is_plugin_active() report the companion plugin as active.
	 *
	 * @covers ::from_address_hint
	 */
	public function test_from_address_hint_is_silent_when_companion_plugin_active() {
		$filter = static function () {
			return array( 'change-from-address/change-from-address.php' );
		};
		add_filter( 'pre_option_active_plugins', $filter, 99 );

		try {
			ob_start();
			( new WPAU_Settings() )->from_address_hint();
			$html = ob_get_clean();

			$this->assertSame( '', trim( $html ) );
		} finally {
			remove_filter( 'pre_option_active_plugins', $filter, 99 );
		}
	}

	/**
	 * Loading the settings page queues the hint onto the admin-notices pipeline.
	 *
	 * @covers ::on_settings_page_load
	 */
	public function test_on_settings_page_load_queues_admin_notice() {
		$settings = new WPAU_Settings();
		$settings->on_settings_page_load();

		$this->assertNotFalse(
			has_action( 'all_admin_notices', array( $settings, 'from_address_hint' ) )
		);
	}

	/**
	 * Maybe_dismiss_from_address_hint ignores requests without the dismiss flag.
	 *
	 * @covers ::maybe_dismiss_from_address_hint
	 */
	public function test_maybe_dismiss_ignores_unrelated_requests() {
		unset( $_GET['wpau_dismiss_from_address_hint'] );

		// Should return without touching meta or redirecting.
		( new WPAU_Settings() )->maybe_dismiss_from_address_hint();

		$this->assertSame(
			'',
			get_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed', true )
		);
	}

	/**
	 * A valid dismiss request records the meta and redirects to a clean URL.
	 *
	 * @covers ::maybe_dismiss_from_address_hint
	 */
	public function test_maybe_dismiss_records_meta_and_redirects() {
		$_GET['wpau_dismiss_from_address_hint'] = '1';
		$nonce                                  = wp_create_nonce( 'wpau_dismiss_from_address_hint' );
		$_GET['_wpnonce']                       = $nonce;
		$_REQUEST['_wpnonce']                   = $nonce;

		$throw = static function ( $location ) {
			throw new WPAU_Redirect_Exception( esc_url_raw( $location ) );
		};
		add_filter( 'wp_redirect', $throw );

		try {
			( new WPAU_Settings() )->maybe_dismiss_from_address_hint();
			$this->fail( 'Expected a redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringNotContainsString( 'wpau_dismiss_from_address_hint', $e->location );
		} finally {
			remove_filter( 'wp_redirect', $throw );
			unset(
				$_GET['wpau_dismiss_from_address_hint'],
				$_GET['_wpnonce'],
				$_REQUEST['_wpnonce']
			);
		}

		$this->assertSame(
			'1',
			get_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed', true )
		);
	}

	/**
	 * A repeat dismiss (meta already set) still redirects rather than erroring.
	 *
	 * The update_user_meta() return is false when the value is unchanged, so
	 * the handler must check the persisted value, not the return, before
	 * deciding the write failed.
	 *
	 * @covers ::maybe_dismiss_from_address_hint
	 */
	public function test_maybe_dismiss_when_already_dismissed_still_redirects() {
		update_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed', 1 );

		$_GET['wpau_dismiss_from_address_hint'] = '1';
		$nonce                                  = wp_create_nonce( 'wpau_dismiss_from_address_hint' );
		$_GET['_wpnonce']                       = $nonce;
		$_REQUEST['_wpnonce']                   = $nonce;

		$throw = static function ( $location ) {
			throw new WPAU_Redirect_Exception( esc_url_raw( $location ) );
		};
		add_filter( 'wp_redirect', $throw );

		try {
			// Reaching the redirect (rather than returning early) proves the
			// handler treated the already-set meta as success, not failure.
			( new WPAU_Settings() )->maybe_dismiss_from_address_hint();
			$this->fail( 'Expected a redirect, not an error notice.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringNotContainsString( 'wpau_dismiss_from_address_hint', $e->location );
		} finally {
			remove_filter( 'wp_redirect', $throw );
			unset(
				$_GET['wpau_dismiss_from_address_hint'],
				$_GET['_wpnonce'],
				$_REQUEST['_wpnonce']
			);
		}
	}

	/**
	 * The hint hides itself once the companion plugin is active.
	 *
	 * The WP test harness short-circuits get_option( 'active_plugins' ) via a
	 * pre_option_active_plugins filter, so we hook the same filter at a later
	 * priority to make is_plugin_active() report the companion plugin as active.
	 *
	 * @covers ::should_show_from_address_hint
	 */
	public function test_should_show_false_when_companion_plugin_active() {
		$filter = static function () {
			return array( 'change-from-address/change-from-address.php' );
		};
		add_filter( 'pre_option_active_plugins', $filter, 99 );

		try {
			$this->assertFalse( ( new WPAU_Settings() )->should_show_from_address_hint() );
		} finally {
			remove_filter( 'pre_option_active_plugins', $filter, 99 );
		}
	}

	/**
	 * When the companion plugin is installed but inactive, offer the activate link.
	 *
	 * @covers ::from_address_plugin_action
	 */
	public function test_from_address_plugin_action_offers_activate_when_installed() {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'change-from-address/change-from-address.php' => array( 'Name' => 'Change From Address' ),
				),
			),
			'plugins'
		);

		try {
			$action = ( new WPAU_Settings() )->from_address_plugin_action();

			$this->assertFalse( $action['modal'] );
			$this->assertStringStartsWith( self_admin_url(), $action['url'] );
			$this->assertStringContainsString( 'action=activate', $action['url'] );
			$this->assertStringContainsString( 'change-from-address', $action['url'] );
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
		}
	}

	/**
	 * In network admin the action link points at the network plugins screen.
	 *
	 * The URL is built with self_admin_url(), which resolves to
	 * network_admin_url() under network admin — that's where a super admin
	 * installs and activates plugins. Only meaningful on multisite.
	 *
	 * @covers ::from_address_plugin_action
	 */
	public function test_from_address_plugin_action_uses_network_admin_url_in_network_context() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network admin context only applies on multisite.' );
		}

		set_current_screen( 'dashboard-network' );

		try {
			$action = ( new WPAU_Settings() )->from_address_plugin_action();

			$this->assertStringStartsWith( network_admin_url(), $action['url'] );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * A dismiss request with an invalid nonce is rejected and writes no meta.
	 *
	 * Guards the CSRF protection on the dismiss handler: a future change that
	 * weakened or dropped check_admin_referer() would slip past every other
	 * test, since they all supply a valid nonce.
	 *
	 * @covers ::maybe_dismiss_from_address_hint
	 */
	public function test_maybe_dismiss_rejects_invalid_nonce() {
		$_GET['wpau_dismiss_from_address_hint'] = '1';
		$_GET['_wpnonce']                       = 'bogus';
		$_REQUEST['_wpnonce']                   = 'bogus';

		try {
			( new WPAU_Settings() )->maybe_dismiss_from_address_hint();
			$this->fail( 'Expected check_admin_referer() to halt the request.' );
		} catch ( WPDieException $e ) {
			$this->assertSame(
				'',
				get_user_meta( self::$admin->ID, 'wp-approve-user-from-address-hint-dismissed', true )
			);
		} finally {
			unset(
				$_GET['wpau_dismiss_from_address_hint'],
				$_GET['_wpnonce'],
				$_REQUEST['_wpnonce']
			);
		}
	}
}
