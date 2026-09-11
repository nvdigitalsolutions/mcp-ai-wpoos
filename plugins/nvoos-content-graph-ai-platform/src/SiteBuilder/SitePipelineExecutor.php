<?php
/**
 * Site Pipeline Executor (Wave E4, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Site_Pipeline_Executor`:
 * byte-identical cache constants (transient prefix, 1-hour default
 * TTL), the topological-sort DAG walk (Kahn's algorithm with the
 * `wp_mcp_ai_site_pipeline_cycle` envelope), incoming-edge resolution,
 * static-inputs + edge-overlay input resolution (single value vs
 * multi-source array), the deterministic
 * `md5( slug . '::' . wp_json_encode() )` cache keys with ksort'd
 * inputs, the in-memory + transient two-tier cache, the
 * `wp_mcp_ai_site_empty_pipeline` / `wp_mcp_ai_site_node_missing_slug`
 * / `wp_mcp_ai_site_missing_dependency` error envelopes, and the
 * pattern-matched SQL cache clearing (`clear_cache()` /
 * `clear_all_caches()` with the object-cache caveat).
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `get_registry()` always resolves this package's
 *    `SiteNodeRegistry` (which itself resolves node classes per
 *    install mode) — the base class name cannot appear in the return
 *    type because the registry class differs between modes.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\SiteBuilder
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SiteBuilder;

/**
 * Executes a site-building pipeline graph.
 *
 * Usage:
 *   $executor = new SitePipelineExecutor();
 *   $result   = $executor->execute( $pipeline_graph, 'my-pipeline' );
 *
 * The $pipeline_graph is an associative array:
 *   [
 *     'nodes' => [
 *       'node_a' => [ 'slug' => 'wp_query_source', 'inputs' => [ 'post_type' => 'post' ] ],
 *       'node_b' => [ 'slug' => 'text_block',       'inputs' => [ 'tag' => 'h1' ] ],
 *     ],
 *     'edges' => [
 *       [ 'source' => 'node_a', 'sourcePort' => 'posts', 'target' => 'node_b', 'targetPort' => 'content' ],
 *     ],
 *   ]
 *
 * On success, returns [ 'outputs' => [ 'node_id' => [ 'port' => value, … ], … ] ].
 * On failure, returns a WP_Error.
 *
 * @since 2.1.0
 */
class SitePipelineExecutor {

	/**
	 * Transient prefix for node output caching.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_site_node_';

	/**
	 * Default cache TTL in seconds (1 hour).
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Cache TTL for this executor instance.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	protected $cache_ttl;

	/**
	 * Registry instance (resolved lazily).
	 *
	 * @since 2.1.0
	 * @var SiteNodeRegistry|null
	 */
	protected $registry;

	/**
	 * In-memory cache for the current execution run (avoids transient lookups
	 * when the same node + inputs appear more than once in one pipeline).
	 *
	 * @since 2.1.0
	 * @var array<string,array>
	 */
	protected $run_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 2.1.0
	 *
	 * @param int $cache_ttl Transient cache TTL in seconds. Default: 1 hour.
	 */
	public function __construct( int $cache_ttl = self::DEFAULT_CACHE_TTL ) {
		$this->cache_ttl = $cache_ttl;
	}

	/**
	 * Lazy-load the node registry.
	 *
	 * @since 2.1.0
	 *
	 * @return SiteNodeRegistry
	 */
	protected function get_registry(): SiteNodeRegistry {
		if ( null === $this->registry ) {
			$this->registry = SiteNodeRegistry::get_instance();
			$this->registry->init();
		}
		return $this->registry;
	}

