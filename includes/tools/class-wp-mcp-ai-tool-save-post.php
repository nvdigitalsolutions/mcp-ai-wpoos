<?php
/**
 * Tool that creates or updates WordPress posts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new post or updates an existing one.
 */
class WP_MCP_AI_Tool_Save_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'save_post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create or Update Post', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new post or updates an existing one with the supplied content.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Existing post ID to update. Leave empty to create a new post.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'The post type to create or update.', 'wp-mcp-ai' ),
					'default'     => 'post',
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'Title of the post.', 'wp-mcp-ai' ),
				),
				'content'   => array(
					'type'        => 'string',
					'description' => __( 'Main content for the post.', 'wp-mcp-ai' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'The status to assign to the post, e.g. draft or publish.', 'wp-mcp-ai' ),
					'default'     => 'draft',
				),
				'excerpt'   => array(
					'type'        => 'string',
					'description' => __( 'Optional excerpt for the post.', 'wp-mcp-ai' ),
				),
				'slug'      => array(
					'type'        => 'string',
					'description' => __( 'Optional slug to use for the post permalink.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'content' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage posts.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : '';

		$post = null;
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'wp_mcp_ai_invalid_post', __( 'The specified post could not be found.', 'wp-mcp-ai' ) );
			}

			if ( '' === $post_type ) {
				$post_type = $post->post_type;
			} elseif ( $post->post_type !== $post_type ) {
				return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not match the existing post.', 'wp-mcp-ai' ) );
			}

			if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this post.', 'wp-mcp-ai' ) );
			}
		} else {
			if ( '' === $post_type ) {
				$post_type = 'post';
			}

			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'wp-mcp-ai' ) );
			}

			$create_cap = isset( $post_type_object->cap->create_posts ) ? $post_type_object->cap->create_posts : $post_type_object->cap->edit_posts;

			if ( ! user_can( $user_id, $create_cap ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create posts of this type.', 'wp-mcp-ai' ) );
			}
		}

		$post_type_object = isset( $post_type_object ) ? $post_type_object : get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'wp-mcp-ai' ) );
		}

		$raw_content = isset( $arguments['content'] ) ? $arguments['content'] : '';
		$content     = wp_kses_post( $raw_content );
		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_content', __( 'Post content is required.', 'wp-mcp-ai' ) );
		}

		if ( 'post' === $post_type ) {
			$content = $this->ensure_post_content_uses_blocks( $content, $raw_content );
		}

		$post_data = array(
			'post_type'    => $post_type,
			'post_content' => $content,
		);

		if ( $post_id > 0 ) {
			$post_data['ID'] = $post_id;
		} else {
			$post_data['post_author'] = $user_id;
		}

		if ( isset( $arguments['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['title'] );
		} elseif ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'A title is required when creating a new post.', 'wp-mcp-ai' ) );
		}

		if ( isset( $arguments['excerpt'] ) ) {
			$post_data['post_excerpt'] = wp_kses_post( $arguments['excerpt'] );
		}

		if ( isset( $arguments['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $arguments['slug'] );
		}

		$status = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		if ( '' !== $status ) {
			$valid_statuses = get_post_stati();
			if ( in_array( $status, $valid_statuses, true ) ) {
				$post_data['post_status'] = $status;
			}
		} elseif ( $post_id > 0 && $post ) {
			$post_data['post_status'] = $post->post_status;
		} elseif ( ! $post_id ) {
			$post_data['post_status'] = 'draft';
		}

		if ( isset( $post_data['ID'] ) ) {
			$result = wp_update_post( wp_slash( $post_data ), true );
		} else {
			$result = wp_insert_post( wp_slash( $post_data ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated_post = get_post( $result );
		if ( ! $updated_post ) {
			return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The post was saved but could not be retrieved.', 'wp-mcp-ai' ) );
		}

		$response = array(
			'summary'   => sprintf(
				/* translators: 1: post ID, 2: post title */
				__( 'Post saved: %1$s (ID: %2$d)', 'wp-mcp-ai' ),
				get_the_title( $updated_post ),
				$updated_post->ID
			),
			'ID'        => $updated_post->ID,
			'title'     => get_the_title( $updated_post ),
			'status'    => get_post_status( $updated_post ),
			'post_type' => $updated_post->post_type,
			'permalink' => get_permalink( $updated_post ),
		);

		$edit_link = get_edit_post_link( $updated_post->ID, '' );
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
			'write',                // Creates or updates posts.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires post editing capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone via post revisions.
		);
	}
}
