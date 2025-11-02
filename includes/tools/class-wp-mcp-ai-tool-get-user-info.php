<?php
/**
 * Tool returning information about a WordPress user.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns basic information about a WordPress user.
 */
class WP_MCP_AI_Tool_Get_User_Info implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_user_info';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get User Information', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns profile details for the specified WordPress user.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the user to inspect. Defaults to the current user.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to inspect users.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;
		if ( ! $user_id && isset( $context['user_id'] ) ) {
			$user_id = absint( $context['user_id'] );
		}

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_no_user', __( 'Unable to determine which user to load.', 'wp-mcp-ai' ) );
		}

		if ( $user_id !== $acting_user_id && ! user_can( $acting_user_id, 'list_users' ) && ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view other user profiles.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'The requested user does not belong to this site.', 'wp-mcp-ai' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The requested user could not be found.', 'wp-mcp-ai' ) );
		}

		return array(
			'ID'           => $user->ID,
			'display_name' => $user->display_name,
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
			'first_name'   => get_user_meta( $user_id, 'first_name', true ),
			'last_name'    => get_user_meta( $user_id, 'last_name', true ),
		);
	}
}
