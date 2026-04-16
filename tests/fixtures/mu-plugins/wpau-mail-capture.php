<?php
/**
 * Plugin Name: WPAU Mail Capture (test fixture)
 * Description: Captures outgoing mail to an option for the multisite Playwright suite. Dev only — never ships.
 * Author: WP Approve User test fixtures
 *
 * @package wp-approve-user
 */

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts wp_mail() and stores the args in a network-wide option without sending the email.
 *
 * @param null|bool $short_circuit Filter short-circuit value.
 * @param array     $atts          wp_mail() arguments.
 * @return bool Always true so wp_mail() reports success.
 */
function wpau_mail_capture_pre_wp_mail( $short_circuit, $atts ) {
	$captured   = get_site_option( 'wpau_captured_mail', array() );
	$captured[] = array(
		'to'      => isset( $atts['to'] ) ? (array) $atts['to'] : array(),
		'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
		'message' => isset( $atts['message'] ) ? (string) $atts['message'] : '',
		'time'    => time(),
	);
	update_site_option( 'wpau_captured_mail', $captured );

	return true;
}
add_filter( 'pre_wp_mail', 'wpau_mail_capture_pre_wp_mail', 10, 2 );
