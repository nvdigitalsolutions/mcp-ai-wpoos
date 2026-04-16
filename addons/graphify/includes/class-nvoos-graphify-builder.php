<?php
/**
 * NV oOS Graphify Addon — Graph Builder
 *
 * Merges extracted graph data (nodes and edges) into the database,
 * handling deduplication, batch inserts, degree calculation, and
 * metadata updates.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Graph builder for the NV oOS Graphify Addon.
 *
 * Takes the output of one or more extractors and persists the
 * resulting nodes and edges into the custom database tables
 * managed by {@see NV_oOS_Graphify_Database}.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Builder {

	/**
	 * Number of rows to insert per batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 100;

	/**
	 * Confidence priority map (higher value = higher priority).
	 *
	 * @var array
	 */
	const CONFIDENCE_PRIORITY = array(
		'EXTRACTED' => 3,
		'INFERRED'  => 2,
		'AMBIGUOUS' => 1,
	);

	/**
	 * Graph ID for all database operations.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $graph_id;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int $graph_id Graph ID to operate on.
	 */
	public function __construct( $graph_id ) {
		$this->graph_id = (int) $graph_id;
	}

	/**
	 * Persist extracted graph data into the database.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $extracted_data {
	 *     Extracted graph data from an extractor.
	 *
	 *     @type array $nodes Array of node arrays.
	 *     @type array $edges Array of edge arrays.
	 * }
	 * @param string $mode Build mode: 'full' truncates existing data first; 'incremental' appends.
	 * @return array {
	 *     Build statistics.
	 *
	 *     @type int    $nodes_inserted Number of nodes written.
	 *     @type int    $edges_inserted Number of edges written.
	 *     @type string $mode           Build mode used.
	 *     @type string $built_at       MySQL datetime of the build.
	 * }
	 */
	public function build( $extracted_data, $mode = 'full' ) {
		$nodes = isset( $extracted_data['nodes'] ) ? $extracted_data['nodes'] : array();
		$edges = isset( $extracted_data['edges'] ) ? $extracted_data['edges'] : array();

		if ( 'full' === $mode ) {
			$this->clear_graph( $this->graph_id );
		}

		$nodes = $this->deduplicate_nodes( $nodes );
		$edges = $this->deduplicate_edges( $edges );

		$nodes_inserted = $this->insert_nodes( $nodes, $this->graph_id );
		$edges_inserted = $this->insert_edges( $edges, $this->graph_id );

		$this->update_degree_counts( $this->graph_id );
		$this->update_graph_meta( $this->graph_id );

		$stats = array(
			'nodes_inserted' => $nodes_inserted,
			'edges_inserted' => $edges_inserted,
			'mode'           => $mode,
			'built_at'       => current_time( 'mysql' ),
		);

		/**
		 * Fires after the knowledge graph has been built.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $graph_id Graph ID.
		 * @param array $stats    Build statistics.
		 */
		do_action( 'nvoos_graphify_graph_built', $this->graph_id, $stats );

		return $stats;
	}

	/**
	 * Delete all nodes and edges for a specific graph.
	 *
	 * @since 0.1.0
	 *
	 * @param int $graph_id Graph ID to clear.
	 * @return void
	 */
	public function clear_graph( $graph_id ) {
		global $wpdb;

		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $nodes_table, array( 'graph_id' => (int) $graph_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $edges_table, array( 'graph_id' => (int) $graph_id ), array( '%d' ) );
	}

	/**
	 * Batch-insert nodes into the database.
	 *
	 * Processes nodes in chunks of {@see BATCH_SIZE} and uses
	 * REPLACE INTO to handle upserts on the unique (node_id, graph_id) key.
	 *
	 * @since 0.1.0
	 *
	 * @param array $nodes    Array of node arrays.
	 * @param int   $graph_id Graph ID to assign.
	 * @return int Number of nodes inserted.
	 */
	public function insert_nodes( $nodes, $graph_id ) {
		global $wpdb;

		if ( empty( $nodes ) ) {
			return 0;
		}

		$table   = NV_oOS_Graphify_Database::get_nodes_table();
		$now     = current_time( 'mysql' );
		$count   = 0;
		$batches = array_chunk( $nodes, self::BATCH_SIZE );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $node ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->replace(
					$table,
					array(
						'graph_id'    => (int) $graph_id,
						'node_id'     => sanitize_text_field( $node['node_id'] ),
						'label'       => sanitize_text_field( $node['label'] ),
						'node_type'   => sanitize_text_field( $node['node_type'] ),
						'source_type' => sanitize_text_field( $node['source_type'] ),
						'source_id'   => (int) $node['source_id'],
						'source_url'  => esc_url_raw( $node['source_url'] ),
						'metadata'    => $node['metadata'],
						'created_at'  => $now,
						'updated_at'  => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
				);

				if ( false !== $result ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Batch-insert edges into the database.
	 *
	 * Processes edges in chunks of {@see BATCH_SIZE} using
	 * `$wpdb->insert()` for each row.
	 *
	 * @since 0.1.0
	 *
	 * @param array $edges    Array of edge arrays.
	 * @param int   $graph_id Graph ID to assign.
	 * @return int Number of edges inserted.
	 */
	public function insert_edges( $edges, $graph_id ) {
		global $wpdb;

		if ( empty( $edges ) ) {
			return 0;
		}

		$table   = NV_oOS_Graphify_Database::get_edges_table();
		$now     = current_time( 'mysql' );
		$count   = 0;
		$batches = array_chunk( $edges, self::BATCH_SIZE );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $edge ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->insert(
					$table,
					array(
						'graph_id'         => (int) $graph_id,
						'source_node_id'   => sanitize_text_field( $edge['source_node_id'] ),
						'target_node_id'   => sanitize_text_field( $edge['target_node_id'] ),
						'relation'         => sanitize_text_field( $edge['relation'] ),
						'confidence'       => sanitize_text_field( $edge['confidence'] ),
						'confidence_score' => (float) $edge['confidence_score'],
						'metadata'         => $edge['metadata'],
						'created_at'       => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
				);

				if ( false !== $result ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Recalculate degree counts for all nodes in a graph.
	 *
	 * A node's degree is the total number of edges where it appears
	 * as either source or target.
	 *
	 * @since 0.1.0
	 *
	 * @param int $graph_id Graph ID.
	 * @return void
	 */
	public function update_degree_counts( $graph_id ) {
		global $wpdb;

		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$nodes_table} SET degree = (
					SELECT COUNT(*) FROM {$edges_table}
					WHERE {$edges_table}.source_node_id = {$nodes_table}.node_id
					AND {$edges_table}.graph_id = %d
				) + (
					SELECT COUNT(*) FROM {$edges_table}
					WHERE {$edges_table}.target_node_id = {$nodes_table}.node_id
					AND {$edges_table}.graph_id = %d
				)
				WHERE {$nodes_table}.graph_id = %d",
				(int) $graph_id,
				(int) $graph_id,
				(int) $graph_id
			)
		);
	}

	/**
	 * Insert or update the graph metadata row with current statistics.
	 *
	 * @since 0.1.0
	 *
	 * @param int $graph_id Graph ID.
	 * @return void
	 */
	public function update_graph_meta( $graph_id ) {
		global $wpdb;

		$meta_table  = NV_oOS_Graphify_Database::get_meta_table();
		$nodes_table = NV_oOS_Graphify_Database::get_nodes_table();
		$edges_table = NV_oOS_Graphify_Database::get_edges_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$nodes_table} WHERE graph_id = %d",
				(int) $graph_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$edge_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$edges_table} WHERE graph_id = %d",
				(int) $graph_id
			)
		);

		$now     = current_time( 'mysql' );
		$site_id = is_multisite() ? get_current_blog_id() : 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$meta_table,
			array(
				'graph_id'    => (int) $graph_id,
				'site_id'     => (int) $site_id,
				'node_count'  => $node_count,
				'edge_count'  => $edge_count,
				'last_built'  => $now,
				'build_status' => 'complete',
				'settings'    => wp_json_encode( NV_oOS_Graphify::get_settings() ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Deduplicate nodes by node_id.
	 *
	 * When duplicate node_id values exist the last occurrence wins.
	 *
	 * @since 0.1.0
	 *
	 * @param array $nodes Array of node arrays.
	 * @return array Deduplicated array of node arrays (re-indexed).
	 */
	public function deduplicate_nodes( $nodes ) {
		$unique = array();

		foreach ( $nodes as $node ) {
			if ( isset( $node['node_id'] ) ) {
				$unique[ $node['node_id'] ] = $node;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Deduplicate edges by (source_node_id, target_node_id, relation).
	 *
	 * When duplicates exist the edge with the highest confidence
	 * priority is kept (EXTRACTED > INFERRED > AMBIGUOUS).
	 *
	 * @since 0.1.0
	 *
	 * @param array $edges Array of edge arrays.
	 * @return array Deduplicated array of edge arrays (re-indexed).
	 */
	public function deduplicate_edges( $edges ) {
		$unique = array();

		foreach ( $edges as $edge ) {
			$key = $edge['source_node_id'] . '|' . $edge['target_node_id'] . '|' . $edge['relation'];

			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $edge;
				continue;
			}

			$existing_confidence = isset( $unique[ $key ]['confidence'] ) ? $unique[ $key ]['confidence'] : 'AMBIGUOUS';
			$new_confidence      = isset( $edge['confidence'] ) ? $edge['confidence'] : 'AMBIGUOUS';

			$existing_priority = isset( self::CONFIDENCE_PRIORITY[ $existing_confidence ] ) ? self::CONFIDENCE_PRIORITY[ $existing_confidence ] : 0;
			$new_priority      = isset( self::CONFIDENCE_PRIORITY[ $new_confidence ] ) ? self::CONFIDENCE_PRIORITY[ $new_confidence ] : 0;

			if ( $new_priority > $existing_priority ) {
				$unique[ $key ] = $edge;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Get current graph statistics from the database.
	 *
	 * @since 0.1.0
	 *
	 * @param int $graph_id Graph ID.
	 * @return array {
	 *     Graph statistics.
	 *
	 *     @type int    $node_count   Number of nodes.
	 *     @type int    $edge_count   Number of edges.
	 *     @type string $build_status Current build status.
	 * }
	 */
	public function get_stats( $graph_id ) {
		global $wpdb;

		$meta_table = NV_oOS_Graphify_Database::get_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT node_count, edge_count, build_status FROM {$meta_table} WHERE graph_id = %d",
				(int) $graph_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return array(
				'node_count'   => 0,
				'edge_count'   => 0,
				'build_status' => 'idle',
			);
		}

		return array(
			'node_count'   => (int) $row['node_count'],
			'edge_count'   => (int) $row['edge_count'],
			'build_status' => $row['build_status'],
		);
	}
}
