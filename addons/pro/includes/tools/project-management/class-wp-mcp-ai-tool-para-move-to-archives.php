<?php
/**
 * Tool: para_move_to_archives
 *
 * Convenience tool that moves a post to the Archives bucket with an audit reason.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move a post to PARA archives.
 */
class WP_MCP_AI_Tool_PARA_Move_To_Archives implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'para_move_to_archives';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'PARA: Move to Archives', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Archive a project, task, event, area, or document by moving it to the PARA Archives bucket. A reason string is recorded for audit purposes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to archive.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'reason'  => array(
					'type'        => 'string',
					'description' => __( 'Reason for archiving (required for audit trail).', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 500,
				),
			),
			'required'             => array( 'post_id', 'reason' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'reversible' );
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) && WP_MCP_AI_PARA_Taxonomy::is_enabled();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to archive items.', 'mcp-ai-wpoos-pro' ) );
		}
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$reason  = isset( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '';
		if ( ! $post_id || '' === $reason ) {
			return new WP_Error( 'wp_mcp_ai_missing_arg', __( 'post_id and reason are required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You cannot edit this post.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_PARA_Taxonomy::assign( $post_id, 'archives', $reason );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'success' => true,
			'post_id' => $post_id,
			'bucket'  => 'archives',
			'message' => __( 'Item archived.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
