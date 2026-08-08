<?php
/**
 * Tool for reading a single WordPress post by ID.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a single WordPress post with its metadata and taxonomy terms.
 */
class WP_MCP_AI_Tool_Get_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Ability_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_post';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Post', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a single WordPress post by ID, including its content, metadata, and taxonomy terms.', 'mcp-ai-wpoos' );
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
					'description' => __( 'The ID of the post to retrieve.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'include_meta'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include post meta fields in the response. Defaults to true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'include_taxonomies' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include taxonomy terms assigned to the post. Defaults to true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read posts.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'post_id is required.', 'mcp-ai-wpoos' ) );
		}

		$post_id = absint( $arguments['post_id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'The requested post does not exist.', 'mcp-ai-wpoos' ) );
		}

		// Verify the user can read posts of this type.
		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The post type is not recognised.', 'mcp-ai-wpoos' ) );
		}

		$read_cap = isset( $post_type_object->cap->read_post ) ? $post_type_object->cap->read_post : 'read';
		if ( 'publish' !== $post->post_status && ! user_can( $current_user_id, $read_cap, $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read this post.', 'mcp-ai-wpoos' ) );
		}

		$include_meta       = isset( $arguments['include_meta'] ) ? (bool) $arguments['include_meta'] : true;
		$include_taxonomies = isset( $arguments['include_taxonomies'] ) ? (bool) $arguments['include_taxonomies'] : true;

		$result = array(
			'ID'             => $post->ID,
			'post_type'      => esc_html( $post->post_type ),
			'title'          => get_the_title( $post ),
			'content'        => wp_kses_post( $post->post_content ),
			'excerpt'        => wp_kses_post( $post->post_excerpt ),
			'status'         => esc_html( $post->post_status ),
			'author_id'      => (int) $post->post_author,
			'date'           => get_the_date( DATE_W3C, $post ),
			'modified'       => get_the_modified_date( DATE_W3C, $post ),
			'slug'           => esc_html( $post->post_name ),
			'parent_id'      => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => esc_html( $post->comment_status ),
			'permalink'      => esc_url( get_permalink( $post ) ),
		);

		$edit_link = get_edit_post_link( $post_id, '' );
		if ( $edit_link ) {
			$result['edit_link'] = $edit_link;
		}

		if ( $include_meta ) {
			$raw_meta = get_post_meta( $post_id );
			$meta     = array();
			foreach ( $raw_meta as $key => $values ) {
				// Skip protected/internal keys that start with underscore.
				if ( '_' === $key[0] ) {
					continue;
				}
				$value = count( $values ) === 1 ? $values[0] : $values;
				// Escape HTML in meta values to prevent stored XSS.
				if ( is_string( $value ) ) {
					$value = wp_kses_post( $value );
				}
				$meta[ $key ] = $value;
			}
			$result['meta'] = $meta;
		}

		if ( $include_taxonomies ) {
			$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
			$terms_map  = array();
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$terms_map[ $taxonomy ] = array_values(
						array_map(
							function ( $term ) {
								return array(
									'term_id' => $term->term_id,
									'name'    => esc_html( $term->name ),
									'slug'    => esc_attr( $term->slug ),
								);
							},
							$terms
						)
					);
				}
			}
			$result['taxonomies'] = $terms_map;
		}

		$summary_text = sprintf(
			/* translators: 1: post title, 2: post ID */
			__( 'Post retrieved: %1$s (ID: %2$d)', 'mcp-ai-wpoos' ),
			get_the_title( $post ),
			$post->ID
		);

		$result['message'] = $summary_text;
		$result['summary'] = $summary_text;

		return $result;
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
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer', 'sequential' ),
			'profession_tags'       => array( 'writer', 'content_creator', 'editor', 'developer' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires 'read' capability.
			'cacheable',           // Results can be cached.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_ability_identifier() {
		return 'get-post';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_ability_category() {
		return 'nvoos-content';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function get_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ID'             => array(
					'type'        => 'integer',
					'description' => 'The post ID.',
				),
				'post_type'      => array(
					'type'        => 'string',
					'description' => 'The post type slug.',
				),
				'title'          => array(
					'type'        => 'string',
					'description' => 'The post title.',
				),
				'content'        => array(
					'type'        => 'string',
					'description' => 'The post content (HTML).',
				),
				'excerpt'        => array(
					'type'        => 'string',
					'description' => 'The post excerpt.',
				),
				'status'         => array(
					'type'        => 'string',
					'description' => 'The post status.',
				),
				'author_id'      => array(
					'type'        => 'integer',
					'description' => 'The author user ID.',
				),
				'date'           => array(
					'type'        => 'string',
					'description' => 'The post date (W3C format).',
				),
				'modified'       => array(
					'type'        => 'string',
					'description' => 'The last modified date (W3C format).',
				),
				'slug'           => array(
					'type'        => 'string',
					'description' => 'The post slug.',
				),
				'parent_id'      => array(
					'type'        => 'integer',
					'description' => 'The parent post ID.',
				),
				'menu_order'     => array(
					'type'        => 'integer',
					'description' => 'The menu order.',
				),
				'comment_status' => array(
					'type'        => 'string',
					'description' => 'The comment status.',
				),
				'permalink'      => array(
					'type'        => 'string',
					'description' => 'The post permalink.',
				),
				'edit_link'      => array(
					'type'        => 'string',
					'description' => 'The admin edit link.',
				),
				'meta'           => array(
					'type'        => 'object',
					'description' => 'Post meta fields (if include_meta=true).',
				),
				'taxonomies'     => array(
					'type'        => 'object',
					'description' => 'Taxonomy terms (if include_taxonomies=true).',
				),
				'message'        => array(
					'type'        => 'string',
					'description' => 'Human-readable summary.',
				),
				'summary'        => array(
					'type'        => 'string',
					'description' => 'Human-readable summary.',
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function is_public_ability() {
		return true;
	}
}
