<?php
/**
 * WPAU_Dashboard_Widget file.
 *
 * @package wp-approve-user
 */

/**
 * Pending-user approvals dashboard widget.
 *
 * Owns registration, rendering, asset enqueue, and AJAX handlers for the
 * `wpau_pending_users` dashboard widget. Delegates the approval side-effects
 * to Obenland_Wp_Approve_User::mark_approved()/mark_unapproved() so the row
 * actions, auto-approval rules, and this widget all converge on the same
 * sequence of meta writes + action hooks.
 *
 * @since 13
 */
class WPAU_Dashboard_Widget {

	/**
	 * Maximum number of pending users to render inside the widget.
	 *
	 * @since 13
	 */
	const ROWS = 5;

	/**
	 * Registers the widget, asset, and AJAX hooks.
	 *
	 * Admin-only hooks (register_widget, enqueue) are gated behind is_admin()
	 * because dashboard setup never fires on the front end. AJAX handlers are
	 * registered unconditionally because admin-ajax.php bootstraps before
	 * `is_admin()` becomes true on some setups.
	 *
	 * @since 13
	 */
	public function register_hooks() {
		if ( is_admin() ) {
			add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
			add_action( 'wp_network_dashboard_setup', array( $this, 'register_widget' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		add_action( 'wp_ajax_wpau_dashboard_approve', array( $this, 'ajax_approve' ) );
		add_action( 'wp_ajax_wpau_dashboard_unapprove', array( $this, 'ajax_unapprove' ) );
		add_action( 'wp_ajax_wpau_dashboard_refresh', array( $this, 'ajax_refresh' ) );
	}

	/**
	 * Registers the `wpau_pending_users` dashboard widget.
	 *
	 * Gated on the `promote_users` capability so authors/editors don't see it.
	 *
	 * @since 13
	 */
	public function register_widget() {
		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		wp_add_dashboard_widget(
			'wpau_pending_users',
			esc_html__( 'Pending User Approvals', 'wp-approve-user' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Enqueues the widget's JS + CSS on the main dashboard only.
	 *
	 * `index.php` covers both the site dashboard and the network dashboard
	 * (same hook slug on both screen sets).
	 *
	 * @since 13
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}

		$plugin_data = get_plugin_data( __DIR__ . '/wp-approve-user.php', false, false );
		$suffix      = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			'wpau-dashboard-widget',
			plugins_url( "/css/dashboard-widget{$suffix}.css", __FILE__ ),
			array(),
			$plugin_data['Version']
		);

		wp_enqueue_script(
			'wpau-dashboard-widget',
			plugins_url( "/js/dashboard-widget{$suffix}.js", __FILE__ ),
			array(),
			$plugin_data['Version'],
			true
		);

		wp_localize_script(
			'wpau-dashboard-widget',
			'wp_approve_user_dashboard',
			array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'refreshNonce'    => wp_create_nonce( 'wpau-dashboard-refresh' ),
				'errorGeneric'    => __( 'Something went wrong. Please try again.', 'wp-approve-user' ),
				'errorPermission' => __( 'You no longer have permission to do that.', 'wp-approve-user' ),
				'retryLabel'      => __( 'Try again', 'wp-approve-user' ),
				'emptyMessage'    => __( 'No users awaiting approval.', 'wp-approve-user' ),
				'refreshLabel'    => __( 'Load more', 'wp-approve-user' ),
			)
		);
	}

	/**
	 * Renders the dashboard widget.
	 *
	 * @since 13
	 */
	public function render_widget() {
		$pending_count = $this->get_pending_count();

		if ( is_network_admin() ) {
			$pending_url  = network_admin_url( 'users.php?role=wpau_pending' );
			$settings_url = network_admin_url( 'settings.php?page=wp-approve-user' );
		} else {
			$pending_url  = admin_url( 'users.php?role=wpau_pending' );
			$settings_url = admin_url( 'options-general.php?page=wp-approve-user' );
		}

		if ( $pending_count <= 0 ) {
			printf( '<p>%s</p>', esc_html__( 'No users awaiting approval.', 'wp-approve-user' ) );
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $settings_url ),
				esc_html__( 'Approve User settings', 'wp-approve-user' )
			);
			return;
		}

		$users = $this->get_pending_users( self::ROWS );

		echo '<ul class="wpau-pending-list" data-wpau-container>';
		foreach ( $users as $user ) {
			echo $this->render_row( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_row escapes internally.
		}
		echo '</ul>';

		printf(
			'<p class="wpau-widget-footer"><a href="%1$s">%2$s</a></p>',
			esc_url( $pending_url ),
			esc_html( $this->view_all_label( $pending_count ) )
		);
	}

	/**
	 * Returns up to $limit pending users, newest first.
	 *
	 * Scope: current blog on a site dashboard, the entire network when called
	 * from the network dashboard (blog_id=0).
	 *
	 * @since 13
	 *
	 * @param int $limit Maximum number of users to return.
	 * @return WP_User[] Pending users.
	 */
	public function get_pending_users( $limit ) {
		$args = array(
			'meta_key'   => 'wp-approve-user', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => 'pending', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => (int) $limit,
			'orderby'    => 'registered',
			'order'      => 'DESC',
		);

		if ( is_multisite() ) {
			$args['blog_id'] = is_network_admin() ? 0 : get_current_blog_id();
		}

		$query = new WP_User_Query( $args );
		return $query->get_results();
	}

	/**
	 * Returns the total number of pending users for the current scope.
	 *
	 * Always issues a fresh query so callers get the post-action count right
	 * after an approve/unapprove.
	 *
	 * @since 13
	 *
	 * @return int Pending user count for the current blog (site dashboard)
	 *             or the entire network (network dashboard).
	 */
	public function get_pending_count() {
		$args = array(
			'fields'      => 'ID',
			'number'      => 1,
			'count_total' => true,
			'meta_key'    => 'wp-approve-user', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => 'pending', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);

		if ( is_multisite() ) {
			$args['blog_id'] = is_network_admin() ? 0 : get_current_blog_id();
		}

		$query = new WP_User_Query( $args );
		return (int) $query->get_total();
	}

	/**
	 * Renders a single pending-user row as an HTML string.
	 *
	 * Returns the markup rather than echoing so the same helper backs the
	 * initial render and the AJAX refresh response.
	 *
	 * @since 13
	 *
	 * @param WP_User $user Pending user.
	 * @return string
	 */
	public function render_row( WP_User $user ) {
		$display = '' !== $user->display_name ? $user->display_name : $user->user_login;

		/*
		 * `user_registered` is stored in site-local time (WP writes it with
		 * current_time( 'mysql' )), so force interpretation through mysql2date()
		 * — appending ' UTC' would skew the difference by the site offset.
		 */
		$time_label = sprintf(
			/* translators: %s: Human-readable time difference (e.g. "2 hours"). */
			__( 'Registered %s ago', 'wp-approve-user' ),
			human_time_diff( mysql2date( 'U', $user->user_registered ) )
		);

		$approve_nonce   = wp_create_nonce( 'wpau-dashboard-approve-' . $user->ID );
		$unapprove_nonce = wp_create_nonce( 'wpau-dashboard-unapprove-' . $user->ID );

		$html  = sprintf(
			'<li class="wpau-pending-row" data-user-id="%1$d" data-nonce-approve="%2$s" data-nonce-unapprove="%3$s">',
			(int) $user->ID,
			esc_attr( $approve_nonce ),
			esc_attr( $unapprove_nonce )
		);
		$html .= '<div class="wpau-pending-avatar">' . get_avatar( $user->ID, 32 ) . '</div>';
		$html .= '<div class="wpau-pending-meta">';
		$html .= sprintf( '<strong class="wpau-pending-name">%s</strong>', esc_html( $display ) );
		$html .= sprintf( '<span class="wpau-pending-email">%s</span>', esc_html( $user->user_email ) );
		$html .= sprintf( '<span class="wpau-pending-time">%s</span>', esc_html( $time_label ) );
		$html .= '</div>';
		$html .= sprintf(
			'<div class="wpau-pending-actions" role="group" aria-label="%s">',
			esc_attr(
				sprintf(
					/* translators: %s: Display name of the pending user. */
					__( 'Actions for %s', 'wp-approve-user' ),
					$display
				)
			)
		);
		$html .= sprintf(
			'<button type="button" class="button button-primary" data-wpau-action="approve">%s</button>',
			esc_html__( 'Approve', 'wp-approve-user' )
		);
		$html .= sprintf(
			'<button type="button" class="button button-link-delete" data-wpau-action="unapprove">%s</button>',
			esc_html__( 'Reject', 'wp-approve-user' )
		);
		$html .= '</div>';
		$html .= '</li>';

		return $html;
	}

	/**
	 * AJAX handler: approves a pending user.
	 *
	 * Validates a user-scoped nonce and the promote_users capability, confirms
	 * the target user exists, is in scope for the current blog, and is still
	 * `pending`, then delegates to the main class's mark_approved() helper so
	 * row actions, auto-approval, and this widget converge on the same side-
	 * effects. Emits the refreshed pending count + server-rendered footer
	 * label so the client never re-applies translations.
	 *
	 * @since 13
	 */
	public function ajax_approve() {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		check_ajax_referer( 'wpau-dashboard-approve-' . $user_id, 'nonce' );

		if ( ! current_user_can( 'promote_users' ) ) {
			wp_send_json_error( array( 'code' => 'cap' ), 403 );
		}

		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			wp_send_json_error( array( 'code' => 'unknown_user' ), 404 );
		}

		/*
		 * On site dashboards, reject requests targeting users that don't
		 * belong to the current blog. The widget never renders out-of-scope
		 * users, but admin-ajax is callable directly, so gate here.
		 */
		if ( is_multisite() && ! is_network_admin() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			wp_send_json_error( array( 'code' => 'out_of_scope' ), 404 );
		}

		$stale = 'pending' !== get_user_meta( $user_id, 'wp-approve-user', true );
		if ( ! $stale ) {
			Obenland_Wp_Approve_User::mark_approved( $user_id );
		}

		wp_send_json_success( $this->transition_payload( $user_id, $stale ) );
	}

