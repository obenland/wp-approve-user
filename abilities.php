<?php
/**
 * WordPress Abilities API integration.
 *
 * Registers `wp-approve-user/approve` and `wp-approve-user/unapprove` abilities
 * so third parties (plugins, agents, REST clients) can flip a user's approval
 * state through the core Abilities API shipped in WordPress 6.9.
 *
 * See https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/
 *
 * @package WP Approve User
 */

/**
 * Registers the approve/unapprove abilities with the core registry.
 *
 * @since 13
 */
function wpau_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$input_schema = array(
		'type'       => 'object',
		'properties' => array(
			'user_id' => array(
				'type'        => 'integer',
				'description' => __( 'The ID of the user to update.', 'wp-approve-user' ),
				'minimum'     => 1,
			),
		),
		'required'   => array( 'user_id' ),
	);

	$output_schema = array(
		'type'       => 'object',
		'properties' => array(
			'success' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the operation completed successfully.', 'wp-approve-user' ),
			),
			'user_id' => array(
				'type'        => 'integer',
				'description' => __( 'The ID of the user that was updated.', 'wp-approve-user' ),
			),
			'status'  => array(
				'type'        => 'string',
				'description' => __( 'The resulting approval status for the user.', 'wp-approve-user' ),
				'enum'        => array( 'approved', 'unapproved', 'pending' ),
			),
		),
		'required'   => array( 'success', 'user_id', 'status' ),
	);

	wp_register_ability(
		'wp-approve-user/approve',
		array(
			'label'               => __( 'Approve user', 'wp-approve-user' ),
			'description'         => __( 'Marks a user as approved so they can log in to the site.', 'wp-approve-user' ),
			'category'            => 'user-management',
			'input_schema'        => $input_schema,
			'output_schema'       => $output_schema,
			'permission_callback' => 'wpau_ability_permission_callback',
			'execute_callback'    => 'wpau_ability_approve_callback',
			'meta'                => array(
				'show_in_rest' => true,
			),
		)
	);

	wp_register_ability(
		'wp-approve-user/unapprove',
		array(
			'label'               => __( 'Unapprove user', 'wp-approve-user' ),
			'description'         => __( 'Marks a user as unapproved so they can no longer log in to the site.', 'wp-approve-user' ),
			'category'            => 'user-management',
			'input_schema'        => $input_schema,
			'output_schema'       => $output_schema,
			'permission_callback' => 'wpau_ability_permission_callback',
			'execute_callback'    => 'wpau_ability_unapprove_callback',
			'meta'                => array(
				'show_in_rest' => true,
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'wpau_register_abilities' );

/**
 * Permission callback shared by both approve/unapprove abilities.
 *
 * @since 13
 *
 * @return true|WP_Error True when the current user may promote users, WP_Error otherwise.
 */
function wpau_ability_permission_callback() {
	if ( current_user_can( 'promote_users' ) ) {
		return true;
	}

	return new WP_Error(
		'wpau_rest_forbidden',
		__( 'Sorry, you are not allowed to change user approval status.', 'wp-approve-user' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Execute callback for the approve ability.
 *
 * Updates the three-state meta to `approved` and fires the `wpau_approve`
 * action so existing side-effects (approval email, logging, etc.) still run.
 *
 * @since 13
 *
 * @param array $input Input arguments validated against the input schema.
 * @return array|WP_Error Result payload or a WP_Error on failure.
 */
function wpau_ability_approve_callback( $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

	if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
		return new WP_Error(
			'wpau_invalid_user',
			__( 'The specified user does not exist.', 'wp-approve-user' ),
			array( 'status' => 404 )
		);
	}

	update_user_meta( $user_id, 'wp-approve-user', 'approved' );

	/** This action is documented in class-obenland-wp-approve-user.php */
	do_action( 'wpau_approve', $user_id );

	return array(
		'success' => true,
		'user_id' => $user_id,
		'status'  => 'approved',
	);
}

/**
 * Execute callback for the unapprove ability.
 *
 * Updates the three-state meta to `unapproved` and fires the `wpau_unapprove`
 * action so existing side-effects (rejection email, logging, etc.) still run.
 *
 * @since 13
 *
 * @param array $input Input arguments validated against the input schema.
 * @return array|WP_Error Result payload or a WP_Error on failure.
 */
function wpau_ability_unapprove_callback( $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

	if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
		return new WP_Error(
			'wpau_invalid_user',
			__( 'The specified user does not exist.', 'wp-approve-user' ),
			array( 'status' => 404 )
		);
	}

	update_user_meta( $user_id, 'wp-approve-user', 'unapproved' );

	/** This action is documented in class-obenland-wp-approve-user.php */
	do_action( 'wpau_unapprove', $user_id );

	return array(
		'success' => true,
		'user_id' => $user_id,
		'status'  => 'unapproved',
	);
}
