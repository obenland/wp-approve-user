<?php
/**
 * Tests for Obenland_Wp_Approve_User.
 *
 * @package wp-approve-user
 */

/**
 * Covers the majority of Obenland_Wp_Approve_User.
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
 */
class WPAU_Main_Class_Test extends WP_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var WP_User
	 */
	public static $admin;

	/**
	 * Shared subscriber used in row-action / query tests.
	 *
	 * @var WP_User
	 */
	public static $subscriber;

	/**
	 * Arguments captured by filter_wp_mail_capture.
	 *
	 * @var array
	 */
	protected $captured_mail = array();

	/**
	 * Number of times filter_wp_mail_count was invoked.
	 *
	 * @var int
	 */
	protected $mail_filter_count = 0;

	/**
	 * Events captured by filter_schedule_event_capture.
	 *
	 * @var array
	 */
	protected $captured_events = array();

	/**
	 * Target user ID used by filter_map_meta_cap_deny_edit.
	 *
	 * @var int
	 */
	protected $deny_edit_target = 0;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin      = $factory->user->create_and_get( array( 'role' => 'administrator' ) );
		self::$subscriber = $factory->user->create_and_get( array( 'role' => 'subscriber' ) );

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
		set_current_screen( 'users' );
		$_REQUEST                = array();
		$this->captured_mail     = array();
		$this->mail_filter_count = 0;
		$this->captured_events   = array();
		$this->deny_edit_target  = 0;
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'pending' );
		delete_user_meta( self::$subscriber->ID, 'wp-approve-user-mail-sent' );
		delete_user_meta( self::$subscriber->ID, 'wp-approve-user-new-registration' );
	}

	/**
	 * Tears down per-test state.
	 *
	 * Hooks added with add_filter/add_action inside a test are automatically
	 * restored because WP_UnitTestCase snapshots and restores `$wp_filter`
	 * around every test, so explicit removal is not required here.
	 */
	public function tear_down() {
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Builds a fresh instance and registers its hooks.
	 *
	 * @return Obenland_Wp_Approve_User
	 */
	protected function make_instance() {
		$instance = new Obenland_Wp_Approve_User();
		$instance->plugins_loaded();
		return $instance;
	}

	/**
	 * Hooks the throwing wp_redirect filter so tests can capture the
	 * intended redirect location without reaching the production exit().
	 */
	protected function capture_redirect() {
		add_filter( 'wp_redirect', array( $this, 'filter_wp_redirect_throw' ) );
	}

	/**
	 * Aborts execution via the `wp_redirect` filter.
	 *
	 * Throws so the caller can catch the intended redirect location without
	 * letting the production `exit()` kill the test runner.
	 *
	 * @param  string $location Target URL.
	 * @return void
	 *
	 * @throws WPAU_Redirect_Exception Always thrown; carries the location.
	 */
	public function filter_wp_redirect_throw( $location ) {
		throw new WPAU_Redirect_Exception( esc_url_raw( $location ) );
	}

	/**
	 * Captures the arguments passed to `wp_mail()`.
	 *
	 * @param  array $args Mailer arguments.
	 * @return array Unmodified `$args`.
	 */
	public function filter_wp_mail_capture( $args ) {
		$this->captured_mail = $args;
		return $args;
	}

	/**
	 * Counts invocations of `wp_mail()` without storing the arguments.
	 *
	 * @param  array $args Mailer arguments.
	 * @return array Unmodified `$args`.
	 */
	public function filter_wp_mail_count( $args ) {
		++$this->mail_filter_count;
		return $args;
	}

	/**
	 * Enables the approval email in the default options via filter.
	 *
	 * @param  array $options Default options.
	 * @return array Options with `wpau-send-approve-email` set to true.
	 */
	public function filter_default_options_enable_approve_email( $options ) {
		$options['wpau-send-approve-email'] = true;
		return $options;
	}

	/**
	 * Returns a custom update message for the `wpau_update_message_handler` filter.
	 *
	 * @param  string $message Existing message.
	 * @param  string $update  Update key.
	 * @return string Custom message template.
	 */
	public function filter_update_message_handler_custom( $message, $update ) {
		return 'Custom for ' . $update . ' %d';
	}

	/**
	 * Overrides the `BLOG_TITLE` placeholder via the message placeholders filter.
	 *
	 * @param  array $placeholders Placeholder map.
	 * @return array Placeholders with BLOG_TITLE replaced.
	 */
	public function filter_message_placeholders_override( $placeholders ) {
		$placeholders['BLOG_TITLE'] = 'Filtered Title';
		return $placeholders;
	}

	/**
	 * Denies `edit_user` for a specific target via the `map_meta_cap` filter.
	 *
	 * @param  array  $caps    Primitive caps.
	 * @param  string $cap     Meta cap being mapped.
	 * @param  int    $user_id User attempting the action (unused).
	 * @param  array  $args    Cap arguments.
	 * @return array Mapped caps — `do_not_allow` for the denied target.
	 */
	public function filter_map_meta_cap_deny_edit( $caps, $cap, $user_id, $args ) {
		if ( 'edit_user' === $cap && isset( $args[0] ) && (int) $args[0] === $this->deny_edit_target ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Captures scheduled cron events for the allow-list hook.
	 *
	 * @param  object $event Scheduled event.
	 * @return object Unmodified event.
	 */
	public function filter_schedule_event_capture( $event ) {
		if ( 'wpau_allowlist_users_cron' === $event->hook ) {
			$this->captured_events[] = $event;
		}
		return $event;
	}

	/**
	 * Returns the same singleton from repeated get_instance() calls.
	 *
	 * @covers ::get_instance
	 */
	public function test_get_instance_returns_singleton() {
		Obenland_Wp_Approve_User::$instance = null;
		$a                                  = Obenland_Wp_Approve_User::get_instance();
		$b                                  = Obenland_Wp_Approve_User::get_instance();
		$this->assertSame( $a, $b );
		$this->assertInstanceOf( Obenland_Wp_Approve_User::class, $a );
	}

	/**
	 * Tests that `plugins_loaded` wires every hook the admin flows rely on.
	 *
	 * This is a regression guard for the hook registration block in the
	 * method — if any `$this->hook( ... )` line is dropped, a downstream
	 * admin flow (row action, bulk action, settings page, row query) breaks
	 * with a silent "nothing happened" failure. Asserting the registration
	 * surface here catches that before the functional tests do.
	 *
	 * @covers ::plugins_loaded
	 */
	public function test_plugins_loaded_registers_admin_hooks() {
		$instance = new Obenland_Wp_Approve_User();
		$instance->plugins_loaded();

		$this->assertNotFalse(
			has_filter( 'user_row_actions', array( $instance, 'user_row_actions' ) )
		);
		$this->assertNotFalse(
			has_filter( 'wp_authenticate_user', array( $instance, 'wp_authenticate_user' ) )
		);
		$this->assertNotFalse(
			has_action( 'user_register', array( $instance, 'user_register' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_action_wpau_approve', array( $instance, 'admin_action_wpau_approve' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_action_wpau_bulk_approve', array( $instance, 'admin_action_wpau_bulk_approve' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_action_wpau_unapprove', array( $instance, 'admin_action_wpau_unapprove' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_action_wpau_update', array( $instance, 'admin_action_wpau_update' ) )
		);
		$this->assertNotFalse(
			has_action( 'wpau_approve', array( $instance, 'wpau_approve' ) )
		);
		$this->assertNotFalse(
			has_action( 'delete_user', array( $instance, 'delete_user' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_init', array( $instance, 'admin_init' ) )
		);

		$menu_hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
		$this->assertNotFalse(
			has_action( $menu_hook, array( $instance, 'admin_menu' ) )
		);
	}

	/**
	 * The wpau_default_options filter flips the send-approve-email default and the rendered checkbox reflects it.
	 *
	 * @covers ::__construct
	 * @covers ::default_options
	 */
	public function test_default_options_filter_is_applied() {
		add_filter(
			'wpau_default_options',
			array( $this, 'filter_default_options_enable_approve_email' )
		);
		delete_option( 'wp-approve-user' );

		$instance = new Obenland_Wp_Approve_User();

		// Observable effect: checkbox_cb renders `checked` when the option is truthy.
		ob_start();
		$instance->checkbox_cb(
			array(
				'name'        => 'wpau-send-approve-email',
				'description' => 'Send it.',
			)
		);
		$this->assertStringContainsString( "checked='checked'", ob_get_clean() );
	}

	/**
	 * Sets a protected property on the given instance via Reflection.
	 *
	 * `setAccessible( true )` is required on PHP 7.4 (the lowest version
	 * in this plugin's CI matrix) — PHP 8.1 made it a no-op default, but
	 * the call still has to be there for the older runtime.
	 *
	 * @param object $instance Object to mutate.
	 * @param string $name     Property name.
	 * @param mixed  $value    New value.
	 */
	protected function set_protected( $instance, $name, $value ) {
		$prop = ( new ReflectionObject( $instance ) )->getProperty( $name );
		$prop->setAccessible( true );
		$prop->setValue( $instance, $value );
	}

	/**
	 * Adds Pending and Unapproved sub-views with the current class on the active role.
	 *
	 * @covers ::views_users
	 * @covers ::get_role
	 */
	public function test_views_users_adds_links_when_counts_present() {
		$instance = new Obenland_Wp_Approve_User();

		$this->set_protected( $instance, 'pending_count', 3 );
		$this->set_protected( $instance, 'unapproved_count', 2 );

		$_REQUEST['role'] = 'wpau_pending';
		$views            = $instance->views_users( array( 'all' => 'All' ) );
		$this->assertArrayHasKey( 'pending', $views );
		$this->assertArrayHasKey( 'unapproved', $views );
		$this->assertStringContainsString( 'class="current"', $views['pending'] );
		$this->assertStringContainsString( '(3)', $views['pending'] );
		$this->assertStringContainsString( '(2)', $views['unapproved'] );

		$_REQUEST['role'] = 'wpau_unapproved';
		$views            = $instance->views_users( array() );
		$this->assertStringContainsString( 'class="current"', $views['unapproved'] );
	}

	/**
	 * Site-users-network views point at site-users.php and round-trip the id query arg.
	 *
	 * @covers ::views_users
	 */
	public function test_views_users_site_users_network_screen_uses_site_users_url() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		set_current_screen( 'site-users-network' );
		$_REQUEST['id'] = 5;

		$instance = new Obenland_Wp_Approve_User();
		$this->set_protected( $instance, 'pending_count', 1 );

		$views = $instance->views_users( array() );
		$this->assertStringContainsString( 'site-users.php', $views['pending'] );
		$this->assertStringContainsString( 'id=5', $views['pending'] );
	}

	/**
	 * Leaves the views array untouched when there are no pending or unapproved users.
	 *
	 * @covers ::views_users
	 */
	public function test_views_users_no_counts_returns_unchanged() {
		$instance = new Obenland_Wp_Approve_User();
		$this->set_protected( $instance, 'pending_count', 0 );
		$this->set_protected( $instance, 'unapproved_count', 0 );
		$views = $instance->views_users( array( 'all' => 'All' ) );
		$this->assertSame( array( 'all' => 'All' ), $views );
	}

	/**
	 * Rewrites wpau_pending role queries to a wp-approve-user meta lookup.
	 *
	 * @covers ::pre_user_query
	 */
	public function test_pre_user_query_pending() {
		$instance = new Obenland_Wp_Approve_User();
		$instance->plugins_loaded();

		$_REQUEST['role']  = 'wpau_pending';
		$query             = new WP_User_Query();
		$query->query_vars = array( 'role' => '' );
		$instance->pre_user_query( $query );

		$this->assertSame( 'wp-approve-user', $query->query_vars['meta_key'] );
		$this->assertSame( 'pending', $query->query_vars['meta_value'] );
	}

	/**
	 * Rewrites wpau_unapproved role queries to a wp-approve-user meta lookup.
	 *
	 * @covers ::pre_user_query
	 */
	public function test_pre_user_query_unapproved() {
		$instance = new Obenland_Wp_Approve_User();
		$instance->plugins_loaded();

		$query             = new WP_User_Query();
		$query->query_vars = array( 'role' => 'wpau_unapproved' );
		$instance->pre_user_query( $query );

		$this->assertSame( 'unapproved', $query->query_vars['meta_value'] );
	}

	/**
	 * Pending users get both the Approve and Unapprove row links.
	 *
	 * @covers ::user_row_actions
	 */
	public function test_user_row_actions_adds_both_links_for_pending() {
		$instance = new Obenland_Wp_Approve_User();
		$actions  = $instance->user_row_actions( array(), self::$subscriber );

		$this->assertArrayHasKey( 'wpau-approve', $actions );
		$this->assertArrayHasKey( 'wpau-unapprove', $actions );
		$this->assertStringContainsString( 'submitapprove', $actions['wpau-approve'] );
		$this->assertStringContainsString( 'action=wpau_approve', $actions['wpau-approve'] );
	}

	/**
	 * Approved users do not see the Approve row link.
	 *
	 * @covers ::user_row_actions
	 */
	public function test_user_row_actions_hides_approve_for_approved_user() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'approved' );

		$instance = new Obenland_Wp_Approve_User();
		$actions  = $instance->user_row_actions( array(), self::$subscriber );

		$this->assertArrayNotHasKey( 'wpau-approve', $actions );
		$this->assertArrayHasKey( 'wpau-unapprove', $actions );
	}

	/**
	 * Unapproved users do not see the Unapprove row link.
	 *
	 * @covers ::user_row_actions
	 */
	public function test_user_row_actions_hides_unapprove_for_unapproved_user() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'unapproved' );

		$instance = new Obenland_Wp_Approve_User();
		$actions  = $instance->user_row_actions( array(), self::$subscriber );

		$this->assertArrayHasKey( 'wpau-approve', $actions );
		$this->assertArrayNotHasKey( 'wpau-unapprove', $actions );
	}

	/**
	 * Does not add row actions to the current user's own row.
	 *
	 * @covers ::user_row_actions
	 */
	public function test_user_row_actions_skips_self() {
		$instance = new Obenland_Wp_Approve_User();
		$actions  = $instance->user_row_actions( array( 'x' => 'y' ), self::$admin );
		$this->assertSame( array( 'x' => 'y' ), $actions );
	}

	/**
	 * Site-users-network row actions carry the blog id through to site-users.php.
	 *
	 * @covers ::user_row_actions
	 */
	public function test_user_row_actions_site_users_network_uses_site_id() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		set_current_screen( 'site-users-network' );
		$_REQUEST['id'] = 7;

		$instance = new Obenland_Wp_Approve_User();
		$actions  = $instance->user_row_actions( array(), self::$subscriber );
		$this->assertStringContainsString( 'site-users.php', $actions['wpau-approve'] );
	}

	/**
	 * The site admin email user is never gated regardless of approval state.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_returns_admin_email_user() {
		$instance = new Obenland_Wp_Approve_User();

		$user = get_user_by( 'email', get_bloginfo( 'admin_email' ) );
		if ( ! $user ) {
			$this->markTestSkipped( 'No admin email user.' );
		}

		update_user_meta( $user->ID, 'wp-approve-user', 'unapproved' );
		$result = $instance->wp_authenticate_user( $user );
		$this->assertSame( $user, $result );
	}

	/**
	 * Approved users authenticate normally.
	 *
	 * @covers ::wp_authenticate_user
	 */
	public function test_wp_authenticate_user_returns_approved_user() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'approved' );
		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->wp_authenticate_user( self::$subscriber );
		$this->assertSame( self::$subscriber, $result );
	}

	/**
	 * Swaps wp_send_new_user_notifications for wp_new_user_notification on pending registrations so the pending user is not emailed.
	 *
	 * @covers ::register_new_user
	 */
	public function test_register_new_user_swaps_notification_when_pending() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'pending' );
		add_action( 'register_new_user', 'wp_send_new_user_notifications' );

		$instance = new Obenland_Wp_Approve_User();
		$instance->register_new_user( self::$subscriber->ID );

		$this->assertFalse( has_action( 'register_new_user', 'wp_send_new_user_notifications' ) );
		$this->assertNotFalse( has_action( 'register_new_user', 'wp_new_user_notification' ) );
	}

	/**
	 * Leaves the register_new_user notification wiring untouched when the user is already approved.
	 *
	 * @covers ::register_new_user
	 */
	public function test_register_new_user_noop_when_not_pending() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'approved' );

		$instance = new Obenland_Wp_Approve_User();
		$instance->register_new_user( self::$subscriber->ID );

		$this->assertFalse( has_action( 'register_new_user', 'wp_new_user_notification' ) );
	}

	/**
	 * Second-action dropdown values with a wpau_ prefix are dispatched as admin actions.
	 *
	 * @covers ::map_action2
	 */
	public function test_map_action2_dispatches_wpau_action() {
		$fired  = false;
		$record = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'admin_action_wpau_fake_action', $record );
		$_REQUEST['action2'] = 'wpau_fake_action';

		$instance = new Obenland_Wp_Approve_User();
		$instance->map_action2();

		remove_action( 'admin_action_wpau_fake_action', $record );

		$this->assertTrue( $fired );
	}

	/**
	 * Second-action values without a wpau_ prefix are left alone.
	 *
	 * @covers ::map_action2
	 */
	public function test_map_action2_ignores_other_actions() {
		$fired  = false;
		$record = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'admin_action_delete', $record );
		$_REQUEST['action2'] = 'delete';

		$instance = new Obenland_Wp_Approve_User();
		$instance->map_action2();

		remove_action( 'admin_action_delete', $record );

		$this->assertFalse( $fired );
	}

	/**
	 * Enqueues the plugin JS on the Users admin screen.
	 *
	 * @covers ::admin_print_scripts_users_php
	 */
	public function test_admin_print_scripts_users_php_enqueues_script() {
		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_print_scripts_users_php();
		$this->assertTrue( wp_script_is( 'wp-approve-user', 'enqueued' ) );
	}

	/**
	 * Enqueues the settings-page stylesheet on the WP Approve User settings screen.
	 *
	 * @covers ::admin_print_styles_settings_page_wp_approve_user
	 */
	public function test_admin_print_styles_settings_page_enqueues_style() {
		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_print_styles_settings_page_wp_approve_user();
		$this->assertTrue( wp_style_is( 'wp-approve-user', 'enqueued' ) );
	}

	/**
	 * Rewrites the post-registration login message to explain that approval is pending.
	 *
	 * @covers ::wp_login_errors
	 */
	public function test_wp_login_errors_rewrites_registered_message() {
		$errors = new WP_Error();
		$errors->add( 'registered', 'Registration complete. Please check your email.' );

		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->wp_login_errors( $errors );
		$this->assertStringContainsString( 'once your registration is approved', $result->get_error_message( 'registered' ) );
	}

	/**
	 * Non-registered login errors are passed through untouched.
	 *
	 * @covers ::wp_login_errors
	 */
	public function test_wp_login_errors_passes_through_other_errors() {
		$errors = new WP_Error();
		$errors->add( 'other', 'whatever' );

		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->wp_login_errors( $errors );
		$this->assertSame( 'whatever', $result->get_error_message( 'other' ) );
	}

	/**
	 * Adds wpau_confirmation_error to the shake list so rejected logins shake the form.
	 *
	 * @covers ::shake_error_codes
	 */
	public function test_shake_error_codes_adds_our_code() {
		$instance = new Obenland_Wp_Approve_User();
		$codes    = $instance->shake_error_codes( array( 'foo' ) );
		$this->assertContains( 'wpau_confirmation_error', $codes );
	}

	/**
	 * Appends the pending-count bubble to the Users menu item and registers the settings submenu.
	 *
	 * @covers ::admin_menu
	 */
	public function test_admin_menu_appends_count_and_submenu() {
		global $menu, $submenu;
		$prev_menu    = $menu;
		$prev_submenu = $submenu;

		$menu    = array();
		$submenu = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$menu[70] = array( 'Users', 'list_users', 'users.php' );

		$instance = new Obenland_Wp_Approve_User();
		$this->set_protected( $instance, 'pending_count', 4 );
		$instance->admin_menu();

		$this->assertStringContainsString( 'plugin-count">4', $menu[70][0] );
		$parent_slug = is_multisite() ? 'settings.php' : 'options-general.php';
		$this->assertArrayHasKey( $parent_slug, $submenu );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$menu    = $prev_menu;
		$submenu = $prev_submenu;
	}

	/**
	 * Wires the plugin sanitize callback onto the wp-approve-user setting so raw form input cannot bypass it.
	 *
	 * Protects against a regression where `register_setting` stops routing
	 * option saves through `Obenland_Wp_Approve_User::sanitize()` — which
	 * would allow unsanitized form input to reach the options table.
	 *
	 * @covers ::admin_init
	 */
	public function test_admin_init_wires_sanitize_callback_for_option() {
		global $wp_registered_settings, $wp_settings_sections, $wp_settings_fields;
		$prev_settings = $wp_registered_settings;
		$prev_sections = $wp_settings_sections;
		$prev_fields   = $wp_settings_fields;

		$wp_registered_settings = array();
		$wp_settings_sections   = array();
		$wp_settings_fields     = array();

		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_init();

		$this->assertArrayHasKey( 'wp-approve-user', $wp_registered_settings );
		$callback = $wp_registered_settings['wp-approve-user']['sanitize_callback'];
		$this->assertIsArray( $callback );
		$this->assertSame( $instance, $callback[0] );
		$this->assertSame( 'sanitize', $callback[1] );

		// The sanitize callback must actually coerce input; prove it by round-tripping.
		$sanitized = call_user_func( $callback, array( 'wpau-send-approve-email' => '1' ) );
		$this->assertTrue( $sanitized['wpau-send-approve-email'] );
		$this->assertFalse( $sanitized['wpau-send-unapprove-email'] );

		$wp_registered_settings = $prev_settings;
		$wp_settings_sections   = $prev_sections;
		$wp_settings_fields     = $prev_fields;
	}

	/**
	 * The settings page renders the form together with the section description, checkbox and textarea callbacks.
	 *
	 * @covers ::settings_page
	 * @covers ::section_description_cb
	 * @covers ::checkbox_cb
	 * @covers ::textarea_cb
	 */
	public function test_settings_page_outputs_form_and_callbacks() {
		$instance = new Obenland_Wp_Approve_User();

		ob_start();
		$instance->settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Approve User Settings', $html );

		ob_start();
		$instance->section_description_cb();
		$section = ob_get_clean();
		$this->assertStringContainsString( 'USERNAME', $section );

		ob_start();
		$instance->checkbox_cb(
			array(
				'name'        => 'wpau-send-approve-email',
				'description' => 'Send it.',
			)
		);
		$checkbox = ob_get_clean();
		$this->assertStringContainsString( 'type="checkbox"', $checkbox );

		ob_start();
		$instance->textarea_cb(
			array(
				'label_for' => 'wpau-approve-email',
				'name'      => 'wpau-approve-email',
				'setting'   => 'wpau-send-approve-email',
			)
		);
		$textarea = ob_get_clean();
		$this->assertStringContainsString( '<textarea', $textarea );
	}

	/**
	 * Trims email bodies, coerces checkbox flags to booleans, and defaults missing fields to empty strings.
	 *
	 * @covers ::sanitize
	 */
	public function test_sanitize_trims_and_coerces() {
		$instance = new Obenland_Wp_Approve_User();
		$result   = $instance->sanitize(
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

		$empty = $instance->sanitize( array() );
		$this->assertSame( '', $empty['wpau-approve-email'] );
		$this->assertSame( '', $empty['wpau-unapprove-email'] );
	}

	/**
	 * Approving a new registration dispatches the approval email with placeholders replaced and records the mail-sent meta.
	 *
	 * @covers ::wpau_approve
	 * @covers ::populate_message
	 */
	public function test_wpau_approve_sends_email_when_enabled() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user-new-registration', true );

		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => true,
				'wpau-approve-email'        => 'Hello USERNAME from BLOG_TITLE',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
			)
		);

		$instance = new Obenland_Wp_Approve_User();

		add_filter( 'wp_mail', array( $this, 'filter_wp_mail_capture' ) );
		add_filter( 'pre_wp_mail', '__return_true' );

		$instance->wpau_approve( self::$subscriber->ID );

		$this->assertNotEmpty( $this->captured_mail );
		$this->assertStringContainsString( 'Hello ' . self::$subscriber->user_nicename, $this->captured_mail['message'] );
		$this->assertEmpty( get_user_meta( self::$subscriber->ID, 'wp-approve-user-new-registration', true ) );
		$this->assertNotEmpty( get_user_meta( self::$subscriber->ID, 'wp-approve-user-mail-sent', true ) );
	}

	/**
	 * Does not resend the approval email once the mail-sent meta is set.
	 *
	 * @covers ::wpau_approve
	 */
	public function test_wpau_approve_skips_when_already_sent() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user-mail-sent', true );

		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => true,
				'wpau-approve-email'        => 'Hi',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
			)
		);

		add_filter( 'wp_mail', array( $this, 'filter_wp_mail_count' ) );
		add_filter( 'pre_wp_mail', '__return_true' );

		$instance = new Obenland_Wp_Approve_User();

		$instance->wpau_approve( self::$subscriber->ID );

		$this->assertSame( 0, $this->mail_filter_count );
	}

	/**
	 * Deleting an unapproved new registration dispatches the unapprove email with placeholders replaced.
	 *
	 * @covers ::delete_user
	 */
	public function test_delete_user_sends_unapprove_email() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'unapproved' );
		update_user_meta( self::$subscriber->ID, 'wp-approve-user-new-registration', true );

		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => true,
				'wpau-unapprove-email'      => 'Sorry USERNAME',
			)
		);

		add_filter( 'wp_mail', array( $this, 'filter_wp_mail_capture' ) );
		add_filter( 'pre_wp_mail', '__return_true' );

		$instance = new Obenland_Wp_Approve_User();

		$instance->delete_user( self::$subscriber->ID );

		$this->assertNotEmpty( $this->captured_mail );
		$this->assertStringContainsString( 'Sorry ' . self::$subscriber->user_nicename, $this->captured_mail['message'] );
	}

	/**
	 * Deleting an unapproved user without the new-registration flag does not send any email.
	 *
	 * @covers ::delete_user
	 */
	public function test_delete_user_does_nothing_when_not_new_registration() {
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'unapproved' );

		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => true,
				'wpau-unapprove-email'      => 'Sorry',
			)
		);

		add_filter( 'wp_mail', array( $this, 'filter_wp_mail_count' ) );
		add_filter( 'pre_wp_mail', '__return_true' );

		$instance = new Obenland_Wp_Approve_User();

		$instance->delete_user( self::$subscriber->ID );

		$this->assertSame( 0, $this->mail_filter_count );
	}

	/**
	 * Surfaces the "X users approved." admin notice for wpau-approved updates.
	 *
	 * @covers ::all_admin_notices
	 * @covers ::admin_action_wpau_update
	 */
	public function test_admin_action_wpau_update_adds_message_for_approved() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$_REQUEST['update'] = 'wpau-approved';
		$_REQUEST['count']  = 2;

		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_action_wpau_update();

		$settings_errors = get_settings_errors( 'wp-approve-user' );
		$this->assertNotEmpty( $settings_errors );
		$this->assertStringContainsString( '2', $settings_errors[0]['message'] );

		ob_start();
		$instance->all_admin_notices();
		$html = ob_get_clean();
		$this->assertStringContainsString( '2', $html );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotUnslashed, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->assertSame( -1, $_REQUEST['action'] );
	}

	/**
	 * Surfaces the "X users unapproved." admin notice for wpau-unapproved updates.
	 *
	 * @covers ::admin_action_wpau_update
	 */
	public function test_admin_action_wpau_update_adds_message_for_unapproved() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$_REQUEST['update'] = 'wpau-unapproved';
		$_REQUEST['count']  = 1;

		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_action_wpau_update();

		$settings_errors = get_settings_errors( 'wp-approve-user' );
		$this->assertNotEmpty( $settings_errors );
	}

	/**
	 * Routes unknown update keys through the wpau_update_message_handler filter.
	 *
	 * @covers ::admin_action_wpau_update
	 */
	public function test_admin_action_wpau_update_uses_custom_filter() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$_REQUEST['update'] = 'wpau-custom';
		$_REQUEST['count']  = 5;

		add_filter(
			'wpau_update_message_handler',
			array( $this, 'filter_update_message_handler_custom' ),
			10,
			2
		);

		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_action_wpau_update();

		$settings_errors = get_settings_errors( 'wp-approve-user' );
		$this->assertStringContainsString( 'Custom for wpau-custom 5', $settings_errors[0]['message'] );
	}

	/**
	 * Returns early when no update query arg is present.
	 *
	 * @covers ::admin_action_wpau_update
	 */
	public function test_admin_action_wpau_update_returns_without_update() {
		$instance = new Obenland_Wp_Approve_User();
		$this->assertNull( $instance->admin_action_wpau_update() );
	}

	/**
	 * The row-action approve writes approved meta and redirects with update=wpau-approved.
	 *
	 * @covers ::admin_action_wpau_approve
	 * @covers ::approve
	 * @covers ::check_user
	 * @covers ::has_remaining_users
	 */
	public function test_admin_action_wpau_approve_redirects_and_approves() {
		$instance = $this->make_instance();

		$_REQUEST['user']     = self::$subscriber->ID;
		$_REQUEST['role']     = 'wpau_pending';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-approve-users' );

		$this->capture_redirect();

		try {
			$instance->admin_action_wpau_approve();
			$this->fail( 'Expected redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'update=wpau-approved', $e->location );
		}

		$this->assertSame( 'approved', get_user_meta( self::$subscriber->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Approve with no user query arg redirects back to users.php without touching any meta.
	 *
	 * @covers ::admin_action_wpau_approve
	 * @covers ::check_user
	 */
	public function test_admin_action_wpau_approve_empty_user_redirects() {
		$instance = $this->make_instance();

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-approve-users' );

		$this->capture_redirect();

		try {
			$instance->admin_action_wpau_approve();
			$this->fail( 'Expected redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringEndsWith( '/users.php', $e->location );
		}
	}

	/**
	 * The row-action unapprove writes unapproved meta and redirects with update=wpau-unapproved.
	 *
	 * @covers ::admin_action_wpau_unapprove
	 * @covers ::unapprove
	 */
	public function test_admin_action_wpau_unapprove_redirects_and_unapproves() {
		$instance = $this->make_instance();

		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'approved' );

		$_REQUEST['user']     = self::$subscriber->ID;
		$_REQUEST['role']     = 'wpau_unapproved';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-unapprove-users' );

		$this->capture_redirect();

		try {
			$instance->admin_action_wpau_unapprove();
			$this->fail( 'Expected redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'update=wpau-unapproved', $e->location );
		}

		$this->assertSame( 'unapproved', get_user_meta( self::$subscriber->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Bulk approve dispatched via do_action approves the selected users and redirects.
	 *
	 * @covers ::admin_action_wpau_bulk_approve
	 * @covers ::set_up_role_context
	 */
	public function test_admin_action_wpau_bulk_approve_redirects() {
		$this->make_instance();

		$_REQUEST['users']            = array( self::$subscriber->ID );
		$_REQUEST['_wp_http_referer'] = '/wp-admin/users.php?role=wpau_pending';
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'bulk-users' );

		$this->capture_redirect();

		try {
			do_action( 'admin_action_wpau_bulk_approve' );
			$this->fail( 'Expected redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'update=wpau-approved', $e->location );
		}

		$this->assertSame( 'approved', get_user_meta( self::$subscriber->ID, 'wp-approve-user', true ) );
	}

	/**
	 * Bulk unapprove from the unapproved view redirects back to the unapproved list.
	 *
	 * @covers ::admin_action_wpau_bulk_unapprove
	 */
	public function test_admin_action_wpau_bulk_unapprove_redirects() {
		$this->make_instance();

		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'approved' );

		$_REQUEST['users']    = array( self::$subscriber->ID );
		$_REQUEST['role']     = 'wpau_unapproved';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-users' );

		$this->capture_redirect();

		try {
			do_action( 'admin_action_wpau_bulk_unapprove' );
			$this->fail( 'Expected redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'role=wpau_unapproved', $e->location );
		}
	}

	/**
	 * Approve keeps the role=wpau_pending query arg when more pending users remain than were just approved.
	 *
	 * @covers ::approve
	 * @covers ::has_remaining_users
	 */
	public function test_approve_sets_role_when_has_remaining_pending() {
		$instance = $this->make_instance();

		$this->set_protected( $instance, 'pending_count', 5 );

		$_REQUEST['user']     = self::$subscriber->ID;
		$_REQUEST['role']     = 'wpau_pending';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-approve-users' );

		$this->capture_redirect();

		try {
			$instance->admin_action_wpau_approve();
			$this->fail( 'Expected redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'role=wpau_pending', $e->location );
		}
	}

	/**
	 * Unapprove keeps the role=wpau_unapproved query arg when more unapproved users remain than were just processed.
	 *
	 * @covers ::unapprove
	 * @covers ::has_remaining_users
	 */
	public function test_unapprove_sets_role_when_has_remaining_unapproved() {
		$instance = $this->make_instance();

		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'approved' );

		$this->set_protected( $instance, 'unapproved_count', 5 );

		$_REQUEST['user']     = self::$subscriber->ID;
		$_REQUEST['role']     = 'wpau_unapproved';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-unapprove-users' );

		$this->capture_redirect();

		try {
			$instance->admin_action_wpau_unapprove();
			$this->fail( 'Expected redirect.' );
		} catch ( WPAU_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'role=wpau_unapproved', $e->location );
		}
	}

	/**
	 * Approve dies with a capability error when the current user cannot edit the target.
	 *
	 * @covers ::approve
	 */
	public function test_approve_wp_dies_when_user_cant_edit_target() {
		$this->deny_edit_target = self::$subscriber->ID;
		$instance               = $this->make_instance();

		$_REQUEST['user']     = self::$subscriber->ID;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-approve-users' );

		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_deny_edit' ), 10, 4 );

		$this->expectException( WPDieException::class );
		$instance->admin_action_wpau_approve();
	}

	/**
	 * Unapprove dies with a capability error when the current user cannot edit the target.
	 *
	 * @covers ::unapprove
	 */
	public function test_unapprove_wp_dies_when_user_cant_edit_target() {
		$this->deny_edit_target = self::$subscriber->ID;
		update_user_meta( self::$subscriber->ID, 'wp-approve-user', 'approved' );

		$instance = $this->make_instance();

		$_REQUEST['user']     = self::$subscriber->ID;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-unapprove-users' );

		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_deny_edit' ), 10, 4 );

		$this->expectException( WPDieException::class );
		$instance->admin_action_wpau_unapprove();
	}

	/**
	 * Bulk actions cause check_user() to read the users[] request key instead of user.
	 *
	 * @covers ::check_user
	 */
	public function test_check_user_bulk_action_uses_users_key() {
		global $wp_current_filter;

		$instance = new Obenland_Wp_Approve_User();
		$reflect  = new ReflectionObject( $instance );
		$method   = $reflect->getMethod( 'check_user' );
		$method->setAccessible( true );

		$_REQUEST['users'] = array( self::$subscriber->ID );

		$wp_current_filter[] = 'admin_action_wpau_bulk_approve';
		try {
			list( $user_ids ) = $method->invoke( $instance );
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertSame( array( self::$subscriber->ID ), $user_ids );
	}

	/**
	 * Dies with a capability error when the current user lacks promote_users.
	 *
	 * @covers ::check_user
	 */
	public function test_check_user_wp_dies_without_promote_users_cap() {
		$instance = $this->make_instance();

		wp_set_current_user( self::$subscriber->ID );
		$_REQUEST['user']     = self::$subscriber->ID;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpau-approve-users' );

		$this->expectException( WPDieException::class );
		$instance->admin_action_wpau_approve();
	}

	/**
	 * Roles outside wpau_pending and wpau_unapproved are left untouched by pre_user_query.
	 *
	 * @covers ::pre_user_query
	 */
	public function test_pre_user_query_other_role_unchanged() {
		$instance = $this->make_instance();

		$query             = new WP_User_Query();
		$query->query_vars = array( 'role' => 'administrator' );
		$instance->pre_user_query( $query );

		$this->assertSame( 'administrator', $query->query_vars['role'] );
		$this->assertArrayNotHasKey( 'meta_value', $query->query_vars );
	}

	/**
	 * The wpau_message_placeholders filter can override the BLOG_TITLE placeholder before replacement.
	 *
	 * @covers ::populate_message
	 */
	public function test_populate_message_filter_is_applied() {
		$instance = new Obenland_Wp_Approve_User();
		$reflect  = new ReflectionObject( $instance );
		$method   = $reflect->getMethod( 'populate_message' );
		$method->setAccessible( true );

		add_filter( 'wpau_message_placeholders', array( $this, 'filter_message_placeholders_override' ) );

		$result = $method->invoke( $instance, 'Welcome to BLOG_TITLE', self::$subscriber );
		$this->assertSame( 'Welcome to Filtered Title', $result );
	}

	/**
	 * RESETLINK is replaced with a wp-login.php password-reset URL that includes the user login.
	 *
	 * @covers ::populate_message
	 */
	public function test_populate_message_replaces_resetlink() {
		$user = self::factory()->user->create_and_get(
			array(
				'user_login' => 'foo@bar',
				'user_email' => 'foo-bar@example.org',
				'role'       => 'subscriber',
			)
		);

		$instance = new Obenland_Wp_Approve_User();
		$reflect  = new ReflectionObject( $instance );
		$method   = $reflect->getMethod( 'populate_message' );
		$method->setAccessible( true );

		$result = $method->invoke( $instance, 'Reset: RESETLINK', $user );

		$encoded_login = 'login=' . rawurlencode( $user->user_login );

		$this->assertStringStartsWith( 'Reset: ', $result );
		$this->assertStringContainsString( 'wp-login.php', $result );
		$this->assertStringContainsString( 'action=rp', $result );
		$this->assertStringContainsString( 'key=', $result );
		$this->assertStringContainsString( $encoded_login, $result );
		$this->assertSame( 1, substr_count( $result, $encoded_login ) );
		$this->assertStringNotContainsString( 'login=' . rawurlencode( rawurlencode( $user->user_login ) ), $result );
		$this->assertStringNotContainsString( 'RESETLINK', $result );
	}

	/**
	 * Messages without RESETLINK do not generate a password-reset key (no user_activation_key side effect).
	 *
	 * @covers ::populate_message
	 */
	public function test_populate_message_without_resetlink_skips_key_generation() {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_userdata( $user_id );

		// phpcs:disable WordPress.DB
		$this->assertEmpty( $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $user_id ) ) );

		$instance = new Obenland_Wp_Approve_User();
		$reflect  = new ReflectionObject( $instance );
		$method   = $reflect->getMethod( 'populate_message' );
		$method->setAccessible( true );

		$method->invoke( $instance, 'Hello USERNAME', $user );

		$this->assertEmpty( $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $user_id ) ) );
		// phpcs:enable WordPress.DB
	}

	/**
	 * Falls back to the login URL when get_password_reset_key() returns a WP_Error.
	 *
	 * @covers ::populate_message
	 */
	public function test_populate_message_resetlink_falls_back_on_wp_error() {
		$deny = function () {
			return false;
		};
		add_filter( 'allow_password_reset', $deny );

		$instance = new Obenland_Wp_Approve_User();
		$reflect  = new ReflectionObject( $instance );
		$method   = $reflect->getMethod( 'populate_message' );
		$method->setAccessible( true );

		$result = $method->invoke( $instance, 'Reset: RESETLINK', self::$subscriber );

		remove_filter( 'allow_password_reset', $deny );

		$this->assertStringContainsString( wp_login_url(), $result );
		$this->assertStringNotContainsString( 'action=rp', $result );
		$this->assertStringNotContainsString( 'RESETLINK', $result );
	}

	/**
	 * The settings section description lists RESETLINK as a supported placeholder.
	 *
	 * @covers ::section_description_cb
	 */
	public function test_section_description_advertises_resetlink() {
		$instance = new Obenland_Wp_Approve_User();

		ob_start();
		$instance->section_description_cb();
		$section = ob_get_clean();

		$this->assertStringContainsString( 'RESETLINK', $section );
	}

	/**
	 * Deprecated update_option_users_can_register() emits a _deprecated_function notice.
	 *
	 * @covers ::update_option_users_can_register
	 */
	public function test_deprecated_update_option_users_can_register() {
		$instance = new Obenland_Wp_Approve_User();
		$this->setExpectedDeprecated( 'update_option_users_can_register' );
		$instance->update_option_users_can_register( 0, 1 );
	}

	/**
	 * Deprecated activation() emits a notice and forwards to wp_approve_user_activate().
	 *
	 * @covers ::activation
	 */
	public function test_deprecated_activation() {
		$instance = new Obenland_Wp_Approve_User();
		$this->setExpectedDeprecated( 'activation' );
		$instance->activation();
	}
}
