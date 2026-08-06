<?php
/**
 * Tool: create_post_from_research — Create a WordPress draft post from research data.
 *
 * Pro tool (PHP 8.1+). Bridges Paper Store research records or raw research data
 * to WordPress posts. Requires publish_posts capability.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Create Post from Research Tool.
 *
 * Reads a Paper Store research record (or accepts raw data) and creates
 * a WordPress draft post. Supports post type, status, category, tags,
 * and author assignment. Optionally updates the Paper Store record status
 * to "published" after successful post creation.
 */
class WP_MCP_AI_Pro_Tool_Create_Post_From_Research implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_post_from_research';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Post from Research', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a WordPress draft post from a Paper Store research record or raw research data. Converts stored research into a WordPress post with configurable type, status, category, tags, and author. Optionally updates the Paper Store record status to "published" after creation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'paper_store_record_id'  => array(
					'type'        => 'string',
					'description' => __( 'Paper Store record ID to convert into a WordPress post. Required if "data" is not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'paper_store_collection' => array(
					'type'        => 'string',
					'description' => __( 'Paper Store collection name containing the record. Required if paper_store_record_id is provided.', 'mcp-ai-wpoos-pro' ),
				),
				'data'                   => array(
					'type'        => 'object',
					'description' => __( 'Raw research data to convert (alternative to Paper Store record). Must include a "title" field. Can include "report" (post content), "description" (excerpt), and "sources".', 'mcp-ai-wpoos-pro' ),
				),
				'post_type'              => array(
					'type'        => 'string',
					'description' => __( 'WordPress post type for the draft. Default: "post".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'post',
				),
				'post_status'            => array(
					'type'        => 'string',
					'description' => __( 'Post status. Use "draft" for private review or "pending" for editorial workflow.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'draft', 'pending' ),
					'default'     => 'draft',
				),
				'category_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Category term ID to assign to the post.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'                   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Tags to assign to the post.', 'mcp-ai-wpoos-pro' ),
				),
				'author_id'              => array(
					'type'        => 'integer',
					'description' => __( 'User ID to set as the post author. Defaults to current user.', 'mcp-ai-wpoos-pro' ),
				),
				'update_paper_status'    => array(
					'type'        => 'boolean',
					'description' => __( 'Update the Paper Store record status to "published" after successful post creation.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'publish_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$record_id    = isset( $arguments['paper_store_record_id'] ) ? sanitize_key( $arguments['paper_store_record_id'] ) : '';
		$collection   = isset( $arguments['paper_store_collection'] ) ? sanitize_key( $arguments['paper_store_collection'] ) : '';
		$raw_data     = isset( $arguments['data'] ) && is_array( $arguments['data'] ) ? $arguments['data'] : null;
		$post_type    = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';
		$post_status  = isset( $arguments['post_status'] ) ? sanitize_key( $arguments['post_status'] ) : 'draft';
		$category_id  = isset( $arguments['category_id'] ) ? absint( $arguments['category_id'] ) : 0;
		$author_id    = isset( $arguments['author_id'] ) ? absint( $arguments['author_id'] ) : 0;
		$update_paper = ! empty( $arguments['update_paper_status'] );

		// Validate: must provide either Paper Store reference OR raw data.
		if ( empty( $record_id ) && null === $raw_data ) {
			return new WP_Error(
				'missing_source',
				__( 'Either "paper_store_record_id" (with "paper_store_collection") or "data" must be provided.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate post type.
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				sprintf(
					/* translators: %s: post type */
					__( 'Post type "%s" does not exist.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

		// Validate post status.
		$allowed_statuses = array( 'draft', 'pending' );
		if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
			$post_status = 'draft';
		}

		// Check publish capability for the target post type.
		$user_id          = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
		$post_type_object = get_post_type_object( $post_type );
		if ( $post_type_object && ! user_can( $user_id, $post_type_object->cap->publish_posts ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				sprintf(
					/* translators: %s: post type */
					__( 'You do not have permission to create %s posts.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

		// Determine author.
		if ( $author_id <= 0 ) {
			$author_id = $user_id;
		} elseif ( ! user_can( $author_id, 'edit_posts' ) ) {
			$author_id = $user_id;
		}

		// Resolve the source data.
		if ( null !== $raw_data ) {
			// Use raw data directly.
			$title       = isset( $raw_data['title'] ) ? sanitize_text_field( $raw_data['title'] ) : '';
			$content     = isset( $raw_data['report'] ) ? wp_kses_post( $raw_data['report'] ) : '';
			$description = isset( $raw_data['description'] ) ? sanitize_text_field( $raw_data['description'] ) : '';
		} else {
			// Read from Paper Store.
			if ( empty( $collection ) ) {
				return new WP_Error(
					'missing_collection',
					__( '"paper_store_collection" is required when using "paper_store_record_id".', 'mcp-ai-wpoos-pro' )
				);
			}

			$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
			$repo    = $manager->get_repository( $collection );
			$record  = $repo->find( $record_id );

			if ( is_wp_error( $record ) ) {
				return new WP_Error(
					'record_not_found',
					sprintf(
						/* translators: 1: record ID, 2: collection */
						__( 'Paper Store record "%1$s" not found in collection "%2$s".', 'mcp-ai-wpoos-pro' ),
						$record_id,
						$collection
					)
				);
			}

			if ( null === $record ) {
				return new WP_Error(
					'record_not_found',
					sprintf(
						/* translators: 1: record ID, 2: collection */
						__( 'Paper Store record "%1$s" not found in collection "%2$s".', 'mcp-ai-wpoos-pro' ),
						$record_id,
						$collection
					)
				);
			}

			// Extract content from Paper Store record.
			$title       = isset( $record['title'] ) ? sanitize_text_field( $record['title'] ) : '';
			$content     = '';
			$description = isset( $record['description'] ) ? sanitize_text_field( $record['description'] ) : '';

			// Handle body content (supports both JSON and Markdown+YAML drivers).
			if ( isset( $record['body']['markdown'] ) ) {
				$content = wp_kses_post( $record['body']['markdown'] );
			} elseif ( isset( $record['body'] ) && is_string( $record['body'] ) ) {
				$content = wp_kses_post( $record['body'] );
			} elseif ( isset( $record['body']['report'] ) ) {
				$content = wp_kses_post( $record['body']['report'] );
			}
		}

		// Validate required fields.
		if ( empty( $title ) ) {
			return new WP_Error(
				'missing_title',
				__( 'Research data must include a "title" field.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $content ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Research data must include content (report, body.markdown, or body string).', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build post data.
		$post_data = array(
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $description,
			'post_author'  => $author_id,
		);

		// Add category if provided and post type supports it.
		if ( $category_id > 0 && is_object_in_taxonomy( $post_type, 'category' ) ) {
			$post_data['post_category'] = array( $category_id );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to create draft post from research: ' . $post_id->get_error_message(),
				array(
					'title'     => $title,
					'post_type' => $post_type,
					'source'    => $record_id ? 'paper_store' : 'raw_data',
				)
			);
			return $post_id;
		}

		// Set tags if provided and post type supports it.
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
			$tag_names = array_map( 'sanitize_text_field', $arguments['tags'] );
			$tag_names = array_filter( $tag_names );
			if ( ! empty( $tag_names ) ) {
				wp_set_post_tags( $post_id, $tag_names, false );
			}
		}

		// Optionally update Paper Store record status.
		if ( $update_paper && ! empty( $record_id ) && ! empty( $collection ) ) {
			$repo->save(
				array(
					'id'          => $record_id,
					'title'       => $title,
					'type'        => $collection,
					'status'      => 'published',
					'description' => $description,
					'meta'        => array(
						'post_id'      => $post_id,
						'post_type'    => $post_type,
						'published_at' => current_time( 'mysql' ),
					),
				)
			);
		}

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'research_post_created',
			'Draft post created from research data',
			array(
				'post_id'     => $post_id,
				'post_type'   => $post_type,
				'post_status' => $post_status,
				'title'       => $title,
				'source'      => $record_id ? 'paper_store' : 'raw_data',
				'author_id'   => $author_id,
			)
		);

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: 1: post title, 2: post status */
				__( 'Draft post "%1$s" created with status "%2$s".', 'mcp-ai-wpoos-pro' ),
				$title,
				$post_status
			),
			array(
				'post_id'     => $post_id,
				'post_type'   => esc_html( $post_type ),
				'post_status' => esc_html( $post_status ),
				'title'       => esc_html( $title ),
				'edit_url'    => esc_url( get_edit_post_link( $post_id, 'raw' ) ),
				'permalink'   => esc_url( get_permalink( $post_id ) ),
				'author_id'   => $author_id,
				'source'      => $record_id ? 'paper_store' : 'raw_data',
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'local-only', 'requires-capability', 'pro' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'input_schema'          => $this->get_parameters_schema(),
			'required_capability'   => $this->get_required_capability(),
			'category'              => array( 'research', 'content', 'orchestration' ),
			'toolkit'               => 'orchestration',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'medium',
		);
	}
}
