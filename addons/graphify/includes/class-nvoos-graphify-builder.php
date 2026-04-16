<?php
/**
 * Graph builder — merges extraction results and writes to database.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NV_oOS_Graphify_Builder
 *
 * Orchestrates full and incremental graph builds by running detectors,
 * extractors, merging their results, persisting to the database, and
 * triggering downstream clustering / degree updates.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Builder {

	/**
	 * Graph identifier.
	 *
	 * @var int
	 */
	private $graph_id;

	/**
	 * Database table names keyed by 'nodes', 'edges', 'meta'.
	 *
	 * @var array
	 */
	private $tables;

	/**
	 * Confidence ranking used for deduplication (higher index = higher trust).
	 *
	 * @var array
	 */
	private static $confidence_rank = array(
		'AMBIGUOUS' => 0,
		'INFERRED'  => 1,
		'EXTRACTED' => 2,
	);

	/**
	 * Constructor.
	 *
	 * @param int $graph_id Graph identifier. Default 1.
	 */
	public function __construct( $graph_id = 1 ) {
		$this->graph_id = absint( $graph_id );
		if ( $this->graph_id < 1 ) {
			$this->graph_id = 1;
		}
		$this->tables = NV_oOS_Graphify_DB::get_table_names();
	}

	/**
	 * Run a full graph build.
	 *
	 * Clears existing data, detects inventory, extracts structural and
	 * (optionally) semantic relationships, merges, clusters, and updates
	 * all metadata.
	 *
	 * @return array|WP_Error Build statistics on success, WP_Error on failure.
	 */
	public function build_full() {
		global $wpdb;

		try {
			// 1. Mark as building.
			NV_oOS_Graphify_DB::update_graph_meta(
				$this->graph_id,
				array( 'build_status' => 'building' )
			);

			// 2. Clear existing graph data.
			$this->clear_graph( $this->graph_id );

			// 3. Detect content inventory.
			$detector  = new NV_oOS_Graphify_Detector();
			$inventory = $detector->detect();

			// 4. Structural extraction.
			$structural   = new NV_oOS_Graphify_Extractor_Structural();
			$struct_result = $structural->extract( $inventory );

			// 5. Semantic extraction (if enabled).
			$sem_result = null;
			$settings   = NV_oOS_Graphify::get_settings();
			if ( ! empty( $settings['include_semantic'] ) ) {
				$semantic   = new NV_oOS_Graphify_Extractor_Semantic();
				$sem_result = $semantic->extract( $inventory );
			}

			// 6. Merge and persist.
			$this->merge_and_store( $struct_result, $sem_result );

			// 7. Community detection.
			$cluster = new NV_oOS_Graphify_Cluster();
			$cluster->detect_communities( $this->graph_id );

			// 8. Degree calculation.
			$this->update_degrees();

			// 9. Gather counts.
			$node_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE graph_id = %d',
					$this->tables['nodes'],
					$this->graph_id
				)
			);

			$edge_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE graph_id = %d',
					$this->tables['edges'],
					$this->graph_id
				)
			);

			$community_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT community_id) FROM %i WHERE graph_id = %d AND community_id IS NOT NULL',
					$this->tables['nodes'],
					$this->graph_id
				)
			);

			// 10. Update graph meta.
			NV_oOS_Graphify_DB::update_graph_meta(
				$this->graph_id,
				array(
					'node_count'      => $node_count,
					'edge_count'      => $edge_count,
					'community_count' => $community_count,
					'build_status'    => 'complete',
					'last_built'      => current_time( 'mysql', true ),
				)
			);

			// 11. Fire completion action.
			do_action( 'nvoos_graphify_graph_built', $this->graph_id );

			return array(
				'success'         => true,
				'graph_id'        => $this->graph_id,
				'node_count'      => $node_count,
				'edge_count'      => $edge_count,
				'community_count' => $community_count,
			);

		} catch ( \Exception $e ) {
			NV_oOS_Graphify_DB::update_graph_meta(
				$this->graph_id,
				array( 'build_status' => 'error' )
			);

			return new WP_Error(
				'graphify_build_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Graph build failed: %s', 'nvoos-graphify' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Incrementally update the graph for a single post.
	 *
	 * Removes old nodes/edges for the post, re-extracts, and updates
	 * degrees for affected neighbours.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|WP_Error Updated stats on success, WP_Error on failure.
	 */
	public function build_incremental( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return new WP_Error(
				'graphify_invalid_post',
				__( 'Invalid post ID.', 'nvoos-graphify' )
			);
		}

		try {
			// 1. Detect single post inventory.
			$detector  = new NV_oOS_Graphify_Detector();
			$inventory = $detector->detect_single( $post_id );

			$node_id = 'post_' . $post_id;

			// 2. Collect affected neighbours before removal.
			$affected = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT DISTINCT CASE WHEN source_node_id = %s THEN target_node_id ELSE source_node_id END
					 FROM %i WHERE graph_id = %d AND ( source_node_id = %s OR target_node_id = %s )',
					$node_id,
					$this->tables['edges'],
					$this->graph_id,
					$node_id,
					$node_id
				)
			);

			// 3. Remove existing edges for this node.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'DELETE FROM %i WHERE graph_id = %d AND ( source_node_id = %s OR target_node_id = %s )',
					$this->tables['edges'],
					$this->graph_id,
					$node_id,
					$node_id
				)
			);

			// 4. Remove existing node.
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->tables['nodes'],
				array(
					'graph_id' => $this->graph_id,
					'node_id'  => $node_id,
				),
				array( '%d', '%s' )
			);

			// 5. Structural extraction for this post.
			$structural    = new NV_oOS_Graphify_Extractor_Structural();
			$struct_result = $structural->extract( $inventory );

			// 6. Store new data.
			$this->merge_and_store( $struct_result );

			// 7. Update degrees for the post node and its neighbours.
			$this->update_degree_for_node( $node_id );
			if ( is_array( $affected ) ) {
				foreach ( $affected as $neighbour_id ) {
					$this->update_degree_for_node( $neighbour_id );
				}
			}

			// 8. Update graph meta counts.
			$node_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE graph_id = %d',
					$this->tables['nodes'],
					$this->graph_id
				)
			);

			$edge_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE graph_id = %d',
					$this->tables['edges'],
					$this->graph_id
				)
			);

			NV_oOS_Graphify_DB::update_graph_meta(
				$this->graph_id,
				array(
					'node_count' => $node_count,
					'edge_count' => $edge_count,
				)
			);

			return array(
				'success'    => true,
				'post_id'    => $post_id,
				'node_count' => $node_count,
				'edge_count' => $edge_count,
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'graphify_incremental_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Incremental build failed: %s', 'nvoos-graphify' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Merge extraction results and persist to the database.
	 *
	 * Deduplicates nodes by node_id (first occurrence wins) and edges by
	 * source + target + relation (highest confidence wins).
	 *
	 * @param array      $structural Structural extraction result with 'nodes' and 'edges'.
	 * @param array|null $semantic   Optional semantic extraction result.
	 * @return void
	 */
	public function merge_and_store( $structural, $semantic = null ) {
		global $wpdb;

		// -- Collect nodes ---------------------------------------------------
		$all_nodes = array();
		if ( ! empty( $structural['nodes'] ) ) {
			foreach ( $structural['nodes'] as $node ) {
				$all_nodes[] = $node;
			}
		}
		if ( null !== $semantic && ! empty( $semantic['nodes'] ) ) {
			foreach ( $semantic['nodes'] as $node ) {
				$all_nodes[] = $node;
			}
		}

		// Deduplicate nodes: keep first occurrence per node_id.
		$unique_nodes = array();
		$seen_nodes   = array();
		foreach ( $all_nodes as $node ) {
			if ( empty( $node['node_id'] ) ) {
				continue;
			}
			if ( isset( $seen_nodes[ $node['node_id'] ] ) ) {
				continue;
			}
			$seen_nodes[ $node['node_id'] ] = true;
			$unique_nodes[]                  = $node;
		}

		// -- Collect edges ---------------------------------------------------
		$all_edges = array();
		if ( ! empty( $structural['edges'] ) ) {
			foreach ( $structural['edges'] as $edge ) {
				$all_edges[] = $edge;
			}
		}
		if ( null !== $semantic && ! empty( $semantic['edges'] ) ) {
			foreach ( $semantic['edges'] as $edge ) {
				$all_edges[] = $edge;
			}
		}

		// Deduplicate edges: same source+target+relation keeps highest confidence.
		$unique_edges = array();
		foreach ( $all_edges as $edge ) {
			if ( empty( $edge['source_node_id'] ) || empty( $edge['target_node_id'] ) || empty( $edge['relation'] ) ) {
				continue;
			}
			$key = $edge['source_node_id'] . '|' . $edge['target_node_id'] . '|' . $edge['relation'];
			if ( ! isset( $unique_edges[ $key ] ) ) {
				$unique_edges[ $key ] = $edge;
			} else {
				$existing_rank = isset( self::$confidence_rank[ $unique_edges[ $key ]['confidence'] ] )
					? self::$confidence_rank[ $unique_edges[ $key ]['confidence'] ]
					: 0;
				$new_rank      = isset( self::$confidence_rank[ $edge['confidence'] ] )
					? self::$confidence_rank[ $edge['confidence'] ]
					: 0;
				if ( $new_rank > $existing_rank ) {
					$unique_edges[ $key ] = $edge;
				}
			}
		}

		// -- Persist nodes ---------------------------------------------------
		foreach ( $unique_nodes as $node ) {
			$metadata_json = isset( $node['metadata'] ) ? wp_json_encode( $node['metadata'] ) : null;

			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->tables['nodes'],
				array(
					'graph_id'    => $this->graph_id,
					'node_id'     => sanitize_text_field( $node['node_id'] ),
					'label'       => sanitize_text_field( $node['label'] ),
					'node_type'   => sanitize_text_field( $node['node_type'] ),
					'source_type' => isset( $node['source_type'] ) ? sanitize_text_field( $node['source_type'] ) : null,
					'source_id'   => isset( $node['source_id'] ) ? absint( $node['source_id'] ) : null,
					'source_url'  => isset( $node['source_url'] ) ? esc_url_raw( $node['source_url'] ) : null,
					'metadata'    => $metadata_json,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			/**
			 * Fires after a node is extracted and stored.
			 *
			 * @param array $node Node data.
			 */
			do_action( 'nvoos_graphify_node_extracted', $node );
		}

		// -- Persist edges ---------------------------------------------------
		foreach ( $unique_edges as $edge ) {
			$metadata_json = isset( $edge['metadata'] ) ? wp_json_encode( $edge['metadata'] ) : null;

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->tables['edges'],
				array(
					'graph_id'         => $this->graph_id,
					'source_node_id'   => sanitize_text_field( $edge['source_node_id'] ),
					'target_node_id'   => sanitize_text_field( $edge['target_node_id'] ),
					'relation'         => sanitize_text_field( $edge['relation'] ),
					'confidence'       => sanitize_text_field( $edge['confidence'] ),
					'confidence_score' => isset( $edge['confidence_score'] ) ? floatval( $edge['confidence_score'] ) : 1.0,
					'metadata'         => $metadata_json,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%f', '%s' )
			);

			/**
			 * Fires after an edge is extracted and stored.
			 *
			 * @param array $edge Edge data.
			 */
			do_action( 'nvoos_graphify_edge_extracted', $edge );
		}
	}

	/**
	 * Update the degree column for every node in the current graph.
	 *
	 * Degree = number of edges where the node appears as source or target.
	 *
	 * @return void
	 */
	public function update_degrees() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS n SET degree = (
					SELECT COUNT(*) FROM %i AS e
					WHERE e.graph_id = %d
					  AND ( e.source_node_id = n.node_id OR e.target_node_id = n.node_id )
				) WHERE n.graph_id = %d',
				$this->tables['nodes'],
				$this->tables['edges'],
				$this->graph_id,
				$this->graph_id
			)
		);
	}

	/**
	 * Update degree for a single node.
	 *
	 * @param string $node_id The node identifier.
	 * @return void
	 */
	private function update_degree_for_node( $node_id ) {
		global $wpdb;

		$degree = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE graph_id = %d AND ( source_node_id = %s OR target_node_id = %s )',
				$this->tables['edges'],
				$this->graph_id,
				$node_id,
				$node_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->tables['nodes'],
			array( 'degree' => $degree ),
			array(
				'graph_id' => $this->graph_id,
				'node_id'  => $node_id,
			),
			array( '%d' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Delete all nodes and edges for a graph.
	 *
	 * @param int $graph_id Graph identifier.
	 * @return void
	 */
	public function clear_graph( $graph_id ) {
		global $wpdb;

		$graph_id = absint( $graph_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE graph_id = %d', $this->tables['edges'], $graph_id )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE graph_id = %d', $this->tables['nodes'], $graph_id )
		);
	}

	/**
	 * Return the current build status for this graph.
	 *
	 * @return string One of 'idle', 'building', 'complete', or 'error'.
	 */
	public function get_build_status() {
		global $wpdb;

		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT build_status FROM %i WHERE graph_id = %d',
				$this->tables['meta'],
				$this->graph_id
			)
		);

		return $status ? $status : 'idle';
	}
}
