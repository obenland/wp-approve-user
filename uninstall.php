<?php
/**
 * Uninstall.
 *
 * @package WP Approve User
 */

// Don't uninstall unless you absolutely want to!
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	wp_die( 'WP_UNINSTALL_PLUGIN undefined.' );
}

delete_option( 'wp-approve-user' );

delete_metadata( 'user', 0, 'wp-approve-user', '', true );
delete_metadata( 'user', 0, 'wp-approve-user-mail-sent', '', true );
 delete_metadata( 'user', 0, 'wp-approve-user-new-registration', '', true );


/* Goodbye! Thank you for having me! */
