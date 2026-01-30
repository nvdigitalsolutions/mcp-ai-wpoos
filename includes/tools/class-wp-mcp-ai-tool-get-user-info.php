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
class WP_MCP_AI_Tool_Get_User_Info implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Get User Information', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns profile details for the specified WordPress user.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the user to inspect. Defaults to the current user.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to inspect users.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;
		if ( ! $user_id && isset( $context['user_id'] ) ) {
			$user_id = absint( $context['user_id'] );
		}

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_no_user', __( 'Unable to determine which user to load.', 'mcp-ai-wpoos' ) );
		}

		if ( $user_id !== $acting_user_id && ! user_can( $acting_user_id, 'list_users' ) && ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view other user profiles.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'The requested user does not belong to this site.', 'mcp-ai-wpoos' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The requested user could not be found.', 'mcp-ai-wpoos' ) );
		}

		$first_name = get_user_meta( $user_id, 'first_name', true );
		$last_name  = get_user_meta( $user_id, 'last_name', true );

		// Build descriptive text message for the LLM and chat UI.
		$text_parts   = array();
		$text_parts[] = sprintf(
			/* translators: 1: display name, 2: user ID */
			__( 'User: %1$s (ID: %2$d)', 'mcp-ai-wpoos' ),
			$user->display_name,
			$user->ID
		);

		if ( '' !== $first_name || '' !== $last_name ) {
			$full_name = trim( $first_name . ' ' . $last_name );
			if ( '' !== $full_name ) {
				$text_parts[] = sprintf(
					/* translators: %s: user's full name */
					__( 'Name: %s', 'mcp-ai-wpoos' ),
					$full_name
				);
			}
		}

		$text_parts[] = sprintf(
			/* translators: %s: user email */
			__( 'Email: %s', 'mcp-ai-wpoos' ),
			$user->user_email
		);

		if ( ! empty( $user->roles ) ) {
			$text_parts[] = sprintf(
				/* translators: %s: comma-separated list of roles */
				__( 'Roles: %s', 'mcp-ai-wpoos' ),
				implode( ', ', $user->roles )
			);
		}

		return array(
			'ID'           => $user->ID,
			'display_name' => $user->display_name,
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'message'      => implode( ' | ', $text_parts ), // Descriptive message for LLM and chat UI.
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'security_compliance',

			'pattern_compatibility' => array( 'layered_defense' ),

			'profession_tags'       => array( 'systems_administrator', 'security_analyst' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
