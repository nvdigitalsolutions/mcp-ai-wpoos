<?php
/**
 * Graphify Knowledge Graph — Core Orchestrator
 *
 * Singleton that ties together the full pipeline: detect → extract → build
 * → cluster → analyze. Entry point for tools and REST endpoints.
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
 * Main orchestrator for the Graphify knowledge graph subsystem.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Graphify|null
	 */
	private static $instance = null;

	/**
	 * Default graph ID.
	 *
	 * @var int
	 */
	const DEFAULT_GRAPH_ID = 1;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Graphify
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton' );
	}

	/**
	 * Build the knowledge graph.
	 *
	 * Orchestrates the full pipeline: detect → extract → build → cluster → analyze.
	 *
	 * @param array $options {
	 *     Build options.
	 *
	 *     @type string $mode       'full' or 'incremental'. Default 'full'.
	 *     @type array  $post_types Post types to include. Default: post, page.
	 *     @type int    $graph_id   Graph ID. Default 1.
	 * }
	 * @return array|WP_Error Build summary or error.
	 */
	public function build_graph( $options = array() ) {
		$defaults = array(
			'mode'       => 'full',
			'post_types' => array( 'post', 'page' ),
			'graph_id'   => self::DEFAULT_GRAPH_ID,
		);

		$options  = wp_parse_args( $options, $defaults );
		$graph_id = absint( $options['graph_id'] );

		$db = 'WP_MCP_AI_Graphify_Database';

		// Ensure tables exist.
		$db::create_tables();

		// Ensure graph meta record exists.
		$meta = $db::get_graph_meta( $graph_id );

		// Prevent concurrent builds.
		if ( $meta && 'building' === $meta['build_status'] ) {
			return new WP_Error(
				'wp_mcp_ai_graphify_building',
				__( 'A graph build is already in progress. Please wait for it to complete.', 'mcp-ai-wpoos' )
			);
		}

		$is_full = ( 'incremental' !== $options['mode'] );

		// 1. Detect content.
		$detector       = new WP_MCP_AI_Graphify_Detector();
		$detect_options = array(
			'post_types'         => $options['post_types'],
			'include_taxonomies' => true,
			'include_users'      => true,
			'include_media'      => false,
		);

		if ( ! $is_full && ! empty( $meta['last_built'] ) ) {
			$detect_options['since'] = $meta['last_built'];
		}

		$detection = $detector->detect( $detect_options );

		if ( empty( $detection['posts'] ) && empty( $detection['terms'] ) && empty( $detection['users'] ) ) {
			if ( ! $is_full ) {
				return array(
					'message'      => __( 'No content changes detected since last build.', 'mcp-ai-wpoos' ),
					'nodes_written' => 0,
					'edges_written' => 0,
					'total_nodes'   => isset( $meta['node_count'] ) ? (int) $meta['node_count'] : 0,
					'total_edges'   => isset( $meta['edge_count'] ) ? (int) $meta['edge_count'] : 0,
					'communities'   => isset( $meta['community_count'] ) ? (int) $meta['community_count'] : 0,
				);
			}

			return new WP_Error(
				'wp_mcp_ai_graphify_no_content',
				__( 'No published content found for the selected post types.', 'mcp-ai-wpoos' )
			);
		}

		// 2. Extract structural relationships.
		$extractor  = new WP_MCP_AI_Graphify_Extractor_Structural();
		$extraction = $extractor->extract( $detection, $graph_id );

		// 3. Build (persist to database).
		$builder      = new WP_MCP_AI_Graphify_Builder();
		$build_result = $builder->build( $graph_id, $extraction, $is_full );

		// 4. Cluster (community detection).
		$cluster_result = array( 'community_count' => 0 );
		if ( $build_result['total_nodes'] >= 3 ) {
			$clusterer      = new WP_MCP_AI_Graphify_Cluster();
			$cluster_result = $clusterer->cluster( $graph_id );
		}

		return array(
			'message'        => sprintf(
				/* translators: 1: node count, 2: edge count, 3: community count */
				__( 'Knowledge graph built: %1$d nodes, %2$d edges, %3$d communities.', 'mcp-ai-wpoos' ),
				$build_result['total_nodes'],
				$build_result['total_edges'],
				$cluster_result['community_count']
			),
			'nodes_written'  => $build_result['nodes_written'],
			'edges_written'  => $build_result['edges_written'],
			'total_nodes'    => $build_result['total_nodes'],
			'total_edges'    => $build_result['total_edges'],
			'communities'    => $cluster_result['community_count'],
			'detection_stats' => $detection['stats'],
		);
	}

	/**
	 * Get graph statistics.
	 *
	 * @param int $graph_id Graph ID.
	 * @return array Stats from analyzer.
	 */
	public function get_stats( $graph_id = 1 ) {
		$analyzer = new WP_MCP_AI_Graphify_Analyzer();
		return $analyzer->graph_stats( $graph_id );
	}

	/**
	 * Query the graph with a natural language question.
	 *
	 * @param string $question     Question text.
	 * @param string $mode         'bfs' or 'dfs'.
	 * @param int    $depth        Traversal depth.
	 * @param int    $token_budget Token budget.
	 * @param int    $graph_id     Graph ID.
	 * @return array Query results.
	 */
	public function query_graph( $question, $mode = 'bfs', $depth = 2, $token_budget = 4000, $graph_id = 1 ) {
		$query_engine = new WP_MCP_AI_Graphify_Query();
		return $query_engine->query( $question, $mode, $depth, $token_budget, $graph_id );
	}
}
