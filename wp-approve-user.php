<?php
/** wp-approve-user.php
 *
 * Plugin Name:	WP Approve User
 * Plugin URI:	http://en.wp.obenland.it/wp-approve-user/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
 * Description:	Adds action links to user table to approve or unapprove user registrations.
 * Version:		2.0.0
 * Author:		Konstantin Obenland
 * Author URI:	http://en.wp.obenland.it/#utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-approve-user
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
	 * @since	1.1.0 - 12.02.2012
	 * @access	public
	 * @static
	 *
	 * @var	Obenland_Wp_Approve_User
	 */
	public static $instance;
	
	
	///////////////////////////////////////////////////////////////////////////
	// PROPERTIES, PROTECTED
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 * The plugin options
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	protected
	 *
	 * @var		array
	 */
	protected $options;
	
	
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
		$this->options	=	get_option( $this->textdomain, $this->default_options() );
		
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
		
		wp_redirect( admin_url( 'options-general.php?page=wp-approve-user' ) );
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

		$this->hook( 'admin_print_scripts-users.php' );
		$this->hook( 'admin_print_scripts-site-users.php', 'admin_print_scripts_users_php' );
		$this->hook( 'admin_print_styles-settings_page_wp-approve-user' );
		$this->hook( 'user_row_actions' );
		$this->hook( 'wp_authenticate_user' );
		$this->hook( 'user_register' );
		$this->hook( 'shake_error_codes' );
		$this->hook( 'admin_menu' );
		$this->hook( 'admin_action_wpau_approve' );
		$this->hook( 'admin_action_wpau_bulk_approve' );
		$this->hook( 'admin_action_wpau_unapprove' );
		$this->hook( 'admin_action_wpau_bulk_unapprove' );
		$this->hook( 'admin_action_wpau_update' );
		
		$this->hook( 'wpau_approve' );
		$this->hook( 'delete_user' );
		$this->hook( 'admin_init' );
		$this->hook( 'obenland_side_info_column', 'donate_box', 1 );
		$this->hook( 'obenland_side_info_column', 'feed_box' );
		
		add_action( 'all_admin_notices', 'settings_errors' );
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
		$plugin_data = get_plugin_data( __FILE__, false, false );
		$suffix = ( defined('SCRIPT_DEBUG') AND SCRIPT_DEBUG ) ? '.dev' : '';

		wp_enqueue_script(
			$this->textdomain,
			plugins_url("/js/{$this->textdomain}{$suffix}.js", __FILE__),
			array('jquery'),
			$plugin_data['Version'],
			true
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
	 * Enqueues the style on the settings page
	 * 
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 10.04.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function admin_print_styles_settings_page_wp_approve_user() {
		$plugin_data = get_plugin_data( __FILE__, false, false );
		$suffix = ( defined('SCRIPT_DEBUG') AND SCRIPT_DEBUG ) ? '.dev' : '';
		
		wp_enqueue_style(
			$this->textdomain,
			plugins_url("/css/settings-page{$suffix}.css", __FILE__),
			array(),
			$plugin_data['Version']
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
		
		if ( ! is_wp_error($userdata) ) {
			if ( ! get_user_meta( $userdata->ID, 'wp-approve-user', true ) AND $userdata->user_email != get_bloginfo('admin_email') ) {
				$userdata	=	new WP_Error(
					'wpau_confirmation_error',
					__('<strong>ERROR:</strong> Your account has to be confirmed by an administrator before you can login.', 'wp-approve-user')
				);
			}
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
	
		switch( $_REQUEST['update'] ) {
			case 'wpau-approved':
				$message	=	_n( 'User approved.', '%d users approved.', $_REQUEST['count'], 'wp-approve-user' );
				break;
			
			case 'wpau-unapproved':
				$message	=	_n( 'User unapproved.', '%d users unapproved.', $_REQUEST['count'], 'wp-approve-user' );
				break;
		}
		
		add_settings_error(
			$this->textdomain,
			esc_attr( $_REQUEST['update'] ),
			sprintf( $message, $_REQUEST['count'] ),
			'updated'
		);
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
	 * Enhances the User menu item to reflect the amount of unapproved users
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

			foreach ( $menu as $key => $menu_item ) {
				if ( array_search( 'users.php', $menu_item ) ) {
				
					//No need for number formatting, count() always returns an integer
					$awaiting_mod	=	count( get_users( array(
						'meta_key'		=>	'wp-approve-user',
						'meta_value'	=>	false
					)));
					$menu[$key][0]	.=	" <span class='update-plugins count-{$awaiting_mod}'><span class='plugin-count'>{$awaiting_mod}</span></span>";
					
					break; // Bail on success
				}
			}
		}
		
		add_options_page(
			__('Approve User', 'wp-approve-user'),	// Page Title
			__('Approve User', 'wp-approve-user'),	// Menu Title
			'promote_users',						// Capability
			$this->textdomain,						// Menu Slug
			array(&$this, 'settings_page')			// Function
		);
	}
	
	
	/**
	 * Registers the plugins' settings
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0.0 - 02.03.2012
	 * @access	public
	 *
	 * return	void
	 */
	public function admin_init() {
		
		register_setting(
			$this->textdomain,
			'wp-approve-user',
			array(&$this, 'sanitize')
		);
		
		add_settings_section(
			$this->textdomain,
			__('Email contents', 'awd-approve-user-email'),
			array(&$this, 'section_description_cb'),
			$this->textdomain
		);
		
		add_settings_field(
			'wp-approve-user[send-approve-email]',
			__('Send Approve Email', 'wp-approve-user'),
			array(&$this, 'send_approve_email_cb'),
			$this->textdomain,
			$this->textdomain,
			array(
				'label_for'	=>	'wpau-send-approve-email'
			)
		);
		add_settings_field(
			'wp-approve-user[approve-email]',
			__('Approve Email', 'wp-approve-user'),
			array(&$this, 'approve_email_cb'),
			$this->textdomain,
			$this->textdomain,
			array(
				'label_for'	=>	'wpau-approve-email'
			)
		);
		
		add_settings_field(
			'wp-approve-user[send-unapprove-email]',
			__('Send Unapprove Email', 'wp-approve-user'),
			array(&$this, 'send_unapprove_email_cb'),
			$this->textdomain,
			$this->textdomain,
			array(
				'label_for'	=>	'wpau-send-unapprove-email'
			)
		);
		add_settings_field(
			'wp-approve-user[unapprove-email]',
			__('Unapprove Email', 'wp-approve-user'),
			array(&$this, 'unapprove_email_cb'),
			$this->textdomain,
			$this->textdomain,
			array(
				'label_for'	=>	'wpau-unapprove-email'
			)
		);
	}
	
	
	/**
	 * Displays the options page
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2><?php esc_html_e( 'Approve User Settings', 'wp-approve-user' ); ?></h2>
			<?php settings_errors(); ?>
	
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
						<div id="side-info-column" class="inner-sidebar">
							<?php do_action( 'obenland_side_info_column' ); ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
	
	
	/**
	 * Displays a box with a donate button and call to action links
	 * 
	 * Props Joost de Valk, as this is almost entirely from his awesome WordPress
	 * SEO Plugin
	 * @see		http://plugins.svn.wordpress.org/wordpress-seo/tags/1.1.5/admin/class-config.php
	 *
	 * @author	Joost de Valk, Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function donate_box() {
		$plugin_data = get_plugin_data( __FILE__ );
		?>
		<div id="formatdiv" class="postbox">
			<h3 class="hndle"><span><?php esc_html_e( 'Help spread the word!', 'wp-approve-user' ); ?></span></h3>
			<div class="inside">
				<p><strong><?php printf( _x( 'Want to help make this plugin even better? All donations are used to improve %1$s, so donate $20, $50 or $100 now!', 'Plugin Name', 'wp-approve-user' ), esc_html($plugin_data['Name']) ); ?></strong></p>
				<form action="https://www.paypal.com/cgi-bin/webscr" method="post">
					<input type="hidden" name="cmd" value="_s-xclick">
					<input type="hidden" name="hosted_button_id" value="G65Y5CM3HVRNY">
					<input type="image" src="https://www.paypalobjects.com/<?php echo get_locale(); ?>/i/btn/btn_donate_LG.gif" border="0" name="submit" alt="PayPal Ñ The safer, easier way to pay online.">
					<img alt="" border="0" src="https://www.paypalobjects.com/de_DE/i/scr/pixel.gif" width="1" height="1">
				</form>
				<p><?php _e( 'Or you could:', 'wp-approve-user' ); ?></p>
				<ul>
					<li><a href="http://wordpress.org/extend/plugins/wp-approve-user/"><?php _e( 'Rate the plugin 5&#9733; on WordPress.org', 'wp-approve-user' ); ?></a></li>
					<li><a href="<?php echo esc_url( $plugin_data['PluginURI'] ); ?>"><?php _e( 'Blog about it &amp; link to the plugin page', 'wp-approve-user' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}
	
	
	/**
	 * Displays a box with feed items and social media links
	 * 
	 * Props Joost de Valk, as this is almost entirely from his awesome WordPress
	 * SEO Plugin
	 * @see		http://plugins.svn.wordpress.org/wordpress-seo/tags/1.1.5/admin/yst_plugin_tools.php
	 *
	 * @author	Joost de Valk, Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function feed_box() {
		
		include_once( ABSPATH . WPINC . '/feed.php' );
		$feed_url = 'http://en.wp.obenland.it/feed/';
		$rss = fetch_feed( $feed_url );
		
		// Bail if feed doesn't work
		if ( is_wp_error($rss) )
			return false;
		
		$rss_items = $rss->get_items( 0, $rss->get_item_quantity( 5 ) );
		
		// If the feed was erroneously
		if ( ! $rss_items ) {
			$md5 = md5( $feed_url );
			delete_transient( 'feed_' . $md5 );
			delete_transient( 'feed_mod_' . $md5 );
			$rss = fetch_feed( 'http://en.wp.obenland.it/feed/' );
			$rss_items = $rss->get_items( 0, $rss->get_item_quantity( 5 ) );
		}
		?>
		<div id="formatdiv" class="postbox">
			<h3 class="hndle"><span><?php esc_html_e( 'News from Konstantin', 'wp-approve-user' ); ?></span></h3>
			<div class="inside">
				<ul>
					<?php if ( ! $rss_items ) : ?>
					<li><?php _e( 'No news items, feed might be broken...', 'wp-approve-user' ); ?></li>
					<?php else :
					foreach ( $rss_items as $item ) :
						$url = preg_replace( '/#.*/', '#utm_source=wordpress&utm_medium=sidebannerpostbox&utm_term=rssitem&utm_campaign=wp-approve-user',  $item->get_permalink() ); ?>
					<li><a class="rsswidget" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item->get_title() ); ?></a></li>
					<?php endforeach; endif; ?>
					<li class="twitter"><a href="http://twitter.com/obenland"><?php _e( 'Follow Konstantin on Twitter', 'wp-approve-user' ); ?></a></li>
					<li class="rss"><a href="<?php echo esc_url( $feed_url ); ?>"><?php _e( 'Subscribe via RSS', 'wp-approve-user' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function section_description_cb() {
		printf(
			_x( 'To take advantage of dynamic data, you can use the following placeholders: %s. Username will be the user login in most cases.', 'Placeholders', 'wp-approve-user' ),
			sprintf( ' <code>%s</code>', implode( '</code>, <code>', array(
				'{{USERNAME}}',
				'{{BLOGNAME}}',
				'{{LOGINURL}}'
			)))
		);
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function send_approve_email_cb() {
		?>
		<label for="wpau-send-approve-email">
			<input type="checkbox" id="wpau-send-approve-email" name="wp-approve-user[send-approve-email]" value="1" <?php checked(  $this->options['send-approve-email'] ); ?> />
			<?php _e( 'Send email on approval.', 'wp-approve-user' ); ?>
		</label>
		<?php
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function approve_email_cb() {
		if ( ! $this->options['send-approve-email'] ) {
			?><input type="hidden" name="wp-approve-user[approve-email]" value="<?php echo esc_attr( $this->options['approve-email'] ); ?>"/><?php
		}
		?>
		<textarea id="wpau-approve-email" class="large-text code" name="wp-approve-user[approve-email]" rows="10" cols="50" <?php disabled( $this->options['send-approve-email'], false ); ?>><?php echo esc_textarea( $this->options['approve-email'] ); ?></textarea>
		<?php
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function send_unapprove_email_cb() {
		?>
		<label for="wpau-send-unapprove-email">
			<input type="checkbox" id="wpau-send-unapprove-email" name="wp-approve-user[send-unapprove-email]" value="1" <?php checked(  $this->options['send-unapprove-email'] ); ?> />
			<?php _e( 'Send email on unapproval.', 'wp-approve-user' ); ?>
		</label>
		<?php
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @return	void
	 */
	public function unapprove_email_cb() {
		if ( ! $this->options['send-unapprove-email'] ) {
			?><input type="hidden" name="wp-approve-user[unapprove-email]" value="<?php echo esc_attr( $this->options['unapprove-email'] ); ?>"/><?php
		}
		?>
		<textarea id="wpau-unapprove-email"  class="large-text code" name="wp-approve-user[unapprove-email]" rows="10" cols="50" <?php disabled( $this->options['send-unapprove-email'], false ); ?>><?php echo esc_textarea( $this->options['unapprove-email'] ); ?></textarea>
		<?php
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @param	array	$settings
	 *
	 * @return	array	The sanitized settings
	 */
	public function sanitize( $settings ) {
		$settings['send-approve-email']		=	isset( $settings['send-approve-email'] ) ? true : false;
		$settings['send-unapprove-email']	=	isset( $settings['send-unapprove-email'] ) ? true : false;
		$settings['approve-email']			=	isset( $settings['approve-email'] ) ? trim( $settings['approve-email'] ) : '';
		$settings['unapprove-email']		=	isset( $settings['unapprove-email'] ) ? trim( $settings['unapprove-email'] ) : '';
		
		return $settings;
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @param	int		$user_id
	 *
	 * @return	void
	 */
	public function wpau_approve( $user_id ) {
		
		// check user meta if mail has been sent already
		if ( ! get_user_meta( $user_id, 'wp-approve-user-mail-sent', true ) AND $this->options['send-approve-email'] ) {
			
			$user		=	new WP_User( $user_id );
			$blogname	=	wp_specialchars_decode( get_option('blogname'), ENT_QUOTES );
			
			// send mail
			$sent	=	@wp_mail(
				$user->user_email,
				sprintf( _x('[%s] Registration approved', 'Blogname', 'wp-approve-user'), $blogname ),
				$this->populate_message( $this->options['approve-email'], $user )
			);
			
			if ( $sent ) {
				update_user_meta( $user_id, 'wp-approve-user-mail-sent', true );
			}
		}
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 31.03.2012
	 * @access	public
	 *
	 * @param	int		$user_id
	 *
	 * @return	void
	 */
	public function delete_user( $user_id ) {
		
		if ( $this->options['send-unapprove-email'] ) {
			$user		=	new WP_User( $user_id );
			$blogname	=	wp_specialchars_decode( get_option('blogname'), ENT_QUOTES );
				
			// send mail
			@wp_mail(
				$user->user_email,
				sprintf( _x('[%s] Registration unapproved', 'Blogname', 'wp-approve-user'), $blogname ),
				$this->populate_message( $this->options['unapprove-email'], $user )
			);
			
			// No need to delete user_meta, since this user will be GONE
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
		
		$url		=	('site-users-network' == get_current_screen()->id) ? "site-users.php?id={$site_id}" : 'users.php';
		$userids	=	$this->check_user( $url );
		
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
		
		$url		=	('site-users-network' == get_current_screen()->id) ? "site-users.php?id={$site_id}" : 'users.php';
		$userids	=	$this->check_user( $url );
		
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
	
	
	/**
	 * Checks permissions and assembles User IDs
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 15.03.2012
	 * @access	protected
	 *
	 * @param	string	$url
	 *
	 * @return	array	User IDs
	 */
	protected function check_user( $url ) {
		$site_id		=	isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
		
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
		
		return $userids;
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 15.03.2012
	 * @access	protected
	 *
	 * @param	string		$message
	 * @param	WP_User		$user
	 *
	 * @return	string
	 */
	protected function populate_message( $message, $user ) {
		
		$blogname	=	wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
		
		$message	=	str_replace( '{{USERNAME}}',	$user->user_nicename,	$message );
		$message	=	str_replace( '{{BLOGNAME}}',	$blogname, 				$message );
		$message	=	str_replace( '{{LOGINURL}}',	wp_login_url(),			$message );
		
		return $message;
	}
	
	
	/**
	 * TODO
	 *
	 * @author	Konstantin Obenland
	 * @since	2.0.0 - 15.03.2012
	 * @access	protected
	 *
	 * @return	array
	 */
	protected function default_options() {
		$options	=	array(
			'send-approve-email'		=>	true,
			'approve-email'			=>	'Hi {{USERNAME}},
Your registration for {{BLOGNAME}} has now been approved.
 
You can log in, using your username and password that you created when registering for our website, at the following URL: {{LOGINURL}}
 
If you have any questions, or problems, then please do not hesitate to contact us.
 
Name,
Company,
Contact details',
			'send-unapprove-email'	=>	false,
			'unapprove-email'		=>	''
		);
		
		return apply_filters( 'wpau_default_options', $options );
	}
}  // End of class Obenland_Wp_Approve_User


new Obenland_Wp_Approve_User;


register_activation_hook( __FILE__, array(
	Obenland_Wp_Approve_User::$instance,
	'activation'
));


/* End of file wp-approve-user.php */
/* Location: ./wp-content/plugins/wp-approve-user/wp-approve-user.php */