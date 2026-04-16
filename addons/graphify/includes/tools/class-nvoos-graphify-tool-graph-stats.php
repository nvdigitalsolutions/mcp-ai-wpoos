<?php
/**
 * Tool: Graph Stats
 *
 * Returns high-level statistics about the current knowledge graph.
 *
 * @package NVoOS_Graphify
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve knowledge-graph statistics.
 *
 * Includes node count, edge count, community count, last-built timestamp,
 * build status, and a confidence breakdown of edge types.
 *
 * @since 1.0.0
 */
class NV_oOS_Graphify_Tool_Graph_Stats implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_graph_stats';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Graph Statistics', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieve high-level statistics about the site knowledge graph including node count, edge count, community count, build status, and a confidence breakdown of edges (EXTRACTED, INFERRED, AMBIGUOUS).', 'nvoos-graphify' );
	}

	/**
	 * Get capability flags for the tool registry.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable', 'local-only' );
	}

	/**
	 * Get the JSON Schema for accepted parameters.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array(),
		);
	}

	/**
	 * Execute the tool and return graph statistics.
	 *
	 * @since  1.0.0
	 * @param  array $arguments Tool arguments (unused).
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Graph statistics on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$meta = NV_oOS_Graphify_DB::get_or_create_graph_meta();

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		global $wpdb;

		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$confidence_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT confidence, COUNT(*) AS total FROM %i GROUP BY confidence",
				$edges_table
			)
		);

		$breakdown = array();
		if ( $confidence_rows ) {
			foreach ( $confidence_rows as $row ) {
				$breakdown[ sanitize_text_field( $row->confidence ) ] = absint( $row->total );
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Graph statistics retrieved.', 'nvoos-graphify' ),
			'data'    => array(
				'node_count'            => isset( $meta['node_count'] ) ? absint( $meta['node_count'] ) : 0,
				'edge_count'            => isset( $meta['edge_count'] ) ? absint( $meta['edge_count'] ) : 0,
				'community_count'       => isset( $meta['community_count'] ) ? absint( $meta['community_count'] ) : 0,
				'last_built'            => isset( $meta['last_built'] ) ? sanitize_text_field( $meta['last_built'] ) : null,
				'build_status'          => isset( $meta['build_status'] ) ? sanitize_text_field( $meta['build_status'] ) : 'unknown',
				'confidence_breakdown'  => $breakdown,
			),
		);
	}
}
