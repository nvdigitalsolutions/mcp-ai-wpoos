<?php
/**
 * Tool for creating new WordPress posts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new WordPress post.
 *
 * This is a simplified version of save_post that only handles
 * post creation, not updates. Use save_post for update operations.
 */
class WP_MCP_AI_Tool_Create_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Post', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new WordPress post. For updating existing posts, use save_post instead.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'Title of the post.', 'wp-mcp-ai' ),
				),
				'content'   => array(
					'type'        => 'string',
					'description' => __( 'Main content for the post.', 'wp-mcp-ai' ),
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'The post type to create.', 'wp-mcp-ai' ),
					'default'     => 'post',
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'The status to assign to the post (publish, draft, pending, or private).', 'wp-mcp-ai' ),
					'default'     => 'draft',
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
				),
				'user_id'   => array(
					'type'        => 'integer',
					'description' => __( 'The user ID to set as the post author. Defaults to current user.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'title', 'content' ),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create posts.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize inputs.
		$title   = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$content = isset( $arguments['content'] ) ? $arguments['content'] : '';

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Post title is required.', 'wp-mcp-ai' ) );
		}

		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_content', __( 'Post content is required.', 'wp-mcp-ai' ) );
		}

		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		// Validate post type exists.
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'wp-mcp-ai' ) );
		}

		// Check if user can create posts of this type.
		$create_cap = isset( $post_type_object->cap->create_posts ) ? $post_type_object->cap->create_posts : $post_type_object->cap->edit_posts;

		if ( ! user_can( $current_user_id, $create_cap ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create posts of this type.', 'wp-mcp-ai' ) );
		}

		// Determine the author ID.
		$author_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : $current_user_id;

		// Validate author exists and has edit_posts capability.
		if ( $author_id !== $current_user_id ) {
			$author = get_userdata( $author_id );
			if ( ! $author ) {
				return new WP_Error( 'wp_mcp_ai_invalid_user', __( 'The specified user does not exist.', 'wp-mcp-ai' ) );
			}

			if ( ! user_can( $author_id, 'edit_posts' ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_author', __( 'The specified user does not have permission to author posts.', 'wp-mcp-ai' ) );
			}
		}

		// Sanitize content.
		$sanitized_content = wp_kses_post( $content );

		// Convert to blocks if needed for standard posts.
		if ( 'post' === $post_type ) {
			$sanitized_content = $this->ensure_post_content_uses_blocks( $sanitized_content, $content );
		}

		// Prepare post data.
		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $sanitized_content,
			'post_author'  => $author_id,
		);

		// Set post status.
		$status                  = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'draft';
		$valid_statuses          = array( 'publish', 'draft', 'pending', 'private' );
		$post_data['post_status'] = in_array( $status, $valid_statuses, true ) ? $status : 'draft';

		// Create the post.
		$result = wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$created_post = get_post( $result );
		if ( ! $created_post ) {
			return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The post was created but could not be retrieved.', 'wp-mcp-ai' ) );
		}

		$response = array(
			'summary'   => sprintf(
				/* translators: 1: post title, 2: post ID */
				__( 'Post created: %1$s (ID: %2$d)', 'wp-mcp-ai' ),
				get_the_title( $created_post ),
				$created_post->ID
			),
			'ID'        => $created_post->ID,
			'title'     => get_the_title( $created_post ),
			'status'    => get_post_status( $created_post ),
			'post_type' => $created_post->post_type,
			'author_id' => $created_post->post_author,
			'permalink' => get_permalink( $created_post ),
		);

		$edit_link = get_edit_post_link( $created_post->ID, '' );
		if ( $edit_link ) {
			$response['edit_link'] = $edit_link;
		}

		return $response;
	}

	/**
	 * Ensures post content uses block markup when working with the core `post` post type.
	 *
	 * @param string $sanitized_content The sanitized post content.
	 * @param string $raw_content       The raw post content, prior to sanitization.
	 *
	 * @return string
	 */
	private function ensure_post_content_uses_blocks( $sanitized_content, $raw_content ) {
		if ( $this->content_contains_blocks( $raw_content ) || $this->content_contains_blocks( $sanitized_content ) ) {
			return $sanitized_content;
		}

		$sanitized_content = trim( $sanitized_content );

		if ( '' === $sanitized_content ) {
			return $sanitized_content;
		}

		$is_plain_text = ( false === strpos( $sanitized_content, '<' ) );

		if ( $is_plain_text ) {
			return $this->convert_plain_text_to_paragraph_blocks( $sanitized_content );
		}

		return sprintf(
			"<!-- wp:html -->\n%s\n<!-- /wp:html -->",
			$sanitized_content
		);
	}

	/**
	 * Determines whether a piece of content already contains block markup.
	 *
	 * @param string $content The content to evaluate.
	 *
	 * @return bool
	 */
	private function content_contains_blocks( $content ) {
		if ( '' === $content ) {
			return false;
		}

		if ( false !== strpos( $content, '<!-- wp:' ) ) {
			return true;
		}

		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Converts plain text content into a sequence of core paragraph blocks.
	 *
	 * @param string $content Plain text content.
	 *
	 * @return string
	 */
	private function convert_plain_text_to_paragraph_blocks( $content ) {
		$normalized_content = preg_replace( "/\r\n?/", "\n", $content );
		$paragraphs         = preg_split( "/\n{2,}/", trim( $normalized_content ) );
		$blocks             = array();

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				continue;
			}

			$paragraph = str_replace( "\n", "<br />\n", $paragraph );

			$blocks[] = sprintf(
				"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
				$paragraph
			);
		}

		if ( empty( $blocks ) ) {
			return "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates posts.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires post creation capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone by deleting the post.
		);
	}
}
