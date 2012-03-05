<?php
/** wp-approve-user.php
 *
 * Plugin Name:	WP Approve User
 * Plugin URI:	http://en.wp.obenland.it/wp-approve-user/?utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description:	Adds action links to user table to approve or unapprove user registrations.
 * Version:		1.1.1
 * Author:		Konstantin Obenland
 * Author URI:	http://en.wp.obenland.it/?utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Text Domain: wp-approve-user
 * Domain Path: /lang
 * License:		GPLv2
 */
 

if ( ! get_option( 'users_can_register' ) ) {
	return;
}


if ( ! class_exists('Obenland_Wp_Plugins_v15') ) {
	require_once( 'obenland-wp-plugins.php' );
}


class Obenland_Wp_Approve_User extends Obenland_Wp_Plugins_v15 {
	
	
	///////////////////////////////////////////////////////////////////////////
	// PROPERTIES, PUBLIC
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 *
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 * @static
	 *
	 * @var	Obenland_Wp_Approve_User
	 */
	public static $instance;
	
	
	///////////////////////////////////////////////////////////////////////////
	// METHODS, PUBLIC
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 * Constructor
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	public
	 *
	 * @return	Obenland_Wp_Approve_User
	 */
	public function __construct() {
		
		parent::__construct( array(
			'textdomain'		=>	'wp-approve-user',
			'plugin_path'		=>	__FILE__,
			'donate_link_id'	=>	'G65Y5CM3HVRNY'
		));
		
		self::$instance	=	$this;
		
		load_plugin_textdomain( 'wp-approve-user' , false, 'wp-approve-user/lang' );
		
		$this->hook( 'plugins_loaded' );
	}
	
	
	/**
	 * Approves all existing users.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	public
	 * @static
	 *
	 * @return	void
	 */
	public function activation() {
		$user_ids = get_users(array(
			'blog_id'	=>	'',
			'fields'	=>	'ID'
		));
		
		foreach ( $user_ids as $user_id ) {
			update_user_meta( $user_id, 'wp-approve-user', true );
		}
	}
	
	
	/**
	 * Hooks in all the hooks :)
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function plugins_loaded() {
		$hooks	=	array(
			'admin_print_scripts-users.php',
			'user_row_actions',
			'wp_authenticate_user',
			'user_register',
			'all_admin_notices',
			'shake_error_codes',
			'admin_menu',
			'admin_action_wpau_approve',
			'admin_action_wpau_bulk_approve',
			'admin_action_wpau_unapprove',
			'admin_action_wpau_bulk_unapprove',
			'admin_action_wpau_update'
		);
		
		foreach ( $hooks as $hook ) {
			$this->hook( $hook );
		}
		
		$this->hook( 'admin_print_scripts-site-users.php', 'admin_print_scripts_users_php' );
	}
	
	
	/**
	 * Enqueues the script
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_print_scripts_users_php() {
		$suffix = ( defined('SCRIPT_DEBUG') AND SCRIPT_DEBUG ) ? '.dev' : '';

		wp_enqueue_script(
			$this->textdomain,
			plugins_url("/js/{$this->textdomain}{$suffix}.js", __FILE__),
			array('jquery'),
			'1.1.0'
		);
		
		wp_localize_script(
			$this->textdomain,
			'wp_approve_user',
			array(
				'approve'	=>	__('Approve', 'wp-approve-user'),
				'unapprove'	=>	__('Unapprove', 'wp-approve-user')
			)
		);
	}
	
	
	/**
	 * Adds the plugin's row actions to the existing ones.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	public
	 *
	 * @param	array	$actions
	 * @param	WP_User	$user_object
	 *
	 * @return	array
	 */
	public function user_row_actions( $actions, $user_object ) {

		if ( ( get_current_user_id() != $user_object->ID ) AND current_user_can('edit_user', $user_object->ID) ) {
			
			$site_id	=	isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
			$url		=	('site-users-network' == get_current_screen()->id) ? "site-users.php?id={$site_id}" : 'users.php';
	
			if ( get_user_meta( $user_object->ID, 'wp-approve-user', true ) ) {
				$url	=	wp_nonce_url( add_query_arg(array(
					'action'	=>	'wpau_unapprove',
					'user'		=>	$user_object->ID
				), $url), 'wpau-unapprove-users' );
				
				$actions['wpau-unapprove']	=	"<a class='submitunapprove' href='{$url}'>" . __( 'Unapprove', 'wp-approve-user' ) . "</a>";
			}
			else {
				$url	=	wp_nonce_url( add_query_arg(array(
					'action'	=>	'wpau_approve',
					'user'		=>	$user_object->ID
				), $url), 'wpau-approve-users' );
				
				$actions['wpau-approve']	=	"<a class='submitapprove' href='{$url}'>" . __( 'Approve', 'wp-approve-user' ) . "</a>";
			}
		}
	
		return $actions;
	}
	
	
	/**
	 * Checks whether the user is approved. Throws error if not.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	public
	 *
	 * @param 	WP_User|WP_Error	$userdata
	 *
	 * @return	WP_User|WP_Error
	 */
	public function wp_authenticate_user( $userdata ) {
		if ( is_wp_error($userdata) ) {
			return $userdata;
		}
		
		if ( ! get_user_meta( $userdata->ID, 'wp-approve-user', true ) AND $userdata->user_email != get_bloginfo('admin_email') ) {
			$userdata	=	new WP_Error(
				'wpau_confirmation_error',
				__('<strong>ERROR:</strong> Your account has to be confirmed by an administrator before you can login.', 'wp-approve-user')
			);
		}
		
		return $userdata;
	}
	
	
	/**
	 * Updates user_meta to approve user when registered by an Administrator.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function user_register( $id ) {
		update_user_meta( $id, 'wp-approve-user', current_user_can('add_users') );
	}
	
	
	/**
	 * Updates user_meta to approve user.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_action_wpau_approve() {
		check_admin_referer( 'wpau-approve-users' );
		$this->approve();
	}
	
	
	/**
	 * Bulkupdates user_meta to approve user.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_action_wpau_bulk_approve() {
		check_admin_referer( 'bulk-users' );
		$this->approve();
	}
	
	
	/**
	 * Updates user_meta to unapprove user.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_action_wpau_unapprove() {
		check_admin_referer( 'wpau-unapprove-users' );
		$this->unapprove();
	}
	
	
	/**
	 * Bulkupdates user_meta to unapprove user.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_action_wpau_bulk_unapprove() {
		check_admin_referer( 'bulk-users' );
		$this->unapprove();
	}
	
	
	/**
	 * Adds the update message to the admin notices queue
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_action_wpau_update() {
	
		switch($_REQUEST['update']) {
			case 'wpau-approved':
				$message	=	_n('User approved.', '%d users approved.', $_REQUEST['count'], 'wp-approve-user');
				break;
			
			case 'wpau-unapproved':
				$message	=	_n('User unapproved.', '%d users unapproved.', $_REQUEST['count'], 'wp-approve-user');
				break;
		}
		add_settings_error(
			$this->textdomain,
			esc_attr($_REQUEST['update']),
			sprintf( $message, $_REQUEST['count'] ),
			'updated'
		);
	}
	
	/**
	 * Displays settings errors if set.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function all_admin_notices() {
		settings_errors( $this->textdomain );
	}
	
	
	/**
	 * Adds our error code to make the login form shake :)
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function shake_error_codes( $shake_error_codes ) {
		$shake_error_codes[]	=	'wpau_confirmation_error';
		return $shake_error_codes;
	}
	
	
	/**
	 * Resets the User menu item to reflect the amopunt of unapproved users
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_menu() {
		
		if ( current_user_can('list_users') AND version_compare(get_bloginfo('version'), '3.2', '>=') ) {
			global $menu;
			
			unset($menu[70]);
			$awaiting_mod = count(get_users(array(
				'meta_key'		=>	'wp-approve-user',
				'meta_value'	=>	false
			)));
			
			$menu[70] = array (
				__('Users') . " <span class='update-plugins count-{$awaiting_mod}'><span class='plugin-count'>" . number_format_i18n($awaiting_mod) . "</span></span>",
				'list_users',
				'users.php',
				'',
				'menu-top menu-icon-users',
				'menu-users',
				'div'
			);
		}
	}
	
	
	///////////////////////////////////////////////////////////////////////////
	// METHODS, PROTECTED
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 * Updates user_meta to approve user.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	protected
	 *
	 * @return	void
	 */
	protected function approve() {
		$site_id		=	isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url			=	('site-users-network' == get_current_screen()->id) ? "site-users.php?id={$site_id}" : 'users.php';
		
		if ( empty($_REQUEST['users']) AND empty($_REQUEST['user']) ) {
			wp_redirect( $url );
			exit();
		}
	
		if ( ! current_user_can('promote_users') ) {
			wp_die( __('You can&#8217;t approve users.', 'wp-approve-user' ) );
		}
		
		if ( empty($_REQUEST['users']) ) {
			$userids = array( intval($_REQUEST['user']) );
		}
		else {
			$userids = (array) $_REQUEST['users'];
		}
		
		foreach ( (array) $userids as $id ) {
			$id = (int) $id;
	
			if ( ! current_user_can( 'edit_user', $id ) )
				wp_die( __( 'You can&#8217;t edit that user.' ) );
	
			update_user_meta( $id, 'wp-approve-user', true );
			do_action( 'wpau_approve', $id );
		}
		
		wp_redirect(add_query_arg( array(
			'action'	=>	'wpau_update',
			'update'	=>	'wpau-approved',
			'count'		=>	count($userids)
		), $url));
		exit();
	}
	
	
	/**
	 * Updates user_meta to unapprove user.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 12.02.2012
	 * @access	protected
	 *
	 * @return	void
	 */
	protected function unapprove() {
		$site_id		=	isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url			=	('site-users-network' == get_current_screen()->id) ? "site-users.php?id={$site_id}" : 'users.php';
		
		if ( empty($_REQUEST['users']) AND empty($_REQUEST['user']) ) {
			wp_redirect( $url );
			exit();
		}
	
		if ( ! current_user_can('promote_users') ) {
			wp_die( __( 'You can&#8217;t unapprove users.', 'wp-approve-user' ) );
		}
		
		if ( empty($_REQUEST['users']) ) {
			$userids = array( intval($_REQUEST['user']) );
		}
		else {
			$userids = (array) $_REQUEST['users'];
		}
		
		foreach ( (array) $userids as $id ) {
			$id = (int) $id;
	
			if ( ! current_user_can( 'edit_user', $id ) )
				wp_die( __( 'You can&#8217;t edit that user.' ) );
	
			update_user_meta( $id, 'wp-approve-user', false );
			do_action( 'wpau_unapprove', $id );
		}
		
		wp_redirect(add_query_arg( array(
			'action'	=>	'wpau_update',
			'update'	=>	'wpau-unapproved',
			'count'		=>	count($userids)
		), $url));
		exit();
	}
}  // End of class Obenland_Wp_Approve_User


new Obenland_Wp_Approve_User;


register_activation_hook( __FILE__, array(
	Obenland_Wp_Approve_User::$instance,
	'activation'
));


/* End of file wp-approve-user.php */
/* Location: ./wp-content/plugins/wp-approve-user/wp-approve-user.php */