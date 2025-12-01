<?php
/**
 * Token Performance Service
 *
 * Provides performance metrics and statistics for token management.
 * Separates data retrieval from presentation logic.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service class for token performance metrics.
 *
 * Handles data aggregation and calculation for performance monitoring.
 */
class WP_MCP_AI_Token_Performance_Service {

	/**
	 * Get comprehensive performance statistics.
	 *
	 * @return array Performance data including optimization stats and query analysis.
	 */
	public static function get_performance_data() {
		return array(
			'stats'    => self::get_optimization_stats(),
			'analysis' => self::get_query_analysis(),
			'cache'    => self::get_cache_stats(),
		);
	}

	/**
	 * Get database optimization statistics.
	 *
	 * @return array Optimization statistics.
	 */
	protected static function get_optimization_stats() {
		if ( ! class_exists( 'WP_MCP_AI_Token_DB_Optimizer' ) ) {
			return array(
				'optimizations_active' => false,
				'schema_version'       => 0,
			);
		}

		return WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats();
	}

	/**
	 * Get query performance analysis.
	 *
	 * @return array Query analysis results.
	 */
	protected static function get_query_analysis() {
		if ( ! class_exists( 'WP_MCP_AI_Token_DB_Optimizer' ) ) {
			return array();
		}

		return WP_MCP_AI_Token_DB_Optimizer::analyze_query_performance();
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Cache performance metrics.
	 */
	protected static function get_cache_stats() {
		// Get cache hit/miss statistics if available.
		$stats = array(
			'cache_enabled' => true,
			'cache_ttl'     => HOUR_IN_SECONDS,
			'cache_group'   => 'wp_mcp_ai',
		);

		// Check if object cache is available.
		if ( wp_using_ext_object_cache() ) {
			$stats['persistent_cache'] = true;
			$stats['cache_backend']    = self::detect_cache_backend();
		} else {
			$stats['persistent_cache'] = false;
			$stats['cache_backend']    = 'runtime';
		}

		return $stats;
	}

	/**
	 * Detect the object cache backend being used.
	 *
	 * @return string Cache backend name.
	 */
	protected static function detect_cache_backend() {
		global $_wp_using_ext_object_cache;

		if ( ! $_wp_using_ext_object_cache ) {
			return 'runtime';
		}

		// Try to detect common cache backends.
		if ( class_exists( 'Memcached' ) && defined( 'WP_CACHE_KEY_SALT' ) ) {
			return 'Memcached';
		}

		if ( class_exists( 'Redis' ) ) {
			return 'Redis';
		}

		if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			return 'APCu';
		}

		return 'unknown';
	}

