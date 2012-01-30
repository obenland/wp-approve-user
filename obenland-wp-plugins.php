<?php
/** obenland-wp-plugins.php
 *
 * @author		Konstantin Obenland
 * @version		1.4
 */


class Obenland_Wp_Plugins {
	
	
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
		$this->plugin_name	=	$args['plugin_name'];
		
		$this->set_donate_link( $args['donate_link_id'] );
		
		$plugin_folder		=	str_replace(
			basename($this->plugin_name),
			"",
			$this->plugin_name
		);
		$this->plugin_path	=	trailingslashit( WP_PLUGIN_DIR ) . $plugin_folder;
		
		
		// Add actions and filters
		add_action( 'plugin_row_meta', array(
			&$this,
			'plugin_meta_donate'
		), 10, 2 );
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
	public function plugin_meta_donate( $plugin_meta, $plugin_file ) {
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
	 * @param	string	$option		Name of option to retrieve. Expected to not be SQL-escaped.
	 * @param	mixed	$default	Optional. Default value to return if the option does not exist.
	 *
	 * @return	mixed	Value set for the option.
	 */
	protected function get_option( $option, $default = false, $use_cache = true ) {
		if ( is_plugin_active_for_network( $this->plugin_name ) ) {
			$return = get_site_option( $option_name, $default, $use_cache );
		} else {
			$return = get_option( $option_name, $default );
		}
		return $return;
	}
	
} // End of class Obenland_Wp_Plugins


/* End of file obenland-wp-plugins.php */
/* Location: ./wp-content/plugins/{obenland-plugin}/obenland-wp-plugins.php */