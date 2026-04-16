<?php
/**
 * Tool for building knowledge graphs from WordPress content.
 *
 * Triggers full or incremental graph builds by detecting content,
 * extracting structural entities and relationships, and persisting
 * the resulting graph.
 *
 * @package NV_oOS_Graphify
 * @since   1.0.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build Knowledge Graph Tool.
 *
 * Orchestrates the three-stage pipeline: detect → extract → build.
 * Supports both full rebuilds and incremental updates scoped to
 * content modified since the previous build.
 *
 * @since 1.0.0
 */
class NV_oOS_Graphify_Tool_Build_Graph implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_build_graph';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Build Knowledge Graph', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Triggers a full or incremental knowledge graph build from WordPress content. Extracts entities and relationships from posts, pages, taxonomies, and users to create a navigable graph structure.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'mode'          => array(
					'type'        => 'string',
					'enum'        => array( 'full', 'incremental' ),
					'description' => __( "Build mode. 'full' rebuilds the entire graph. 'incremental' only processes content changed since the last build.", 'mcp-ai-wpoos' ),
					'default'     => 'full',
				),
				'content_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Post types to include. Defaults to the configured post types in settings.', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'state-changing',
			'long-running',
			'local-only',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.0.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_strategist', 'seo_specialist' ),
			'risk_level'            => 'medium',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user || ! $user->has_cap( 'manage_options' ) ) {
				return new WP_Error(
					'forbidden',
					__( 'You do not have permission to build the knowledge graph.', 'mcp-ai-wpoos' )
				);
			}
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to build the knowledge graph.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		$mode     = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'full';
		$graph_id = NV_oOS_Graphify::get_graph_id();
		$settings = NV_oOS_Graphify::get_settings();

		if ( isset( $arguments['content_types'] ) && is_array( $arguments['content_types'] ) ) {
			$settings['content_types'] = array_map( 'sanitize_text_field', $arguments['content_types'] );
		}

		$start_time  = microtime( true );
		$incremental = ( 'incremental' === $mode );
		$since       = null;

		if ( $incremental ) {
			global $wpdb;
			$meta_table = NV_oOS_Graphify_Database::get_meta_table();
			$since      = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$meta_table} WHERE graph_id = %s AND meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$graph_id,
					'last_built'
				)
			);
		}

		$detector         = new NV_oOS_Graphify_Detector( $settings );
		$detected_content = $detector->detect( $incremental, $since );

		$extractor      = new NV_oOS_Graphify_Extractor_Structural( $graph_id );
		$extracted_data = $extractor->extract( $detected_content );

		$builder = new NV_oOS_Graphify_Builder( $graph_id );
		$stats   = $builder->build( $extracted_data, $mode );

		$elapsed = round( microtime( true ) - $start_time, 2 );

		$node_count = isset( $stats['node_count'] ) ? absint( $stats['node_count'] ) : 0;
		$edge_count = isset( $stats['edge_count'] ) ? absint( $stats['edge_count'] ) : 0;

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: node count, 2: edge count, 3: elapsed seconds, 4: build mode */
				__( 'Knowledge graph built successfully: %1$d nodes, %2$d edges in %3$s seconds (%4$s mode).', 'mcp-ai-wpoos' ),
				$node_count,
				$edge_count,
				$elapsed,
				$mode
			),
			'data'    => array(
				'node_count' => $node_count,
				'edge_count' => $edge_count,
				'build_time' => $elapsed,
				'mode'       => $mode,
			),
		);
	}
}
