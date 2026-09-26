<?php
/**
 * Tool: get_comment — Returns a single comment by ID.
 *
 * Port of mcp-wordpress wp_get_comment tool.
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
 * Returns full details for a single comment by ID.
 */
class WP_MCP_AI_Tool_Get_Comment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_comment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Comment', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns full details for a single comment by ID.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Fetching full details for a known comment ID, including content, author, and moderation status.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Browsing many comments or filtering by post/status; use list_comments instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_comments', 'update_comment', 'delete_comment' ),
			'notes'           => __( 'comment_id comes from list_comments or create_comment responses.', 'mcp-ai-wpoos' ),
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
					'description' => __( 'The ID of the comment to retrieve.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view comments.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'moderate_comments' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view comments.', 'mcp-ai-wpoos' ) );
		}

		$comment_id = isset( $arguments['comment_id'] ) ? absint( $arguments['comment_id'] ) : 0;

		if ( ! $comment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'comment_id is required.', 'mcp-ai-wpoos' ) );
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error( 'wp_mcp_ai_comment_not_found', __( 'The requested comment could not be found.', 'mcp-ai-wpoos' ) );
		}

		$message = sprintf(
			/* translators: 1: comment author, 2: comment ID */
			__( 'Comment by %1$s (ID: %2$d).', 'mcp-ai-wpoos' ),
			$comment->comment_author,
			$comment_id
		);

		return array(
			'message'    => $message,
			'comment_id' => $comment_id,
			'comment'    => array(
				'id'           => absint( $comment->comment_ID ),
				'post_id'      => absint( $comment->comment_post_ID ),
				'author'       => esc_html( $comment->comment_author ),
				'author_email' => esc_html( $comment->comment_author_email ),
				'author_url'   => esc_url_raw( $comment->comment_author_url ),
				'date'         => $comment->comment_date,
				'date_gmt'     => $comment->comment_date_gmt,
				'content'      => esc_html( $comment->comment_content ),
				'status'       => $this->map_comment_status( $comment->comment_approved ),
				'parent'       => absint( $comment->comment_parent ),
				'user_id'      => absint( $comment->user_id ),
				'type'         => esc_html( $comment->comment_type ),
			),
		);
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
			'requires-capability',  // Requires the moderate_comments capability.
		);
	}
}
