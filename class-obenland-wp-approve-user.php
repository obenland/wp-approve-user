<?php
/**
 * Obenland_Wp_Approve_User file.
 *
 * @package wp-approve-user
 */

/**
 * Class Obenland_Wp_Approve_User.
 */
class Obenland_Wp_Approve_User extends Obenland_Wp_Plugins_V5 {

	/**
	 * Class instance.
	 *
	 * @since   1.1.0 - 12.02.2012
	 * @access  public
	 * @static
	 *
	 * @var     Obenland_Wp_Approve_User
	 */
	public static $instance;

	/**
	 * The plugin options.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access protected
	 *
	 * @var    array
	 */
	protected $options;

	/**
	 * Users flagged as pending.
	 *
	 * Null until the count has been computed for the current request.
	 *
	 * @since 12
	 *
	 * @var int|null
	 */
	protected $pending_count = null;

	/**
	 * Users flagged as unapproved.
	 *
	 * @author Konstantin Obenland
	 * @since  2.2.0 - 30.03.2013
	 * @access protected
	 *
	 * @var    array
	 */
	protected $unapproved_users = array();

	/**
	 * Number of unapproved users.
	 *
	 * @since 12
	 *
	 * @var int
	 */
	protected $unapproved_count = 0;

	/**
	 * Constructor
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 */
	public function __construct() {
		parent::__construct(
			array(
				'textdomain'     => 'wp-approve-user',
				'plugin_path'    => __DIR__ . '/wp-approve-user.php',
				'donate_link_id' => 'G65Y5CM3HVRNY',
			)
		);

		self::$instance = $this;
		$this->options  = wp_parse_args(
			get_option( $this->textdomain, array() ),
			$this->default_options()
		);

		if ( is_admin() ) {
			/**
			 * Get all users where wp-approve-user meta value is false or doesn't exist.
			 */
			$args = array(
				'fields'     => 'ID',
				'meta_key'   => 'wp-approve-user', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'pending', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			);

			if ( is_multisite() ) {
				$args['blog_id'] = is_network_admin() ? 0 : get_current_blog_id();
			}
			$this->pending_count = count( get_users( $args ) );

			$args['meta_value']     = 'unapproved'; //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$this->unapproved_count = count( get_users( $args ) );
		}

		load_plugin_textdomain( 'wp-approve-user', false, 'wp-approve-user/lang' );

		register_meta(
			'user',
			'wp-approve-user',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_status_meta' ),
			)
		);

