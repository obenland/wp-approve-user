<?php
/**
 * Tests for the dashboard widget's AJAX handlers.
 *
 * @package wp-approve-user
 */

/**
 * Covers WPAU_Dashboard_Widget AJAX handlers.
 *
 * @coversDefaultClass WPAU_Dashboard_Widget
 */
class WPAU_Dashboard_Widget_Ajax_Test extends WP_Ajax_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var WP_User
	 */
	public static $admin;

	/**
	 * Subscriber fixture.
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
		( new WPAU_Dashboard_Widget() )->register_hooks();
	}

	/**
	 * Creates a pending subscriber and returns its ID.
	 *
	 * @param string $email Email address.
	 * @return int
	 */
	protected function make_pending( $email = 'pending@example.test' ) {
		$id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => $email,
			)
		);
		update_user_meta( $id, 'wp-approve-user', 'pending' );
		return $id;
	}

	/**
	 * Dispatches an AJAX action and returns the decoded JSON response.
	 *
	 * WP_Ajax_UnitTestCase surfaces the success exit via WPAjaxDieContinueException;
	 * catching it here so each caller can just assert on the response payload.
	 *
	 * @param string $action AJAX action slug.
	 * @return array
	 */
	protected function dispatch( $action ) {
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		return json_decode( $this->_last_response, true );
	}

	/**
	 * Approve handler flips the user to approved, fires wpau_approve, and returns the new count.
	 *
	 * @covers ::ajax_approve
	 */
	public function test_ajax_approve_marks_user_approved() {
		$user_id = $this->make_pending();

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-approve-' . $user_id );

		$fired    = array();
		$listener = function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'wpau_approve', $listener );

		try {
			$response = $this->dispatch( 'wpau_dashboard_approve' );
		} finally {
			remove_action( 'wpau_approve', $listener );
		}

		$this->assertSame( 'approved', get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertSame( array( $user_id ), $fired );
		$this->assertTrue( $response['success'] );
		$this->assertSame( $user_id, $response['data']['user_id'] );
		$this->assertSame( 0, $response['data']['pending_count'] );
		$this->assertSame( '', $response['data']['pending_label'] );
		$this->assertFalse( $response['data']['stale'] );
	}

	/**
	 * The approve response includes the next off-screen pending user so the client can refill its row slot.
	 *
	 * @covers ::ajax_approve
	 * @covers ::transition_payload
	 * @covers ::get_pending_users_slice
	 */
	public function test_ajax_approve_returns_next_row_when_more_pending_remain() {
		$ids = array();
		for ( $i = 0; $i < WPAU_Dashboard_Widget::ROWS + 2; $i++ ) {
			$ids[] = $this->make_pending( "refill-{$i}@example.test" );
		}

		$target = $ids[0];

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $target;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-approve-' . $target );

		$response = $this->dispatch( 'wpau_dashboard_approve' );

		$this->assertTrue( $response['success'] );
		$this->assertNotSame( '', $response['data']['next_row'] );
		$this->assertStringContainsString( 'class="wpau-pending-row"', $response['data']['next_row'] );
		$this->assertStringNotContainsString( 'data-user-id="' . $target . '"', $response['data']['next_row'] );
	}

	/**
	 * The approve response omits next_row when fewer than ROWS pending users remain.
	 *
	 * @covers ::ajax_approve
	 * @covers ::transition_payload
	 */
	public function test_ajax_approve_returns_empty_next_row_when_queue_drains() {
		$user_id = $this->make_pending( 'drain@example.test' );

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-approve-' . $user_id );

		$response = $this->dispatch( 'wpau_dashboard_approve' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( '', $response['data']['next_row'] );
	}

	/**
	 * A nonce minted for one user ID is rejected when POSTed against a different user ID.
	 *
	 * @covers ::ajax_approve
	 */
	public function test_ajax_approve_rejects_mismatched_user_nonce() {
		$target_id     = $this->make_pending( 'target@example.test' );
		$other_id      = $this->make_pending( 'other@example.test' );
		$other_pending = get_user_meta( $target_id, 'wp-approve-user', true );

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $target_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-approve-' . $other_id );

		try {
			$this->expectException( WPAjaxDieStopException::class );
			$this->_handleAjax( 'wpau_dashboard_approve' );
		} finally {
			$this->assertSame(
				$other_pending,
				get_user_meta( $target_id, 'wp-approve-user', true ),
				'Target user must not be mutated when the nonce is for a different user.'
			);
		}
	}

	/**
	 * Unapprove handler flips the user to unapproved, destroys sessions, and fires wpau_unapprove.
	 *
	 * @covers ::ajax_unapprove
	 */
	public function test_ajax_unapprove_destroys_sessions_and_fires_action() {
		$user_id = $this->make_pending( 'reject@example.test' );

		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->create( time() + 3600 );
		$this->assertNotEmpty( $sessions->get_all() );

		$_POST['action']  = 'wpau_dashboard_unapprove';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-unapprove-' . $user_id );

		$fired    = array();
		$listener = function ( $id ) use ( &$fired ) {
			$fired[] = $id;
		};
		add_action( 'wpau_unapprove', $listener );

		try {
			$response = $this->dispatch( 'wpau_dashboard_unapprove' );
		} finally {
			remove_action( 'wpau_unapprove', $listener );
		}

		$this->assertSame( 'unapproved', get_user_meta( $user_id, 'wp-approve-user', true ) );
		$this->assertEmpty( WP_Session_Tokens::get_instance( $user_id )->get_all() );
		$this->assertSame( array( $user_id ), $fired );
		$this->assertTrue( $response['success'] );
	}

	/**
	 * Approve handler rejects requests without a valid nonce and leaves the meta untouched.
	 *
	 * @covers ::ajax_approve
	 */
	public function test_ajax_approve_rejects_bad_nonce() {
		$user_id = $this->make_pending();

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = 'bogus';

		$fired = 0;
		add_action(
			'wpau_approve',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		try {
			$this->expectException( WPAjaxDieStopException::class );
			$this->_handleAjax( 'wpau_dashboard_approve' );
		} finally {
			$this->assertSame( 'pending', get_user_meta( $user_id, 'wp-approve-user', true ) );
			$this->assertSame( 0, $fired );
		}
	}

	/**
	 * Approve handler 403s when the caller lacks promote_users.
	 *
	 * @covers ::ajax_approve
	 */
	public function test_ajax_approve_rejects_without_promote_users_cap() {
		$user_id = $this->make_pending();

		wp_set_current_user( self::$subscriber->ID );

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-approve-' . $user_id );

		$response = $this->dispatch( 'wpau_dashboard_approve' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'cap', $response['data']['code'] );
	}

	/**
	 * Approve handler is a no-op on an already-approved user but still reports success.
	 *
	 * @covers ::ajax_approve
	 */
	public function test_ajax_approve_idempotent_on_already_approved_user() {
		$user_id = $this->make_pending();
		update_user_meta( $user_id, 'wp-approve-user', 'approved' );

		$fired = 0;
		add_action(
			'wpau_approve',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-approve-' . $user_id );

		$response = $this->dispatch( 'wpau_dashboard_approve' );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['stale'] );
		$this->assertSame( 0, $fired );
	}

	/**
	 * Unknown user id returns a 404-style error.
	 *
	 * @covers ::ajax_approve
	 */
	public function test_ajax_approve_unknown_user() {
		$ghost_id = 999999;

		$_POST['action']  = 'wpau_dashboard_approve';
		$_POST['user_id'] = $ghost_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-approve-' . $ghost_id );

		$response = $this->dispatch( 'wpau_dashboard_approve' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'unknown_user', $response['data']['code'] );
	}

	/**
	 * Unapprove handler rejects requests without a valid nonce and leaves the meta untouched.
	 *
	 * @covers ::ajax_unapprove
	 */
	public function test_ajax_unapprove_rejects_bad_nonce() {
		$user_id = $this->make_pending();

		$_POST['action']  = 'wpau_dashboard_unapprove';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = 'bogus';

		$fired = 0;
		add_action(
			'wpau_unapprove',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		try {
			$this->expectException( WPAjaxDieStopException::class );
			$this->_handleAjax( 'wpau_dashboard_unapprove' );
		} finally {
			$this->assertSame( 'pending', get_user_meta( $user_id, 'wp-approve-user', true ) );
			$this->assertSame( 0, $fired );
		}
	}

	/**
	 * Unapprove handler 403s when the caller lacks promote_users.
	 *
	 * @covers ::ajax_unapprove
	 */
	public function test_ajax_unapprove_rejects_without_cap() {
		$user_id = $this->make_pending();

		wp_set_current_user( self::$subscriber->ID );

		$_POST['action']  = 'wpau_dashboard_unapprove';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-unapprove-' . $user_id );

		$response = $this->dispatch( 'wpau_dashboard_unapprove' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'cap', $response['data']['code'] );
	}

	/**
	 * Unapprove handler is a no-op on a non-pending user but still reports success.
	 *
	 * @covers ::ajax_unapprove
	 */
	public function test_ajax_unapprove_stale_on_non_pending_user() {
		$user_id = $this->make_pending();
		update_user_meta( $user_id, 'wp-approve-user', 'approved' );

		$_POST['action']  = 'wpau_dashboard_unapprove';
		$_POST['user_id'] = $user_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-unapprove-' . $user_id );

		$response = $this->dispatch( 'wpau_dashboard_unapprove' );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['stale'] );
	}

	/**
	 * Unknown user id on unapprove returns a 404-style error.
	 *
	 * @covers ::ajax_unapprove
	 */
	public function test_ajax_unapprove_unknown_user() {
		$ghost_id = 999998;

		$_POST['action']  = 'wpau_dashboard_unapprove';
		$_POST['user_id'] = $ghost_id;
		$_POST['nonce']   = wp_create_nonce( 'wpau-dashboard-unapprove-' . $ghost_id );

		$response = $this->dispatch( 'wpau_dashboard_unapprove' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'unknown_user', $response['data']['code'] );
	}

	/**
	 * Refresh handler returns HTML for the current pending batch.
	 *
	 * @covers ::ajax_refresh
	 */
	public function test_ajax_refresh_returns_html_rows() {
		$id1 = $this->make_pending( 'a@example.test' );
		$id2 = $this->make_pending( 'b@example.test' );

		$_POST['action'] = 'wpau_dashboard_refresh';
		$_POST['nonce']  = wp_create_nonce( 'wpau-dashboard-refresh' );

		$response = $this->dispatch( 'wpau_dashboard_refresh' );

		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'data-user-id="' . $id1 . '"', $response['data']['html'] );
		$this->assertStringContainsString( 'data-user-id="' . $id2 . '"', $response['data']['html'] );
		$this->assertSame( 2, $response['data']['pending_count'] );
	}

	/**
	 * Refresh handler rejects requests without a valid nonce.
	 *
	 * @covers ::ajax_refresh
	 */
	public function test_ajax_refresh_rejects_bad_nonce() {
		$_POST['action'] = 'wpau_dashboard_refresh';
		$_POST['nonce']  = 'bogus';

		$this->expectException( WPAjaxDieStopException::class );
		$this->_handleAjax( 'wpau_dashboard_refresh' );
	}

	/**
	 * On multisite site dashboards, rejects users who aren't a member of the current blog.
	 *
	 * @covers ::ajax_approve
	 * @covers ::ajax_unapprove
	 */
	public function test_ajax_rejects_user_outside_current_blog_scope() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$stranger_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'outsider@example.test',
			)
		);
		update_user_meta( $stranger_id, 'wp-approve-user', 'pending' );

		/* Remove from the current blog so is_user_member_of_blog() returns false. */
		remove_user_from_blog( $stranger_id, get_current_blog_id() );

		foreach ( array( 'approve', 'unapprove' ) as $action ) {
			$this->_last_response = '';
			$_POST                = array(
				'action'  => 'wpau_dashboard_' . $action,
				'user_id' => $stranger_id,
				'nonce'   => wp_create_nonce( 'wpau-dashboard-' . $action . '-' . $stranger_id ),
			);

			$response = $this->dispatch( 'wpau_dashboard_' . $action );

			$this->assertIsArray( $response );
			$this->assertFalse( $response['success'] );
			$this->assertSame( 'out_of_scope', $response['data']['code'] );
		}
	}

	/**
	 * Refresh handler rejects callers without promote_users.
	 *
	 * @covers ::ajax_refresh
	 */
	public function test_ajax_refresh_rejects_without_cap() {
		wp_set_current_user( self::$subscriber->ID );

		$_POST['action'] = 'wpau_dashboard_refresh';
		$_POST['nonce']  = wp_create_nonce( 'wpau-dashboard-refresh' );

		$response = $this->dispatch( 'wpau_dashboard_refresh' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'cap', $response['data']['code'] );
	}
}
