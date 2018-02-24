<?php
/**
 * Plugin Name: WP Approve User
 * Plugin URI:  http://en.wp.obenland.it/wp-approve-user/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description: Adds action links to user table to approve or unapprove user registrations.
 * Version:     3
 * Author:      Konstantin Obenland
 * Author URI:  http://en.wp.obenland.it/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Text Domain: wp-approve-user
 * Domain Path: /lang
 * License:     GPLv2
 *
 * @package WP Approve User
 */

if ( ! get_option( 'users_can_register' ) ) {
	/**
	 * Whitelists all users.
	 *
	 * @author Konstantin Obenland
	 * @since  2.2.0 - 30.03.2013
	 *
	 * @param bool $new New option value.
	 */
	function wpau_whitelist_users( $new ) {
		if ( $new ) {
			$user_ids = get_users( array(
				'blog_id' => '',
				'fields'  => 'ID',
			) );

			foreach ( $user_ids as $user_id ) {
				update_user_meta( $user_id, 'wp-approve-user', true );
				update_user_meta( $user_id, 'wp-approve-user-mail-sent', true );
			}
		}
	}
	add_filter( 'pre_update_option_users_can_register', 'wpau_whitelist_users' );
	return;
}


if ( ! class_exists( 'Obenland_Wp_Plugins_V4' ) ) {
	require_once 'obenland-wp-plugins.php';
}

/**
 * Class Obenland_Wp_Approve_User.
 */
class Obenland_Wp_Approve_User extends Obenland_Wp_Plugins_V4 {

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
	 * Constructor
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 *
	 * @return Obenland_Wp_Approve_User
	 */
	public function __construct() {
		parent::__construct( array(
			'textdomain'     => 'wp-approve-user',
			'plugin_path'    => __FILE__,
			'donate_link_id' => 'G65Y5CM3HVRNY',
		) );

		self::$instance = $this;
		$this->options  = wp_parse_args(
			get_option( $this->textdomain, array() ),
			$this->default_options()
		);

		if ( is_admin() ) {
			$this->unapproved_users = get_users( array(
				'meta_key'   => 'wp-approve-user',
				'meta_value' => false,
			) );
		}

		load_plugin_textdomain( 'wp-approve-user', false, 'wp-approve-user/lang' );

		$this->hook( 'plugins_loaded' );
	}


	/**
	 * Approves all existing users.
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 * @static
	 *
	 * @return void
	 */
	public function activation() {
		$user_ids = get_users( array(
			'blog_id' => '',
			'fields'  => 'ID',
		) );

		foreach ( $user_ids as $user_id ) {
			update_user_meta( $user_id, 'wp-approve-user', true );
			update_user_meta( $user_id, 'wp-approve-user-mail-sent', true );
		}
	}


	/**
	 * Hooks in all the hooks :)
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 *
	 * @return void
	 */
	public function plugins_loaded() {
		$this->hook( 'user_row_actions' );
		$this->hook( 'ms_user_row_actions', 'user_row_actions' );
		$this->hook( 'wp_authenticate_user' );
		$this->hook( 'user_register' );
		$this->hook( 'shake_error_codes' );
		$this->hook( 'admin_menu' );
		$this->hook( 'network_admin_menu', 'admin_menu' );

		$this->hook( 'admin_print_scripts-users.php' );
		$this->hook( 'admin_print_scripts-site-users.php', 'admin_print_scripts_users_php' );
		$this->hook( 'admin_print_styles-settings_page_wp-approve-user' );

		$this->hook( 'load-users.php', 'map_action2' );
		$this->hook( 'load-site-users.php', 'map_action2' );
		$this->hook( 'admin_action_wpau_approve' );
		$this->hook( 'admin_action_wpau_bulk_approve' );
		$this->hook( 'admin_action_wpau_unapprove' );
		$this->hook( 'admin_action_wpau_bulk_unapprove' );
		$this->hook( 'admin_action_wpau_update' );

		$this->hook( 'wpau_approve' );
		$this->hook( 'delete_user' );
		$this->hook( 'admin_init' );

		if ( is_admin() ) {
			$this->hook( 'views_users' );
			$this->hook( 'views_users-network', 'views_users' );
			$this->hook( 'views_site-users-network', 'views_users' );
			$this->hook( 'pre_user_query' );
		}
	}


