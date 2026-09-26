<?php
/**
 * Tool: delete_comment — Deletes a comment by ID.
 *
 * Port of mcp-wordpress wp_delete_comment tool.
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
 * Deletes a comment by ID, trashing it by default or permanently with force.
 */
class WP_MCP_AI_Tool_Delete_Comment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_comment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Comment', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a comment by ID, moving it to the trash by default or permanently removing it with force.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Removing a known comment ID, either by trashing it or permanently deleting it.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Changing a comment\'s moderation status; use update_comment instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_comment', 'list_comments', 'update_comment' ),
			'notes'           => __( 'comment_id comes from list_comments or get_comment. The default trash move is reversible; force=true permanently deletes the comment.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the comment to delete.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'force'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true, permanently deletes the comment instead of moving it to the trash.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'comment_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'comment_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'moderate_comments';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete comments.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'moderate_comments' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete comments.', 'mcp-ai-wpoos' ) );
		}

		$comment_id = isset( $arguments['comment_id'] ) ? absint( $arguments['comment_id'] ) : 0;
		$force      = isset( $arguments['force'] ) ? (bool) $arguments['force'] : false;

		if ( ! $comment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'comment_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! get_comment( $comment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_comment_not_found', __( 'The requested comment could not be found.', 'mcp-ai-wpoos' ) );
		}

		$result = wp_delete_comment( $comment_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_comment_delete_failed', __( 'The comment could not be deleted.', 'mcp-ai-wpoos' ) );
		}

		if ( $force ) {
			$message = sprintf(
				/* translators: %d: comment ID */
				__( 'Comment %d was permanently deleted.', 'mcp-ai-wpoos' ),
				$comment_id
			);
		} else {
			$message = sprintf(
				/* translators: %d: comment ID */
				__( 'Comment %d was moved to trash.', 'mcp-ai-wpoos' ),
				$comment_id
			);
		}

		return array(
			'message'    => $message,
			'comment_id' => $comment_id,
			'force'      => $force,
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
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'content_creator', 'editor' ),
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
			'requires-capability',  // Requires the moderate_comments capability.
			'state-changing',       // Modifies database state.
			'data-destruction',     // Permanently removes data beyond recovery when force is true.
		);
	}
}
