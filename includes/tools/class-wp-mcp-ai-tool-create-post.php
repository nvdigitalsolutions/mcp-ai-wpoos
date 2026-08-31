<?php
/**
 * Tool for creating new WordPress posts.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-tool-content-media.php';
require_once __DIR__ . '/trait-wp-mcp-ai-tool-markdown-converter.php';

/**
 * Creates a new WordPress post.
 *
 * This is a simplified version of save_post that only handles
 * post creation, not updates. Use save_post for update operations.
 */
class WP_MCP_AI_Tool_Create_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Content_Media;
	use WP_MCP_AI_Tool_Markdown_Converter;

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
		return __( 'Create Post', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new WordPress post. For updating existing posts, use save_post instead.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'title'             => array(
					'type'        => 'string',
					'description' => __( 'Title of the post.', 'mcp-ai-wpoos' ),
				),
				'content'           => array(
					'type'        => 'string',
					'description' => __( 'Main content for the post.', 'mcp-ai-wpoos' ),
				),
				'post_type'         => array(
					'type'        => 'string',
					'description' => __( 'The post type to create.', 'mcp-ai-wpoos' ),
					'default'     => 'post',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => __( 'The status to assign to the post (publish, draft, pending, or private).', 'mcp-ai-wpoos' ),
					'default'     => 'draft',
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
				),
				'user_id'           => array(
					'type'        => 'integer',
					'description' => __( 'The user ID to set as the post author. Defaults to current user.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'excerpt'           => array(
					'type'        => 'string',
					'description' => __( 'Optional excerpt for the post.', 'mcp-ai-wpoos' ),
				),
				'slug'              => array(
					'type'        => 'string',
					'description' => __( 'Optional slug to use for the post permalink.', 'mcp-ai-wpoos' ),
				),
				'featured_image_id' => array(
					'type'        => 'integer',
					'description' => __( 'Attachment ID to set as the featured image.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => __( 'Array of category IDs or names to assign to the post.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'anyOf' => array(
							array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							array( 'type' => 'string' ),
						),
					),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => __( 'Array of tag IDs or names to assign to the post.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'anyOf' => array(
							array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							array( 'type' => 'string' ),
						),
					),
				),
				'page_template'     => array(
					'type'        => 'string',
					'description' => __( 'Page template filename (e.g., "template-full-width.php"). Only applies to pages and custom post types that support page templates.', 'mcp-ai-wpoos' ),
				),
				'post_parent'       => array(
					'type'        => 'integer',
					'description' => __( 'ID of the parent post for hierarchical post types (e.g., pages).', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
				),
				'menu_order'        => array(
					'type'        => 'integer',
					'description' => __( 'Menu order for sorting hierarchical post types.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
				),
				'comment_status'    => array(
					'type'        => 'string',
					'description' => __( 'Whether to allow comments (open or closed).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'open', 'closed' ),
				),
				'ping_status'       => array(
					'type'        => 'string',
					'description' => __( 'Whether to allow pingbacks and trackbacks (open or closed).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'open', 'closed' ),
				),
				'meta_input'        => array(
					'type'                 => 'object',
					'description'          => __( 'Array of custom field key-value pairs to set as post meta.', 'mcp-ai-wpoos' ),
					'additionalProperties' => true,
				),
				'format'            => array(
					'type'        => 'string',
					'description' => __( 'Content format for the post. Use "block-editor" for Gutenberg (default), "classic-editor" for plain HTML, "elementor" for Elementor page builder, or "auto" to detect based on post type.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'block-editor', 'classic-editor', 'elementor', 'auto' ),
					'default'     => 'auto',
				),
				'elementor_data'    => array(
					'type'        => 'object',
					'description' => __( 'Elementor page builder data (requires Elementor plugin). When format is "elementor", the content parameter is converted to a text-editor widget inside an Elementor JSON structure unless elementor_data is explicitly provided.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'template_type' => array(
							'type'        => 'string',
							'description' => __( 'Elementor template type (page, header, footer, section, etc).', 'mcp-ai-wpoos' ),
						),
						'edit_mode'     => array(
							'type'        => 'string',
							'description' => __( 'Elementor editor mode (builder or default).', 'mcp-ai-wpoos' ),
							'enum'        => array( 'builder', 'default' ),
						),
					),
				),
			),
			'required'             => array( 'title', 'content' ),
			'additionalProperties' => false,
		);

		// Merge content media parameters.
		$schema['properties'] = array_merge( $schema['properties'], $this->get_content_media_parameters() );

		return $schema;
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create posts.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Validate and sanitize inputs.
		$title   = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$content = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Post title is required.', 'mcp-ai-wpoos' ) );
		}

		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_content', __( 'Post content is required.', 'mcp-ai-wpoos' ) );
		}

		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		// Validate post type exists.
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'mcp-ai-wpoos' ) );
		}

		// Check if user can create posts of this type.
		$create_cap = isset( $post_type_object->cap->create_posts ) ? $post_type_object->cap->create_posts : $post_type_object->cap->edit_posts;

		if ( ! user_can( $current_user_id, $create_cap ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create posts of this type.', 'mcp-ai-wpoos' ) );
		}

		// Determine the author ID.
		$author_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : $current_user_id;

		// Validate author exists and has edit_posts capability.
		if ( $author_id !== $current_user_id ) {
			$author = get_userdata( $author_id );
			if ( ! $author ) {
				return new WP_Error( 'wp_mcp_ai_invalid_user', __( 'The specified user does not exist.', 'mcp-ai-wpoos' ) );
			}

			if ( ! user_can( $author_id, 'edit_posts' ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_author', __( 'The specified user does not have permission to author posts.', 'mcp-ai-wpoos' ) );
			}
		}

		// Resolve the content format.
		$requested_format = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : WP_MCP_AI_Content_Format_Helper::FORMAT_AUTO;
		$format           = WP_MCP_AI_Content_Format_Helper::resolve_format( $requested_format );

		// Convert Markdown to HTML if the content appears to be Markdown rather
		// than already-formatted HTML or block markup. LLMs frequently return
		// Markdown, and raw `# Heading` or `**bold**` stored in paragraph blocks
		// renders as literal text instead of styled headings/bold.
		$content = $this->maybe_convert_markdown( $content );

		// Sanitize content.
		$sanitized_content = wp_kses_post( $content );

		// Handle content formatting based on the resolved format.
		$elementor_stored_content = '';

		if ( WP_MCP_AI_Content_Format_Helper::FORMAT_ELEMENTOR === $format ) {
			if ( ! WP_MCP_AI_Content_Format_Helper::is_elementor_active() ) {
				return new WP_Error( 'wp_mcp_ai_elementor_not_active', __( 'Elementor is not installed or activated on this site.', 'mcp-ai-wpoos' ) );
			}
			// For Elementor posts, store content in _elementor_data meta, not post_content.
			$elementor_stored_content = $this->build_elementor_content( $sanitized_content, $arguments );
				$sanitized_content    = '';
		} elseif ( WP_MCP_AI_Content_Format_Helper::FORMAT_BLOCK_EDITOR === $format
				|| (
					WP_MCP_AI_Content_Format_Helper::FORMAT_AUTO === $format
					&& post_type_supports( $post_type, 'editor' )
					&& function_exists( 'use_block_editor_for_post_type' )
					&& use_block_editor_for_post_type( $post_type )
				)
			) {
			// Block editor: wrap in blocks.
			$sanitized_content = $this->ensure_post_content_uses_blocks( $sanitized_content, $content );
		}
			// Classic editor: keep HTML as-is, skip block wrapping (no-op).

		// Embed content media (images and charts) — only for non-Elementor formats.
		if ( '' !== $sanitized_content ) {
			$sanitized_content = $this->embed_content_media( $sanitized_content, $arguments );
		}

		// Track whether categories/tags were explicitly provided for auto-suggestion logic.
		$has_explicit_categories = isset( $arguments['categories'] ) && is_array( $arguments['categories'] );
		$has_explicit_tags       = isset( $arguments['tags'] ) && is_array( $arguments['tags'] );

		// Prepare post data.
		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $sanitized_content,
			'post_author'  => $author_id,
		);

		// Store Elementor data in meta_input so it is written during wp_insert_post.
		if ( '' !== $elementor_stored_content ) {
			if ( ! isset( $post_data['meta_input'] ) || ! is_array( $post_data['meta_input'] ) ) {
				$post_data['meta_input'] = array();
			}
			$post_data['meta_input']['_elementor_edit_mode'] = 'builder';
			$post_data['meta_input']['_elementor_data']      = $elementor_stored_content;
			$post_data['meta_input']['_elementor_version']   = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';
		}

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
			return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The post was created but could not be retrieved.', 'mcp-ai-wpoos' ) );
		}

		// Handle post-creation operations.
		$post_meta_result = $this->handle_post_metadata( $created_post->ID, $arguments, $post_type );
		if ( is_wp_error( $post_meta_result ) ) {
			// Delete the post if metadata handling fails critically.
			wp_delete_post( $created_post->ID, true );
			return $post_meta_result;
		}

		// Auto-suggest and apply categories/tags when none were explicitly provided.
		$suggested_categories = array();
		$suggested_tags       = array();
		$auto_applied_cats    = array();
		$auto_applied_tags    = array();

		if ( ! $has_explicit_categories ) {
			$suggested_categories = $this->suggest_taxonomy_terms(
				$title,
				$sanitized_content,
				$post_type,
				'category',
				5
			);
			// Auto-apply categories with score >= 10 (strong match).
			foreach ( $suggested_categories as $cat ) {
				if ( $cat['score'] >= 10 ) {
					$auto_applied_cats[] = (int) $cat['term_id'];
				}
			}
			if ( ! empty( $auto_applied_cats ) ) {
				wp_set_post_categories( $created_post->ID, $auto_applied_cats );
			}
		}

		if ( ! $has_explicit_tags ) {
			$suggested_tags = $this->suggest_taxonomy_terms(
				$title,
				$sanitized_content,
				$post_type,
				'post_tag',
				10
			);
			// Auto-apply tags with score >= 8 (slightly lower bar for tags).
			foreach ( $suggested_tags as $tag ) {
				if ( $tag['score'] >= 8 ) {
					$auto_applied_tags[] = (int) $tag['term_id'];
				}
			}
			if ( ! empty( $auto_applied_tags ) ) {
				wp_set_post_tags( $created_post->ID, $auto_applied_tags );
			}
		}

		$summary_text = sprintf(
			/* translators: 1: post title, 2: post ID */
			__( 'Post created: %1$s (ID: %2$d)', 'mcp-ai-wpoos' ),
			get_the_title( $created_post ),
			$created_post->ID
		);

		$response = array(
			'message'   => $summary_text, // Chat client display.
			'summary'   => $summary_text, // Backward compatibility.
			'ID'        => $created_post->ID,
			'title'     => get_the_title( $created_post ),
			'status'    => esc_html( get_post_status( $created_post ) ),
			'post_type' => esc_html( $created_post->post_type ),
			'author_id' => $created_post->post_author,
			'permalink' => esc_url( get_permalink( $created_post ) ),
			'format'    => esc_html( $format ),
		);

		$edit_link = get_edit_post_link( $created_post->ID, '' );
		if ( $edit_link ) {
			$response['edit_link'] = $edit_link;
		}

		// Include category/tag suggestions and auto-applied terms.
		if ( ! empty( $auto_applied_cats ) || ! empty( $auto_applied_tags ) ) {
			$applied_names = array();
			foreach ( $auto_applied_cats as $cat_id ) {
				$cat = get_term( $cat_id, 'category' );
				if ( $cat && ! is_wp_error( $cat ) ) {
					$applied_names[] = $cat->name;
				}
			}
			foreach ( $auto_applied_tags as $tag_id ) {
				$tag = get_term( $tag_id, 'post_tag' );
				if ( $tag && ! is_wp_error( $tag ) ) {
					$applied_names[] = $tag->name;
				}
			}
			$response['auto_applied_terms'] = $applied_names;
		}

		if ( ! empty( $suggested_categories ) ) {
			$response['suggested_categories'] = array_map(
				function ( $c ) {
					return array(
						'name'  => $c['name'],
						'score' => $c['score'],
					);
				},
				$suggested_categories
			);
		}

		if ( ! empty( $suggested_tags ) ) {
			$response['suggested_tags'] = array_map(
				function ( $t ) {
					return array(
						'name'  => $t['name'],
						'score' => $t['score'],
					);
				},
				$suggested_tags
			);
		}

		return $response;
	}

	/**
	 * Suggests categories and tags based on post title and content.
	 *
	 * Extracts meaningful keywords from the content and matches them against
	 * existing taxonomy terms using word-boundary comparison. Returns scored
	 * suggestions that the LLM or auto-assignment logic can use.
	 *
	 * @since 1.1.0
	 *
	 * @param string $title     Post title.
	 * @param string $content   Post content.
	 * @param string $post_type Post type.
	 * @param string $taxonomy  Taxonomy name (category or post_tag).
	 * @param int    $limit     Maximum suggestions to return.
	 * @return array Array of arrays with term_id, name, slug, and score keys.
	 */
	private function suggest_taxonomy_terms( $title, $content, $post_type, $taxonomy, $limit = 5 ) {
		if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			return array();
		}

		// Get all terms for this taxonomy.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 200,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		// Build a combined text to extract keywords from.
		$combined = wp_strip_all_tags( $title . ' ' . $content );
		$combined = mb_strtolower( $combined );

		// Extract meaningful words (3+ chars, skip common stop words).
		$stop_words = array(
			'the',
			'and',
			'for',
			'are',
			'but',
			'not',
			'you',
			'all',
			'can',
			'had',
			'her',
			'was',
			'one',
			'our',
			'out',
			'has',
			'have',
			'been',
			'some',
			'than',
			'that',
			'this',
			'will',
			'with',
			'from',
			'they',
			'them',
			'then',
			'also',
			'into',
			'just',
			'about',
			'over',
			'such',
			'only',
			'other',
			'more',
			'very',
			'what',
			'when',
			'where',
			'which',
			'your',
			'their',
		);
		$words      = preg_split( '/[\s,.;:!?()\[\]"\']+/', $combined, -1, PREG_SPLIT_NO_EMPTY );
		$keywords   = array();
		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( mb_strlen( $word ) >= 3 && ! in_array( $word, $stop_words, true ) ) {
				$keywords[ $word ] = ( $keywords[ $word ] ?? 0 ) + 1;
			}
		}

		// Score each term against the keyword set.
		$scored = array();
		foreach ( $terms as $term ) {
			$term_name_lower = mb_strtolower( $term->name );
			$score           = 0;

			// Full name match (e.g., "artificial intelligence" in content).
			$name_count = mb_substr_count( $combined, $term_name_lower );
			if ( $name_count > 0 ) {
				$score += $name_count * 10;
			}

			// Individual word matches.
			$term_words = preg_split( '/[\s-]+/', $term_name_lower, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $term_words as $tw ) {
				if ( isset( $keywords[ $tw ] ) ) {
					$score += $keywords[ $tw ] * 3;
				}
			}

			if ( $score > 0 ) {
				$scored[] = array(
					'term_id' => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'score'   => $score,
				);
			}
		}

		// Sort by score descending.
		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Ensures post content uses block markup when the post type supports the block editor.
	 *
	 * Plain text is converted to paragraph blocks; HTML that lacks block markers
	 * is wrapped in a single wp:html block to prevent block-editor corruption.
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
	 * Build a basic Elementor JSON structure from sanitized content.
	 *
	 * Wraps the HTML content in a single container with a text-editor widget
	 * so that content created via the API renders inside the Elementor editor.
	 * When explicit elementor_data is provided in the arguments, its template_type
	 * and edit_mode are preserved in post meta via handle_elementor_metadata().
	 *
	 * @since 1.10.0
	 *
	 * @param string $content   Sanitized HTML content.
	 * @param array  $arguments Tool arguments.
	 * @return string JSON-encoded Elementor data.
	 */
	private function build_elementor_content( $content, $arguments ) {
		// If caller supplied raw Elementor JSON via elementor_data.content, use it as-is.
		if ( isset( $arguments['elementor_data']['content'] ) && is_string( $arguments['elementor_data']['content'] ) ) {
			$decoded = json_decode( $arguments['elementor_data']['content'], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return wp_json_encode( $decoded );
			}
		}

		// Build a minimal Elementor structure: one container with one text-editor widget.
		$container_id = substr( md5( uniqid( 'cont', true ) ), 0, 8 );
		$widget_id    = substr( md5( uniqid( 'widg', true ) ), 0, 8 );

		$elementor_data = array(
			'title'         => '',
			'type'          => 'page',
			'version'       => '0.4',
			'page_settings' => array(),
			'content'       => array(
				array(
					'id'       => $container_id,
					'elType'   => 'container',
					'isInner'  => false,
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => $widget_id,
							'elType'     => 'widget',
							'widgetType' => 'text-editor',
							'isInner'    => false,
							'settings'   => array(
								'editor' => $content,
							),
							'elements'   => array(),
						),
					),
				),
			),
		);

		return wp_json_encode( $elementor_data );
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
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'writer', 'content_creator', 'journalist', 'blogger' ),
			'risk_level'            => 'standard',
		);
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