	/**
	 * Get performance recommendations.
	 *
	 * Analyzes current state and provides actionable recommendations.
	 *
	 * @return array Array of recommendations.
	 */
	public static function get_recommendations() {
		$recommendations = array();
		$stats           = self::get_optimization_stats();
		$cache_stats     = self::get_cache_stats();

		// Check if optimizations are active.
		if ( empty( $stats['optimizations_active'] ) ) {
			$recommendations[] = array(
				'type'        => 'warning',
				'title'       => __( 'Database Not Optimized', 'wp-mcp-ai' ),
				'description' => __( 'Database indexes are not created. Run optimization to improve query performance.', 'wp-mcp-ai' ),
				'action'      => 'run_optimization',
				'priority'    => 'high',
			);
		}

		// Check for missing indexes.
		if ( empty( $stats['tier_index_exists'] ) ) {
			$recommendations[] = array(
				'type'        => 'warning',
				'title'       => __( 'Missing Tier Index', 'wp-mcp-ai' ),
				'description' => __( 'Tier lookup index is missing. This may slow down tier retrieval.', 'wp-mcp-ai' ),
				'action'      => 'create_tier_index',
				'priority'    => 'medium',
			);
		}

		if ( empty( $stats['usage_index_exists'] ) ) {
			$recommendations[] = array(
				'type'        => 'warning',
				'title'       => __( 'Missing Usage Index', 'wp-mcp-ai' ),
				'description' => __( 'Usage lookup index is missing. This may slow down usage queries.', 'wp-mcp-ai' ),
				'action'      => 'create_usage_index',
				'priority'    => 'medium',
			);
		}

		// Check for persistent cache.
		if ( empty( $cache_stats['persistent_cache'] ) ) {
			$recommendations[] = array(
				'type'        => 'info',
				'title'       => __( 'No Persistent Cache', 'wp-mcp-ai' ),
				'description' => __( 'Consider installing Redis or Memcached for improved cache persistence across requests.', 'wp-mcp-ai' ),
				'action'      => 'enable_object_cache',
				'priority'    => 'low',
			);
		}

		// Check data volume.
		$tier_records  = isset( $stats['tier_records'] ) ? $stats['tier_records'] : 0;
		$usage_records = isset( $stats['usage_records'] ) ? $stats['usage_records'] : 0;

		if ( $tier_records > 1000 || $usage_records > 1000 ) {
			$recommendations[] = array(
				'type'        => 'success',
				'title'       => __( 'Large Dataset Detected', 'wp-mcp-ai' ),
				'description' => sprintf(
					/* translators: %d: number of records */
					__( 'You have %d records. Database optimizations are especially beneficial for large datasets.', 'wp-mcp-ai' ),
					$tier_records + $usage_records
				),
				'action'      => 'verify_performance',
				'priority'    => 'medium',
			);
		}

		return $recommendations;
	}

	/**
	 * Calculate cache hit rate (if available).
	 *
	 * @param int $user_count Number of users to sample.
	 * @return array Cache hit rate statistics.
	 */
	public static function calculate_cache_hit_rate( $user_count = 100 ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			return array(
				'hit_rate'    => 0,
				'sample_size' => 0,
			);
		}

		global $wpdb;

		// Get sample user IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT %d",
				$user_count
			)
		);

		if ( empty( $user_ids ) ) {
			return array(
				'hit_rate'    => 0,
				'sample_size' => 0,
			);
		}

		// Count cache hits.
		$cache_hits   = 0;
		$cache_misses = 0;

		foreach ( $user_ids as $user_id ) {
			$cache_key = "wp_mcp_ai_user_tier_{$user_id}";
			$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai' );

			if ( false !== $cached ) {
				++$cache_hits;
			} else {
				++$cache_misses;
			}
		}

		$total    = $cache_hits + $cache_misses;
		$hit_rate = $total > 0 ? ( $cache_hits / $total ) * 100 : 0;

		return array(
			'hit_rate'     => round( $hit_rate, 2 ),
			'cache_hits'   => $cache_hits,
			'cache_misses' => $cache_misses,
			'sample_size'  => $total,
		);
	}

	/**
	 * Get widget data for admin dashboard.
	 *
	 * Formats performance data for widget display.
	 *
	 * @return array Widget data.
	 */
	public static function get_widget_data() {
		$performance = self::get_performance_data();

		// Add cache hit rate for populated sites.
		$cache_hit_rate = self::calculate_cache_hit_rate( 50 );

		return array(
			'stats'          => $performance['stats'],
			'analysis'       => $performance['analysis'],
			'cache'          => $performance['cache'],
			'cache_hit_rate' => $cache_hit_rate,
		);
	}

	/**
	 * Trigger database optimization.
	 *
	 * @return array Result of optimization.
	 */
	public static function run_optimization() {
		if ( ! class_exists( 'WP_MCP_AI_Token_DB_Optimizer' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Database optimizer not available.', 'wp-mcp-ai' ),
			);
		}

		try {
			WP_MCP_AI_Token_DB_Optimizer::optimize_database();

			return array(
				'success' => true,
				'message' => __( 'Database optimization completed successfully.', 'wp-mcp-ai' ),
				'stats'   => WP_MCP_AI_Token_DB_Optimizer::get_optimization_stats(),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Optimization failed: %s', 'wp-mcp-ai' ),
					$e->getMessage()
				),
			);
		}
	}
}
