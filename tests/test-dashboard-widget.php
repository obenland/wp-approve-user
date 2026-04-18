<?php
/**
 * Tests for the pending user approvals dashboard widget.
 *
 * @package wp-approve-user
 */

/**
 * Covers WPAU_Dashboard_Widget registration and rendering.
 *
 * @coversDefaultClass WPAU_Dashboard_Widget
 */
class WPAU_Dashboard_Widget_Test extends WP_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var WP_User
	 */
	public static $admin;

	/**
	 * Subscriber user (lacks the `promote_users` capability).
	 *
	 * @var WP_User
	 */
	public static $subscriber;

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
		set_current_screen( 'dashboard' );
	}

	/**
	 * Creates N pending subscribers with distinct emails and registration times.
	 *
	 * Each user's `user_registered` timestamp is offset by its index so ordering
	 * is deterministic (newest first in the widget).
	 *
	 * @param int $count Number of pending users to create.
	 * @return int[] User IDs, oldest first.
	 */
	protected function seed_pending_users( $count ) {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id = self::factory()->user->create(
				array(
					'role'            => 'subscriber',
					'user_email'      => "pending-{$i}@example.test",
					'user_login'      => "pending{$i}-" . wp_generate_password( 6, false ),
					'user_registered' => gmdate( 'Y-m-d H:i:s', time() - ( $count - $i ) * 3600 ),
				)
			);
			update_user_meta( $id, 'wp-approve-user', 'pending' );
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Registers the widget on wp_dashboard_setup when the current user has promote_users.
	 *
	 * @covers ::register_widget
	 */
	public function test_register_widget_adds_widget_for_promote_users() {
		global $wp_meta_boxes;
		$prev          = $wp_meta_boxes;
		$wp_meta_boxes = array();

		( new WPAU_Dashboard_Widget() )->register_widget();

		$this->assertArrayHasKey( 'dashboard', $wp_meta_boxes );
		$this->assertArrayHasKey( 'wpau_pending_users', $wp_meta_boxes['dashboard']['normal']['core'] );
		$this->assertSame(
			'Pending User Approvals',
			$wp_meta_boxes['dashboard']['normal']['core']['wpau_pending_users']['title']
		);

		$wp_meta_boxes = $prev;
	}

	/**
	 * Does not register the widget for users without the promote_users capability.
	 *
	 * @covers ::register_widget
	 */
	public function test_register_widget_skipped_without_promote_users_cap() {
		global $wp_meta_boxes;
		$prev          = $wp_meta_boxes;
		$wp_meta_boxes = array();

		wp_set_current_user( self::$subscriber->ID );

		( new WPAU_Dashboard_Widget() )->register_widget();

		$this->assertTrue(
			empty( $wp_meta_boxes['dashboard']['normal']['core']['wpau_pending_users'] )
		);

		$wp_meta_boxes = $prev;
	}

	/**
	 * Empty-state branch renders the "no pending" message and a link to settings.
	 *
	 * @covers ::render_widget
	 */
	public function test_render_widget_zero_count() {
		ob_start();
		( new WPAU_Dashboard_Widget() )->render_widget();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'No users awaiting approval.', $html );
		$this->assertStringContainsString( 'page=wp-approve-user', $html );
		$this->assertStringNotContainsString( 'wpau-pending-list', $html );
	}

	/**
	 * Non-empty branch lists the pending users and emits per-row actions.
	 *
	 * @covers ::render_widget
	 * @covers ::render_row
	 * @covers ::get_pending_users
	 * @covers ::get_pending_count
	 */
	public function test_render_widget_lists_pending_users_with_actions() {
		$ids = $this->seed_pending_users( 3 );

		ob_start();
		( new WPAU_Dashboard_Widget() )->render_widget();
		$html = ob_get_clean();

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			$this->assertStringContainsString( 'data-user-id="' . $id . '"', $html );
			$this->assertStringContainsString( $user->user_email, $html );
		}

		$this->assertStringContainsString( 'data-wpau-action="approve"', $html );
		$this->assertStringContainsString( 'data-wpau-action="unapprove"', $html );
		$this->assertStringContainsString( 'wpau-pending-list', $html );
		$this->assertStringContainsString( 'View all 3 pending', $html );
	}

	/**
	 * Widget caps at WPAU_Dashboard_Widget::ROWS even when more pending users exist.
	 *
	 * @covers ::render_widget
	 * @covers ::get_pending_users
	 */
	public function test_render_widget_caps_rows_at_limit() {
		$ids = $this->seed_pending_users( WPAU_Dashboard_Widget::ROWS + 2 );

		ob_start();
		( new WPAU_Dashboard_Widget() )->render_widget();
		$html = ob_get_clean();

		$this->assertSame(
			WPAU_Dashboard_Widget::ROWS,
			substr_count( $html, 'class="wpau-pending-row"' )
		);
		$this->assertStringContainsString( 'View all ' . count( $ids ) . ' pending', $html );
	}

	/**
	 * Network dashboard renders the network URL in the footer.
	 *
	 * @covers ::render_widget
	 */
	public function test_render_widget_uses_network_url_on_network_dashboard() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$this->seed_pending_users( 2 );
		set_current_screen( 'dashboard-network' );

		try {
			ob_start();
			( new WPAU_Dashboard_Widget() )->render_widget();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'network/users.php', $html );
		} finally {
			set_current_screen( 'dashboard' );
		}
	}

	/**
	 * Empty state on the network dashboard falls back to the settings link.
	 *
	 * @covers ::render_widget
	 */
	public function test_render_widget_zero_count_uses_network_settings_url_on_network_dashboard() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		set_current_screen( 'dashboard-network' );

		try {
			ob_start();
			( new WPAU_Dashboard_Widget() )->render_widget();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'network/settings.php', $html );
		} finally {
			set_current_screen( 'dashboard' );
		}
	}

	/**
	 * View_all_label() returns the _n() form for the given count.
	 *
	 * @covers ::view_all_label
	 */
	public function test_view_all_label_interpolates_count() {
		$widget = new WPAU_Dashboard_Widget();

		$this->assertSame( 'View all 1 pending', $widget->view_all_label( 1 ) );
		$this->assertSame( 'View all 42 pending', $widget->view_all_label( 42 ) );
	}

	/**
	 * Rows without a display name fall back to the user login.
	 *
	 * @covers ::render_row
	 */
	public function test_render_row_falls_back_to_user_login_without_display_name() {
		$id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'user_login'   => 'no-display-' . wp_generate_password( 6, false ),
				'user_email'   => 'nodisplay@example.test',
				'display_name' => '',
			)
		);
		update_user_meta( $id, 'wp-approve-user', 'pending' );

		$user   = get_userdata( $id );
		$widget = new WPAU_Dashboard_Widget();
		$html   = $widget->render_row( $user );

		$this->assertStringContainsString( $user->user_login, $html );
	}

	/**
	 * Registers the dashboard setup + AJAX handlers so that wp_dashboard_setup
	 * triggers register_widget and each AJAX action routes to its handler. The
	 * AJAX tests in test-dashboard-widget-ajax.php call handlers directly; this
	 * test guards the wiring that connects them.
	 *
	 * @covers ::register_hooks
	 */
	public function test_register_hooks_registers_dashboard_and_ajax_callbacks() {
		$widget = new WPAU_Dashboard_Widget();
		$widget->register_hooks();

		$this->assertNotFalse( has_action( 'wp_dashboard_setup', array( $widget, 'register_widget' ) ) );
		$this->assertNotFalse( has_action( 'wp_network_dashboard_setup', array( $widget, 'register_widget' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $widget, 'enqueue_assets' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wpau_dashboard_approve', array( $widget, 'ajax_approve' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wpau_dashboard_unapprove', array( $widget, 'ajax_unapprove' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wpau_dashboard_refresh', array( $widget, 'ajax_refresh' ) ) );
	}

	/**
	 * Enqueue runs on the dashboard only and only for users with promote_users.
	 *
	 * @covers ::enqueue_assets
	 */
	public function test_enqueue_assets_only_on_dashboard_index() {
		$widget = new WPAU_Dashboard_Widget();

		$widget->enqueue_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( 'wpau-dashboard-widget', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wpau-dashboard-widget', 'enqueued' ) );

		$widget->enqueue_assets( 'index.php' );
		$this->assertTrue( wp_script_is( 'wpau-dashboard-widget', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wpau-dashboard-widget', 'enqueued' ) );

		wp_dequeue_script( 'wpau-dashboard-widget' );
		wp_dequeue_style( 'wpau-dashboard-widget' );
	}

	/**
	 * Enqueue bails out when the current user lacks promote_users.
	 *
	 * @covers ::enqueue_assets
	 */
	public function test_enqueue_assets_requires_promote_users_cap() {
		wp_set_current_user( self::$subscriber->ID );

		( new WPAU_Dashboard_Widget() )->enqueue_assets( 'index.php' );

		$this->assertFalse( wp_script_is( 'wpau-dashboard-widget', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wpau-dashboard-widget', 'enqueued' ) );
	}
}