		$this->hook( 'plugins_loaded' );
	}

	/**
	 * Normalizes `wp-approve-user` meta writes to the canonical three-state
	 * string. Legacy boolean writes from pre-V12 integrations map to the
	 * closest match; canonical values pass through untouched; unexpected
	 * scalars are left alone so consumers see the raw value.
	 *
	 * Mapping mirrors `wpau_upgrade_to_12()` so an in-flight write and a
	 * post-hoc migration land on the same string for the same input.
	 *
	 * @since 13
	 *
	 * @param mixed $meta_value Incoming value passed to update_user_meta().
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_status_meta( $meta_value ) {
		if ( in_array( $meta_value, array( 'approved', 'unapproved', 'pending' ), true ) ) {
			return $meta_value;
		}

		if ( true === $meta_value || 1 === $meta_value || '1' === $meta_value ) {
			return 'approved';
		}

		if ( false === $meta_value || 0 === $meta_value || '0' === $meta_value || '' === $meta_value ) {
			return 'pending';
		}

		return $meta_value;
	}

	/**
	 * Singleton.
	 *
	 * @return Obenland_Wp_Approve_User
	 */
	public static function get_instance() {
		if ( ! static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Returns the merged plugin options array.
	 *
	 * Read-only accessor for collaborator classes (settings UI, dashboard
	 * widget) that need to render or reason about stored preferences.
	 *
	 * @since 13
	 *
	 * @return array
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Returns the eagerly-computed pending-user count cached by the constructor.
	 *
	 * The count is populated once per request during `is_admin()` bootstrapping
	 * and reused by the admin-menu bubble to avoid a second `count_users`-style
	 * query. Non-admin callers get `0` — the property stays `null` outside admin
	 * and the int cast coerces it — so check `is_admin()` at the call site if a
	 * non-admin zero would be misleading.
	 *
	 * @since 13
	 *
	 * @return int Cached count, or 0 when the constructor didn't populate it.
	 */
	public function get_pending_count_cached() {
		return (int) $this->pending_count;
	}

	/**
	 * Hooks in all the hooks :)
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 */
	public function plugins_loaded() {
		$this->hook( 'user_row_actions' );
		$this->hook( 'ms_user_row_actions', 'user_row_actions' );
		$this->hook( 'wp_authenticate_user' );
		$this->hook( 'user_register', 5, 'capture_registration_ip' );
		$this->hook( 'user_register' );
		$this->hook( 'user_register', 20, 'auto_approve_user' );
		$this->hook( 'register_new_user', 0 );
		$this->hook( 'wp_new_user_notification_email_admin' );
		$this->hook( 'wp_login_errors' );
		$this->hook( 'shake_error_codes' );

		$this->hook( 'admin_print_scripts-users.php' );
		$this->hook( 'admin_print_scripts-site-users.php', 'admin_print_scripts_users_php' );

		$this->hook( 'load-users.php', 'map_action2' );
		$this->hook( 'load-site-users.php', 'map_action2' );
		$this->hook( 'admin_action_wpau_approve' );
		$this->hook( 'admin_action_wpau_bulk_approve' );
		$this->hook( 'admin_action_wpau_unapprove' );
		$this->hook( 'admin_action_wpau_bulk_unapprove' );
		$this->hook( 'admin_action_wpau_update' );

		$this->hook( 'wpau_approve' );
		$this->hook( 'delete_user' );

		if ( is_admin() ) {
			$this->hook( 'views_users' );
			$this->hook( 'views_users-network', 'views_users' );
			$this->hook( 'views_site-users-network', 'views_users' );
			$this->hook( 'pre_user_query' );
		}

		if ( class_exists( 'WPAU_Dashboard_Widget' ) ) {
			( new WPAU_Dashboard_Widget() )->register_hooks();
		}

		if ( class_exists( 'WPAU_Settings' ) ) {
			( new WPAU_Settings() )->register_hooks();
		}
	}

	/**
	 * Enqueues the script
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 */
	public function admin_print_scripts_users_php() {
		$plugin_data = get_plugin_data( __FILE__, false, false );
		$suffix      = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			$this->textdomain,
			plugins_url( "/js/{$this->textdomain}{$suffix}.js", __FILE__ ),
			array( 'jquery' ),
			$plugin_data['Version'],
			true
		);

		wp_localize_script(
			$this->textdomain,
			'wp_approve_user',
			array(
				'approve'   => __( 'Approve', 'wp-approve-user' ),
				'unapprove' => __( 'Unapprove', 'wp-approve-user' ),
			)
		);
	}

	/**
	 * Adds a link to a list view of unapproved users.
	 *
	 * @author Konstantin Obenland
	 * @since  2.2.0 - 30.03.2013
	 * @access public
	 *
	 * @param  array $views List of registered user list views.
	 * @return array
	 */
	public function views_users( $views ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url     = 'site-users-network' === get_current_screen()->id ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

		if ( $this->pending_count ) {
			$views['pending'] = sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( array( 'role' => 'wpau_pending' ), $url ) ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'wpau_pending' === $this->get_role() ? 'current' : '',
				esc_html__( 'Pending', 'wp-approve-user' ),
				$this->pending_count
			);
		}

		if ( $this->unapproved_count ) {
			$views['unapproved'] = sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( array( 'role' => 'wpau_unapproved' ), $url ) ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'wpau_unapproved' === $this->get_role() ? 'current' : '',
				esc_html__( 'Unapproved', 'wp-approve-user' ),
				$this->unapproved_count
			);
		}

		return $views;
	}

	/**
	 * Resets the user query to handle request for unapproved users only.
	 *
	 * @author Konstantin Obenland
	 * @since  2.2.0 - 30.03.2013
	 * @access public
	 *
	 * @param WP_User_Query $query User query object.
	 */
	public function pre_user_query( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$role = empty( $query->query_vars['role'] ) && isset( $_REQUEST['role'] ) ? $_REQUEST['role'] : $query->query_vars['role'];

		if ( 'wpau_pending' === $role ) {
			unset( $query->query_vars['meta_query'] );
			$query->query_vars['role']       = '';
			$query->query_vars['meta_key']   = 'wp-approve-user'; //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->query_vars['meta_value'] = 'pending'; //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			remove_filter( 'pre_user_query', array( $this, 'pre_user_query' ) );
			$query->prepare_query();
		}

		if ( 'wpau_unapproved' === $role ) {
			unset( $query->query_vars['meta_query'] );
			$query->query_vars['role']       = '';
			$query->query_vars['meta_key']   = 'wp-approve-user'; //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->query_vars['meta_value'] = 'unapproved'; //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			remove_filter( 'pre_user_query', array( $this, 'pre_user_query' ) );
			$query->prepare_query();
		}
	}

	/**
	 * Adds the plugin's row actions to the existing ones.
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 *
	 * @param  array   $actions     User action links.
	 * @param  WP_User $user_object User object.
	 * @return array
	 */
	public function user_row_actions( $actions, $user_object ) {
		if ( get_current_user_id() !== $user_object->ID && current_user_can( 'edit_user', $user_object->ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
			$url     = 'site-users-network' === get_current_screen()->id ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

			$status = get_user_meta( $user_object->ID, 'wp-approve-user', true );

			if ( 'approved' !== $status ) {
				$unapprove_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'wpau_approve',
							'user'   => $user_object->ID,
							'role'   => $this->get_role(),
						),
						$url
					),
					'wpau-approve-users'
				);

				$actions['wpau-approve'] = sprintf( '<a class="submitapprove" href="%1$s">%2$s</a>', esc_url( $unapprove_url ), esc_html__( 'Approve', 'wp-approve-user' ) );
			}

			if ( 'unapproved' !== $status ) {
				$approve_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'wpau_unapprove',
							'user'   => $user_object->ID,
							'role'   => $this->get_role(),
						),
						$url
					),
					'wpau-unapprove-users'
				);

				$actions['wpau-unapprove'] = sprintf( '<a class="submitunapprove" href="%1$s">%2$s</a>', esc_url( $approve_url ), esc_html__( 'Unapprove', 'wp-approve-user' ) );
			}
		}

		return $actions;
	}

	/**
	 * Checks whether the user is approved. Throws error if not.
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 *
	 * @param  WP_User|WP_Error $userdata User object.
	 * @return WP_User|WP_Error
	 */
	public function wp_authenticate_user( $userdata ) {
		if ( is_wp_error( $userdata ) ) {
			return $userdata;
		}

		if ( get_bloginfo( 'admin_email' ) === $userdata->user_email ) {
			return $userdata;
		}

		if ( is_super_admin( $userdata->ID ) ) {
			return $userdata;
		}

		$status          = get_user_meta( $userdata->ID, 'wp-approve-user', true );
		$has_status_meta = metadata_exists( 'user', $userdata->ID, 'wp-approve-user' );

		/*
		 * A missing meta row means the user predates the plugin (or its
		 * activation hook never finished). Treat only missing meta as
		 * approved; an existing empty value may represent a legacy
		 * pre-v12 pending state that hasn't been migrated yet.
		 */
		if ( 'approved' === $status || ! $has_status_meta ) {
			return $userdata;
		}

		return new WP_Error(
			'wpau_confirmation_error',
			wp_kses_post( __( 'Your account must be confirmed before you can log in.', 'wp-approve-user' ) )
		);
	}

	/**
	 * Updates user_meta to approve user when created by an Administrator.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 *
	 * @param int $id User ID.
	 */
	public function user_register( $id ) {
		$status = current_user_can( 'create_users' ) ? 'approved' : 'pending';

		update_user_meta( $id, 'wp-approve-user', $status );
		update_user_meta( $id, 'wp-approve-user-new-registration', true );
	}

	/**
	 * Captures the registering user's IP for later rule evaluation.
	 *
	 * Runs on `user_register` at priority 5, before `user_register()` writes
	 * the three-state meta and before `auto_approve_user()` evaluates rules
	 * at priority 20, so the IP is available when a rule matcher looks it up.
	 *
	 * @since 13
	 * @access public
	 *
	 * @param int $user_id ID of the newly registered user.
	 */
	public function capture_registration_ip( $user_id ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}

		update_user_meta( $user_id, 'wp-approve-user-ip', $ip );
	}

	/**
	 * Auto-approves newly registered users when an auto-approval rule matches.
	 *
	 * Runs on `user_register` at priority 20, after `user_register()` has
	 * written the initial `'pending'` status. Admin-created users are left
	 * alone because they already have `'approved'` meta.
	 *
	 * @since 13
	 * @access public
	 *
	 * @param int $user_id ID of the newly registered user.
	 */
	public function auto_approve_user( $user_id ) {
		if ( 'pending' !== get_user_meta( $user_id, 'wp-approve-user', true ) ) {
			return;
		}

		$stored = isset( $this->options['auto_approve_rules'] ) && is_array( $this->options['auto_approve_rules'] )
			? $this->options['auto_approve_rules']
			: array();

		/**
		 * Filters the auto-approval rules evaluated against new registrations.
		 *
		 * Developers can add rules programmatically without touching the
		 * stored option.
		 *
		 * @since 13
		 *
		 * @param array $rules   List of rule arrays (`type`, `value`).
		 * @param int   $user_id ID of the newly registered user.
		 */
		$rules = apply_filters( 'wpau_auto_approve_rules', $stored, $user_id );

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['type'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}

			if ( $this->auto_approve_rule_matches( $rule, $user ) ) {
				self::mark_approved( $user_id );
				return;
			}
		}
	}

	/**
	 * Flips a user's approval state to `approved` and fires `wpau_approve`.
	 *
	 * Shared helper so the admin UI, auto-approval rules, and any future
	 * callers converge on the same sequence of side-effects.
	 *
	 * @since 13
	 * @access public
	 *
	 * @param int $user_id User ID to mark approved.
	 */
	public static function mark_approved( $user_id ) {
		update_user_meta( $user_id, 'wp-approve-user', 'approved' );

		/**
		 * Fires after a user has been approved.
		 *
		 * @since 1.1.0
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'wpau_approve', $user_id );
	}

	/**
	 * Flips a user's approval state to `unapproved`, destroys their sessions,
	 * and fires `wpau_unapprove`.
	 *
	 * Shared helper so the admin UI, AJAX handlers, and any future callers
	 * converge on the same sequence of side-effects.
	 *
	 * @since 13
	 * @access public
	 *
	 * @param int $user_id User ID to mark unapproved.
	 */
	public static function mark_unapproved( $user_id ) {
		update_user_meta( $user_id, 'wp-approve-user', 'unapproved' );
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		/**
		 * Fires after a user has been unapproved.
		 *
		 * @since 1.1.0
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'wpau_unapprove', $user_id );
	}

	/**
	 * Evaluates a single auto-approval rule against a user.
	 *
	 * @since 13
	 * @access protected
	 *
	 * @param array   $rule Rule with `type` and `value` keys.
	 * @param WP_User $user User being evaluated.
	 * @return bool True when the rule matches, false otherwise.
	 */
	protected function auto_approve_rule_matches( $rule, $user ) {
		$type  = sanitize_key( (string) $rule['type'] );
		$email = strtolower( (string) $user->user_email );

		if ( 'email_domain' === $type ) {
			/*
			 * Normalize the rule value defensively: filter-injected rules bypass
			 * the settings sanitize pass, so the matcher can't assume lowercase
			 * or `@`-stripped input.
			 */
			$domain = self::sanitize_email_domain( (string) $rule['value'] );
			if ( '' === $domain ) {
				return false;
			}

			$at_pos    = strrpos( $email, '@' );
			$user_host = false === $at_pos ? '' : substr( $email, $at_pos + 1 );

			return '' !== $user_host && $user_host === $domain;
		}

		if ( 'email_suffix' === $type ) {
			$suffix = self::sanitize_email_suffix( (string) $rule['value'] );
			if ( '' === $suffix ) {
				return false;
			}

			/* A bare ".edu" matches "alice@mit.edu" but not "bob@studyedu.com". */
			return '' !== $email && substr( $email, -strlen( $suffix ) ) === $suffix;
		}

		if ( 'ip_range' === $type ) {
			$range = self::sanitize_ip_range( (string) $rule['value'] );
			if ( '' === $range ) {
				return false;
			}

			$user_ip = (string) get_user_meta( $user->ID, 'wp-approve-user-ip', true );

			return '' !== $user_ip && self::ip_in_range( $user_ip, $range );
		}

		return false;
	}

	/**
	 * Appends a link to the pending-users screen to WordPress's admin notification email
	 * when the newly registered user is awaiting approval.
	 *
	 * WordPress already sends the admin a "New User Registration" email; this filter
	 * extends that existing email rather than dispatching a second, near-duplicate one.
	 *
	 * @since 13
	 *
	 * @param array   $email {
	 *     Arguments passed to wp_mail() for the admin notification.
	 *
	 *     @type string $to      Admin email address.
	 *     @type string $subject Email subject.
	 *     @type string $message Email body.
	 *     @type string $headers Email headers.
	 * }
	 * @param WP_User $user     The newly registered user.
	 * @param string  $blogname Site name.
	 * @return array Filtered email arguments.
	 */
	public function wp_new_user_notification_email_admin( $email, $user, $blogname ) {
		if ( 'pending' !== get_user_meta( $user->ID, 'wp-approve-user', true ) ) {
			return $email;
		}

		$pending_url = is_multisite()
			? network_admin_url( 'users.php?role=wpau_pending' )
			: admin_url( 'users.php?role=wpau_pending' );

		$email['message'] = rtrim( $email['message'] ) . "\r\n\r\n" . sprintf(
			/* translators: %s: URL to the pending users admin screen. */
			__( 'Review pending users: %s', 'wp-approve-user' ),
			$pending_url
		);

		return $email;
	}


	/**
	 * Fires after a new user registration has been recorded.
	 *
	 * Prevents WordPress to send the new user notification email, if the user has to be approved first.
	 * Still notifies the admin about the new user registration.
	 *
	 * @author Konstantin Obenland
	 * @since  6 - 04.03.2019
	 * @access public
	 *
	 * @param int $user_id ID of the newly registered user.
	 */
	public function register_new_user( $user_id ) {
		if ( 'pending' === get_user_meta( $user_id, 'wp-approve-user', true ) ) {
			remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
			add_action( 'register_new_user', 'wp_new_user_notification' );
		}
	}

	/**
	 * Calls actions that depend on the `action` parameter.
	 *
	 * @author Konstantin Obenland
	 * @since  3 - 21.12.2017
	 * @access public
	 */
	public function map_action2() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput

		if ( ! empty( $_REQUEST['action2'] ) && false !== stripos( $_REQUEST['action2'], 'wpau_' ) ) {
			do_action( "admin_action_{$_REQUEST['action2']}" );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput

		wp_add_inline_style( 'list-tables', '.wp-list-table.users tbody th, .wp-list-table.users tbody td { box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.1); } #the-list .submitapprove { color:#007017; } #the-list .submitunapprove { color:#996800; }' );
	}

	/**
	 * Updates user_meta to approve user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 */
	public function admin_action_wpau_approve() {
		check_admin_referer( 'wpau-approve-users' );
		$this->approve();
	}

	/**
	 * Bulkupdates user_meta to approve user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 */
	public function admin_action_wpau_bulk_approve() {
		$action = 'users-network' === get_current_screen()->id ? 'bulk-users-network' : 'bulk-users';
		check_admin_referer( $action );

		$this->set_up_role_context();
		$this->approve();
	}

	/**
	 * Updates user_meta to unapprove user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 */
	public function admin_action_wpau_unapprove() {
		check_admin_referer( 'wpau-unapprove-users' );
		$this->unapprove();
	}

	/**
	 * Bulkupdates user_meta to unapprove user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 */
	public function admin_action_wpau_bulk_unapprove() {
		$action = 'users-network' === get_current_screen()->id ? 'bulk-users-network' : 'bulk-users';
		check_admin_referer( $action );

		$this->set_up_role_context();
		$this->unapprove();
	}

	/**
	 * Adds the update message to the admin notices queue.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 */
	public function admin_action_wpau_update() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
		if ( empty( $_REQUEST['update'] ) ) {
			return;
		}

		$count = absint( $_REQUEST['count'] );

		switch ( $_REQUEST['update'] ) {
			case 'wpau-approved':
				/* translators: Number of users. */
				$message = esc_html( _n( '%d User approved.', '%d users approved.', $count, 'wp-approve-user' ) );
				break;

			case 'wpau-unapproved':
				/* translators: Number of users. */
				$message = esc_html( _n( '%d User unapproved.', '%d users unapproved.', $count, 'wp-approve-user' ) );
				break;

			default:
				$message = apply_filters( 'wpau_update_message_handler', '', $_REQUEST['update'] );
		}

		if ( isset( $message ) ) {
			add_settings_error(
				$this->textdomain,
				esc_attr( $_REQUEST['update'] ),
				sprintf( $message, $count ),
				'updated'
			);

			$this->hook( 'all_admin_notices' );
		}

		// Prevent other admin action handlers from trying to handle our action.
		$_REQUEST['action'] = -1;

		// phpcs:enable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Filters the login page errors.
	 *
	 * @author Konstantin Obenland
	 * @since  6 - 04.03.2019
	 * @access public
	 *
	 * @param WP_Error $errors WP Error object.
	 * @return WP_Error
	 */
	public function wp_login_errors( $errors ) {
		if ( in_array( 'registered', $errors->get_error_codes(), true ) ) {
			$message = __( 'Registration complete. You will receive an email once your registration is approved.', 'wp-approve-user' );
			$errors->remove( 'registered' );
			$errors->add( 'registered', $message, 'message' );
		}

		return $errors;
	}

	/**
	 * Adds our error code to make the login form shake :)
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 *
	 * @param  array $shake_error_codes Error codes that trigger form shaking.
	 * @return array Shake error codes
	 */
	public function shake_error_codes( $shake_error_codes ) {
		$shake_error_codes[] = 'wpau_confirmation_error';

		return $shake_error_codes;
	}

	/**
	 * Normalizes and validates an email-domain rule value.
	 *
	 * Strips a leading `@`, lowercases, and rejects anything that still
	 * contains whitespace or `@`, or that doesn't look like a domain
	 * (needs at least one dot).
	 *
	 * @since 13
	 * @access public
	 * @static
	 *
	 * @param  string $value Raw domain value.
	 * @return string Normalized domain, or empty string when the value is invalid.
	 */
	public static function sanitize_email_domain( $value ) {
		$domain = ltrim( trim( $value ), '@' );
		$domain = strtolower( $domain );

		if ( '' === $domain ) {
			return '';
		}

		if ( preg_match( '/\s/', $domain ) ) {
			return '';
		}

		if ( false !== strpos( $domain, '@' ) ) {
			return '';
		}

		if ( false === strpos( $domain, '.' ) ) {
			return '';
		}

		return $domain;
	}

	/**
	 * Normalizes and validates an email-suffix rule value.
	 *
	 * Suffix rules match any email whose address ends with the given string,
	 * so `.edu` catches every university email and `@acme.co.uk` catches every
	 * address at that specific host. The suffix must begin with `.` or `@` so
	 * it can't accidentally swallow a lookalike substring (`edu` alone would
	 * false-match `alice@studyedu.com`).
	 *
	 * @since 13
	 * @access public
	 * @static
	 *
	 * @param  string $value Raw suffix value.
	 * @return string Normalized suffix, or empty string when the value is invalid.
	 */
	public static function sanitize_email_suffix( $value ) {
		$suffix = strtolower( trim( $value ) );

		/* Must carry the leading anchor plus at least one domain character. */
		if ( strlen( $suffix ) < 2 ) {
			return '';
		}

		if ( preg_match( '/\s/', $suffix ) ) {
			return '';
		}

		if ( '.' !== $suffix[0] && '@' !== $suffix[0] ) {
			return '';
		}

		return $suffix;
	}

	/**
	 * Normalizes and validates an IP-address rule value.
	 *
	 * Accepts either a single IP (IPv4 or IPv6) or an IPv4 CIDR block like
	 * `192.168.1.0/24`. IPv6 ranges are not supported in v13 because the
	 * matcher's prefix math uses `ip2long`, which is IPv4-only.
	 *
	 * @since 13
	 * @access public
	 * @static
	 *
	 * @param  string $value Raw IP or CIDR.
	 * @return string Normalized value, or empty string when the value is invalid.
	 */
	public static function sanitize_ip_range( $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, '/' ) ) {
			list( $ip, $prefix ) = explode( '/', $value, 2 );

			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return '';
			}

			if ( ! ctype_digit( $prefix ) ) {
				return '';
			}

			$prefix = (int) $prefix;
			if ( $prefix < 0 || $prefix > 32 ) {
				return '';
			}

			return $ip . '/' . $prefix;
		}

		$validated = filter_var( $value, FILTER_VALIDATE_IP );

		return false === $validated ? '' : $validated;
	}

	/**
	 * Tests whether an IP falls inside a range produced by sanitize_ip_range().
	 *
	 * Single-value ranges use string equality. CIDR ranges (IPv4 only) compare
	 * the masked subnet bits via `ip2long`.
	 *
	 * @since 13
	 * @access public
	 * @static
	 *
	 * @param  string $ip    IP to test (already validated upstream).
	 * @param  string $range Normalized range from sanitize_ip_range().
	 * @return bool
	 */
	public static function ip_in_range( $ip, $range ) {
		if ( false === strpos( $range, '/' ) ) {
			return $ip === $range;
		}

		list( $subnet, $prefix ) = explode( '/', $range, 2 );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		/*
		 * Both `$ip` and `$subnet` have already been validated through
		 * FILTER_VALIDATE_IP + FILTER_FLAG_IPV4 (here for `$ip`, and upstream
		 * in sanitize_ip_range() for `$subnet`), so ip2long() cannot fail.
		 */
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		$prefix = (int) $prefix;
		/* Prefix 0 matches every address; avoid PHP's negative-shift quirk. */
		if ( 0 === $prefix ) {
			return true;
		}

		$mask = ( -1 << ( 32 - $prefix ) ) & 0xFFFFFFFF;

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/**
	 * Sends the approval email.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param int $user_id User ID.
	 */
	public function wpau_approve( $user_id ) {
		if ( get_user_meta( $user_id, 'wp-approve-user-new-registration', true ) ) {
			wp_new_user_notification( $user_id, null, 'user' );
			delete_user_meta( $user_id, 'wp-approve-user-new-registration' );
		}

		// Check user meta if mail has been sent already.
		if ( $this->options['wpau-send-approve-email'] && ! get_user_meta( $user_id, 'wp-approve-user-mail-sent', true ) ) {
			$user     = new WP_User( $user_id );
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

			// Send mail.
			$sent = wp_mail(
				$user->user_email,
				/* translators: Blog name. */
				sprintf( esc_html_x( '[%s] Registration approved', 'Blogname', 'wp-approve-user' ), $blogname ),
				$this->populate_message( $this->options['wpau-approve-email'], $user )
			);

			if ( $sent ) {
				update_user_meta( $user_id, 'wp-approve-user-mail-sent', true );
			}
		}
	}

	/**
	 * Sends the rejection email.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param int $user_id User ID.
	 */
	public function delete_user( $user_id ) {
		$is_new_registration = get_user_meta( $user_id, 'wp-approve-user-new-registration', true );
		$is_unapproved       = 'unapproved' === get_user_meta( $user_id, 'wp-approve-user', true );

		if ( $is_new_registration && $is_unapproved && $this->options['wpau-send-unapprove-email'] ) {
			$user     = new WP_User( $user_id );
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

			// Send mail.
			wp_mail(
				$user->user_email,
				/* translators: Blog name. */
				sprintf( esc_html_x( '[%s] Registration unapproved', 'Blogname', 'wp-approve-user' ), $blogname ),
				$this->populate_message( $this->options['wpau-unapprove-email'], $user )
			);

			// No need to delete user_meta, since this user will be GONE.
		}
	}

	/**
	 * Display all messages registered to this plugin.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 30.03.2012
	 * @access public
	 */
	public function all_admin_notices() {
		settings_errors( $this->textdomain );
	}

	/**
	 * Updates user_meta to approve user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access protected
	 */
	protected function approve() {
		list( $user_ids, $url ) = $this->check_user();

		foreach ( (array) $user_ids as $id ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_user', $id ) ) {
				wp_die(
					esc_html__( 'You can&#8217;t edit that user.', 'wp-approve-user' ),
					'',
					array(
						'back_link' => true,
					)
				);
			}

			self::mark_approved( $id );
		}

		$role          = $this->get_role();
		$count         = count( $user_ids );
		$has_remaining = $this->has_remaining_users( $role, $count );
		$query_args    = array(
			'action' => 'wpau_update',
			'update' => 'wpau-approved',
			'count'  => $count,
		);

		if ( $has_remaining ) {
			$query_args['role'] = $role;
		}

		wp_safe_redirect( add_query_arg( $query_args, $url ) );
		// @codeCoverageIgnoreStart
		exit();
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Updates user_meta to unapprove user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access protected
	 */
	protected function unapprove() {
		list( $user_ids, $url ) = $this->check_user();

		foreach ( (array) $user_ids as $id ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_user', $id ) ) {
				wp_die(
					esc_html__( 'You can&#8217;t edit that user.', 'wp-approve-user' ),
					'',
					array(
						'back_link' => true,
					)
				);
			}

			self::mark_unapproved( $id );
		}

		$role          = $this->get_role();
		$count         = count( $user_ids );
		$has_remaining = $this->has_remaining_users( $role, $count );
		$query_args    = array(
			'action' => 'wpau_update',
			'update' => 'wpau-unapproved',
			'count'  => $count,
		);

		// Special case: If someone unapproves all unapproved users, we want to stay on the unapproved list.
		if ( $has_remaining || 'wpau_unapproved' === $role ) {
			$query_args['role'] = $role;
		}

		wp_safe_redirect( add_query_arg( $query_args, $url ) );
		// @codeCoverageIgnoreStart
		exit();
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Checks permissions and assembles User IDs.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 15.03.2012
	 * @access protected
	 *
	 * @return array User IDs and URL
	 */
	protected function check_user() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		$screen_id = get_current_screen()->id;
		$users_key = 'user';

		if ( false !== stripos( current_action(), 'wpau_bulk_' ) ) {
			$users_key = 'users-network' === $screen_id ? 'allusers' : 'users';
		}

		$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url     = 'site-users-network' === $screen_id ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

		if ( empty( $_REQUEST[ $users_key ] ) ) {
			wp_safe_redirect( $url );
			// @codeCoverageIgnoreStart
			exit();
			// @codeCoverageIgnoreEnd
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			wp_die(
				esc_html__( 'You can&#8217;t unapprove users.', 'wp-approve-user' ),
				'',
				array(
					'back_link' => true,
				)
			);
		}

		$user_ids = array_map( 'intval', (array) $_REQUEST[ $users_key ] );
		$user_ids = array_diff( $user_ids, array( get_user_by( 'email', get_bloginfo( 'admin_email' ) )->ID ) );

		return array( $user_ids, $url );

		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Replaces all the placeholders with their content.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 15.03.2012
	 * @access protected
	 *
	 * @param  string  $message Email body.
	 * @param  WP_User $user    User object.
	 *
	 * @return string
	 */
	protected function populate_message( $message, $user ) {
		$placeholders = array(
			'BLOG_TITLE' => wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
			'BLOG_URL'   => home_url(),
			'LOGINLINK'  => wp_login_url(),
			'USERNAME'   => $user->user_nicename,
		);

		if ( false !== strpos( $message, 'RESETLINK' ) ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$placeholders['RESETLINK'] = add_query_arg(
					array(
						'action' => 'rp',
						'key'    => $key,
						'login'  => rawurlencode( $user->user_login ),
					),
					network_site_url( 'wp-login.php', 'login' )
				);
			} else {
				$placeholders['RESETLINK'] = wp_login_url();
			}
		}

		if ( is_multisite() ) {
			$placeholders['SITE_NAME'] = $GLOBALS['current_site']->site_name;
		}

		/**
		 * Filters the placeholders in approve/unapprove emails.
		 *
		 * @since 7
		 *
		 * @param array   $placeholders Key => Value pair of placeholders and the value they're replaced with.
		 * @param string  $message      Message that will have its placeholders replaced. Note: This will not change the message.
		 *                              Use `option_wp-approve-user` to filter message bodies.
		 * @param WP_User $user         WP_User object of the user being approved/unapproved.
		 */
		$placeholders = apply_filters( 'wpau_message_placeholders', $placeholders, $message, $user );

		foreach ( $placeholders as $placeholder => $replacement ) {
			$message = str_replace( $placeholder, $replacement, $message );
		}

		return $message;
	}

	/**
	 * Returns the default options.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 15.03.2012
	 * @access protected
	 *
	 * @return array
	 */
	protected function default_options() {
		$options = array(
			'wpau-send-approve-email'   => false,
			'wpau-approve-email'        => '
Hi USERNAME,
Your registration for BLOG_TITLE has now been approved.

You can log in, using your username and password that you created when registering for our website, at the following URL: LOGINLINK

If you have any questions, or problems, then please do not hesitate to contact us.

Name,
Company,
Contact details',
			'wpau-send-unapprove-email' => false,
			'wpau-unapprove-email'      => '',
			'auto_approve_rules'        => array(),
		);

		return apply_filters( 'wpau_default_options', $options );
	}

	/**
	 * Sets the role context on bulk actions.
	 *
	 * On bulk actions the role parameter is not passed, since we're using a form
	 * to submit information. The information is only available through the
	 * `_wp_http_referer` parameter, so we get it from there and make it available
	 * for the request.
	 *
	 * @author Konstantin Obenland
	 * @since  3 - 04.09.2014
	 * @access protected
	 */
	protected function set_up_role_context() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput

		if ( empty( $_REQUEST['role'] ) && ! empty( $_REQUEST['_wp_http_referer'] ) ) {
			$referrer = wp_parse_url( $_REQUEST['_wp_http_referer'] );

			if ( ! empty( $referrer['query'] ) ) {
				$args = wp_parse_args( $referrer['query'] );

				if ( ! empty( $args['role'] ) ) {
					$_REQUEST['role'] = $args['role'];
				}
			}
		}
	}

	/**
	 * Returns the current role.
	 *
	 * If the user list is in the context of a specific role, this function makes
	 * sure that the requested role is valid. By returning `false` otherwise, we
	 * make sure that parameter gets removed from the activation link.
	 *
	 * @author Konstantin Obenland
	 * @since  3 - 04.09.2014
	 * @access protected
	 *
	 * @return string|bool The role key if set, false otherwise.
	 */
	protected function get_role() {
		$roles   = array_keys( get_editable_roles() );
		$roles[] = 'wpau_unapproved';
		$roles[] = 'wpau_pending';
		$role    = false;

		if ( isset( $_REQUEST['role'] ) && in_array( $_REQUEST['role'], $roles, true ) ) {
			$role = $_REQUEST['role'];
		}

		return $role;

		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Checks if there are remaining users to approve/unapprove.
	 *
	 * This function is used to determine if the user should be redirected back
	 * to the all user view or to the role-specific view after approving/unapproving users.
	 *
	 * @param string $role  Role key.
	 * @param int    $count Number of users approved/unapproved.
	 * @return int Number of remaining users.
	 */
	protected function has_remaining_users( $role, $count ) {
		// If we're in a role context outside of our custom roles, always redirect back to the role view.
		$has_remaining = ! in_array( $role, array( 'wpau_pending', 'wpau_unapproved' ), true );

		if ( 'wpau_pending' === $role ) {
			$has_remaining = $this->pending_count - $count;
		} elseif ( 'wpau_unapproved' === $role ) {
			$has_remaining = $this->unapproved_count - $count;
		}

		return $has_remaining;
	}

	/**
	 * Re-runs the activation hook when registration is activated.
	 *
	 * If the plugin is activated and user registration is disabled, the plugin
	 * activation hook never gets added, let alone fired. This a secondary
	 * measure to make sure all existing users are approved on activation.
	 *
	 * @author     Konstantin Obenland
	 * @deprecated 2.3.0 - 13.08.2013
	 * @access     public
	 *
	 * @param string $old       Old settings value.
	 * @param int    $new_value New settings value.
	 */
	public function update_option_users_can_register( $old, $new_value ) {
		_deprecated_function( __FUNCTION__, '2.3' );
	}

	/**
	 * Approves all existing users.
	 *
	 * @author     Konstantin Obenland
	 * @since      1.0 - 29.01.2012
	 * @deprecated 10 - 03.11.2022
	 * @access     public
	 */
	public function activation() {
		_deprecated_function( __FUNCTION__, '10', 'wp_approve_user_activate' );

		wp_approve_user_activate();
	}
}
