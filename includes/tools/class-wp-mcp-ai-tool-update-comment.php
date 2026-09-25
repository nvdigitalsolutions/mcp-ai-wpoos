<?php
/**
 * Tool: update_comment — Updates a comment's content or status.
 *
 * Port of mcp-wordpress wp_update_comment tool.
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
 * Updates an existing comment's content or moderation status.
 */
class WP_MCP_AI_Tool_Update_Comment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_comment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Comment', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing comment\'s content or moderation status, including approving and spamming.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Editing comment content or changing a comment\'s moderation status.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Removing a comment entirely; use delete_comment instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_comment', 'list_comments', 'delete_comment' ),
			'notes'           => __( 'Covers approving and spamming comments via the status parameter. status accepts hold, approve, spam, or trash.', 'mcp-ai-wpoos' ),
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
					'description' => __( 'The ID of the comment to update.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'content'    => array(
					'type'        => 'string',
					'description' => __( 'Optional: new comment content. Basic HTML is allowed.', 'mcp-ai-wpoos' ),
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'Optional: new moderation status.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'hold', 'approve', 'spam', 'trash' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update comments.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'moderate_comments' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update comments.', 'mcp-ai-wpoos' ) );
		}

		$comment_id = isset( $arguments['comment_id'] ) ? absint( $arguments['comment_id'] ) : 0;
		$content    = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';
		$status     = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';

		if ( ! $comment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'comment_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( '' === trim( $content ) && '' === $status ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'Provide at least one of content or status to update.', 'mcp-ai-wpoos' ) );
		}

		$allowed_statuses = array( 'hold', 'approve', 'spam', 'trash' );
		if ( '' !== $status && ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_status', __( 'Invalid status. Allowed values: hold, approve, spam, trash.', 'mcp-ai-wpoos' ) );
		}

		if ( ! get_comment( $comment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_comment_not_found', __( 'The requested comment could not be found.', 'mcp-ai-wpoos' ) );
		}

		$comment_data = array( 'comment_ID' => $comment_id );

		if ( '' !== trim( $content ) ) {
			$comment_data['comment_content'] = $content;
		}

		if ( '' !== $status ) {
			$comment_data['comment_approved'] = $this->map_status_to_approved( $status );
		}

		$result = wp_update_comment( $comment_data );

		if ( 0 === $result ) {
			return new WP_Error( 'wp_mcp_ai_comment_not_found', __( 'The requested comment could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( false === $result ) {
			return new WP_Error( 'wp_mcp_ai_comment_update_failed', __( 'The comment could not be updated.', 'mcp-ai-wpoos' ) );
		}

		$updated = get_comment( $comment_id );

		if ( '' !== $status ) {
			$final_status = $status;
		} else {
			$final_status = $updated ? $this->map_comment_status( $updated->comment_approved ) : 'approve';
		}

		if ( '' !== $status ) {
			$message = sprintf(
				/* translators: 1: comment ID, 2: new comment status */
				__( 'Comment %1$d updated with new status %2$s.', 'mcp-ai-wpoos' ),
				$comment_id,
				$final_status
			);
		} else {
			$message = sprintf(
				/* translators: 1: comment ID, 2: current comment status */
				__( 'Comment %1$d content updated (status: %2$s).', 'mcp-ai-wpoos' ),
				$comment_id,
				$final_status
			);
		}

		return array(
			'message'    => $message,
			'comment_id' => $comment_id,
			'status'     => $final_status,
		);
	}

	/**
	 * Maps a friendly status slug to the stored comment_approved value.
	 *
	 * @param string $status One of hold, approve, spam, or trash.
	 * @return string|int Stored comment_approved value.
	 */
	private function map_status_to_approved( $status ) {
		switch ( $status ) {
			case 'hold':
				return 0;
			case 'approve':
				return 1;
			case 'spam':
				return 'spam';
			case 'trash':
			default:
				return 'trash';
		}
	}

	/**
	 * Maps a stored comment_approved value to a friendly status slug.
	 *
	 * @param string $approved Raw comment_approved value from the database.
	 * @return string One of hold, approve, spam, or trash.
	 */
	private function map_comment_status( $approved ) {
		switch ( (string) $approved ) {
			case '1':
				return 'approve';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			case '0':
			default:
				return 'hold';
		}
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
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Modifies comment content or status.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires the moderate_comments capability.
			'state-changing',       // Modifies database state.
			'reversible',           // Status and content changes can be undone.
		);
	}
}
