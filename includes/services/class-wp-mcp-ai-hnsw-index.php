<?php
/**
 * HNSW Approximate Nearest Neighbor Index.
 *
 * Pure-PHP implementation of the Hierarchical Navigable Small World (HNSW)
 * algorithm for fast approximate nearest neighbor search. Designed for
 * WordPress plugin use with no external dependencies.
 *
 * Integrates with {@see WP_MCP_AI_Tool_Embedding_Store} and
 * {@see WP_MCP_AI_Content_Embedding_Store} — vectors are stored in the
 * same float32 binary packing format.
 *
 * @link https://arxiv.org/abs/1603.09320 HNSW paper (Malkov & Yashunin, 2016)
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HNSW approximate nearest neighbor index for vector similarity search.
 *
 * Builds a multi-layer navigable small-world graph over embedding vectors,
 * enabling sub-linear approximate nearest neighbor queries. Suitable for
 * datasets up to ~50K vectors within a typical WordPress request budget.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_HNSW_Index {

	/**
	 * Default number of bidirectional connections per layer.
	 *
	 * @var int
	 */
	const DEFAULT_M = 16;

	/**
	 * Default ef construction parameter — size of the dynamic candidate
	 * list during insertion.
	 *
	 * @var int
	 */
	const DEFAULT_EF_CONSTRUCTION = 200;

	/**
	 * Default ef search parameter — size of the dynamic candidate list
	 * during query.
	 *
	 * @var int
	 */
	const DEFAULT_EF_SEARCH = 100;

	/**
	 * Object cache group for persisted index data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wp_mcp_ai_hnsw';

	/**
	 * Object cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_TTL = 3600;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_HNSW_Index|null
	 */
	private static $instance = null;

	/**
	 * Vector storage: node_id => float[].
	 *
	 * @var array
	 */
	private $vectors = array();

	/**
	 * Multi-layer graph: layer => (node_id => neighbor_node_ids[]).
	 *
	 * @var array
	 */
	private $graph = array();

	/**
	 * Top-layer entry point node_id.
	 *
	 * @var string|null
	 */
	private $entry_point = null;

	/**
	 * Highest layer index in the graph.
	 *
	 * @var int
	 */
	private $max_level = 0;

	/**
	 * Connections per layer (M).
	 *
	 * @var int
	 */
	private $m = self::DEFAULT_M;

	/**
	 * Size of the dynamic candidate list during construction.
	 *
	 * @var int
	 */
	private $ef_construction = self::DEFAULT_EF_CONSTRUCTION;

	/**
	 * Size of the dynamic candidate list during search.
	 *
	 * @var int
	 */
	private $ef_search = self::DEFAULT_EF_SEARCH;

	/**
	 * Vector dimension — set on first insert.
	 *
	 * @var int|null
	 */
	private $dim = null;

	/**
	 * Distance function: 'cosine' or 'euclidean'.
	 *
	 * @var string
	 */
	private $distance_func = 'cosine';

	/**
	 * Whether stored vectors are L2-normalized (cosine → inner-product
	 * optimization).
	 *
	 * @var bool
	 */
	private $normalized = false;

	/**
	 * Whether the in-memory state has changed since the last save.
	 *
	 * @var bool
	 */
	private $modified = false;

	/**
	 * Whether data was loaded from cache.
	 *
	 * @var bool
	 */
	private $loaded = false;

	// -------------------------------------------------------------------------
	// Public API — singleton, configuration, CRUD, search
	// -------------------------------------------------------------------------

	/**
	 * Get singleton instance.
	 *
	 * @since 1.9.0
	 *
	 * @return WP_MCP_AI_HNSW_Index
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — initializes empty state.
	 *
	 * @since 1.9.0
	 */
	private function __construct() {
		// State initialized via property defaults.
	}

	/**
	 * Configure HNSW hyperparameters.
	 *
	 * Call before inserting vectors to tune the index for the expected
	 * dataset size and recall / speed trade-off.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $m              Connections per layer (default 16).
	 * @param int    $ef_construction Dynamic candidate list size during
	 *                                construction (default 200).
	 * @param int    $ef_search      Dynamic candidate list size during
	 *                                search (default 100).
	 * @param string $distance       Distance function: 'cosine' or
	 *                                'euclidean' (default 'cosine').
	 * @return void
	 */
	public function set_params( $m = 16, $ef_construction = 200, $ef_search = 100, $distance = 'cosine' ) {
		$this->m               = max( 2, (int) $m );
		$this->ef_construction = max( 1, (int) $ef_construction );
		$this->ef_search       = max( 1, (int) $ef_search );

		if ( 'euclidean' === $distance ) {
			$this->distance_func = 'euclidean';
		} else {
			$this->distance_func = 'cosine';
		}
	}

	/**
	 * Return the current ef_search value.
	 *
	 * @since 1.9.0
	 *
	 * @return int
	 */
	public function get_ef_search() {
		return $this->ef_search;
	}

	/**
	 * Set ef_search at query time (does not require a rebuild).
	 *
	 * Higher values improve recall at the cost of speed.
	 *
	 * @since 1.9.0
	 *
	 * @param int $ef Dynamic candidate list size during search.
	 * @return void
	 */
	public function set_ef_search( $ef ) {
		$this->ef_search = max( 1, (int) $ef );
	}

	/**
	 * Insert a single vector into the index.
	 *
	 * The first inserted vector sets the required dimension; all subsequent
	 * vectors must match that dimension.
	 *
	 * When distance is 'cosine', the vector is L2-normalized on insert so
	 * that similarity becomes a fast inner-product computation.
	 *
	 * @since 1.9.0
	 *
	 * @param string $node_id Unique node identifier (non-empty string).
	 * @param array  $vector  Float array representing the embedding.
	 * @return bool True on success, false on validation failure.
	 */
	public function insert( $node_id, array $vector ) {
		$node_id = trim( (string) $node_id );
		if ( '' === $node_id ) {
			return false;
		}

		// Cast all values to float and strip non-numeric keys.
		$vector = array_values( array_map( 'floatval', $vector ) );

		if ( empty( $vector ) ) {
			return false;
		}

		// Set or validate dimension.
		if ( null === $this->dim ) {
			$this->dim = count( $vector );
		} elseif ( count( $vector ) !== $this->dim ) {
			return false;
		}

		// Overwrite any existing entry (simplifies re-indexing).
		unset( $this->vectors[ $node_id ] );

		// L2-normalize for cosine distance — enables inner-product optimization.
		if ( 'cosine' === $this->distance_func ) {
			$vector           = $this->normalize_vector( $vector );
			$this->normalized = true;
		}

		// Store the vector.
		$this->vectors[ $node_id ] = $vector;

		// Random level assignment — standard HNSW formula.
		// wp_rand() can return 0; guard against log(0) by clamping to ≥1.
		$r     = max( wp_rand(), 1 ) / (float) mt_getrandmax();
		$level = (int) floor( -log( $r ) * ( 1.0 / log( $this->m ) ) );

		// Insert into the multi-layer graph.
		$this->insert_at_level( $node_id, $vector, $level );

		$this->modified = true;

		return true;
	}

	/**
	 * Batch-insert multiple vectors.
	 *
	 * @since 1.9.0
	 *
	 * @param array $items Associative array of node_id => float[] vector.
	 * @return int Number of vectors successfully inserted.
	 */
	public function insert_batch( array $items ) {
		$inserted = 0;
		foreach ( $items as $node_id => $vector ) {
			if ( $this->insert( (string) $node_id, $vector ) ) {
				++$inserted;
			}
		}
		return $inserted;
	}

	/**
	 * Remove a node from all layers of the index.
	 *
	 * Also removes the node from the neighbor lists of every other node.
	 * If the entry point is removed, a replacement is selected from the
	 * highest remaining layer.
	 *
	 * @since 1.9.0
	 *
	 * @param string $node_id Node identifier to remove.
	 * @return bool True if the node was found and removed.
	 */
	public function remove( $node_id ) {
		if ( ! isset( $this->vectors[ $node_id ] ) ) {
			return false;
		}

		unset( $this->vectors[ $node_id ] );

		// Remove from every layer.
		foreach ( $this->graph as $layer => &$layer_nodes ) {
			unset( $layer_nodes[ $node_id ] );

			// Purge the removed node from every neighbor list in this layer.
			foreach ( $layer_nodes as &$neighbor_list ) {
				$neighbor_list = array_values(
					array_filter(
						$neighbor_list,
						function ( $nid ) use ( $node_id ) {
							return $nid !== $node_id;
						}
					)
				);
			}
		}
		unset( $layer_nodes, $neighbor_list );

		// If we just removed the entry point, pick a new one from the
		// highest non-empty layer.
		if ( $node_id === $this->entry_point ) {
			$this->entry_point = null;
			for ( $l = $this->max_level; $l >= 0; $l-- ) {
				if ( ! empty( $this->graph[ $l ] ) ) {
					reset( $this->graph[ $l ] );
					$this->entry_point = key( $this->graph[ $l ] );
					$this->max_level   = $l;
					break;
				}
			}
		}

		// Drop layers that became empty.
		foreach ( $this->graph as $l => $nodes ) {
			if ( empty( $nodes ) ) {
				unset( $this->graph[ $l ] );
			}
		}

		if ( empty( $this->graph ) ) {
			$this->max_level   = 0;
			$this->entry_point = null;
		}

		$this->modified = true;

		return true;
	}

	/**
	 * Search for the k nearest neighbors to a query vector.
	 *
	 * Implements standard HNSW search: greedy descent through upper layers
	 * (ef=1) followed by an ef-sized candidate search at layer 0.
	 *
	 * @since 1.9.0
	 *
	 * @param array $query_vector Float array representing the query embedding.
	 * @param int   $k            Number of nearest neighbors to return
	 *                            (default 10).
	 * @return array Associative array of node_id => similarity_score,
	 *               sorted by descending similarity.
	 */
	public function search( array $query_vector, $k = 10 ) {
		if ( empty( $this->vectors ) || null === $this->entry_point ) {
			return array();
		}

		$k = max( 1, (int) $k );

		// Cast and normalize query.
		$query_vector = array_values( array_map( 'floatval', $query_vector ) );
		if ( 'cosine' === $this->distance_func ) {
			$query_vector = $this->normalize_vector( $query_vector );
		}

		// Greedy descent through layers above layer 0 (ef=1).
		$ep = $this->entry_point;
		for ( $lc = $this->max_level; $lc > 0; $lc-- ) {
			$w  = $this->search_layer( $query_vector, $ep, 1, $lc );
			$ep = $this->closest_from_results( $w );
		}

		// Search layer 0 with ef = max(ef_search, k) to guarantee k results.
		$ef         = max( $this->ef_search, $k );
		$candidates = $this->search_layer( $query_vector, $ep, $ef, 0 );

		// Sort by distance ascending, trim to k.
		asort( $candidates, SORT_NUMERIC );
		$candidates = array_slice( $candidates, 0, $k, true );

		return $this->distances_to_scores( $candidates );
	}

	/**
	 * Search for k nearest neighbors restricted to a set of candidate IDs.
	 *
	 * Useful for filtered queries — e.g., only search posts of a certain
	 * post type or within a date range.
	 *
	 * @since 1.9.0
	 *
	 * @param array $query_vector  Float array representing the query embedding.
	 * @param array $candidate_ids List of node IDs to consider.
	 * @param int   $k             Number of nearest neighbors to return
	 *                             (default 10).
	 * @return array Associative array of node_id => similarity_score,
	 *               sorted by descending similarity.
	 */
	public function search_by_ids( array $query_vector, array $candidate_ids, $k = 10 ) {
		if ( empty( $this->vectors ) || empty( $candidate_ids ) ) {
			return array();
		}

		$k = max( 1, (int) $k );

		// Cast and normalize query.
		$query_vector = array_values( array_map( 'floatval', $query_vector ) );
		if ( 'cosine' === $this->distance_func ) {
			$query_vector = $this->normalize_vector( $query_vector );
		}

		// Filter to candidate IDs that actually exist in the index.
		$candidate_ids = array_intersect( $candidate_ids, array_keys( $this->vectors ) );
		if ( empty( $candidate_ids ) ) {
			return array();
		}

		// Brute-force over the restricted candidate set, then select top-k.
		// For typical filtered sets (hundreds, not thousands) this is faster
		// than navigating the full HNSW graph.
		$distances = array();
		foreach ( $candidate_ids as $nid ) {
			$distances[ $nid ] = $this->compute_distance( $query_vector, $this->vectors[ $nid ] );
		}

		asort( $distances, SORT_NUMERIC );
		$distances = array_slice( $distances, 0, $k, true );

		return $this->distances_to_scores( $distances );
	}

	/**
	 * Serialize and persist the index to the object cache.
	 *
	 * Stores vectors, graph structure, entry point, and hyperparameters
	 * so the index can be restored without rebuilding.
	 *
	 * @since 1.9.0
	 *
	 * @param string $cache_key Optional cache key. Auto-generated from
	 *                          the set of node IDs if empty.
	 * @return string The cache key used.
	 */
	public function save_to_cache( $cache_key = '' ) {
		if ( '' === $cache_key ) {
			$cache_key = 'hnsw_index_' . md5( wp_json_encode( array_keys( $this->vectors ) ) );
		}

		$data = array(
			'vectors'         => $this->vectors,
			'graph'           => $this->graph,
			'entry_point'     => $this->entry_point,
			'max_level'       => $this->max_level,
			'dim'             => $this->dim,
			'distance_func'   => $this->distance_func,
			'normalized'      => $this->normalized,
			'm'               => $this->m,
			'ef_construction' => $this->ef_construction,
			'ef_search'       => $this->ef_search,
		);

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		$this->modified = false;

		return $cache_key;
	}

	/**
	 * Load the index from the object cache.
	 *
	 * Restores the full internal state — vectors, graph, entry point,
	 * and all hyperparameters.
	 *
	 * @since 1.9.0
	 *
	 * @param string $cache_key Cache key to load from.
	 * @return bool True if successfully loaded, false otherwise.
	 */
	public function load_from_cache( $cache_key ) {
		$data = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$this->vectors         = isset( $data['vectors'] ) ? (array) $data['vectors'] : array();
		$this->graph           = isset( $data['graph'] ) ? (array) $data['graph'] : array();
		$this->entry_point     = isset( $data['entry_point'] ) ? (string) $data['entry_point'] : null;
		$this->max_level       = isset( $data['max_level'] ) ? (int) $data['max_level'] : 0;
		$this->dim             = isset( $data['dim'] ) ? (int) $data['dim'] : null;
		$this->distance_func   = isset( $data['distance_func'] ) ? (string) $data['distance_func'] : 'cosine';
		$this->normalized      = isset( $data['normalized'] ) ? (bool) $data['normalized'] : false;
		$this->m               = isset( $data['m'] ) ? (int) $data['m'] : self::DEFAULT_M;
		$this->ef_construction = isset( $data['ef_construction'] ) ? (int) $data['ef_construction'] : self::DEFAULT_EF_CONSTRUCTION;
		$this->ef_search       = isset( $data['ef_search'] ) ? (int) $data['ef_search'] : self::DEFAULT_EF_SEARCH;

		// Sanity-check loaded values.
		if ( null === $this->entry_point || '' === $this->entry_point ) {
			$this->entry_point = null;
		}
		if ( null === $this->dim || $this->dim < 1 ) {
			$this->dim = null;
		}
		$this->m               = max( 2, $this->m );
		$this->ef_construction = max( 1, $this->ef_construction );
		$this->ef_search       = max( 1, $this->ef_search );

		$this->modified = false;
		$this->loaded   = true;

		return true;
	}

	/**
	 * Return diagnostic statistics about the index.
	 *
	 * @since 1.9.0
	 *
	 * @return array{
	 *     total_vectors: int,
	 *     dim: int|null,
	 *     layers: int,
	 *     m: int,
	 *     ef_construction: int,
	 *     ef_search: int,
	 *     distance: string,
	 *     memory_estimate_bytes: int
	 * }
	 */
	public function get_stats() {
		$memory = 0;

		// Rough memory estimate: each float ≈16 bytes (PHP zval overhead).
		foreach ( $this->vectors as $vec ) {
			$memory += count( $vec ) * 16;
		}

		// Each edge ≈8 bytes + array bookkeeping.
		foreach ( $this->graph as $layer_nodes ) {
			foreach ( $layer_nodes as $neighbors ) {
				$memory += count( $neighbors ) * 8;
			}
		}

		return array(
			'total_vectors'         => count( $this->vectors ),
			'dim'                   => $this->dim,
			'layers'                => null !== $this->entry_point ? $this->max_level + 1 : 0,
			'm'                     => $this->m,
			'ef_construction'       => $this->ef_construction,
			'ef_search'             => $this->ef_search,
			'distance'              => $this->distance_func,
			'memory_estimate_bytes' => $memory,
		);
	}

	/**
	 * Clear all internal state.
	 *
	 * Resets the index to its initial empty state.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function clear() {
		$this->vectors     = array();
		$this->graph       = array();
		$this->entry_point = null;
		$this->max_level   = 0;
		$this->dim         = null;
		$this->modified    = false;
		$this->loaded      = false;
	}

	// -------------------------------------------------------------------------
	// Private — graph construction and traversal
	// -------------------------------------------------------------------------

	/**
	 * Insert a node into the multi-layer graph at the given level.
	 *
	 * Implements the standard HNSW insertion algorithm:
	 * 1. Greedy descent through upper layers (ef=1) to find an entry point.
	 * 2. Search each layer from min(level, max_level) down to 0 with ef_construction.
	 * 3. Select M nearest neighbors and add bidirectional connections.
	 * 4. Prune neighbors that exceed M connections.
	 * 5. If level > max_level, extend layers and promote the new node as entry point.
	 *
	 * @since 1.9.0
	 *
	 * @param string $node_id Node identifier.
	 * @param array  $vector  Normalized float vector.
	 * @param int    $level   Assigned layer level.
	 * @return void
	 */
	private function insert_at_level( $node_id, array $vector, $level ) {
		// First node — initialise the graph.
		if ( null === $this->entry_point ) {
			$this->entry_point = $node_id;
			$this->max_level   = $level;

			for ( $l = 0; $l <= $level; $l++ ) {
				$this->graph[ $l ] = array( $node_id => array() );
			}
			return;
		}

		$ep = $this->entry_point;

		// Greedy descent from max_level down to level+1 (ef=1).
		for ( $lc = $this->max_level; $lc > $level; $lc-- ) {
			$w  = $this->search_layer( $vector, $ep, 1, $lc );
			$ep = $this->closest_from_results( $w );
		}

		// Insert into layers from min(level, max_level) down to 0.
		$min_level = min( $level, $this->max_level );
		for ( $lc = $min_level; $lc >= 0; $lc-- ) {
			$w         = $this->search_layer( $vector, $ep, $this->ef_construction, $lc );
			$neighbors = $this->select_neighbors_simple( $vector, $w, $this->m );

			// Ensure the layer array exists.
			if ( ! isset( $this->graph[ $lc ] ) ) {
				$this->graph[ $lc ] = array();
			}

			// Add node with its selected neighbors.
			$this->graph[ $lc ][ $node_id ] = $neighbors;

			// Add reverse connections and prune if necessary.
			foreach ( $neighbors as $neighbor_id ) {
				if ( ! isset( $this->graph[ $lc ][ $neighbor_id ] ) ) {
					$this->graph[ $lc ][ $neighbor_id ] = array();
				}
				$this->graph[ $lc ][ $neighbor_id ][] = $node_id;

				// Prune if the neighbor now has more than M connections.
				if ( count( $this->graph[ $lc ][ $neighbor_id ] ) > $this->m ) {
					$this->graph[ $lc ][ $neighbor_id ] = $this->shrink_neighbor_connections(
						$neighbor_id,
						$this->graph[ $lc ][ $neighbor_id ]
					);
				}
			}

			// Carry the closest result forward as the entry point for the
			// next layer down.
			if ( ! empty( $w ) ) {
				$ep = $this->closest_from_results( $w );
			}
		}

		// If the new node's level exceeds the current max, extend the graph
		// and promote it as the new top-layer entry point.
		if ( $level > $this->max_level ) {
			for ( $lc = $this->max_level + 1; $lc <= $level; $lc++ ) {
				$this->graph[ $lc ] = array( $node_id => array() );
			}
			$this->entry_point = $node_id;
			$this->max_level   = $level;
		}
	}

	/**
	 * Search a single layer for the ef closest nodes to a query vector.
	 *
	 * Implements the standard HNSW layer-search algorithm: maintains a
	 * dynamic candidate list and a result set, expanding from the closest
	 * unvisited candidate until the stopping condition is met.
	 *
	 * @since 1.9.0
	 *
	 * @param array        $query_vector Normalized query vector.
	 * @param string|array $entry_points Entry-point node_id (string), or
	 *                                   result array (node_id => distance)
	 *                                   from a previous layer search.
	 * @param int          $ef           Number of candidates to return.
	 * @param int          $layer        Layer index to search.
	 * @return array Associative array of node_id => distance.
	 */
	private function search_layer( array $query_vector, $entry_points, $ef, $layer ) {
		if ( ! isset( $this->graph[ $layer ] ) || empty( $this->graph[ $layer ] ) ) {
			return array();
		}

		$visited    = array();
		$candidates = array(); // node_id => distance.
		$results    = array(); // node_id => distance.

		// Seed from the provided entry point(s).
		if ( is_array( $entry_points ) ) {
			foreach ( $entry_points as $nid => $dist ) {
				$nid = (string) $nid;
				if ( isset( $this->graph[ $layer ][ $nid ] ) ) {
					$visited[ $nid ]    = true;
					$candidates[ $nid ] = (float) $dist;
					$results[ $nid ]    = (float) $dist;
				}
			}
		} else {
			$nid = (string) $entry_points;
			if ( isset( $this->graph[ $layer ][ $nid ] ) ) {
				$d                  = $this->compute_distance( $query_vector, $this->vectors[ $nid ] );
				$visited[ $nid ]    = true;
				$candidates[ $nid ] = $d;
				$results[ $nid ]    = $d;
			}
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		// Main search loop.
		while ( ! empty( $candidates ) ) {
			// Extract the closest candidate (smallest distance).
			asort( $candidates, SORT_NUMERIC );
			reset( $candidates );
			$c_key  = key( $candidates );
			$c_dist = current( $candidates );
			unset( $candidates[ $c_key ] );

			// Determine the distance of the furthest element in results.
			if ( ! empty( $results ) ) {
				arsort( $results, SORT_NUMERIC );
				reset( $results );
				$f_dist = current( $results );
			} else {
				$f_dist = -1.0;
			}

			// Stopping condition: the closest candidate is further than the
			// furthest result AND we already have ef results.
			if ( $c_dist > $f_dist && count( $results ) >= $ef ) {
				break;
			}

			// Explore every neighbor of the closest candidate.
			if ( ! isset( $this->graph[ $layer ][ $c_key ] ) ) {
				continue;
			}

			foreach ( $this->graph[ $layer ][ $c_key ] as $neighbor_id ) {
				if ( isset( $visited[ $neighbor_id ] ) ) {
					continue;
				}
				$visited[ $neighbor_id ] = true;

				// Recompute furthest-result distance (may have changed after
				// adding neighbors from the previous iteration).
				if ( ! empty( $results ) ) {
					arsort( $results, SORT_NUMERIC );
					$f_dist_inner = current( $results );
				} else {
					$f_dist_inner = -1.0;
				}

				$neighbor_dist = $this->compute_distance( $query_vector, $this->vectors[ $neighbor_id ] );

				if ( $neighbor_dist < $f_dist_inner || count( $results ) < $ef ) {
					$candidates[ $neighbor_id ] = $neighbor_dist;
					$results[ $neighbor_id ]    = $neighbor_dist;

					// Trim results to ef.
					if ( count( $results ) > $ef ) {
						arsort( $results, SORT_NUMERIC );
						array_pop( $results );
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Select the M closest neighbors from a set of candidates.
	 *
	 * @since 1.9.0
	 *
	 * @param array $query_vector Query vector (unused — kept for API
	 *                            compatibility with heuristic extensions).
	 * @param array $candidates   Associative array of node_id => distance.
	 * @param int   $m            Maximum number of neighbors to select.
	 * @return string[] Array of selected node_ids.
	 */
	private function select_neighbors_simple( array $query_vector, array $candidates, $m ) {
		if ( empty( $candidates ) ) {
			return array();
		}

		asort( $candidates, SORT_NUMERIC );
		return array_slice( array_keys( $candidates ), 0, $m );
	}

	/**
	 * Compute the distance between two vectors.
	 *
	 * For 'cosine' with normalized vectors: returns 1.0 − inner_product
	 *   (a valid distance metric for normalized vectors).
	 * For 'euclidean': returns sqrt(∑(a[i]−b[i])²).
	 *
	 * @since 1.9.0
	 *
	 * @param array $a First float vector.
	 * @param array $b Second float vector.
	 * @return float Distance value (lower = more similar).
	 */
	private function compute_distance( array $a, array $b ) {
		$count = count( $a );
		if ( count( $b ) !== $count ) {
			return PHP_FLOAT_MAX;
		}

		if ( 'euclidean' === $this->distance_func ) {
			$sum = 0.0;
			for ( $i = 0; $i < $count; $i++ ) {
				$diff = $a[ $i ] - $b[ $i ];
				$sum += $diff * $diff;
			}
			return sqrt( $sum );
		}

		// Cosine distance for normalized vectors: 1.0 − inner_product.
		$dot = 0.0;
		for ( $i = 0; $i < $count; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
		}
		return 1.0 - $dot;
	}

	/**
	 * L2-normalize a vector.
	 *
	 * Divides every component by the Euclidean norm. Zero vectors are
	 * returned unchanged to avoid NaN.
	 *
	 * @since 1.9.0
	 *
	 * @param array $v Float vector.
	 * @return array L2-normalized float vector.
	 */
	private function normalize_vector( array $v ) {
		$sum_sq = 0.0;
		$count  = count( $v );
		for ( $i = 0; $i < $count; $i++ ) {
			$sum_sq += $v[ $i ] * $v[ $i ];
		}

		$norm = sqrt( $sum_sq );
		if ( $norm < 1e-12 ) {
			// Zero vector — return as-is to avoid division by zero.
			return $v;
		}

		$result = array();
		foreach ( $v as $val ) {
			$result[] = $val / $norm;
		}
		return $result;
	}

	/**
	 * Extract the closest node_id from a set of search results.
	 *
	 * @since 1.9.0
	 *
	 * @param array $results Associative array of node_id => distance.
	 * @return string|null The closest node_id, or null if results are empty.
	 */
	private function closest_from_results( array $results ) {
		if ( empty( $results ) ) {
			return null;
		}
		asort( $results, SORT_NUMERIC );
		reset( $results );
		return key( $results );
	}

	/**
	 * Shrink a node's neighbor list to M using the select-neighbors
	 * heuristic.
	 *
	 * Called when a node accumulates more than M bidirectional connections
	 * after a new node is inserted.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $node_id   The node whose connections are being pruned.
	 * @param string[] $neighbors Current neighbor list (may exceed M).
	 * @return string[] Pruned neighbor list (at most M entries).
	 */
	private function shrink_neighbor_connections( $node_id, array $neighbors ) {
		if ( ! isset( $this->vectors[ $node_id ] ) ) {
			return array_slice( $neighbors, 0, $this->m );
		}

		$node_vec = $this->vectors[ $node_id ];
		$dists    = array();
		foreach ( $neighbors as $nid ) {
			if ( isset( $this->vectors[ $nid ] ) ) {
				$dists[ $nid ] = $this->compute_distance( $node_vec, $this->vectors[ $nid ] );
			} else {
				$dists[ $nid ] = PHP_FLOAT_MAX;
			}
		}

		return $this->select_neighbors_simple( $node_vec, $dists, $this->m );
	}

	/**
	 * Convert raw distance values to similarity scores for sorting.
	 *
	 * For 'cosine': score = inner_product = 1.0 − distance
	 *   (since distance = 1.0 − inner_product for normalized vectors).
	 * For 'euclidean': score = −distance (negated so higher = more similar).
	 *
	 * Results are sorted by descending score.
	 *
	 * @since 1.9.0
	 *
	 * @param array $distances Associative array of node_id => distance.
	 * @return array Associative array of node_id => score, sorted descending.
	 */
	private function distances_to_scores( array $distances ) {
		$scores = array();

		if ( 'cosine' === $this->distance_func ) {
			// distance = 1.0 − inner_product  ⇒  inner_product = 1.0 − distance.
			foreach ( $distances as $nid => $dist ) {
				$scores[ $nid ] = 1.0 - (float) $dist;
			}
		} else {
			// Negate distance so higher scores mean more similar.
			foreach ( $distances as $nid => $dist ) {
				$scores[ $nid ] = - (float) $dist;
			}
		}

		arsort( $scores, SORT_NUMERIC );
		return $scores;
	}
}
