<?php
/**
 * Tool: delete_user — Deletes a WordPress user.
 *
 * Port of mcp-wordpress wp_delete_user tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a WordPress user, optionally reassigning their content to another user.
 */
class WP_MCP_AI_Tool_Delete_User implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_user';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete User', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a WordPress user by ID, optionally reassigning their content to another user.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Removing a known user ID, optionally reassigning their content to another user first.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Disabling an account temporarily; use update_user to change roles instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_users', 'get_user_info', 'update_user', 'create_user' ),
			'notes'           => __( 'user_id comes from list_users or create_user. You cannot delete your own account. Content is reassigned to the reassign user when provided, otherwise it is removed.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the user to delete.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'reassign' => array(
					'type'        => 'integer',
					'description' => __( 'Optional: user ID to receive the deleted user\'s content.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'user_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'delete_users';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete users.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'delete_users' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete users.', 'mcp-ai-wpoos' ) );
		}

		$user_id  = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;
		$reassign = isset( $arguments['reassign'] ) ? absint( $arguments['reassign'] ) : 0;

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'user_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( $user_id === $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_cannot_delete_self', __( 'You cannot delete your own account via this tool.', 'mcp-ai-wpoos' ) );
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The requested user could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( $reassign > 0 ) {
			if ( $reassign === $user_id ) {
				return new WP_Error( 'wp_mcp_ai_invalid_reassign', __( 'The reassign user must be different from the user being deleted.', 'mcp-ai-wpoos' ) );
			}

			if ( ! get_userdata( $reassign ) ) {
				return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The reassign user could not be found.', 'mcp-ai-wpoos' ) );
			}
		}

		$result = wp_delete_user( $user_id, $reassign );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_user_delete_failed', __( 'The user could not be deleted.', 'mcp-ai-wpoos' ) );
		}

		if ( $reassign > 0 ) {
			$message = sprintf(
				/* translators: 1: deleted user ID, 2: reassign user ID */
				__( 'User %1$d deleted; content reassigned to user %2$d.', 'mcp-ai-wpoos' ),
				$user_id,
				$reassign
			);
		} else {
			$message = sprintf(
				/* translators: %d: deleted user ID */
				__( 'User %d deleted.', 'mcp-ai-wpoos' ),
				$user_id
			);
		}

		return array(
			'message'  => $message,
			'user_id'  => $user_id,
			'reassign' => $reassign,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'security_compliance',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'systems_administrator', 'security_analyst' ),
			'risk_level'            => 'high',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Modifies state (deletes a user).
			'local-only',           // No external API calls.
			'requires-capability',  // Requires the delete_users capability.
			'state-changing',       // Modifies database state.
			'data-destruction',     // Permanently removes user data beyond recovery.
		);
	}
}
