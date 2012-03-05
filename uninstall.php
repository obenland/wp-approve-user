<?php
//Don't uninstall unless you absolutely want to!
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	wp_die( 'WP_UNINSTALL_PLUGIN undefined.' );
}

$user_ids = get_users(array(
	'blog_id'	=>	'',
	'fields'	=>	'ID'
));

foreach ( $user_ids as $user_id ) {
	delete_user_meta( $user_id, 'wp-approve-user' );
}


/* Goodbye! Thank you for having me! */


/* End of file uninstall.php */
/* Location: ./wp-content/plugins/wp-approve-user/uninstall.php */