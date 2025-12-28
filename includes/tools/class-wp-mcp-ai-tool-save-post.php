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
				'post_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Existing post ID to update. Leave empty to create a new post.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'post_type'          => array(
					'type'        => 'string',
					'description' => __( 'The post type to create or update.', 'wp-mcp-ai' ),
					'default'     => 'post',
				),
				'title'              => array(
					'type'        => 'string',
					'description' => __( 'Title of the post.', 'wp-mcp-ai' ),
				),
				'content'            => array(
					'type'        => 'string',
					'description' => __( 'Main content for the post.', 'wp-mcp-ai' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'The status to assign to the post, e.g. draft or publish.', 'wp-mcp-ai' ),
					'default'     => 'draft',
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

		if ( isset( $arguments['post_parent'] ) ) {
			$parent_id = absint( $arguments['post_parent'] );
			if ( $parent_id > 0 ) {
				$parent_post = get_post( $parent_id );
				if ( $parent_post && $parent_post->post_type === $post_type ) {
					$post_data['post_parent'] = $parent_id;
				}
			} elseif ( 0 === $parent_id ) {
				// Allow explicitly setting no parent.
				$post_data['post_parent'] = 0;
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

		// Handle post-creation/update operations for metadata.
		$this->handle_post_metadata( $updated_post->ID, $arguments, $post_type );

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
	 * Handles post metadata operations after post creation/update.
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
			} elseif ( 0 === $thumbnail_id ) {
				// Allow explicitly removing featured image.
				delete_post_thumbnail( $post_id );
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
			'write',                // Creates or updates posts.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires post editing capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone via post revisions.
		);
	}
}
