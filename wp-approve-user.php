<?php
/** wp-approve-user.php
 *
 * Plugin Name:	WP Approve User
 * Plugin URI:	http://en.wp.obenland.it/wp-approve-user/?utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description:	Adds action links to user table to approve or unapprove user registrations.
 * Version:		1.0
 * Author:		Konstantin Obenland
 * Author URI:	http://en.wp.obenland.it/?utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Text Domain: wp-approve-user
 * Domain Path: /lang
 * License:		GPLv2
 */


if ( ! get_option( 'users_can_register' ) ) {
	return;
}


if ( ! class_exists('Obenland_Wp_Plugins') ) {
	require_once( 'obenland-wp-plugins.php' );
}


register_activation_hook( __FILE__, array(
	'Obenland_Wp_Approve_User',
	'activation'
));


class Obenland_Wp_Approve_User extends Obenland_Wp_Plugins {
	
	
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
			'plugin_name'		=>	plugin_basename(__FILE__),
			'donate_link_id'	=>	'G65Y5CM3HVRNY'
		));
		
		load_plugin_textdomain( $this->textdomain , false, $this->textdomain . '/lang' );
		
		
		add_filter( 'wp_authenticate_user', array(
			&$this,
			'authenticate_user'
		));
		
		add_filter( 'user_row_actions', array(
			&$this,
			'user_row_actions'
		), 10, 2);
		
		add_action( 'admin_head-users.php', array(
			&$this,
			'take_action'
		));
		
		add_action( 'all_admin_notices', array(
			&$this,
			'admin_notices'
		));
		
		add_filter( 'shake_error_codes', array(
			&$this,
			'shake_it_baby'
		));
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
	public static function activation() {
		
		$user_ids = get_users(array(
			'blog_id'	=>	'',
			'fields'	=>	'ID'
		));
		
		foreach ( $user_ids as $user_id ) {
			update_user_meta( $user_id, 'wp-approve-user', true );
		}
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
			
			$site_id		=	isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
			$url			=	('site-users-network' == get_current_screen()->id) ? "site-users.php?id={$site_id}" : 'users.php';
	
			if ( get_user_meta( $user_object->ID, 'wp-approve-user', true ) ) {
				$url	=	wp_nonce_url( add_query_arg(array(
					'action'	=>	'wpau_reject',
					'user'		=>	$user_object->ID
				), $url), 'wpau-reject-users' );
				
				$actions['reject']	=	"<a class='submitreject' href='{$url}'>" . __( 'Unapprove', 'wp-approve-user' ) . "</a>";
			}
			else {
				$url	=	wp_nonce_url( add_query_arg(array(
					'action'	=>	'wpau_approve',
					'user'		=>	$user_object->ID
				), $url), 'wpau-approve-users' );
				
				$actions['confirm']	=	"<a class='submitconfirm' href='{$url}'>" . __( 'Approve', 'wp-approve-user' ) . "</a>";
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
	public function authenticate_user( $userdata ) {
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
	 * Updates or deletes user_meta to approve or reject user.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function take_action() {
		
		$site_id		=	isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		$url			=	('site-users-network' == get_current_screen()->id) ? "site-users.php?id={$site_id}" : 'users.php';
		
		switch ( $this->current_action() ) {
			case 'wpau_approve':
				check_admin_referer( 'wpau-approve-users' );
				
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
				}
				
				add_settings_error(
					$this->textdomain,
					esc_attr('wpau-approved'),
					sprintf(
						_n('User approved.', '%d users approved.', count($userids), 'wp-approve-user'),
						count($userids)
					),
					'updated'
				);
				
				break;
				
			case 'wpau_reject':
				check_admin_referer('wpau-reject-users');
				
				if ( empty($_REQUEST['users']) AND empty($_REQUEST['user']) ) {
					wp_redirect( $url );
					exit();
				}
			
				if ( ! current_user_can('promote_users') ) {
					wp_die( __( 'You can&#8217;t reject users.', 'wp-approve-user' ) );
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
			
					delete_user_meta( $id, 'wp-approve-user', true );
				}
				
				add_settings_error(
					$this->textdomain,
					esc_attr('wpau-unapproved'),
					sprintf(
						_n('User unapproved.', '%d users unapproved.', count($userids), 'wp-approve-user'),
						count($userids)
					),
					'updated'
				);
				
				break;
				
			default:
				do_action( 'wpau_user_row_actions' );
				break;
		}
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
	public function admin_notices() {
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
	public function shake_it_baby( $shake_error_codes ) {
		$shake_error_codes[]	=	'wpau_confirmation_error';
		return $shake_error_codes;
	}
	
	
	///////////////////////////////////////////////////////////////////////////
	// METHODS, PROTECTED
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 * Returns the current action parameter of false
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 29.01.2012
	 * @access	protected
	 *
	 * @return	int|string|bool
	 */
	protected function current_action() {
		
		foreach ( array('action', 'action2') as $action ) {
			if ( isset( $_REQUEST[$action] ) AND -1 != $_REQUEST[$action] ) {
				return $_REQUEST[$action];
			}
		}
		return false;
	}
}  // End of class Obenland_Wp_Approve_User


new Obenland_Wp_Approve_User;


/* End of file wp-approve-user.php */
/* Location: ./wp-content/plugins/wp-approve-user/wp-approve-user.php */