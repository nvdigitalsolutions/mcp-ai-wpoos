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
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Create or Update Post', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new post or updates an existing one with the supplied content. Supports multi-step orchestration mode with automatic content research, validation, AI enhancement, and post-creation optimization. Set orchestration_mode=true to enable 5-step workflow.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Existing post ID to update. Leave empty to create a new post.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'orchestration_mode' => array(
					'type'        => 'boolean',
					'description' => __( 'Enable multi-step orchestration workflow. When true, executes 5-step process: Research → Validate → Enhance → Save → Optimize. Default: false (legacy mode).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'auto_research'      => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically research content topic using web_search tool. Only applies when orchestration_mode is true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'enhance_content'    => array(
					'type'        => 'boolean',
					'description' => __( 'Use AI to enhance content quality, readability, and SEO. Only applies when orchestration_mode is true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'optimize'           => array(
					'type'        => 'boolean',
					'description' => __( 'Optimize post after creation (featured image, SEO metadata, cache). Only applies when orchestration_mode is true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'generate_featured_image' => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically generate a featured image for the post using AI. Only applies when orchestration_mode and optimize are true.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'post_type'         => array(
					'type'        => 'string',
					'description' => __( 'The post type to create or update.', 'mcp-ai-wpoos' ),
					'default'     => 'post',
				),
				'title'             => array(
					'type'        => 'string',
					'description' => __( 'Title of the post.', 'mcp-ai-wpoos' ),
				),
				'content'           => array(
					'type'        => 'string',
					'description' => __( 'Main content for the post.', 'mcp-ai-wpoos' ),
				),
				'status'            => array(
					'type'        => 'string',
					'description' => __( 'The status to assign to the post, e.g. draft or publish.', 'mcp-ai-wpoos' ),
					'default'     => 'draft',
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
				'elementor_data'    => array(
					'type'        => 'object',
					'description' => __( 'Elementor page builder data (requires Elementor plugin).', 'mcp-ai-wpoos' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage posts.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Check if orchestration mode is enabled.
		$orchestration_mode = isset( $arguments['orchestration_mode'] ) && $arguments['orchestration_mode'];

		if ( $orchestration_mode ) {
			return $this->execute_orchestrated( $arguments, $context, $user_id );
		}

		// Legacy execution path (maintain backward compatibility).
		return $this->execute_legacy( $arguments, $context, $user_id );
	}

	/**
	 * Legacy execution path without orchestration.
	 *
	 * Maintains backward compatibility with existing integrations.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Post data or error.
	 */
	protected function execute_legacy( array $arguments, array $context, int $user_id ) {

		$post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : '';

		$post = null;
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'wp_mcp_ai_invalid_post', __( 'The specified post could not be found.', 'mcp-ai-wpoos' ) );
			}

			if ( '' === $post_type ) {
				$post_type = $post->post_type;
			} elseif ( $post->post_type !== $post_type ) {
				return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not match the existing post.', 'mcp-ai-wpoos' ) );
			}

			if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this post.', 'mcp-ai-wpoos' ) );
			}
		} else {
			if ( '' === $post_type ) {
				$post_type = 'post';
			}

			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'mcp-ai-wpoos' ) );
			}

			$create_cap = isset( $post_type_object->cap->create_posts ) ? $post_type_object->cap->create_posts : $post_type_object->cap->edit_posts;

			if ( ! user_can( $user_id, $create_cap ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create posts of this type.', 'mcp-ai-wpoos' ) );
			}
		}

		$post_type_object = isset( $post_type_object ) ? $post_type_object : get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'mcp-ai-wpoos' ) );
		}

		$raw_content = isset( $arguments['content'] ) ? $arguments['content'] : '';
		$content     = wp_kses_post( $raw_content );
		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_content', __( 'Post content is required.', 'mcp-ai-wpoos' ) );
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
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'A title is required when creating a new post.', 'mcp-ai-wpoos' ) );
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
			return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The post was saved but could not be retrieved.', 'mcp-ai-wpoos' ) );
		}

		// Handle post-creation/update operations for metadata.
		$this->handle_post_metadata( $updated_post->ID, $arguments, $post_type );

		$summary_text = sprintf(
			/* translators: 1: post ID, 2: post title */
			__( 'Post saved: %1$s (ID: %2$d)', 'mcp-ai-wpoos' ),
			get_the_title( $updated_post ),
			$updated_post->ID
		);

		$response = array(
			'message'   => $summary_text, // Chat client display.
			'summary'   => $summary_text, // Backward compatibility.
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
	 * Execute post creation/update with multi-step orchestration.
	 *
	 * Implements a 5-step workflow:
	 * 1. Content Research (optional topic research via web_search)
	 * 2. Data Validation (required fields, duplicate check)
	 * 3. Content Enhancement (AI quality improvement, SEO, readability)
	 * 4. Post Save (standard WordPress creation/update)
	 * 5. Post-Processing (featured image, SEO metadata, cache)
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Post data or error.
	 */
	protected function execute_orchestrated( array $arguments, array $context, int $user_id ) {
		// Generate unique execution ID for tracking.
		$execution_id = 'post_save_' . wp_generate_uuid4();

		$this->log_orchestration_step( $execution_id, 'started', array(
			'user_id'  => $user_id,
			'post_id'  => $arguments['post_id'] ?? 0,
			'is_update' => ! empty( $arguments['post_id'] ),
		) );

		// Step 1: Content Research (optional).
		if ( ! empty( $arguments['auto_research'] ) && $arguments['auto_research'] ) {
			$this->log_orchestration_step( $execution_id, 'research', 'Starting content research' );
			$research_data = $this->step_research_content( $arguments, $context );

			if ( is_wp_error( $research_data ) ) {
				$this->log_orchestration_step( $execution_id, 'research_failed', $research_data->get_error_message() );
				// Non-critical: Continue with provided data.
			} else {
				// Merge research data with arguments.
				$arguments = array_merge( $arguments, $research_data );
				$this->log_orchestration_step( $execution_id, 'research_completed', 'Research data merged' );
			}
		}

		// Step 2: Data Validation.
		$this->log_orchestration_step( $execution_id, 'validate', 'Validating post data' );
		$validation_result = $this->step_validate_post_data( $arguments, $user_id );

		if ( is_wp_error( $validation_result ) ) {
			$this->log_orchestration_step( $execution_id, 'validation_failed', $validation_result->get_error_message() );
			return $this->handle_orchestration_error( 'validate', $validation_result, $execution_id );
		}

		$this->log_orchestration_step( $execution_id, 'validation_completed', 'Data validated successfully' );

		// Step 3: Content Enhancement.
		if ( ! empty( $arguments['enhance_content'] ) && $arguments['enhance_content'] ) {
			$this->log_orchestration_step( $execution_id, 'enhance', 'Enhancing content quality' );
			$enhanced_data = $this->step_enhance_content( $arguments, $context );

			if ( ! is_wp_error( $enhanced_data ) ) {
				$arguments = array_merge( $arguments, $enhanced_data );
				$this->log_orchestration_step( $execution_id, 'enhancement_completed', 'Content enhanced' );
			} else {
				$this->log_orchestration_step( $execution_id, 'enhancement_skipped', $enhanced_data->get_error_message() );
			}
		}

		// Step 4: Post Save.
		$this->log_orchestration_step( $execution_id, 'save', 'Saving post' );
		$post_data = $this->execute_legacy( $arguments, $context, $user_id );

		if ( is_wp_error( $post_data ) ) {
			$this->log_orchestration_step( $execution_id, 'save_failed', $post_data->get_error_message() );
			return $this->handle_orchestration_error( 'save', $post_data, $execution_id );
		}

		$post_id = $post_data['ID'];
		$this->log_orchestration_step( $execution_id, 'save_completed', array( 'post_id' => $post_id ) );

		// Step 5: Post-Processing.
		if ( ! empty( $arguments['optimize'] ) && $arguments['optimize'] ) {
			$this->log_orchestration_step( $execution_id, 'optimize', 'Optimizing post' );
			$optimization_result = $this->step_optimize_post( $post_id, $arguments, $context );

			if ( ! is_wp_error( $optimization_result ) ) {
				$post_data = array_merge( $post_data, $optimization_result );
				$this->log_orchestration_step( $execution_id, 'optimization_completed', 'Post optimized' );
			} else {
				$this->log_orchestration_step( $execution_id, 'optimization_skipped', $optimization_result->get_error_message() );
			}
		}

		$this->log_orchestration_step( $execution_id, 'completed', 'Post save workflow completed' );

		// Add orchestration metadata to response.
		$post_data['execution_id'] = $execution_id;
		$post_data['orchestration'] = array(
			'enabled' => true,
			'steps'   => $this->get_orchestration_steps( $execution_id ),
		);

		return $post_data;
	}

	/**
	 * Step 1: Research content topic.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Research data or error.
	 */
	protected function step_research_content( $arguments, $context ) {
		// Check if web_search tool is available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new WP_Error( 'tool_registry_unavailable', 'Tool registry not available' );
		}

		$search_tool = WP_MCP_AI_Tool_Registry::get_tool( 'web_search' );

		if ( ! $search_tool ) {
			return new WP_Error( 'search_tool_unavailable', 'Web search tool not available' );
		}

		// Build search query from title or content.
		$query = ! empty( $arguments['title'] ) ? $arguments['title'] : '';

		if ( empty( $query ) && ! empty( $arguments['content'] ) ) {
			// Extract first sentence or first 50 words as query.
			$content_text = wp_strip_all_tags( $arguments['content'] );
			$query = wp_trim_words( $content_text, 10, '...' );
		}

		if ( empty( $query ) ) {
			return new WP_Error( 'no_search_query', 'Cannot research: no title or content provided' );
		}

		$search_result = $search_tool->execute(
			array( 'query' => $query ),
			$context
		);

		if ( is_wp_error( $search_result ) ) {
			return $search_result;
		}

		// Store research results in meta for reference.
		$enriched_data = array(
			'_research_results' => wp_json_encode( $search_result ),
		);

		return $enriched_data;
	}

	/**
	 * Step 2: Validate post data.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	protected function step_validate_post_data( $arguments, $user_id ) {
		$errors = array();

		// Validate title (required for new posts).
		if ( empty( $arguments['post_id'] ) && empty( $arguments['title'] ) ) {
			$errors[] = __( 'Title is required when creating a new post', 'mcp-ai-wpoos' );
		}

		// Validate title length.
		if ( ! empty( $arguments['title'] ) && strlen( $arguments['title'] ) > 200 ) {
			$errors[] = __( 'Post title must be 200 characters or less', 'mcp-ai-wpoos' );
		}

		// Validate content (required).
		if ( empty( $arguments['content'] ) ) {
			$errors[] = __( 'Content is required', 'mcp-ai-wpoos' );
		}

		// Validate content length (minimum).
		if ( ! empty( $arguments['content'] ) && strlen( wp_strip_all_tags( $arguments['content'] ) ) < 10 ) {
			$errors[] = __( 'Content must be at least 10 characters', 'mcp-ai-wpoos' );
		}

		// Validate post type exists.
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';
		if ( ! post_type_exists( $post_type ) ) {
			$errors[] = sprintf(
				/* translators: %s: post type */
				__( 'Post type "%s" does not exist', 'mcp-ai-wpoos' ),
				$post_type
			);
		}

		// Check duplicate title for new posts.
		if ( empty( $arguments['post_id'] ) && ! empty( $arguments['title'] ) ) {
			$existing = get_page_by_title( $arguments['title'], OBJECT, $post_type );
			if ( $existing ) {
				$errors[] = sprintf(
					/* translators: %s: post title */
					__( 'A post with the title "%s" already exists', 'mcp-ai-wpoos' ),
					$arguments['title']
				);
			}
		}

		// Validate status.
		if ( ! empty( $arguments['status'] ) ) {
			$valid_statuses = get_post_stati();
			if ( ! in_array( $arguments['status'], $valid_statuses, true ) ) {
				$errors[] = sprintf(
					/* translators: %s: status */
					__( 'Invalid post status: %s', 'mcp-ai-wpoos' ),
					$arguments['status']
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'post_validation_failed',
				__( 'Post data validation failed', 'mcp-ai-wpoos' ),
				array( 'errors' => $errors )
			);
		}

		return true;
	}

	/**
	 * Step 3: Enhance content with AI.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Enhanced data or error.
	 */
	protected function step_enhance_content( $arguments, $context ) {
		$enhanced_data = array();

		// Enhance content readability and SEO.
		if ( ! empty( $arguments['content'] ) && class_exists( 'WP_MCP_AI_Streaming' ) ) {
			$ai_client = WP_MCP_AI_Streaming::get_instance();
			$prompt    = sprintf(
				'Improve the following content for better readability and SEO. Keep the same meaning but enhance structure and clarity. Content: %s',
				wp_trim_words( $arguments['content'], 200 )
			);

			$response = $ai_client->send_message( $prompt );

			if ( ! is_wp_error( $response ) && ! empty( $response['content'] ) ) {
				$enhanced_data['content'] = wp_kses_post( $response['content'] );
			}
		}

		// Generate excerpt if missing.
		if ( empty( $arguments['excerpt'] ) && ! empty( $arguments['content'] ) ) {
			$enhanced_data['excerpt'] = wp_trim_words( wp_strip_all_tags( $arguments['content'] ), 30 );
		}

		// SEO optimization (if Rank Math is available).
		if ( class_exists( 'RankMath' ) && ! empty( $arguments['title'] ) ) {
			$enhanced_data['_rank_math_focus_keyword'] = sanitize_text_field( $arguments['title'] );
		}

		return $enhanced_data;
	}

	/**
	 * Step 5: Optimize post after creation.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Optimization results or error.
	 */
	protected function step_optimize_post( $post_id, $arguments, $context ) {
		$optimization_results = array();

		// Generate featured image if requested and missing.
		if ( ! empty( $arguments['generate_featured_image'] ) && empty( get_post_thumbnail_id( $post_id ) ) ) {
			$image_generated = $this->generate_featured_image( $post_id, $arguments, $context );
			if ( ! is_wp_error( $image_generated ) ) {
				$optimization_results['featured_image_generated'] = true;
			}
		}

		// Apply SEO metadata.
		if ( class_exists( 'RankMath' ) ) {
			$post = get_post( $post_id );
			if ( $post ) {
				update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $post->post_title ) );

				$excerpt = get_the_excerpt( $post_id );
				if ( ! empty( $excerpt ) ) {
					update_post_meta( $post_id, 'rank_math_description', wp_trim_words( $excerpt, 30 ) );
				}

				$optimization_results['seo_optimized'] = true;
			}
		}

		// Purge cache.
		$post_url = get_permalink( $post_id );
		if ( $post_url ) {
			$cache_tool = class_exists( 'WP_MCP_AI_Tool_Registry' ) ? WP_MCP_AI_Tool_Registry::get_tool( 'purge_cache' ) : null;
			if ( $cache_tool ) {
				$cache_tool->execute( array( 'urls' => array( $post_url ) ), $context );
				$optimization_results['cache_purged'] = true;
			}
		}

		return $optimization_results;
	}

	/**
	 * Generate featured image for post.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function generate_featured_image( $post_id, $arguments, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return new WP_Error( 'tool_registry_unavailable', 'Tool registry not available' );
		}

		$image_tool = WP_MCP_AI_Tool_Registry::get_tool( 'generate_openai_image' );

		if ( ! $image_tool ) {
			return new WP_Error( 'image_tool_unavailable', 'Image generation tool not available' );
		}

		$title = ! empty( $arguments['title'] ) ? $arguments['title'] : get_the_title( $post_id );

		$image_result = $image_tool->execute(
			array(
				'prompt'  => sprintf( 'Blog post featured image for: %s', sanitize_text_field( $title ) ),
				'size'    => '1792x1024',
				'quality' => 'standard',
			),
			$context
		);

		if ( is_wp_error( $image_result ) ) {
			return $image_result;
		}

		// Set as featured image.
		if ( ! empty( $image_result['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, $image_result['attachment_id'] );
			return true;
		}

		return new WP_Error( 'image_not_set', 'Image generated but not set as featured' );
	}

	/**
	 * Log orchestration step.
	 *
	 * @param string $execution_id Execution ID.
	 * @param string $step         Step name.
	 * @param mixed  $data         Step data.
	 */
	protected function log_orchestration_step( $execution_id, $step, $data ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[WP_MCP_AI] [%s] Step: %s | Data: %s',
					$execution_id,
					$step,
					is_string( $data ) ? $data : wp_json_encode( $data )
				)
			);
		}

		// Store steps in transient for retrieval.
		$steps = get_transient( "wp_mcp_ai_post_exec_{$execution_id}" ) ?: array();
		$steps[] = array(
			'step' => $step,
			'time' => current_time( 'mysql' ),
			'data' => $data,
		);
		set_transient( "wp_mcp_ai_post_exec_{$execution_id}", $steps, HOUR_IN_SECONDS );
	}

	/**
	 * Get orchestration steps summary.
	 *
	 * @param string $execution_id Execution ID.
	 * @return array Steps summary.
	 */
	protected function get_orchestration_steps( $execution_id ) {
		$steps = get_transient( "wp_mcp_ai_post_exec_{$execution_id}" ) ?: array();

		return array_map(
			function( $step ) {
				return array(
					'name' => $step['step'],
					'time' => $step['time'],
				);
			},
			$steps
		);
	}

	/**
	 * Handle orchestration error.
	 *
	 * @param string   $step_name Step that failed.
	 * @param WP_Error $error     Error object.
	 * @param string   $execution_id Execution ID.
	 * @return WP_Error Enhanced error.
	 */
	protected function handle_orchestration_error( $step_name, $error, $execution_id ) {
		do_action( 'wp_mcp_ai_post_orchestration_failed', $step_name, $error, $execution_id );

		return new WP_Error(
			'orchestration_failed',
			sprintf(
				/* translators: 1: step name, 2: error message */
				__( 'Post save orchestration failed at step: %1$s. %2$s', 'mcp-ai-wpoos' ),
				$step_name,
				$error->get_error_message()
			),
			array(
				'step'          => $step_name,
				'original_code' => $error->get_error_code(),
				'original_data' => $error->get_error_data(),
				'execution_id'  => $execution_id,
			)
		);
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
			'write',                // Creates or updates posts.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires post editing capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone via post revisions.
		);
	}
}
