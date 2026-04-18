<?php
/**
 * Tests for the pending user approvals dashboard widget.
 *
 * @package wp-approve-user
 */

/**
 * Covers dashboard widget registration and rendering.
 *
 * @coversDefaultClass Obenland_Wp_Approve_User
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
	 * Sets a protected property via reflection.
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
	 * Registers the widget on wp_dashboard_setup when the current user has promote_users.
	 *
	 * @covers ::register_dashboard_widget
	 */
	public function test_register_dashboard_widget_adds_widget_for_promote_users() {
		global $wp_meta_boxes;
		$prev          = $wp_meta_boxes;
		$wp_meta_boxes = array();

		$instance = new Obenland_Wp_Approve_User();
		$instance->register_dashboard_widget();

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
	 * @covers ::register_dashboard_widget
	 */
	public function test_register_dashboard_widget_skipped_without_promote_users_cap() {
		global $wp_meta_boxes;
		$prev          = $wp_meta_boxes;
		$wp_meta_boxes = array();

		wp_set_current_user( self::$subscriber->ID );

		$instance = new Obenland_Wp_Approve_User();
		$instance->register_dashboard_widget();

		$this->assertTrue(
			empty( $wp_meta_boxes['dashboard']['normal']['core']['wpau_pending_users'] )
		);

		$wp_meta_boxes = $prev;
	}

	/**
	 * Lazily runs a WP_User_Query and caches the result when pending_count is unset.
	 *
	 * @covers ::get_pending_count
	 */
	public function test_get_pending_count_queries_when_unset() {
		$pending_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $pending_user, 'wp-approve-user', 'pending' );

		$instance = new Obenland_Wp_Approve_User();
		$this->set_protected( $instance, 'pending_count', null );

		$this->assertSame( 1, $instance->get_pending_count() );

		/* Second call returns the cached value without re-running the query. */
		update_user_meta( $pending_user, 'wp-approve-user', 'approved' );
		$this->assertSame( 1, $instance->get_pending_count() );
	}

	/**
	 * Renders the non-zero branch with a count sentence and the Review button.
	 *
	 * @covers ::render_dashboard_widget
	 * @covers ::get_pending_count
	 */
	public function test_render_dashboard_widget_non_zero_count() {
		$instance = new Obenland_Wp_Approve_User();
		$this->set_protected( $instance, 'pending_count', 3 );

		$this->assertSame( 3, $instance->get_pending_count() );

		ob_start();
		$instance->render_dashboard_widget();
		$html = ob_get_clean();

		$this->assertStringContainsString( '3 users are awaiting approval', $html );
		$this->assertStringContainsString( 'role=wpau_pending', $html );
		$this->assertStringContainsString( 'button-primary', $html );
		$this->assertStringContainsString( 'Review pending users', $html );
	}

	/**
	 * Singular message uses "1 user" for a count of one.
	 *
	 * @covers ::render_dashboard_widget
	 */
	public function test_render_dashboard_widget_singular_count() {
		$instance = new Obenland_Wp_Approve_User();
		$this->set_protected( $instance, 'pending_count', 1 );

		ob_start();
		$instance->render_dashboard_widget();
		$html = ob_get_clean();

		$this->assertStringContainsString( '1 user is awaiting approval', $html );
	}

	/**
	 * Zero-count branch renders the empty-state message and a link to settings.
	 *
	 * @covers ::render_dashboard_widget
	 */
	public function test_render_dashboard_widget_zero_count() {
		$instance = new Obenland_Wp_Approve_User();
		$this->set_protected( $instance, 'pending_count', 0 );

		ob_start();
		$instance->render_dashboard_widget();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'No users awaiting approval.', $html );
		$this->assertStringContainsString( 'page=wp-approve-user', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}

	/**
	 * On the network admin dashboard the widget links to network URLs so
	 * super admins reach the network-wide users screen.
	 *
	 * @covers ::render_dashboard_widget
	 */
	public function test_render_dashboard_widget_uses_network_url_on_network_admin() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		set_current_screen( 'dashboard-network' );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$this->set_protected( $instance, 'pending_count', 2 );

			ob_start();
			$instance->render_dashboard_widget();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'network/users.php', $html );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * On a multisite site dashboard the widget must link to the site's own
	 * users screen, not network admin — site admins cannot reach the latter.
	 *
	 * @covers ::render_dashboard_widget
	 */
	public function test_render_dashboard_widget_uses_site_url_on_site_dashboard() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		set_current_screen( 'dashboard' );

		try {
			$instance = new Obenland_Wp_Approve_User();
			$this->set_protected( $instance, 'pending_count', 2 );

			ob_start();
			$instance->render_dashboard_widget();
			$html = ob_get_clean();

			$this->assertStringNotContainsString( 'network/users.php', $html );
			$this->assertStringContainsString( 'users.php?role=wpau_pending', $html );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Registers both dashboard-widget hooks when plugins_loaded runs.
	 *
	 * @covers ::plugins_loaded
	 */
	public function test_plugins_loaded_registers_dashboard_widget_hooks() {
		$instance = new Obenland_Wp_Approve_User();
		$instance->plugins_loaded();

		$this->assertNotFalse(
			has_action( 'wp_dashboard_setup', array( $instance, 'register_dashboard_widget' ) )
		);
		$this->assertNotFalse(
			has_action( 'wp_network_dashboard_setup', array( $instance, 'register_dashboard_widget' ) )
		);
	}
}
