<?php
/**
 * Tool for deleting a WordPress post by ID.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes (trashes or permanently removes) a WordPress post.
 */
class WP_MCP_AI_Tool_Delete_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Post', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a WordPress post by ID. By default moves the post to the trash; set force_delete to true to permanently remove it.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'      => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post to delete.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'force_delete' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, permanently deletes the post instead of moving it to the trash. Requires the delete_posts capability.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
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
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete posts.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'post_id is required.', 'mcp-ai-wpoos' ) );
		}

		$post_id      = absint( $arguments['post_id'] );
		$force_delete = isset( $arguments['force_delete'] ) ? (bool) $arguments['force_delete'] : false;
		$post         = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'The requested post does not exist.', 'mcp-ai-wpoos' ) );
		}

		// Prevent deleting plugin-internal post types that should not be removed via this generic tool.
		$protected_post_types = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' );
		if ( in_array( $post->post_type, $protected_post_types, true ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'This post type cannot be deleted via this tool.', 'mcp-ai-wpoos' ) );
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The post type is not recognised.', 'mcp-ai-wpoos' ) );
		}

		// Check capability: delete_post (per-post) or the post type's delete_posts capability.
		$delete_cap = isset( $post_type_object->cap->delete_post ) ? $post_type_object->cap->delete_post : $post_type_object->cap->delete_posts;
		if ( ! user_can( $current_user_id, $delete_cap, $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this post.', 'mcp-ai-wpoos' ) );
		}

		$post_title = get_the_title( $post );
		$post_type  = $post->post_type;

		$result = wp_delete_post( $post_id, $force_delete );

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_delete_failed',
				$force_delete
					? __( 'The post could not be permanently deleted.', 'mcp-ai-wpoos' )
					: __( 'The post could not be moved to the trash.', 'mcp-ai-wpoos' )
			);
		}

		$action_label = $force_delete
			? __( 'permanently deleted', 'mcp-ai-wpoos' )
			: __( 'moved to trash', 'mcp-ai-wpoos' );

		$summary_text = sprintf(
			/* translators: 1: post title, 2: post ID, 3: action (e.g. "moved to trash") */
			__( 'Post %1$s (ID: %2$d) %3$s.', 'mcp-ai-wpoos' ),
			$post_title,
			$post_id,
			$action_label
		);

		return array(
			'message'       => $summary_text,
			'summary'       => $summary_text,
			'post_id'       => $post_id,
			'post_type'     => $post_type,
			'title'         => $post_title,
			'force_deleted' => $force_delete,
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
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'writer', 'content_creator', 'editor', 'developer' ),
			'risk_level'            => 'high',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Modifies state (deletes data).
			'local-only',           // No external API calls.
			'requires-capability',  // Requires delete_posts capability.
			'state-changing',       // Modifies database state.
			'destructive',          // May permanently remove data.
		);
	}
}
