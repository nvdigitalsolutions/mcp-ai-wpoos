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
				'title'              => array(
					'type'        => 'string',
					'description' => __( 'Title of the post.', 'wp-mcp-ai' ),
				),
				'content'            => array(
					'type'        => 'string',
					'description' => __( 'Main content for the post.', 'wp-mcp-ai' ),
				),
				'post_type'          => array(
					'type'        => 'string',
					'description' => __( 'The post type to create.', 'wp-mcp-ai' ),
					'default'     => 'post',
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'The status to assign to the post (publish, draft, pending, or private).', 'wp-mcp-ai' ),
					'default'     => 'draft',
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
				),
				'user_id'            => array(
					'type'        => 'integer',
					'description' => __( 'The user ID to set as the post author. Defaults to current user.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'excerpt'            => array(
					'type'        => 'string',
					'description' => __( 'Optional excerpt for the post.', 'wp-mcp-ai' ),
				),
				'slug'               => array(
					'type'        => 'string',
					'description' => __( 'Optional slug to use for the post permalink.', 'wp-mcp-ai' ),
				),
				'featured_image_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Attachment ID to set as the featured image.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'categories'         => array(
					'type'        => 'array',
					'description' => __( 'Array of category IDs or names to assign to the post.', 'wp-mcp-ai' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'integer', 'minimum' => 1 ),
							array( 'type' => 'string' ),
						),
					),
				),
				'tags'               => array(
					'type'        => 'array',
					'description' => __( 'Array of tag IDs or names to assign to the post.', 'wp-mcp-ai' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'integer', 'minimum' => 1 ),
							array( 'type' => 'string' ),
						),
					),
				),
				'page_template'      => array(
					'type'        => 'string',
					'description' => __( 'Page template filename (e.g., "template-full-width.php"). Only applies to pages and custom post types that support page templates.', 'wp-mcp-ai' ),
				),
				'post_parent'        => array(
					'type'        => 'integer',
					'description' => __( 'ID of the parent post for hierarchical post types (e.g., pages).', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'menu_order'         => array(
					'type'        => 'integer',
					'description' => __( 'Menu order for sorting hierarchical post types.', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'comment_status'     => array(
					'type'        => 'string',
					'description' => __( 'Whether to allow comments (open or closed).', 'wp-mcp-ai' ),
					'enum'        => array( 'open', 'closed' ),
				),
				'ping_status'        => array(
					'type'        => 'string',
					'description' => __( 'Whether to allow pingbacks and trackbacks (open or closed).', 'wp-mcp-ai' ),
					'enum'        => array( 'open', 'closed' ),
				),
				'meta_input'         => array(
					'type'        => 'object',
					'description' => __( 'Array of custom field key-value pairs to set as post meta.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
				'elementor_data'     => array(
					'type'        => 'object',
					'description' => __( 'Elementor page builder data (requires Elementor plugin).', 'wp-mcp-ai' ),
					'properties'  => array(
						'template_type' => array(
							'type'        => 'string',
							'description' => __( 'Elementor template type (page, header, footer, section, etc).', 'wp-mcp-ai' ),
						),
						'edit_mode'     => array(
							'type'        => 'string',
							'description' => __( 'Elementor editor mode (builder or default).', 'wp-mcp-ai' ),
							'enum'        => array( 'builder', 'default' ),
						),
					),
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

		// Convert to blocks if the post type supports the block editor.
		if ( post_type_supports( $post_type, 'editor' ) && function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type ) ) {
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
		$status                   = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'draft';
		$valid_statuses           = array( 'publish', 'draft', 'pending', 'private' );
		$post_data['post_status'] = in_array( $status, $valid_statuses, true ) ? $status : 'draft';

		// Add optional fields to post data.
		if ( isset( $arguments['excerpt'] ) ) {
			$post_data['post_excerpt'] = wp_kses_post( $arguments['excerpt'] );
		}

		if ( isset( $arguments['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $arguments['slug'] );
		}

		if ( isset( $arguments['post_parent'] ) ) {
			$parent_id = absint( $arguments['post_parent'] );
			if ( $parent_id > 0 ) {
				$parent_post = get_post( $parent_id );
				if ( $parent_post && $parent_post->post_type === $post_type ) {
					$post_data['post_parent'] = $parent_id;
				}
			}
		}

		if ( isset( $arguments['menu_order'] ) ) {
			$post_data['menu_order'] = absint( $arguments['menu_order'] );
		}

		if ( isset( $arguments['comment_status'] ) && in_array( $arguments['comment_status'], array( 'open', 'closed' ), true ) ) {
			$post_data['comment_status'] = $arguments['comment_status'];
		}

		if ( isset( $arguments['ping_status'] ) && in_array( $arguments['ping_status'], array( 'open', 'closed' ), true ) ) {
			$post_data['ping_status'] = $arguments['ping_status'];
		}

		// Add meta_input for custom fields.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			$post_data['meta_input'] = $this->sanitize_meta_input( $arguments['meta_input'] );
		}

		// Create the post.
		$result = wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$created_post = get_post( $result );
		if ( ! $created_post ) {
			return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The post was created but could not be retrieved.', 'wp-mcp-ai' ) );
		}

		// Handle post-creation operations.
		$post_meta_result = $this->handle_post_metadata( $created_post->ID, $arguments, $post_type );
		if ( is_wp_error( $post_meta_result ) ) {
			// Delete the post if metadata handling fails critically.
			wp_delete_post( $created_post->ID, true );
			return $post_meta_result;
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
		$normalized_content = preg_replace( '~\r\n?~', "\n", $content );
		$paragraphs         = preg_split( '~\n{2,}~', trim( $normalized_content ) );
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
	 * Sanitizes meta_input array to ensure safe values.
	 *
	 * @param array $meta_input Raw meta input array.
	 * @return array Sanitized meta input array.
	 */
	private function sanitize_meta_input( $meta_input ) {
		$sanitized = array();

		foreach ( $meta_input as $key => $value ) {
			$sanitized_key = sanitize_key( $key );

			// Skip protected meta keys.
			if ( is_protected_meta( $sanitized_key, 'post' ) ) {
				continue;
			}

			// Recursively sanitize arrays.
			if ( is_array( $value ) ) {
				$sanitized[ $sanitized_key ] = array_map( 'sanitize_text_field', $value );
			} else {
				$sanitized[ $sanitized_key ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Handles post metadata operations after post creation.
	 *
	 * @param int    $post_id   The post ID.
	 * @param array  $arguments Tool arguments.
	 * @param string $post_type Post type.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function handle_post_metadata( $post_id, $arguments, $post_type ) {
		// Handle featured image.
		if ( isset( $arguments['featured_image_id'] ) ) {
			$thumbnail_id = absint( $arguments['featured_image_id'] );
			if ( $thumbnail_id > 0 && wp_attachment_is_image( $thumbnail_id ) ) {
				set_post_thumbnail( $post_id, $thumbnail_id );
			}
		}

		// Handle categories (only for post types that support 'category' taxonomy).
		if ( isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			if ( is_object_in_taxonomy( $post_type, 'category' ) ) {
				$category_ids = $this->resolve_taxonomy_terms( $arguments['categories'], 'category' );
				if ( ! empty( $category_ids ) ) {
					wp_set_post_categories( $post_id, $category_ids );
				}
			}
		}

		// Handle tags (only for post types that support 'post_tag' taxonomy).
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			if ( is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
				$tag_ids = $this->resolve_taxonomy_terms( $arguments['tags'], 'post_tag' );
				if ( ! empty( $tag_ids ) ) {
					wp_set_post_tags( $post_id, $tag_ids );
				}
			}
		}

		// Handle page template.
		if ( isset( $arguments['page_template'] ) && '' !== $arguments['page_template'] ) {
			$template = sanitize_text_field( $arguments['page_template'] );
			// Validate template exists.
			$page_templates = wp_get_theme()->get_page_templates( null, $post_type );
			if ( isset( $page_templates[ $template ] ) || 'default' === $template ) {
				update_post_meta( $post_id, '_wp_page_template', $template );
			}
		}

		// Handle Elementor data.
		if ( isset( $arguments['elementor_data'] ) && is_array( $arguments['elementor_data'] ) ) {
			$this->handle_elementor_metadata( $post_id, $arguments['elementor_data'] );
		}

		return true;
	}

	/**
	 * Resolves taxonomy terms from IDs or names.
	 *
	 * @param array  $terms    Array of term IDs or names.
	 * @param string $taxonomy Taxonomy name.
	 * @return array Array of term IDs.
	 */
	private function resolve_taxonomy_terms( $terms, $taxonomy ) {
		$term_ids = array();

		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$term_id = absint( $term );
				if ( term_exists( $term_id, $taxonomy ) ) {
					$term_ids[] = $term_id;
				}
			} else {
				// Try to find or create term by name.
				$term_obj = term_exists( $term, $taxonomy );
				if ( ! $term_obj ) {
					// Create the term if it doesn't exist.
					$term_obj = wp_insert_term( sanitize_text_field( $term ), $taxonomy );
				}

				if ( ! is_wp_error( $term_obj ) && isset( $term_obj['term_id'] ) ) {
					$term_ids[] = $term_obj['term_id'];
				}
			}
		}

		return array_unique( $term_ids );
	}

	/**
	 * Handles Elementor-specific metadata.
	 *
	 * @param int   $post_id        The post ID.
	 * @param array $elementor_data Elementor data array.
	 */
	private function handle_elementor_metadata( $post_id, $elementor_data ) {
		// Check if Elementor is active.
		if ( ! ( defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin', false ) ) ) {
			return;
		}

		// Set template type.
		if ( isset( $elementor_data['template_type'] ) ) {
			$template_type = sanitize_key( $elementor_data['template_type'] );
			update_post_meta( $post_id, '_elementor_template_type', $template_type );
		}

		// Set edit mode.
		if ( isset( $elementor_data['edit_mode'] ) ) {
			$edit_mode = sanitize_key( $elementor_data['edit_mode'] );
			if ( 'builder' === $edit_mode ) {
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			}
		}
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