	/**
	 * Enqueues the script
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 *
	 * @return void
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
	 * Enqueues the style on the settings page
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 10.04.2012
	 * @access public
	 *
	 * @return void
	 */
	public function admin_print_styles_settings_page_wp_approve_user() {
		$plugin_data = get_plugin_data( __FILE__, false, false );
		$suffix      = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			$this->textdomain,
			plugins_url( "/css/settings-page{$suffix}.css", __FILE__ ),
			array(),
			$plugin_data['Version']
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
	 *
	 * @return array
	 */
	public function views_users( $views ) {
		if ( $this->unapproved_users ) {
			// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification
			$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
			$url     = 'site-users-network' === get_current_screen()->id ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

			$views['unapproved'] = sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( array( 'role' => 'wpau_unapproved' ), $url ) ),
				// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification
				'wpau_unapproved' === $this->get_role() ? 'current' : '',
				esc_html__( 'Unapproved', 'wp-approve-users' ),
				count( $this->unapproved_users )
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
	 * @param  WP_User_Query $query User query object.
	 *
	 * @return void
	 */
	public function pre_user_query( $query ) {
		// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification, WordPress.VIP.ValidatedSanitizedInput
		$role = empty( $query->query_vars['role'] ) && isset( $_REQUEST['role'] ) ? $_REQUEST['role'] : $query->query_vars['role'];

		if ( 'wpau_unapproved' === $role ) {
			unset( $query->query_vars['meta_query'] );
			$query->query_vars['role']       = '';
			$query->query_vars['meta_key']   = 'wp-approve-user';
			$query->query_vars['meta_value'] = false;

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
	 *
	 * @return array
	 */
	public function user_row_actions( $actions, $user_object ) {
		if ( get_current_user_id() !== $user_object->ID && current_user_can( 'edit_user', $user_object->ID ) ) {
			// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification
			$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
			$url     = 'site-users-network' === get_current_screen()->id ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

			if ( get_user_meta( $user_object->ID, 'wp-approve-user', true ) ) {
				$url = wp_nonce_url( add_query_arg( array(
					'action' => 'wpau_unapprove',
					'user'   => $user_object->ID,
				), $url ), 'wpau-unapprove-users' );

				$actions['wpau-unapprove'] = sprintf( '<a class="submitunapprove" href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Unapprove', 'wp-approve-user' ) );

			} else {
				$url = wp_nonce_url( add_query_arg( array(
					'action' => 'wpau_approve',
					'user'   => $user_object->ID,
					'role'   => $this->get_role(),
				), $url ), 'wpau-approve-users' );

				$actions['wpau-approve'] = sprintf( '<a class="submitapprove" href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Approve', 'wp-approve-user' ) );
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
	 *
	 * @return WP_User|WP_Error
	 */
	public function wp_authenticate_user( $userdata ) {
		if ( ! is_wp_error( $userdata ) && ! get_user_meta( $userdata->ID, 'wp-approve-user', true ) && get_bloginfo( 'admin_email' ) !== $userdata->user_email ) {
			$userdata = new WP_Error(
				'wpau_confirmation_error',
				wp_kses_post( __( '<strong>ERROR:</strong> Your account has to be confirmed by an administrator before you can login.', 'wp-approve-user' ) )
			);
		}

		return $userdata;
	}


	/**
	 * Updates user_meta to approve user when created by an Administrator.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 *
	 * @param  int $id User ID.
	 *
	 * @return void
	 */
	public function user_register( $id ) {
		update_user_meta( $id, 'wp-approve-user', current_user_can( 'create_users' ) );
	}

	/**
	 * Calls actions that depend on the `action` parameter.
	 *
	 * @author Konstantin Obenland
	 * @since  3 - 21.12.2017
	 * @access public
	 */
	public function map_action2() {
		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification, WordPress.VIP.ValidatedSanitizedInput

		if ( ! empty( $_REQUEST['action2'] ) && false !== stripos( $_REQUEST['action2'], 'wpau_' ) ) {
			do_action( "admin_action_{$_REQUEST['action2']}" );
		}

		// phpcs:enable WordPress.CSRF.NonceVerification.NoNonceVerification, WordPress.VIP.ValidatedSanitizedInput
	}


	/**
	 * Updates user_meta to approve user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 *
	 * @return void
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
	 *
	 * @return void
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
	 *
	 * @return void
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
	 *
	 * @return void
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
	 *
	 * @return void
	 */
	public function admin_action_wpau_update() {
		// phpcs:disable WordPress.VIP.ValidatedSanitizedInput, WordPress.CSRF.NonceVerification.NoNonceVerification
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

		// phpcs:enable WordPress.VIP.ValidatedSanitizedInput, WordPress.CSRF.NonceVerification.NoNonceVerification
	}


	/**
	 * Adds our error code to make the login form shake :)
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 *
	 * @param  array $shake_error_codes Error codes that trigger form shaking.
	 *
	 * @return array Shake error codes
	 */
	public function shake_error_codes( $shake_error_codes ) {
		$shake_error_codes[] = 'wpau_confirmation_error';

		return $shake_error_codes;
	}


	/**
	 * Enhances the User menu item to reflect the amount of unapproved users.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 *
	 * @return void
	 */
	public function admin_menu() {
		if ( current_user_can( 'list_users' ) && version_compare( get_bloginfo( 'version' ), '3.2', '>=' ) ) {
			global $menu;

			foreach ( $menu as $key => $menu_item ) {
				if ( array_search( 'users.php', $menu_item, true ) ) {

					// No need for number formatting, count() always returns an integer.
					$awaiting_mod = count( $this->unapproved_users );

					// phpcs:ignore WordPress.Variables.GlobalVariables.OverrideProhibited
					$menu[ $key ][0] .= " <span class='update-plugins count-{$awaiting_mod}'><span class='plugin-count'>{$awaiting_mod}</span></span>";

					break; // Bail on success.
				}
			}
		}

		add_options_page(
			esc_html__( 'Approve User', 'wp-approve-user' ), // Page Title.
			esc_html__( 'Approve User', 'wp-approve-user' ), // Menu Title.
			'promote_users',                                 // Capability.
			$this->textdomain,                               // Menu Slug.
			array( $this, 'settings_page' )                  // Function.
		);
	}


	/**
	 * Registers the plugins' settings.
	 *
	 * @author Konstantin Obenland
	 * @since  1.0.0 - 02.03.2012
	 * @access public
	 *
	 * return  void
	 */
	public function admin_init() {
		register_setting(
			$this->textdomain,
			'wp-approve-user',
			array( &$this, 'sanitize' )
		);

		add_settings_section(
			$this->textdomain,
			esc_html__( 'Email contents', 'wp-approve-user' ),
			array( $this, 'section_description_cb' ),
			$this->textdomain
		);

		add_settings_field(
			'wp-approve-user[send-approve-email]',
			esc_html__( 'Send Approve Email', 'wp-approve-user' ),
			array( $this, 'checkbox_cb' ),
			$this->textdomain,
			$this->textdomain,
			array(
				'name'        => 'wpau-send-approve-email',
				'description' => __( 'Send email on approval.', 'wp-approve-user' ),
			)
		);

		add_settings_field(
			'wp-approve-user[approve-email]',
			esc_html__( 'Approve Email', 'wp-approve-user' ),
			array( $this, 'textarea_cb' ),
			$this->textdomain,
			$this->textdomain,
			array(
				'label_for' => 'wpau-approve-email',
				'name'      => 'wpau-approve-email',
				'setting'   => 'wpau-send-approve-email',
			)
		);

		add_settings_field(
			'wp-approve-user[send-unapprove-email]',
			esc_html__( 'Send Unapprove Email', 'wp-approve-user' ),
			array( $this, 'checkbox_cb' ),
			$this->textdomain,
			$this->textdomain,
			array(
				'name'        => 'wpau-send-unapprove-email',
				'description' => __( 'Send email on unapproval.', 'wp-approve-user' ),
			)
		);
		add_settings_field(
			'wp-approve-user[unapprove-email]',
			esc_html__( 'Unapprove Email', 'wp-approve-user' ),
			array( $this, 'textarea_cb' ),
			$this->textdomain,
			$this->textdomain,
			array(
				'label_for' => 'wpau-unapprove-email',
				'name'      => 'wpau-unapprove-email',
				'setting'   => 'wpau-send-unapprove-email',
			)
		);
	}


	/**
	 * Displays the options page.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @return void
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2><?php esc_html_e( 'Approve User Settings', 'wp-approve-user' ); ?></h2>

			<div id="poststuff">
				<div id="post-body" class="obenland-wp columns-2">
					<div id="post-body-content">
						<form method="post" action="options.php">
							<?php
							settings_fields( $this->textdomain );
							do_settings_sections( $this->textdomain );
							submit_button();
							?>
						</form>
					</div>
					<div id="postbox-container-1">
						<div id="side-info-column">
							<?php do_action( 'obenland_side_info_column' ); ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}


	/**
	 * Prints the section description.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @return void
	 */
	public function section_description_cb() {
		$tags = array( 'USERNAME', 'BLOG_TITLE', 'BLOG_URL', 'LOGINLINK' );
		if ( is_multisite() ) {
			$tags[] = 'SITE_NAME';
		}

		printf(
			/* translators: Placeholders. */
			esc_html_x( 'To take advantage of dynamic data, you can use the following placeholders: %s. Username will be the user login in most cases.', 'Placeholders', 'wp-approve-user' ),
			sprintf( '<code>%s</code>', implode( '</code>, <code>', $tags ) ) // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
		);
	}


	/**
	 * Populates the setting field.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param  array $option Settings option.
	 *
	 * @return void
	 */
	public function checkbox_cb( $option ) {
		$option = (object) $option;
		?>
		<label for="<?php echo esc_attr( sanitize_title_with_dashes( $option->name ) ); ?>">
			<input type="checkbox" name="wp-approve-user[<?php echo esc_attr( $option->name ); ?>]" id="<?php echo esc_attr( sanitize_title_with_dashes( $option->name ) ); ?>" value="1" <?php checked( $this->options[ $option->name ] ); ?> />
			<?php echo esc_html( $option->description ); ?>
		</label><br />
		<?php
	}


	/**
	 * Populates the setting field.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param  array $option Settings option.
	 *
	 * @return void
	 */
	public function textarea_cb( $option ) {
		$option = (object) $option;
		?>
		<textarea id="<?php echo esc_attr( sanitize_title_with_dashes( $option->name ) ); ?>" class="large-text code" name="wp-approve-user[<?php echo esc_attr( $option->name ); ?>]" rows="10" cols="50" >
			<?php echo esc_textarea( $this->options[ $option->name ] ); ?>
		</textarea>
		<?php
	}


	/**
	 * Sanitizes the settings input.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param  array $input Form input.
	 *
	 * @return array The sanitized settings
	 */
	public function sanitize( $input ) {
		return array(
			'wpau-send-approve-email'   => (bool) isset( $input['wpau-send-approve-email'] ),
			'wpau-send-unapprove-email' => (bool) isset( $input['wpau-send-unapprove-email'] ),
			'wpau-approve-email'        => isset( $input['wpau-approve-email'] ) ? trim( $input['wpau-approve-email'] ) : '',
			'wpau-unapprove-email'      => isset( $input['wpau-unapprove-email'] ) ? trim( $input['wpau-unapprove-email'] ) : '',
		);
	}


	/**
	 * Sends the approval email.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param  int $user_id User ID.
	 *
	 * @return void
	 */
	public function wpau_approve( $user_id ) {
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
	 * @param  int $user_id User ID.
	 *
	 * @return void
	 */
	public function delete_user( $user_id ) {
		if ( $this->options['wpau-send-unapprove-email'] ) {
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
	 *
	 * @return void
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
	 *
	 * @return void
	 */
	protected function approve() {
		list( $user_ids, $url ) = $this->check_user();

		foreach ( (array) $user_ids as $id ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_user', $id ) ) {
				wp_die( esc_html__( 'You can&#8217;t edit that user.' ), '', array(
					'back_link' => true,
				) );
			}

			update_user_meta( $id, 'wp-approve-user', true );
			do_action( 'wpau_approve', $id );
		}

		wp_safe_redirect( add_query_arg( array(
			'action' => 'wpau_update',
			'update' => 'wpau-approved',
			'count'  => count( $user_ids ),
			'role'   => $this->get_role(),
		), $url ) );
		exit();
	}


	/**
	 * Updates user_meta to unapprove user.
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access protected
	 *
	 * @return void
	 */
	protected function unapprove() {
		list( $user_ids, $url ) = $this->check_user();

		foreach ( (array) $user_ids as $id ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_user', $id ) ) {
				wp_die( esc_html__( 'You can&#8217;t edit that user.' ), '', array(
					'back_link' => true,
				) );
			}

			update_user_meta( $id, 'wp-approve-user', false );
			do_action( 'wpau_unapprove', $id );
		}

		wp_safe_redirect( add_query_arg( array(
			'action' => 'wpau_update',
			'update' => 'wpau-unapproved',
			'count'  => count( $user_ids ),
			'role'   => $this->get_role(),
		), $url ) );
		exit();
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
		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification

		$screen_id = get_current_screen()->id;
		$users_key = 'user';

		if ( false !== stripos( current_action(), 'wpau_bulk_' ) ) {
			$users_key = 'users-network' === $screen_id ? 'allusers' : 'users';
		}

		$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url     = 'site-users-network' === $screen_id ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

		if ( empty( $_REQUEST[ $users_key ] ) && empty( $_REQUEST[ $users_key ] ) ) {
			wp_safe_redirect( $url );
			exit();
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'You can&#8217;t unapprove users.', 'wp-approve-user' ), '', array(
				'back_link' => true,
			) );
		}

		$user_ids = array_map( 'intval', (array) $_REQUEST[ $users_key ] );
		$user_ids = array_diff( $user_ids, array( get_user_by( 'email', get_bloginfo( 'admin_email' ) )->ID ) );

		return array( $user_ids, $url );

		// phpcs:enable WordPress.CSRF.NonceVerification.NoNonceVerification
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
		$title = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$message = str_replace( 'BLOG_TITLE', $title, $message );
		$message = str_replace( 'BLOG_URL', home_url(), $message );
		$message = str_replace( 'LOGINLINK', wp_login_url(), $message );
		$message = str_replace( 'USERNAME', $user->user_nicename, $message );

		if ( is_multisite() ) {
			$message = str_replace( 'SITE_NAME', $GLOBALS['current_site']->site_name, $message );
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
		// phpcs:disable WordPress.CSRF.NonceVerification.NoNonceVerification, WordPress.VIP.ValidatedSanitizedInput

		if ( empty( $_REQUEST['role'] ) && ! empty( $_REQUEST['_wp_http_referer'] ) ) {
			$referrer = parse_url( $_REQUEST['_wp_http_referer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

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
		$role    = false;

		if ( isset( $_REQUEST['role'] ) && in_array( $_REQUEST['role'], $roles, true ) ) {
			$role = $_REQUEST['role'];
		}

		return $role;

		// phpcs:enable WordPress.CSRF.NonceVerification.NoNonceVerification, WordPress.VIP.ValidatedSanitizedInput
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
	 * @param      string $old Old settings value.
	 * @param      int    $new New settings value.
	 *
	 * @return     void
	 */
	public function update_option_users_can_register( $old, $new ) {
		_deprecated_function( __FUNCTION__, '2.3' );
	}
} // End of class Obenland_Wp_Approve_User


new Obenland_Wp_Approve_User();


register_activation_hook( __FILE__, array(
	Obenland_Wp_Approve_User::$instance,
	'activation',
) );