	/**
	 * Execute a pipeline graph.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $pipeline_graph Associative array with 'nodes' and 'edges' keys.
	 * @param string $pipeline_id    Unique identifier for this pipeline (used for cache keys).
	 * @return array|\WP_Error On success: [ 'outputs' => [ node_id => [ port => value ] ] ].
	 *                        On failure: WP_Error.
	 */
	public function execute( array $pipeline_graph, string $pipeline_id = '' ) {
		$nodes = isset( $pipeline_graph['nodes'] ) ? $pipeline_graph['nodes'] : array();
		$edges = isset( $pipeline_graph['edges'] ) ? $pipeline_graph['edges'] : array();

		if ( empty( $nodes ) ) {
			return new \WP_Error(
				'wp_mcp_ai_site_empty_pipeline',
				__( 'The pipeline graph contains no nodes.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Reset in-memory cache for this run.
		$this->run_cache = array();

		// Build adjacency for topological sort.
		$sorted = $this->topological_sort( $nodes, $edges );
		if ( is_wp_error( $sorted ) ) {
			return $sorted;
		}

		// Build a quick lookup: which edges feed INTO each (node, port)?
		$incoming = $this->build_incoming_edges( $edges );

		// Execute nodes in topological order.
		$outputs = array();
		foreach ( $sorted as $node_id ) {
			$node_config = $nodes[ $node_id ];

			if ( ! isset( $node_config['slug'] ) ) {
				return new \WP_Error(
					'wp_mcp_ai_site_node_missing_slug',
					sprintf(
						/* translators: %s: node ID */
						__( 'Node "%s" is missing a slug.', 'nvoos-content-graph-ai-platform' ),
						esc_html( $node_id )
					)
				);
			}

			$slug = sanitize_text_field( $node_config['slug'] );

			// Resolve inputs: merge static inputs with values from upstream edges.
			$inputs = $this->resolve_inputs( $node_id, $node_config, $incoming, $outputs );
			if ( is_wp_error( $inputs ) ) {
				return $inputs;
			}

			// Compute cache key.
			$cache_key = $this->build_cache_key( $pipeline_id, $node_id, $slug, $inputs );

			// Try in-memory cache first.
			if ( isset( $this->run_cache[ $cache_key ] ) ) {
				$outputs[ $node_id ] = $this->run_cache[ $cache_key ];
				continue;
			}

			// Try persistent cache.
			$cached = $this->get_cached_output( $cache_key );
			if ( null !== $cached ) {
				$outputs[ $node_id ]           = $cached;
				$this->run_cache[ $cache_key ] = $cached;
				continue;
			}

			// Execute the node.
			$node_output = $this->get_registry()->execute_node( $slug, $inputs );
			if ( is_wp_error( $node_output ) ) {
				return $node_output;
			}

			// Cache and store.
			$this->set_cached_output( $cache_key, $node_output );
			$this->run_cache[ $cache_key ] = $node_output;
			$outputs[ $node_id ]           = $node_output;
		}

		return array(
			'outputs' => $outputs,
		);
	}

	/**
	 * Topologically sort nodes using Kahn's algorithm.
	 *
	 * Returns nodes in execution order (all dependencies before dependents).
	 * Detects cycles and returns a WP_Error if one is found.
	 *
	 * @since 2.1.0
	 *
	 * @param array $nodes Associative array of node_id => config.
	 * @param array $edges Array of edge objects { source, sourcePort, target, targetPort }.
	 * @return string[]|\WP_Error Sorted node IDs, or WP_Error on cycle.
	 */
	protected function topological_sort( array $nodes, array $edges ) {
		$node_ids = array_keys( $nodes );

		// In-degree: how many edges feed INTO each node.
		$in_degree = array_fill_keys( $node_ids, 0 );
		// Adjacency: node → list of downstream nodes.
		$adjacency = array_fill_keys( $node_ids, array() );

		foreach ( $edges as $edge ) {
			$source = $edge['source'] ?? '';
			$target = $edge['target'] ?? '';

			if ( '' === $source || '' === $target ) {
				continue;
			}

			if ( ! isset( $nodes[ $source ] ) || ! isset( $nodes[ $target ] ) ) {
				continue;
			}

			$in_degree[ $target ]   = ( $in_degree[ $target ] ?? 0 ) + 1;
			$adjacency[ $source ][] = $target;
		}

		// Queue nodes with no incoming edges.
		$queue = array();
		foreach ( $node_ids as $id ) {
			if ( 0 === $in_degree[ $id ] ) {
				$queue[] = $id;
			}
		}

		$sorted = array();
		while ( ! empty( $queue ) ) {
			$current  = array_shift( $queue );
			$sorted[] = $current;

			foreach ( $adjacency[ $current ] as $neighbor ) {
				--$in_degree[ $neighbor ];
				if ( 0 === $in_degree[ $neighbor ] ) {
					$queue[] = $neighbor;
				}
			}
		}

		// If we didn't visit all nodes, there's a cycle.
		if ( count( $sorted ) !== count( $node_ids ) ) {
			return new \WP_Error(
				'wp_mcp_ai_site_pipeline_cycle',
				__( 'The pipeline graph contains a cycle. DAGs must be acyclic.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $sorted;
	}

	/**
	 * Build a lookup map of incoming edges.
	 *
	 * Returns: [ 'node_id' => [ 'port_name' => [ 'sourceNode' => …, 'sourcePort' => … ] ] ]
	 *
	 * @since 2.1.0
	 *
	 * @param array $edges Edge definitions.
	 * @return array
	 */
	protected function build_incoming_edges( array $edges ): array {
		$incoming = array();
		foreach ( $edges as $edge ) {
			$target = $edge['target'] ?? '';
			$port   = $edge['targetPort'] ?? '';

			if ( '' === $target || '' === $port ) {
				continue;
			}

			if ( ! isset( $incoming[ $target ] ) ) {
				$incoming[ $target ] = array();
			}
			$incoming[ $target ][ $port ][] = array(
				'sourceNode' => $edge['source'] ?? '',
				'sourcePort' => $edge['sourcePort'] ?? '',
			);
		}
		return $incoming;
	}

	/**
	 * Resolve inputs for a node by merging static config with upstream edge values.
	 *
	 * Static inputs (from node config) serve as defaults. Values from connected
	 * edges override them.
	 *
	 * @since 2.1.0
	 *
	 * @param string $node_id     ID of the node being resolved.
	 * @param array  $node_config Node configuration (slug + static inputs).
	 * @param array  $incoming    Incoming edge lookup (from build_incoming_edges).
	 * @param array  $outputs     Already-computed outputs from upstream nodes.
	 * @return array|\WP_Error Resolved inputs, or WP_Error if a dependency is missing.
	 */
	protected function resolve_inputs(
		string $node_id,
		array $node_config,
		array $incoming,
		array $outputs
	) {
		// Start with static inputs.
		$inputs = isset( $node_config['inputs'] ) ? (array) $node_config['inputs'] : array();

		// Overlay edge-connected values.
		$edges_for_node = $incoming[ $node_id ] ?? array();
		foreach ( $edges_for_node as $port_name => $sources ) {
			// Multiple edges to the same port → collect as array (for list-type ports).
			$collected = array();
			foreach ( $sources as $source ) {
				$src_node = $source['sourceNode'];
				$src_port = $source['sourcePort'];

				if ( ! isset( $outputs[ $src_node ] ) ) {
					return new \WP_Error(
						'wp_mcp_ai_site_missing_dependency',
						sprintf(
							/* translators: 1: target node ID, 2: source node ID */
							__( 'Cannot resolve input for node "%1$s": upstream node "%2$s" has not been executed.', 'nvoos-content-graph-ai-platform' ),
							esc_html( $node_id ),
							esc_html( $src_node )
						)
					);
				}

				$upstream = $outputs[ $src_node ];
				if ( isset( $upstream[ $src_port ] ) ) {
					$collected[] = $upstream[ $src_port ];
				}
			}

			// If only one source, pass the value directly; if multiple, pass as array.
			if ( 1 === count( $collected ) ) {
				$inputs[ $port_name ] = $collected[0];
			} elseif ( count( $collected ) > 1 ) {
				$inputs[ $port_name ] = $collected;
			}
		}

		return $inputs;
	}

	/**
	 * Build a deterministic cache key for a node + inputs combination.
	 *
	 * @since 2.1.0
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @param string $node_id     Node ID within the pipeline.
	 * @param string $slug        Node type slug.
	 * @param array  $inputs      Resolved input values.
	 * @return string
	 */
	protected function build_cache_key(
		string $pipeline_id,
		string $node_id,
		string $slug,
		array $inputs
	): string {
		// Sort inputs by key for deterministic hashing.
		ksort( $inputs );
		$hash = md5( $slug . '::' . wp_json_encode( $inputs ) );

		return self::CACHE_PREFIX . $pipeline_id . '_' . $node_id . '_' . $hash;
	}

	/**
	 * Retrieve a cached node output from transients.
	 *
	 * @since 2.1.0
	 *
	 * @param string $cache_key Cache key.
	 * @return array|null Cached output, or null if not found / expired.
	 */
	protected function get_cached_output( string $cache_key ) {
		$cached = get_transient( $cache_key );
		if ( false === $cached || ! is_array( $cached ) ) {
			return null;
		}
		return $cached;
	}

	/**
	 * Store a node output in transients.
	 *
	 * @since 2.1.0
	 *
	 * @param string $cache_key Cache key.
	 * @param array  $output    Node output to cache.
	 * @return void
	 */
	protected function set_cached_output( string $cache_key, array $output ) {
		set_transient( $cache_key, $output, $this->cache_ttl );
	}

	/**
	 * Clear the transient cache for an entire pipeline.
	 *
	 * Deletes all cached node outputs whose key matches the pipeline prefix.
	 * Uses direct SQL deletion for performance on large cache sets.
	 *
	 * NOTE: This only clears database-stored transients. In environments with
	 * a persistent object cache (Redis, Memcached), stale entries may survive
	 * until their TTL expires. For full clearance in those environments,
	 * iterate with delete_transient() or call wp_cache_flush().
	 *
	 * @since 2.1.0
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return int Number of cache entries cleared.
	 */
	public function clear_cache( string $pipeline_id ): int {
		global $wpdb;

		$prefix = self::CACHE_PREFIX . $pipeline_id . '_';

		// Direct SQL deletion is the most reliable cross-version approach
		// for pattern-matching transient keys.
		$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		// Also clear timeout entries.
		$like_timeout = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_timeout
			)
		);

		return $wpdb->rows_affected;
	}

	/**
	 * Clear all site-builder node caches across all pipelines.
	 *
	 * NOTE: Same object-cache caveat as clear_cache() applies — this only
	 * clears database-stored transients, not external object cache entries.
	 *
	 * @since 2.1.0
	 *
	 * @return int Number of cache entries cleared.
	 */
	public function clear_all_caches(): int {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		$like_timeout = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_timeout
			)
		);

		return $wpdb->rows_affected;
	}
}
