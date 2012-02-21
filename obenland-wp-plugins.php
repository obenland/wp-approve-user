<?php
/** obenland-wp-plugins.php
 *
 * @author		Konstantin Obenland
 * @version		1.5
 */


class Obenland_Wp_Plugins_v15 {


	/////////////////////////////////////////////////////////////////////////////
	// PROPERTIES, PROTECTED
	/////////////////////////////////////////////////////////////////////////////

	/**
	 * The plugins' text domain
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 03.04.2011
	 * @access	protected
	 *
	 * @var		string
	 */
	protected $textdomain;


	/**
	 * The name of the calling plugin
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 23.03.2011
	 * @access	protected
	 *
	 * @var		string
	 */
	protected $plugin_name;


	/**
	 * The donate link for the plugin
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 23.03.2011
	 * @access	protected
	 *
	 * @var		string
	 */
	protected $donate_link;


	/**
	 * The path to the plugin folder
	 *
	 * /path/to/wp-content/plugins/{plugin-name}/
	 *
	 * @author	Konstantin Obenland
	 * @since	1.2 - 21.04.2011
	 * @access	protected
	 *
	 * @var		string
	 */
	protected $plugin_path;


	///////////////////////////////////////////////////////////////////////////
	// METHODS, PUBLIC
	///////////////////////////////////////////////////////////////////////////

	/**
	 * Constructor
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 23.03.2011
	 * @access	public
	 *
	 * @param	string	$plugin_name
	 * @param	string	$donate_link_id
	 *
	 * @return	Obenland_Wp_Plugins
	 */
	public function __construct( $args = array() ) {

		// Set class properties
		$this->textdomain	=	$args['textdomain'];
		$this->plugin_path	=	plugin_dir_path( $args['plugin_path'] );
		$this->plugin_name	=	plugin_basename( $args['plugin_path'] );

		$this->set_donate_link( $args['donate_link_id'] );


		// Add actions and filters
		$this->hook( 'plugin_row_meta' );
	}


	/**
	 *
	 * @author	Konstantin Obenland
	 * @since	1.0 - 23.03.2011
	 * @access	public
	 *
	 * @param	array	$plugin_meta
	 * @param	string	$plugin_file
	 *
	 * @return	string
	 */
	public function plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( $this->plugin_name == $plugin_file ) {
			$plugin_meta[]	=	sprintf('
				<a href="%1$s" target="_blank" title="%2$s">%2$s</a>',
			$this->donate_link,
			__('Donate', $this->textdomain)
			);
		}
		return $plugin_meta;
	}


	///////////////////////////////////////////////////////////////////////////
	// METHODS, PROTECTED
	///////////////////////////////////////////////////////////////////////////

	/**
	 * Hooks methods their WordPress Actions and Filters
	 *
	 * @example:
	 * $this->hook( 'the_title' );
	 * $this->hook( 'init', 5 );
	 * $this->hook( 'omg', 'is_really_tedious', 3 );
	 *
	 * @author	Mark Jaquith
	 * @see		http://sliwww.slideshare.net/markjaquith/creating-and-maintaining-wordpress-plugins
	 * @since	1.5 - 12.02.2012
	 * @access	protected
	 *
	 * @param	string	$hook Action or Filter Hook name
	 *
	 * @return	boolean	true
	 */
	protected function hook( $hook ) {
		$priority	=	10;
		$method		=	$this->sanitize_method( $hook );
		$args		=	func_get_args();
		unset( $args[0] ); // Filter name

		foreach ( (array) $args as $arg ) {
			if ( is_int( $arg ) ) {
				$priority	=	$arg;
			}
			else {
				$method		=	$arg;

			}
		}

		return add_action(	$hook, array( $this, $method ), $priority , 999 );
	}
	
	
	/**
	 * Sets the donate link
	 *
	 * @author	Konstantin Obenland
	 * @since	1.1 - 03.04.2011
	 * @access	protected
	 *
	 * @param	string	$donate_link_id
	 *
	 * @return	void
	 */
	protected function set_donate_link( $donate_link_id ) {
		$this->donate_link	=	add_query_arg( array(
			'cmd'				=>	'_s-xclick',
			'hosted_button_id'	=>	$donate_link_id
		), 'https://www.paypal.com/cgi-bin/webscr' );
	}


	/**
	 * Retrieve option value based on the environment.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.4 - 23.12.2011
	 * @access	protected
	 *
	 * @param	string	$option_name	Name of option to retrieve. Expected to not be SQL-escaped.
	 * @param	mixed	$default		Optional. Default value to return if the option does not exist.
	 * @param	boolean	$use_cache		Optional. Whether to use cache. Multisite only. Default true.
	 *
	 * @return	mixed	Value set for the option.
	 */
	protected function get_option( $option_name, $default = false, $use_cache = true ) {
		
		$options	=	$this->get_options( $default, $use_cache );

		if ( isset($options[$option_name]) ) {
			return $options[$option_name];
		}

		return $default;
	}

	
	/**
	 *
	 * @author	Konstantin Obenland
	 * @since	1.5 - 12.02.2012
	 * @access	protected
	 *
	 * @param	string	$option_name	Name of option to retrieve. Expected to not be SQL-escaped.
	 * @param	mixed	$value			Option value. Expected to not be SQL-escaped.
	 *
	 * @return	boolen	False if value was not updated and true if value was updated.
	 */
	protected function set_option( $option_name, $value ) {
		$options		=	$this->get_options();
		$options[$key]	=	$value;
		
		if ( is_plugin_active_for_network($this->plugin_name) ) {
			return update_site_option( $this->textdomain, $options );
		}
		
		return update_option( $this->textdomain, $options );
	}


	///////////////////////////////////////////////////////////////////////////
	// METHODS, PRIVATE
	///////////////////////////////////////////////////////////////////////////

	/**
	 * Retrieve options based on the environment.
	 *
	 * @author	Konstantin Obenland
	 * @since	1.5 - 12.02.2012
	 * @access	private
	 *
	 * @param	mixed	$default	Optional. Default value to return if the option does not exist.
	 * @param	boolean	$use_cache	Optional. Whether to use cache. Multisite only. Default true.
	 *
	 * @return	mixed	Value set for the option.
	 */
	private function get_options( $default = false, $use_cache = true ) {
		
		if ( is_plugin_active_for_network($this->plugin_name) ) {
			$options	=	get_site_option( $this->textdomain, $default, $use_cache );
		} else {
			$options	=	get_option( $this->textdomain, $default );
		}
		
		return	$options;
	}
	
	
	/**
	 * Sanitizes method names
	 *
	 * @author	Mark Jaquith
	 * @see		http://sliwww.slideshare.net/markjaquith/creating-and-maintaining-wordpress-plugins
	 * @since	1.5 - 12.02.2012
	 * @access	private
	 *
	 * @param	string	$method		Method name to be sanitized
	 *
	 * @return	string	Sanitized method name
	 */
	private function sanitize_method( $method ) {
		return str_replace( array( '.', '-' ), '_', $method );
	}

} // End of class Obenland_Wp_Plugins


/* End of file obenland-wp-plugins.php */
/* Location: ./wp-content/plugins/{obenland-plugin}/obenland-wp-plugins.php */