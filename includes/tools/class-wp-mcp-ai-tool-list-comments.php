<?php
/**
 * Tool: list_comments — Lists comments with optional filters.
 *
 * Port of mcp-wordpress wp_list_comments tool.
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
 * Lists comments with optional filtering by post, status, and search term.
 */
class WP_MCP_AI_Tool_List_Comments implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_comments';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Comments', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists comments with optional filtering by post, moderation status, and search term.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Browsing comments across the site, optionally filtered by post, moderation status, or a search term.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Fetching full details for a single known comment ID; use get_comment instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'analyze_comment_content', 'get_comment', 'update_comment', 'moderate_content' ),
			'notes'           => __( 'status accepts hold, approve, spam, or trash. Content previews are truncated to 200 characters; use get_comment for the full comment.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Optional: only list comments on this post.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'status'   => array(
					'type'        => 'string',
					'description' => __( 'Optional: only list comments with this moderation status.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'hold', 'approve', 'spam', 'trash' ),
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Optional: only list comments matching this search term.', 'mcp-ai-wpoos' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of comments per page (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number to retrieve.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'default'     => 1,
				),
			),
			'additionalProperties' => false,
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to list comments.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'moderate_comments' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list comments.', 'mcp-ai-wpoos' ) );
		}

		$post_id  = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$status   = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$search   = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$per_page = min( 100, max( 1, $per_page ) );
		$page     = max( 1, $page );

		$allowed_statuses = array( 'hold', 'approve', 'spam', 'trash' );
		if ( '' !== $status && ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_status', __( 'Invalid status. Allowed values: hold, approve, spam, trash.', 'mcp-ai-wpoos' ) );
		}

		$query_args = array(
			'number' => $per_page,
			'paged'  => $page,
		);

		if ( $post_id > 0 ) {
			$query_args['post_id'] = $post_id;
		}

		if ( '' !== $status ) {
			$query_args['status'] = $status;
		}

		if ( '' !== $search ) {
			$query_args['search'] = $search;
		}

		$query    = new WP_Comment_Query( $query_args );
		$comments = $query->get_comments();

		$total_query = new WP_Comment_Query( array_merge( $query_args, array( 'count' => true ) ) );
		$total       = absint( $total_query->get_comments() );

		$comments_list = array();
		foreach ( $comments as $comment ) {
			$comments_list[] = array(
				'id'           => absint( $comment->comment_ID ),
				'post_id'      => absint( $comment->comment_post_ID ),
				'author'       => esc_html( $comment->comment_author ),
				'author_email' => esc_html( $comment->comment_author_email ),
				'date'         => $comment->comment_date,
				'status'       => $this->map_comment_status( $comment->comment_approved ),
				'content'      => wp_html_excerpt( $comment->comment_content, 200 ),
			);
		}

		if ( 0 === $total ) {
			$message = __( 'No comments matched the requested filters.', 'mcp-ai-wpoos' );
		} else {
			$message = sprintf(
				/* translators: 1: number of comments returned, 2: total number of matching comments */
				__( 'Returned %1$d comment(s) of %2$d total.', 'mcp-ai-wpoos' ),
				count( $comments_list ),
				$total
			);
		}

		return array(
			'message'  => $message,
			'comments' => $comments_list,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
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
