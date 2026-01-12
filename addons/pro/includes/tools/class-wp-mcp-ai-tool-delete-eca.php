<?php
/**
 * Tool for deleting ECAs (Extra-Curricular Activities).
 *
 * Allows AI assistants to delete ECAs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an ECA.
 */
class WP_MCP_AI_Tool_Delete_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete ECA', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes an Extra-Curricular Activity (ECA) permanently.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id' => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to delete (required)', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// ECA management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete ECAs.', 'wp-mcp-ai' ) );
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'ECA ID is required.', 'wp-mcp-ai' ) );
		}

		// Verify the post exists and is an ECA.
		$post = get_post( $eca_id );
		if ( ! $post || 'mcp_ai_eca' !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'ECA not found.', 'wp-mcp-ai' ) );
		}

		// Check if user can delete this post.
		if ( ! current_user_can( 'delete_post', $eca_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this ECA.', 'wp-mcp-ai' ) );
		}

		// Delete the post.
		$result = wp_delete_post( $eca_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete ECA.', 'wp-mcp-ai' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'ECA deleted successfully.', 'wp-mcp-ai' ),
			'eca_id'  => $eca_id,
		);
	}
}
