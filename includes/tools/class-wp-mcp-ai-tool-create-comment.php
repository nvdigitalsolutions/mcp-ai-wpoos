<?php
/**
 * Tool: create_comment — Creates a new comment on a post.
 *
 * Port of mcp-wordpress wp_create_comment tool.
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
 * Creates a new comment on a post, optionally attributed to a registered user.
 */
class WP_MCP_AI_Tool_Create_Comment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_comment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Comment', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new comment on a post, optionally attributed to a registered user.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Adding a comment to a post on behalf of a visitor or a registered user.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Editing an existing comment or changing its moderation status; use update_comment instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_comments', 'get_comment', 'update_comment' ),
			'notes'           => __( 'Returns comment_id for chaining into get_comment or update_comment. Comments are created as approved; posts with comments closed still accept the comment, noted in the message.', 'mcp-ai-wpoos' ),
		);
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
					'description' => __( 'The ID of the post to comment on.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'content'      => array(
					'type'        => 'string',
					'description' => __( 'The comment content. Basic HTML is allowed.', 'mcp-ai-wpoos' ),
				),
				'author_name'  => array(
					'type'        => 'string',
					'description' => __( 'Optional: display name of the comment author. Defaults to the attributed user or the acting user.', 'mcp-ai-wpoos' ),
				),
				'author_email' => array(
					'type'        => 'string',
					'description' => __( 'Optional: email address of the comment author.', 'mcp-ai-wpoos' ),
				),
				'user_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Optional: attribute the comment to this registered user. Requires the moderate_comments capability and an existing user.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'post_id', 'content' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => 'comment_id',
			'consumes' => null,
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to create comments.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'moderate_comments' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create comments.', 'mcp-ai-wpoos' ) );
		}

		$post_id      = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$content      = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';
		$author_name  = isset( $arguments['author_name'] ) ? sanitize_text_field( $arguments['author_name'] ) : '';
		$author_email = isset( $arguments['author_email'] ) ? sanitize_email( $arguments['author_email'] ) : '';
		$user_id      = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;

		if ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'post_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'content is required.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'wp_mcp_ai_post_not_found', __( 'The requested post could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! post_type_supports( $post->post_type, 'comments' ) ) {
			return new WP_Error( 'wp_mcp_ai_comments_not_supported', __( 'This post type does not support comments.', 'mcp-ai-wpoos' ) );
		}

		// Only attribute the comment to a registered user when the acting user may
		// moderate comments and the referenced user actually exists.
		$attributed_user_id = 0;

		if ( $user_id > 0 ) {
			if ( ! user_can( $acting_user_id, 'moderate_comments' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to attribute comments to other users.', 'mcp-ai-wpoos' ) );
			}

			if ( ! get_userdata( $user_id ) ) {
				return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The user to attribute this comment to could not be found.', 'mcp-ai-wpoos' ) );
			}

			$attributed_user_id = $user_id;
		}

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => $content,
			'comment_author'       => $author_name,
			'comment_author_email' => $author_email,
			'user_id'              => $attributed_user_id,
			'comment_approved'     => 1,
		);

		$new_comment_id = wp_new_comment( $comment_data, true );

		if ( is_wp_error( $new_comment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_comment_create_failed',
				__( 'The comment could not be created.', 'mcp-ai-wpoos' ),
				array( 'reason' => $new_comment_id->get_error_message() )
			);
		}

		if ( ! $new_comment_id ) {
			return new WP_Error( 'wp_mcp_ai_comment_create_failed', __( 'The comment could not be created.', 'mcp-ai-wpoos' ) );
		}

		$created = get_comment( $new_comment_id );
		$summary = array(
			'author' => $created ? esc_html( $created->comment_author ) : esc_html( $author_name ),
			'status' => $created ? $this->map_comment_status( $created->comment_approved ) : 'approve',
		);

		$message = sprintf(
			/* translators: 1: comment author, 2: comment ID, 3: post ID */
			__( 'Comment by %1$s created (ID: %2$d) on post %3$d.', 'mcp-ai-wpoos' ),
			$summary['author'],
			absint( $new_comment_id ),
			$post_id
		);

		if ( ! comments_open( $post_id ) ) {
			$message .= ' ' . __( 'Note: comments are closed on this post, so the comment may not be publicly visible until they are re-opened.', 'mcp-ai-wpoos' );
		}

		return array(
			'message'    => $message,
			'comment_id' => absint( $new_comment_id ),
			'comment'    => $summary,
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
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates a comment.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires the moderate_comments capability.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone by deleting the comment.
		);
	}
}
