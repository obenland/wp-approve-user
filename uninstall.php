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

$user_ids = get_users( array(
	'blog_id' => '',
	'fields'  => 'ID',
) );

foreach ( $user_ids as $user_id ) {
	delete_user_meta( $user_id, 'wp-approve-user' );
	delete_user_meta( $user_id, 'wp-approve-user-mail-sent' );
}

delete_option( 'wp-approve-user' );


/* Goodbye! Thank you for having me! */