	/**
	 * AJAX handler: unapproves a pending user.
	 *
	 * Mirror of ajax_approve() — see that method for the per-step rationale.
	 * The two flows are kept separate instead of shared so PHP coverage
	 * tracking sees each exit branch directly.
	 *
	 * @since 13
	 */
	public function ajax_unapprove() {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		check_ajax_referer( 'wpau-dashboard-unapprove-' . $user_id, 'nonce' );

		if ( ! current_user_can( 'promote_users' ) ) {
			wp_send_json_error( array( 'code' => 'cap' ), 403 );
		}

		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			wp_send_json_error( array( 'code' => 'unknown_user' ), 404 );
		}

		if ( is_multisite() && ! is_network_admin() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			wp_send_json_error( array( 'code' => 'out_of_scope' ), 404 );
		}

		$stale = 'pending' !== get_user_meta( $user_id, 'wp-approve-user', true );
		if ( ! $stale ) {
			Obenland_Wp_Approve_User::mark_unapproved( $user_id );
		}

		wp_send_json_success( $this->transition_payload( $user_id, $stale ) );
	}

	/**
	 * Builds the JSON payload shared by approve and unapprove success responses.
	 *
	 * Also fetches the next off-screen pending user (if any) so the client can
	 * refill its row slot without a second request — keeping the widget at
	 * ROWS visible users as the admin works through the queue.
	 *
	 * @since 13
	 * @access protected
	 *
	 * @param int  $user_id The user the action just applied to.
	 * @param bool $stale   True when the target was no longer `pending` when the
	 *                      request arrived (another admin beat us to it).
	 * @return array
	 */
	protected function transition_payload( $user_id, $stale ) {
		$count = $this->get_pending_count();

		/*
		 * After the transition, the pending list has shifted — the user that
		 * was at post-index ROWS-1 is the one the client hasn't seen yet but
		 * needs to display to refill to ROWS. Ask for exactly that user.
		 */
		$next_row = '';
		$next     = $this->get_pending_users_slice( self::ROWS - 1, 1 );
		if ( ! empty( $next ) ) {
			$next_row = $this->render_row( $next[0] );
		}

		return array(
			'user_id'       => $user_id,
			'stale'         => $stale,
			'pending_count' => $count,
			'pending_label' => $count > 0 ? $this->view_all_label( $count ) : '',
			'next_row'      => $next_row,
		);
	}

	/**
	 * Returns a specific slice of the pending-user query.
	 *
	 * Thin wrapper around WP_User_Query used to fetch a single off-screen row
	 * for `transition_payload()`; kept separate from `get_pending_users()` so
	 * the offset-aware query args don't bleed into the main render path.
	 *
	 * @since 13
	 * @access protected
	 *
	 * @param int $offset Zero-based offset into the pending list.
	 * @param int $limit  Maximum number of users to return.
	 * @return WP_User[]
	 */
	protected function get_pending_users_slice( $offset, $limit ) {
		$args = array(
			'meta_key'   => 'wp-approve-user', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => 'pending', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => (int) $limit,
			'offset'     => (int) $offset,
			'orderby'    => 'registered',
			'order'      => 'DESC',
		);

		if ( is_multisite() ) {
			$args['blog_id'] = is_network_admin() ? 0 : get_current_blog_id();
		}

		$query = new WP_User_Query( $args );
		return $query->get_results();
	}

	/**
	 * AJAX handler: returns the next batch of pending-user rows as HTML.
	 *
	 * Called when the visible list has emptied but the server reports more
	 * pending users remain. Returns an empty `html` string when no users are
	 * left so the client can render the empty state.
	 *
	 * @since 13
	 */
	public function ajax_refresh() {
		check_ajax_referer( 'wpau-dashboard-refresh', 'nonce' );

		if ( ! current_user_can( 'promote_users' ) ) {
			wp_send_json_error( array( 'code' => 'cap' ), 403 );
		}

		$users = $this->get_pending_users( self::ROWS );

		$html = '';
		foreach ( $users as $user ) {
			$html .= $this->render_row( $user );
		}

		$count = $this->get_pending_count();
		wp_send_json_success(
			array(
				'html'          => $html,
				'pending_count' => $count,
				'pending_label' => $count > 0 ? $this->view_all_label( $count ) : '',
			)
		);
	}

	/**
	 * Renders the "View all N pending" footer label.
	 *
	 * Single source of truth for the pluralized string so PHP and JS don't
	 * drift on nplurals handling.
	 *
	 * @since 13
	 *
	 * @param int $pending_count Current pending user count.
	 * @return string
	 */
	public function view_all_label( $pending_count ) {
		return sprintf(
			/* translators: %d: Number of users awaiting approval. */
			_n( 'View all %d pending', 'View all %d pending', $pending_count, 'wp-approve-user' ),
			$pending_count
		);
	}
}
