<?php
/** wp-approve-user.php
 *
 * Plugin Name: WP Approve User
 * Plugin URI:  http://en.wp.obenland.it/wp-approve-user/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description: Adds action links to user table to approve or unapprove user registrations.
 * Version:     2.2.0
 * Author:      Konstantin Obenland
 * Author URI:  http://en.wp.obenland.it/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Text Domain: wp-approve-user
 * Domain Path: /lang
 * License:     GPLv2
 */


if ( ! get_option( 'users_can_register' ) ) {
	return;
}


if ( ! class_exists( 'Obenland_Wp_Plugins_v300' ) ) {
	require_once( 'obenland-wp-plugins.php' );
}


class Obenland_Wp_Approve_User extends Obenland_Wp_Plugins_v300 {


	///////////////////////////////////////////////////////////////////////////
	// PROPERTIES, PUBLIC
	///////////////////////////////////////////////////////////////////////////

	/**
	 *
	 * @since   1.1.0 - 12.02.2012
	 * @access  public
	 * @static
	 *
	 * @var     Obenland_Wp_Approve_User
	 */
	public static $instance;


	///////////////////////////////////////////////////////////////////////////
	// PROPERTIES, PROTECTED
	///////////////////////////////////////////////////////////////////////////

	/**
	 * The plugin options.
	 *
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


	///////////////////////////////////////////////////////////////////////////
	// METHODS, PUBLIC
	///////////////////////////////////////////////////////////////////////////

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
		));

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

		load_plugin_textdomain( 'wp-approve-user' , false, 'wp-approve-user/lang' );

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
			'fields'  => 'ID'
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
		$this->hook( 'update_option_users_can_register' );

		$this->hook( 'user_row_actions' );
		$this->hook( 'wp_authenticate_user' );
		$this->hook( 'user_register' );
		$this->hook( 'shake_error_codes' );
		$this->hook( 'admin_menu' );

		$this->hook( 'admin_print_scripts-users.php' );
		$this->hook( 'admin_print_scripts-site-users.php', 'admin_print_scripts_users_php' );
		$this->hook( 'admin_print_styles-settings_page_wp-approve-user' );
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
		$suffix      = ( defined( 'SCRIPT_DEBUG' ) AND SCRIPT_DEBUG ) ? '' : '.min';

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
				'approve'   => __( 'Approve',   'wp-approve-user' ),
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
		$suffix      = ( defined( 'SCRIPT_DEBUG' ) AND SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_style(
			$this->textdomain,
			plugins_url( "/css/settings-page{$suffix}.css", __FILE__ ),
			array(),
			$plugin_data['Version']
		);
	}


	/**
	 * Re-runs the activation hook when registration is activated.
	 *
	 * If the plugin is activated and user registration is disabled, the plugin
	 * activation hook never gets added, let alone fired. This a secondary
	 * measure to make sure all existing users are approved on activation.
	 *
	 * @author Konstantin Obenland
	 * @since  2.2.0 - 30.03.2013
	 * @access public
	 *
	 * @param  string $old Old settings value.
	 * @param  int    $new New settings value.
	 *
	 * @return void
	 */
	public function update_option_users_can_register( $old, $new ) {
		if ( $new )
			$this->activation();
	}



