<?php
/**
 * Tool for deleting allergies.
 *
 * Allows AI assistants to delete allergy records from the health wellness system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an allergy record.
 */
class WP_MCP_AI_Tool_Delete_Allergy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_allergy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Allergy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes an allergy record from the health and wellness system. Only the allergy creator or users with delete_others_posts capability can delete allergy records.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'allergy_id' => array(
					'type'        => 'integer',
					'description' => __( 'Allergy ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'force'      => array(
					'type'        => 'boolean',
					'description' => __( 'Force permanent deletion (bypass trash). Default: false', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'allergy_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete allergy records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$allergy_id = isset( $arguments['allergy_id'] ) ? absint( $arguments['allergy_id'] ) : 0;
		$force      = isset( $arguments['force'] ) ? (bool) $arguments['force'] : false;

		if ( ! $allergy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_allergy_id', __( 'Allergy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify allergy exists.
		$allergy = get_post( $allergy_id );

		if ( ! $allergy || 'mcp_ai_allergy' !== $allergy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_allergy_not_found', __( 'Allergy record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author = absint( $allergy->post_author ) === $current_user_id;
		$can_delete_others = user_can( $current_user_id, 'delete_others_posts' );

		if ( ! $is_author && ! $can_delete_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this allergy record.', 'mcp-ai-wpoos-pro' ) );
		}

		// Delete the allergy.
		$result = wp_delete_post( $allergy_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete allergy record.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'    => true,
			'allergy_id' => $allergy_id,
			'message'    => sprintf(
				/* translators: 1: allergen name, 2: action (deleted/trashed) */
				__( 'Allergy record for "%1$s" has been %2$s.', 'mcp-ai-wpoos-pro' ),
				$allergy->post_title,
				$force ? __( 'permanently deleted', 'mcp-ai-wpoos-pro' ) : __( 'moved to trash', 'mcp-ai-wpoos-pro' )
			),
		);
	}
}
