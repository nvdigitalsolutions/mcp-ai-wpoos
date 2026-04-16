<?php
/**
 * Tool — Build Knowledge Graph
 *
 * Triggers a full or incremental build of the site's knowledge graph.
 * Orchestrates the detect → extract → build → cluster pipeline.
 *
 * @package WP_MCP_AI
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build Knowledge Graph tool implementation.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Graphify_Build implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'graphify_build_graph';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Build Knowledge Graph', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Build or rebuild the site knowledge graph from WordPress content. Extracts posts, pages, taxonomy terms, authors, and their relationships (internal links, categories, tags, authorship, featured images) into a queryable graph structure with community detection. Use "full" mode for a complete rebuild or "incremental" to update only content changed since the last build.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'mode'       => array(
					'type'        => 'string',
					'description' => __( 'Build mode: "full" clears and rebuilds the entire graph; "incremental" updates only content modified since the last build.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'full', 'incremental' ),
					'default'     => 'full',
				),
				'post_types' => array(
					'type'        => 'array',
					'description' => __( 'Post types to include in the graph. Defaults to post and page.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array( 'post', 'page' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Build results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You need manage_options capability to build the knowledge graph.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$mode       = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'full';
		$post_types = isset( $arguments['post_types'] ) && is_array( $arguments['post_types'] )
			? array_map( 'sanitize_key', $arguments['post_types'] )
			: array( 'post', 'page' );

		// Validate post types exist.
		$valid_types = array();
		foreach ( $post_types as $pt ) {
			if ( post_type_exists( $pt ) ) {
				$valid_types[] = $pt;
			}
		}

		if ( empty( $valid_types ) ) {
			return new WP_Error(
				'wp_mcp_ai_graphify_invalid_types',
				__( 'None of the specified post types exist on this site.', 'mcp-ai-wpoos' )
			);
		}

		$graphify = WP_MCP_AI_Graphify::get_instance();
		$result   = $graphify->build_graph(
			array(
				'mode'       => $mode,
				'post_types' => $valid_types,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_chat_response( $result, $result['message'] );
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.6.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'web_developer', 'content_strategist', 'seo_specialist' ),
			'risk_level'            => 'medium',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'state-changing',
			'local-only',
			'requires-capability',
		);
	}
}