	/**
	 * @author Konstantin Obenland
	 * @since  2.2.0 - 30.03.2013
	 * @access public
	 *
	 * @param  array $views
	 *
	 * @return array
	 */
	public function views_users( $views ) {
		if ( $this->unapproved_users ) {
			$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
			$url     = ( 'site-users-network' == get_current_screen()->id ) ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

			$views['unapproved'] = sprintf( '<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$s)</span></a>',
					add_query_arg( array( 'role' => 'wpau_unapproved' ), $url ),
					( isset( $_REQUEST['role'] ) AND 'wpau_unapproved' == $_REQUEST['role'] ) ? 'current' : '',
					__( 'Unapproved', 'wp-approve-users' ),
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
	 * @param  WP_User_Query $query
	 *
	 * @return void
	 */
	public function pre_user_query( $query ) {
		if ( 'wpau_unapproved' == $query->query_vars['role'] ) {
			unset( $query->query_vars['meta_query'][0] );
			$query->set( 'role', '' );
			$query->set( 'meta_key', 'wp-approve-user' );
			$query->set( 'meta_value', false );
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
	 * @param  array   $actions
	 * @param  WP_User $user_object
	 *
	 * @return array
	 */
	public function user_row_actions( $actions, $user_object ) {

		if ( ( get_current_user_id() != $user_object->ID ) AND current_user_can( 'edit_user', $user_object->ID ) ) {

			$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
			$url     = ( 'site-users-network' == get_current_screen()->id ) ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

			if ( get_user_meta( $user_object->ID, 'wp-approve-user', true ) ) {
				$url = wp_nonce_url( add_query_arg( array(
					'action' => 'wpau_unapprove',
					'user'   => $user_object->ID,
				), $url ), 'wpau-unapprove-users' );

				$actions['wpau-unapprove'] = sprintf( '<a class="submitunapprove" href="%1$s">%2$s</a>', $url, __( 'Unapprove', 'wp-approve-user' ) );

			} else {
				$url = wp_nonce_url( add_query_arg( array(
					'action' => 'wpau_approve',
					'user'   => $user_object->ID,
					'role'   => isset( $_REQUEST['role'] ) ? $_REQUEST['role'] : '',
				), $url ), 'wpau-approve-users' );

				$actions['wpau-approve'] = sprintf( '<a class="submitapprove" href="%1$s">%2$s</a>', $url, __( 'Approve', 'wp-approve-user' ) );
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
	 * @param  WP_User|WP_Error $userdata
	 *
	 * @return WP_User|WP_Error
	 */
	public function wp_authenticate_user( $userdata ) {

		if ( ! is_wp_error( $userdata ) AND ! get_user_meta( $userdata->ID, 'wp-approve-user', true ) AND $userdata->user_email != get_bloginfo( 'admin_email' ) ) {
			$userdata = new WP_Error(
				'wpau_confirmation_error',
				__('<strong>ERROR:</strong> Your account has to be confirmed by an administrator before you can login.', 'wp-approve-user')
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
	 * @return void
	 */
	public function user_register( $id ) {
		update_user_meta( $id, 'wp-approve-user', current_user_can( 'create_users' ) );
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
		check_admin_referer( 'bulk-users' );
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
		check_admin_referer( 'bulk-users' );
		$this->unapprove();
	}


	/**
	 * Adds the update message to the admin notices queue
	 *
	 * @author Konstantin Obenland
	 * @since  1.1 - 12.02.2012
	 * @access public
	 *
	 * @return void
	 */
	public function admin_action_wpau_update() {

		switch( $_REQUEST['update'] ) {
			case 'wpau-approved':
				$message = _n( 'User approved.', '%d users approved.', $_REQUEST['count'], 'wp-approve-user' );
				break;

			case 'wpau-unapproved':
				$message = _n( 'User unapproved.', '%d users unapproved.', $_REQUEST['count'], 'wp-approve-user' );
				break;
			default:
				$message = apply_filters( 'wpau_update_message_handler', '', $_REQUEST['update'] );
		}

		if ( isset( $message ) ) {
			add_settings_error(
				$this->textdomain,
				esc_attr( $_REQUEST['update'] ),
				sprintf( $message, $_REQUEST['count'] ),
				'updated'
			);

			$this->hook( 'all_admin_notices' );
		}

		// Prevent other admin action handlers from trying to handle our action.
		$_REQUEST['action'] = -1;
	}


	/**
	 * Adds our error code to make the login form shake :)
	 *
	 * @author Konstantin Obenland
	 * @since  1.0 - 29.01.2012
	 * @access public
	 *
	 * @return void
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

		if ( current_user_can( 'list_users' ) AND version_compare( get_bloginfo( 'version' ), '3.2', '>=' ) ) {
			global $menu;

			foreach ( $menu as $key => $menu_item ) {
				if ( array_search( 'users.php', $menu_item ) ) {

					// No need for number formatting, count() always returns an integer
					$awaiting_mod = count( $this->unapproved_users );
					$menu[ $key ][0] .= " <span class='update-plugins count-{$awaiting_mod}'><span class='plugin-count'>{$awaiting_mod}</span></span>";

					break; // Bail on success
				}
			}
		}

		add_options_page(
			__( 'Approve User', 'wp-approve-user' ), // Page Title
			__( 'Approve User', 'wp-approve-user' ), // Menu Title
			'promote_users',                         // Capability
			$this->textdomain,                       // Menu Slug
			array( &$this, 'settings_page' )         // Function
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
			__( 'Email contents', 'wp-approve-user' ),
			array( &$this, 'section_description_cb' ),
			$this->textdomain
		);

		add_settings_field(
			'wp-approve-user[send-approve-email]',
			__( 'Send Approve Email', 'wp-approve-user' ),
			array( &$this, 'checkbox_cb' ),
			$this->textdomain,
			$this->textdomain,
			array(
				'name'        => 'wpau-send-approve-email',
				'description' => __( 'Send email on approval.', 'wp-approve-user' ),
			)
		);
		add_settings_field(
			'wp-approve-user[approve-email]',
			__( 'Approve Email', 'wp-approve-user' ),
			array( &$this, 'textarea_cb' ),
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
			__( 'Send Unapprove Email', 'wp-approve-user' ),
			array( &$this, 'checkbox_cb' ),
			$this->textdomain,
			$this->textdomain,
			array(
				'name'        => 'wpau-send-unapprove-email',
				'description' => __( 'Send email on unapproval.', 'wp-approve-user' ),
			)
		);
		add_settings_field(
			'wp-approve-user[unapprove-email]',
			__( 'Unapprove Email', 'wp-approve-user' ),
			array( &$this, 'textarea_cb' ),
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
			_x( 'To take advantage of dynamic data, you can use the following placeholders: %s. Username will be the user login in most cases.', 'Placeholders', 'wp-approve-user' ),
			sprintf( '<code>%s</code>', implode( '</code>, <code>', $tags ) )
		);
	}


	/**
	 * Populates the setting field.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @return void
	 */
	public function checkbox_cb( $option ) {
		$option = (object) $option; ?>
		<label for="<?php echo sanitize_title_with_dashes( $option->name ); ?>">
			<input type="checkbox" name="wp-approve-user[<?php echo esc_attr( $option->name ); ?>]" id="<?php echo sanitize_title_with_dashes( $option->name ); ?>" value="1" <?php checked( $this->options[$option->name] ); ?> />
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
	 * @return void
	 */
	public function textarea_cb( $option ) {
		$option = (object) $option;
		?>
		<textarea id="<?php echo sanitize_title_with_dashes( $option->name ); ?>" class="large-text code" name="wp-approve-user[<?php echo esc_attr( $option->name ); ?>]" rows="10" cols="50" ><?php echo esc_textarea( $this->options[$option->name] ); ?></textarea>
		<?php
	}


	/**
	 * Sanitizes the settings input.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param  array $input
	 *
	 * @return array The sanitized settings
	 */
	public function sanitize( $input ) {
		$output = array();
		$output['wpau-send-approve-email']   = (bool) isset( $input['wpau-send-approve-email'] );
		$output['wpau-send-unapprove-email'] = (bool) isset( $input['wpau-send-unapprove-email'] );
		$output['wpau-approve-email']        = isset( $input['wpau-approve-email'] ) ? trim( $input['wpau-approve-email'] ) : '';
		$output['wpau-unapprove-email']      = isset( $input['wpau-unapprove-email'] ) ? trim( $input['wpau-unapprove-email'] ) : '';

		return $output;
	}


	/**
	 * Sends the approval email.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 31.03.2012
	 * @access public
	 *
	 * @param  int $user_id
	 *
	 * @return void
	 */
	public function wpau_approve( $user_id ) {

		// Check user meta if mail has been sent already.
		if ( $this->options['wpau-send-approve-email'] AND ! get_user_meta( $user_id, 'wp-approve-user-mail-sent', true ) ) {

			$user     = new WP_User( $user_id );
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

			// Send mail.
			$sent = @wp_mail(
				$user->user_email,
				sprintf( _x( '[%s] Registration approved', 'Blogname', 'wp-approve-user' ), $blogname ),
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
	 * @param  int $user_id
	 *
	 * @return void
	 */
	public function delete_user( $user_id ) {

		if ( $this->options['wpau-send-unapprove-email'] ) {
			$user     = new WP_User( $user_id );
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

			// Send mail.
			@wp_mail(
				$user->user_email,
				sprintf( _x( '[%s] Registration unapproved', 'Blogname', 'wp-approve-user' ), $blogname ),
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


	///////////////////////////////////////////////////////////////////////////
	// METHODS, PROTECTED
	///////////////////////////////////////////////////////////////////////////

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

		list( $userids, $url ) = $this->check_user();

		foreach ( (array) $userids as $id ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_user', $id ) )
				wp_die( __( 'You can&#8217;t edit that user.' ) );

			update_user_meta( $id, 'wp-approve-user', true );
			do_action( 'wpau_approve', $id );
		}

		wp_redirect( add_query_arg( array(
			'action' => 'wpau_update',
			'update' => 'wpau-approved',
			'count'  => count( $userids ),
			'role'   => isset( $_REQUEST['role'] ) ? $_REQUEST['role'] : '',
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

		list( $userids, $url ) = $this->check_user();

		foreach ( (array) $userids as $id ) {
			$id = (int) $id;

			if ( ! current_user_can( 'edit_user', $id ) )
				wp_die( __( 'You can&#8217;t edit that user.' ) );

			update_user_meta( $id, 'wp-approve-user', false );
			do_action( 'wpau_unapprove', $id );
		}

		wp_redirect( add_query_arg( array(
			'action' => 'wpau_update',
			'update' => 'wpau-unapproved',
			'count'  => count( $userids ),
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

		$site_id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url     = ( 'site-users-network' == get_current_screen()->id ) ? add_query_arg( array( 'id' => $site_id ), 'site-users.php' ) : 'users.php';

		if ( empty( $_REQUEST['users'] ) AND empty( $_REQUEST['user'] ) ) {
			wp_redirect( $url );
			exit();
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			wp_die( __( 'You can&#8217;t unapprove users.', 'wp-approve-user' ) );
		}

		$userids = ( empty( $_REQUEST['users'] ) ) ? array( intval( $_REQUEST['user'] ) ) : (array) $_REQUEST['users'];
		$userids = array_diff( $userids, array( get_user_by( 'email', get_bloginfo( 'admin_email' ) )->ID ) );

		return array( $userids, $url );
	}


	/**
	 * Replaces all the placeholders with their content.
	 *
	 * @author Konstantin Obenland
	 * @since  2.0.0 - 15.03.2012
	 * @access protected
	 *
	 * @param  string  $message
	 * @param  WP_User $user
	 *
	 * @return string
	 */
	protected function populate_message( $message, $user ) {

		$title   = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

		$message = str_replace( 'BLOG_TITLE', $title,               $message );
		$message = str_replace( 'BLOG_URL',   home_url(),           $message );
		$message = str_replace( 'LOGINLINK',  wp_login_url(),       $message );
		$message = str_replace( 'USERNAME',   $user->user_nicename, $message );

		if ( is_multisite() ) {
			global $current_site;
			$message = str_replace( 'SITE_NAME', $current_site->site_name, $message );
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
			'wpau-approve-email'        => 'Hi USERNAME,
Your registration for BLOG_TITLE has now been approved.

You can log in, using your username and password that you created when registering for our website, at the following URL: LOGINLINK

If you have any questions, or problems, then please do not hesitate to contact us.

Name,
Company,
Contact details',
			'wpau-send-unapprove-email' => false,
			'wpau-unapprove-email'      => ''
		);

		return apply_filters( 'wpau_default_options', $options );
	}
} // End of class Obenland_Wp_Approve_User


new Obenland_Wp_Approve_User;


register_activation_hook( __FILE__, array(
	Obenland_Wp_Approve_User::$instance,
	'activation'
));


/* End of file wp-approve-user.php */
/* Location: ./wp-content/plugins/wp-approve-user/wp-approve-user.php */