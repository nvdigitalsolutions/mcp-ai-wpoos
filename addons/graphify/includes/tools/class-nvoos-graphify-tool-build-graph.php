<?php
/**
 * Tool: Build Graph
 *
 * Triggers a full or incremental knowledge-graph build from WordPress content.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build (or rebuild) the site knowledge graph.
 *
 * Delegates to NV_oOS_Graphify_Builder for heavy lifting and returns
 * build statistics such as nodes created, edges created, and duration.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Build_Graph implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_build_graph';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Build Knowledge Graph', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Build or rebuild the site knowledge graph from WordPress content. Supports full rebuild or incremental update of only changed content. Optionally include semantic similarity edges.', 'nvoos-graphify' );
	}

	/**
	 * Get capability flags for the tool registry.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'async', 'long-running', 'local-only' );
	}

	/**
	 * Get the JSON Schema for accepted parameters.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'             => array(
					'type'        => 'string',
					'enum'        => array( 'full', 'incremental' ),
					'default'     => 'full',
					'description' => __( 'Build mode: full rebuilds the entire graph, incremental updates only changed content.', 'nvoos-graphify' ),
				),
				'content_types'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional list of post types to include. Overrides saved settings when provided.', 'nvoos-graphify' ),
				),
				'include_semantic' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Whether to generate semantic similarity edges between nodes.', 'nvoos-graphify' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Execute the graph build.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Build statistics on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$mode             = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'full';
		$content_types    = isset( $arguments['content_types'] ) ? array_map( 'sanitize_text_field', (array) $arguments['content_types'] ) : array();
		$include_semantic = ! empty( $arguments['include_semantic'] );

		if ( ! in_array( $mode, array( 'full', 'incremental' ), true ) ) {
			return new WP_Error( 'invalid_mode', __( 'Mode must be "full" or "incremental".', 'nvoos-graphify' ) );
		}

		$builder = new NV_oOS_Graphify_Builder();

		if ( ! empty( $content_types ) ) {
			$builder->set_content_types( $content_types );
		}

		if ( $include_semantic ) {
			$builder->enable_semantic();
		}

		$stats = ( 'full' === $mode )
			? $builder->build_full()
			: $builder->build_incremental();

		if ( is_wp_error( $stats ) ) {
			return $stats;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: build mode (full or incremental) */
				__( 'Graph %s build completed.', 'nvoos-graphify' ),
				$mode
			),
			'data'    => $stats,
		);
	}
}
