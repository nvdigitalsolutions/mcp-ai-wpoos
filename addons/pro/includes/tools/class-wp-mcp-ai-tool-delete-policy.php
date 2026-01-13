<?php
/**
 * Tool for deleting insurance policies.
 *
 * Allows AI assistants to delete policies from the health wellness system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an insurance policy.
 */
class WP_MCP_AI_Tool_Delete_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_policy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Policy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes an insurance policy from the health and wellness system. Only the policy creator or users with delete_others_posts capability can delete policies.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'policy_id' => array(
					'type'        => 'integer',
					'description' => __( 'Policy ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'force'     => array(
					'type'        => 'boolean',
					'description' => __( 'Force permanent deletion (bypass trash). Default: false', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'policy_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'destructive' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete policies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$policy_id = isset( $arguments['policy_id'] ) ? absint( $arguments['policy_id'] ) : 0;
		$force     = isset( $arguments['force'] ) ? (bool) $arguments['force'] : false;

		if ( ! $policy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_policy_id', __( 'Policy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify policy exists.
		$policy = get_post( $policy_id );

		if ( ! $policy || 'mcp_ai_policy' !== $policy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_policy_not_found', __( 'Policy not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author         = absint( $policy->post_author ) === $current_user_id;
		$can_delete_others = user_can( $current_user_id, 'delete_others_posts' );

		if ( ! $is_author && ! $can_delete_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this policy.', 'mcp-ai-wpoos-pro' ) );
		}

		// Delete the policy.
		$result = wp_delete_post( $policy_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete policy.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'   => true,
			'policy_id' => $policy_id,
			'message'   => sprintf(
				/* translators: 1: policy name, 2: action (deleted/trashed) */
				__( 'Policy "%1$s" has been %2$s.', 'mcp-ai-wpoos-pro' ),
				$policy->post_title,
				$force ? __( 'permanently deleted', 'mcp-ai-wpoos-pro' ) : __( 'moved to trash', 'mcp-ai-wpoos-pro' )
			),
		);
	}
}
