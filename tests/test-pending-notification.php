<?php
/**
 * Tests for the pending-user admin email notification.
 *
 * @package wp-approve-user
 */

/**
 * Covers Obenland_Wp_Approve_User::notify_admin_pending().
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
 */
class WPAU_Pending_Notification_Test extends WP_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var WP_User
	 */
	public static $admin;

	/**
	 * Pending subscriber fixture.
	 *
	 * @var WP_User
	 */
	public static $pending_user;

	/**
	 * Captured wp_mail arguments.
	 *
	 * @var array
	 */
	protected $captured_mail = array();

	/**
	 * Callable stored so we can specifically remove our wp_mail capture.
	 *
	 * @var callable|null
	 */
	protected $wp_mail_listener;

	/**
	 * Pre_wp_mail short-circuit callable.
	 *
	 * @var callable|null
	 */
	protected $pre_wp_mail_listener;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin        = $factory->user->create_and_get( array( 'role' => 'administrator' ) );
		self::$pending_user = $factory->user->create_and_get( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Resets per-test state.
	 */
	public function set_up() {
		parent::set_up();
		$this->captured_mail        = array();
		$this->wp_mail_listener     = null;
		$this->pre_wp_mail_listener = null;

		update_user_meta( self::$pending_user->ID, 'wp-approve-user', 'pending' );
		delete_user_meta( self::$pending_user->ID, 'wp-approve-user-admin-notified' );

		// Stub wp_mail delivery so we don't actually try to send.
		$this->pre_wp_mail_listener = '__return_true';
		add_filter( 'pre_wp_mail', $this->pre_wp_mail_listener );
	}

	/**
	 * Tears down per-test state.
	 */
	public function tear_down() {
		if ( $this->wp_mail_listener ) {
			remove_filter( 'wp_mail', $this->wp_mail_listener );
			$this->wp_mail_listener = null;
		}
		if ( $this->pre_wp_mail_listener ) {
			remove_filter( 'pre_wp_mail', $this->pre_wp_mail_listener );
			$this->pre_wp_mail_listener = null;
		}
		parent::tear_down();
	}

	/**
	 * Registers a wp_mail capture filter and keeps a handle so tear_down can remove it.
	 */
	protected function start_wp_mail_capture() {
		$this->wp_mail_listener = function ( $args ) {
			$this->captured_mail = $args;
			return $args;
		};
		add_filter( 'wp_mail', $this->wp_mail_listener );
	}

	/**
	 * Fires the admin notification email when a new user registers as pending.
	 *
	 * @covers ::notify_admin_pending
	 * @covers ::plugins_loaded
	 */
	public function test_notify_admin_pending_sends_email_for_pending_user() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
				'wpau-notify-admin'         => true,
			)
		);

		$this->start_wp_mail_capture();

		$instance = new Obenland_Wp_Approve_User();
		$instance->notify_admin_pending( self::$pending_user->ID );

		$this->assertNotEmpty( $this->captured_mail );
		$expected_admin = is_multisite() ? get_site_option( 'admin_email' ) : get_option( 'admin_email' );
		$this->assertSame( $expected_admin, $this->captured_mail['to'] );
		$this->assertStringContainsString( 'awaiting approval', $this->captured_mail['subject'] );
		$this->assertStringContainsString( self::$pending_user->user_login, $this->captured_mail['message'] );
		$this->assertStringContainsString( self::$pending_user->user_email, $this->captured_mail['message'] );
		$this->assertStringContainsString( 'role=wpau_pending', $this->captured_mail['message'] );
		$this->assertNotEmpty( get_user_meta( self::$pending_user->ID, 'wp-approve-user-admin-notified', true ) );
	}

	/**
	 * Does not send when the wpau-notify-admin setting is disabled.
	 *
	 * @covers ::notify_admin_pending
	 */
	public function test_notify_admin_pending_respects_disabled_setting() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
				'wpau-notify-admin'         => false,
			)
		);

		$this->start_wp_mail_capture();

		$instance = new Obenland_Wp_Approve_User();
		$instance->notify_admin_pending( self::$pending_user->ID );

		$this->assertEmpty( $this->captured_mail );
		$this->assertSame(
			'',
			get_user_meta( self::$pending_user->ID, 'wp-approve-user-admin-notified', true )
		);
	}

	/**
	 * Does not send for admin-created users (those start as approved).
	 *
	 * @covers ::notify_admin_pending
	 */
	public function test_notify_admin_pending_skips_approved_user() {
		update_user_meta( self::$pending_user->ID, 'wp-approve-user', 'approved' );

		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
				'wpau-notify-admin'         => true,
			)
		);

		$this->start_wp_mail_capture();

		$instance = new Obenland_Wp_Approve_User();
		$instance->notify_admin_pending( self::$pending_user->ID );

		$this->assertEmpty( $this->captured_mail );
	}

	/**
	 * Does not send twice when the admin-notified meta is already set.
	 *
	 * @covers ::notify_admin_pending
	 */
	public function test_notify_admin_pending_does_not_fire_twice() {
		update_user_meta( self::$pending_user->ID, 'wp-approve-user-admin-notified', true );

		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
				'wpau-notify-admin'         => true,
			)
		);

		$this->start_wp_mail_capture();

		$instance = new Obenland_Wp_Approve_User();
		$instance->notify_admin_pending( self::$pending_user->ID );

		$this->assertEmpty( $this->captured_mail );
	}

	/**
	 * The wpau_pending_notification_message filter overrides the placeholders before interpolation.
	 *
	 * @covers ::notify_admin_pending
	 */
	public function test_notify_admin_pending_filter_overrides_placeholders() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
				'wpau-notify-admin'         => true,
			)
		);

		$filter = function ( $placeholders ) {
			$placeholders['SITE_NAME']   = 'Filtered Site';
			$placeholders['PENDING_URL'] = 'https://example.test/custom-pending';
			return $placeholders;
		};
		add_filter( 'wpau_pending_notification_message', $filter );

		$this->start_wp_mail_capture();

		$instance = new Obenland_Wp_Approve_User();
		$instance->notify_admin_pending( self::$pending_user->ID );

		remove_filter( 'wpau_pending_notification_message', $filter );

		$this->assertStringContainsString( 'Filtered Site', $this->captured_mail['subject'] );
		$this->assertStringContainsString( 'Filtered Site', $this->captured_mail['message'] );
		$this->assertStringContainsString( 'https://example.test/custom-pending', $this->captured_mail['message'] );
	}

	/**
	 * Bails when the user ID doesn't resolve to a real user.
	 *
	 * @covers ::notify_admin_pending
	 */
	public function test_notify_admin_pending_bails_on_missing_user() {
		update_option(
			'wp-approve-user',
			array(
				'wpau-send-approve-email'   => false,
				'wpau-approve-email'        => '',
				'wpau-send-unapprove-email' => false,
				'wpau-unapprove-email'      => '',
				'wpau-notify-admin'         => true,
			)
		);

		// Create a bogus user_id with the pending meta but no user row.
		$ghost_id = 999999;
		update_user_meta( $ghost_id, 'wp-approve-user', 'pending' );

		$this->start_wp_mail_capture();

		$instance = new Obenland_Wp_Approve_User();
		$instance->notify_admin_pending( $ghost_id );

		delete_user_meta( $ghost_id, 'wp-approve-user' );

		$this->assertEmpty( $this->captured_mail );
	}

	/**
	 * The wpau-notify-admin default is true so sanitize() round-trips the default.
	 *
	 * @covers ::default_options
	 * @covers ::sanitize
	 */
	public function test_wpau_notify_admin_default_is_true() {
		delete_option( 'wp-approve-user' );
		$instance = new Obenland_Wp_Approve_User();

		$reflect = new ReflectionObject( $instance );
		$prop    = $reflect->getProperty( 'options' );
		$prop->setAccessible( true );
		$options = $prop->getValue( $instance );

		$this->assertTrue( $options['wpau-notify-admin'] );

		// Unchecked checkbox sanitizes back to false.
		$sanitized = $instance->sanitize( array() );
		$this->assertFalse( $sanitized['wpau-notify-admin'] );

		// Checked checkbox round-trips.
		$sanitized = $instance->sanitize( array( 'wpau-notify-admin' => '1' ) );
		$this->assertTrue( $sanitized['wpau-notify-admin'] );
	}

	/**
	 * Registers the notifications section and the wpau-notify-admin field during admin_init.
	 *
	 * @covers ::admin_init
	 * @covers ::notifications_section_description_cb
	 */
	public function test_admin_init_registers_notifications_section() {
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;
		$prev_sections = $wp_settings_sections;
		$prev_fields   = $wp_settings_fields;
		$prev_settings = $wp_registered_settings;

		$wp_settings_sections   = array();
		$wp_settings_fields     = array();
		$wp_registered_settings = array();

		$instance = new Obenland_Wp_Approve_User();
		$instance->admin_init();

		$this->assertArrayHasKey( 'wp-approve-user', $wp_settings_sections );
		$this->assertArrayHasKey( 'wp-approve-user-notifications', $wp_settings_sections['wp-approve-user'] );

		$this->assertArrayHasKey( 'wp-approve-user-notifications', $wp_settings_fields['wp-approve-user'] );
		$this->assertArrayHasKey(
			'wp-approve-user[notify-admin]',
			$wp_settings_fields['wp-approve-user']['wp-approve-user-notifications']
		);

		ob_start();
		$instance->notifications_section_description_cb();
		$this->assertStringContainsString( 'new user is awaiting approval', ob_get_clean() );

		$wp_settings_sections   = $prev_sections;
		$wp_settings_fields     = $prev_fields;
		$wp_registered_settings = $prev_settings;
	}
}
