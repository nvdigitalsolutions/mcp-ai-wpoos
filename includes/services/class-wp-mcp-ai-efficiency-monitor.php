<?php
/**
 * Orchestration Efficiency Monitor Service
 *
 * Provides comprehensive health metrics and performance monitoring for
 * the orchestration layer. Aggregates data from load balancer, chain predictor,
 * and load monitor to generate actionable insights. Part of Phase 2.3.
 *
 * Features:
 * - System-wide health metrics
 * - Bottleneck identification
 * - Optimization recommendations
 * - Historical trend analysis
 * - Dashboard data provider
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration Efficiency Monitor class
 *
 * Monitors and reports on orchestration efficiency.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Efficiency_Monitor {

	/**
	 * Storage keys
	 */
	const METRICS_HISTORY_KEY = 'wp_mcp_ai_efficiency_metrics_history';
	const RECOMMENDATIONS_KEY = 'wp_mcp_ai_efficiency_recommendations';

	/**
	 * Configuration
	 */
	const HISTORY_RETENTION_DAYS = 7;
	const CACHE_TTL              = 300; // 5 minutes.

	/**
	 * Load monitor instance
	 *
	 * @var WP_MCP_AI_Tool_Load_Monitor|null
	 */
	protected $load_monitor;

	/**
	 * Load balancer instance
	 *
	 * @var WP_MCP_AI_Tool_Load_Balancer|null
	 */
	protected $load_balancer;

	/**
	 * Chain predictor instance
	 *
	 * @var WP_MCP_AI_Tool_Chain_Predictor|null
	 */
	protected $chain_predictor;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Load_Monitor|null    $load_monitor Load monitor.
	 * @param WP_MCP_AI_Tool_Load_Balancer|null   $load_balancer Load balancer.
	 * @param WP_MCP_AI_Tool_Chain_Predictor|null $chain_predictor Chain predictor.
	 */
	public function __construct( $load_monitor = null, $load_balancer = null, $chain_predictor = null ) {
		$this->load_monitor    = $load_monitor;
		$this->load_balancer   = $load_balancer;
		$this->chain_predictor = $chain_predictor;
	}

	/**
	 * Get orchestration health metrics
	 *
	 * Returns comprehensive system health status with recommendations.
	 *
	 * @return array Health metrics.
	 */
	public function get_health_metrics() {
		// Check cache first.
		$cache_key = 'wp_mcp_ai_efficiency_health';
		$cached    = wp_cache_get( $cache_key, 'mcp_ai_efficiency' );

		if ( false !== $cached ) {
			return $cached;
		}

		$monitor = $this->get_load_monitor();
		if ( ! $monitor ) {
			return $this->get_fallback_metrics();
		}

		// Get system load metrics.
		$system_load = $monitor->get_system_load_metrics();

		// Calculate tool execution metrics.
		$tool_metrics = $this->calculate_tool_execution_metrics();

		// Calculate load balancing metrics.
		$balancing_metrics = $this->calculate_load_balancing_metrics();

		// Calculate resource usage metrics.
		$resource_metrics = $this->calculate_resource_usage_metrics( $system_load );

		// Identify bottlenecks.
		$bottlenecks = $this->identify_bottlenecks( $system_load );

		$metrics = array(
			'health_status'      => $system_load['health_status'] ?? 'unknown',
			'tool_execution'     => $tool_metrics,
			'load_balancing'     => $balancing_metrics,
			'resource_usage'     => $resource_metrics,
			'bottlenecks'        => $bottlenecks,
			'timestamp'          => current_time( 'mysql' ),
			'available_capacity' => $system_load['available_capacity'] ?? 100,
		);

		// Cache for 5 minutes.
		wp_cache_set( $cache_key, $metrics, 'mcp_ai_efficiency', self::CACHE_TTL );

		// Store in history.
		$this->store_metrics_history( $metrics );

		return $metrics;
	}

	/**
	 * Get optimization recommendations
	 *
	 * Analyzes current metrics and generates actionable recommendations.
	 *
	 * @return array Recommendations with priority and impact estimates.
	 */
	public function get_recommendations() {
		$metrics = $this->get_health_metrics();

		$recommendations = array();
		$health_status   = $metrics['health_status'] ?? 'unknown';

		// Critical health status recommendations.
		if ( 'critical' === $health_status ) {
			$recommendations[] = array(
				'priority'    => 'high',
				'title'       => __( 'Critical System Load', 'mcp-ai-wpoos' ),
				'description' => __( 'System capacity is critically low. Immediate action required.', 'mcp-ai-wpoos' ),
				'actions'     => array(
					__( 'Enable asynchronous execution for all non-critical tools', 'mcp-ai-wpoos' ),
					__( 'Review and optimize slow-running tools', 'mcp-ai-wpoos' ),
					__( 'Consider scaling server resources', 'mcp-ai-wpoos' ),
				),
				'impact'      => 'high',
			);
		} elseif ( 'warning' === $health_status ) {
			$recommendations[] = array(
				'priority'    => 'medium',
				'title'       => __( 'High System Utilization', 'mcp-ai-wpoos' ),
				'description' => __( 'System is experiencing elevated load. Monitor closely.', 'mcp-ai-wpoos' ),
				'actions'     => array(
					__( 'Enable caching for read-only tools', 'mcp-ai-wpoos' ),
					__( 'Review tool execution patterns', 'mcp-ai-wpoos' ),
				),
				'impact'      => 'medium',
			);
		}

		// Cache hit rate recommendations.
		$cache_hit_rate = $metrics['tool_execution']['cache_hit_rate'] ?? 0;
		if ( $cache_hit_rate < 20 ) {
			$recommendations[] = array(
				'priority'    => 'low',
				'title'       => __( 'Low Cache Hit Rate', 'mcp-ai-wpoos' ),
				'description' => sprintf(
					/* translators: %d: cache hit rate percentage */
					__( 'Cache hit rate is only %d%%. Enabling caching for more tools could improve performance.', 'mcp-ai-wpoos' ),
					$cache_hit_rate
				),
				'actions'     => array(
					__( 'Review and mark deterministic tools as cacheable', 'mcp-ai-wpoos' ),
					__( 'Increase cache TTL for stable data', 'mcp-ai-wpoos' ),
				),
				'impact'      => 'medium',
			);
		}

		// Bottleneck recommendations.
		$bottlenecks = $metrics['bottlenecks'] ?? array();
		foreach ( $bottlenecks as $bottleneck ) {
			$tool_slug = $bottleneck['tool_slug'] ?? 'unknown';
			$issue     = $bottleneck['issue'] ?? '';

			if ( 'high_utilization' === $issue ) {
				$recommendations[] = array(
					'priority'    => 'medium',
					'title'       => sprintf(
						/* translators: %s: tool slug */
						__( 'Tool "%s" Heavily Utilized', 'mcp-ai-wpoos' ),
						$tool_slug
					),
					'description' => __( 'This tool is experiencing high load and may cause delays.', 'mcp-ai-wpoos' ),
					'actions'     => array(
						__( 'Enable asynchronous execution for this tool', 'mcp-ai-wpoos' ),
						__( 'Optimize tool implementation', 'mcp-ai-wpoos' ),
						__( 'Consider caching if applicable', 'mcp-ai-wpoos' ),
					),
					'impact'      => 'medium',
				);
			}
		}

		// Async ratio recommendations.
		$sync_async_ratio = $metrics['load_balancing']['sync_async_ratio'] ?? 1.0;
		if ( $sync_async_ratio > 0.9 && $health_status !== 'excellent' ) {
			$recommendations[] = array(
				'priority'    => 'low',
				'title'       => __( 'High Synchronous Execution Ratio', 'mcp-ai-wpoos' ),
				'description' => __( 'Most tools are running synchronously. Consider async execution for better scalability.', 'mcp-ai-wpoos' ),
				'actions'     => array(
					__( 'Review tool capability flags', 'mcp-ai-wpoos' ),
					__( 'Enable async mode for long-running tools', 'mcp-ai-wpoos' ),
				),
				'impact'      => 'low',
			);
		}

		// Sort by priority.
		usort(
			$recommendations,
			function ( $a, $b ) {
				$priority_order = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
				$a_val          = $priority_order[ $a['priority'] ] ?? 0;
				$b_val          = $priority_order[ $b['priority'] ] ?? 0;
				return $b_val <=> $a_val;
			}
		);

		return $recommendations;
	}

	/**
	 * Get historical metrics
	 *
	 * @param int $days Number of days to retrieve (default 7).
	 * @return array Historical metrics.
	 */
	public function get_historical_metrics( $days = 7 ) {
		$history = get_option( self::METRICS_HISTORY_KEY, array() );

		if ( ! is_array( $history ) ) {
			return array();
		}

		// Filter by time window.
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		return array_filter(
			$history,
			function ( $entry ) use ( $cutoff ) {
				$timestamp = isset( $entry['timestamp_unix'] ) ? $entry['timestamp_unix'] : 0;
				return $timestamp > $cutoff;
			}
		);
	}

	/**
	 * Calculate tool execution metrics
	 *
	 * @return array Tool execution metrics.
	 */
	protected function calculate_tool_execution_metrics() {
		// Placeholder - integrate with actual tool execution stats.
		return array(
			'avg_execution_time' => 0,
			'success_rate'       => 0,
			'cache_hit_rate'     => 0,
		);
	}

	/**
	 * Calculate load balancing metrics
	 *
	 * @return array Load balancing metrics.
	 */
	protected function calculate_load_balancing_metrics() {
		// Placeholder - integrate with orchestrator stats.
		return array(
			'sync_async_ratio' => 0,
			'queue_depth'      => 0,
			'distributed_calls' => 0,
		);
	}

	/**
	 * Calculate resource usage metrics
	 *
	 * @param array $system_load System load data.
	 * @return array Resource usage metrics.
	 */
	protected function calculate_resource_usage_metrics( $system_load ) {
		return array(
			'memory_utilization' => $this->get_memory_usage_percentage(),
			'api_rate_limits'    => $this->get_rate_limit_status(),
			'token_consumption'  => $this->get_token_usage(),
		);
	}

	/**
	 * Identify system bottlenecks
	 *
	 * @param array $system_load System load data.
	 * @return array Identified bottlenecks.
	 */
	protected function identify_bottlenecks( $system_load ) {
		$bottlenecks = array();

		// Check for heavily utilized tools.
		$top_tools = $system_load['top_tools'] ?? array();

		foreach ( $top_tools as $tool_slug => $metrics ) {
			$utilization = $metrics['utilization'] ?? 0;

			if ( $utilization > 0.9 ) {
				$bottlenecks[] = array(
					'type'        => 'tool',
					'tool_slug'   => $tool_slug,
					'issue'       => 'high_utilization',
					'severity'    => 'high',
					'utilization' => $utilization,
				);
			} elseif ( $utilization > 0.7 ) {
				$bottlenecks[] = array(
					'type'        => 'tool',
					'tool_slug'   => $tool_slug,
					'issue'       => 'elevated_utilization',
					'severity'    => 'medium',
					'utilization' => $utilization,
				);
			}
		}

		// Check system memory.
		$memory_usage = $this->get_memory_usage_percentage();
		if ( $memory_usage > 90 ) {
			$bottlenecks[] = array(
				'type'     => 'system',
				'issue'    => 'high_memory',
				'severity' => 'high',
				'value'    => $memory_usage,
			);
		}

		return $bottlenecks;
	}

	/**
	 * Get memory usage percentage
	 *
	 * @return float Memory usage percentage.
	 */
	protected function get_memory_usage_percentage() {
		$memory_limit = ini_get( 'memory_limit' );
		if ( empty( $memory_limit ) || '-1' === $memory_limit ) {
			return 0;
		}

		// Convert memory limit to bytes.
		$limit = $this->convert_to_bytes( $memory_limit );
		if ( $limit <= 0 ) {
			return 0;
		}

		$current = memory_get_usage( true );

		return ( $current / $limit ) * 100;
	}

	/**
	 * Convert memory value to bytes
	 *
	 * @param string $value Memory value (e.g., '256M').
	 * @return int Bytes.
	 */
	protected function convert_to_bytes( $value ) {
		$value = trim( $value );
		$last  = strtolower( $value[ strlen( $value ) - 1 ] );
		$value = (int) $value;

		switch ( $last ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
		}

		return $value;
	}

	/**
	 * Get API rate limit status
	 *
	 * @return array Rate limit status.
	 */
	protected function get_rate_limit_status() {
		// Placeholder - integrate with rate limit manager.
		return array(
			'openai'  => 'healthy',
			'gemini'  => 'healthy',
		);
	}

	/**
	 * Get token usage
	 *
	 * @return array Token usage stats.
	 */
	protected function get_token_usage() {
		// Placeholder - integrate with usage tracker.
		return array(
			'total_tokens' => 0,
			'cost_today'   => 0,
		);
	}

	/**
	 * Store metrics in history
	 *
	 * @param array $metrics Metrics to store.
	 * @return void
	 */
	protected function store_metrics_history( $metrics ) {
		$history = get_option( self::METRICS_HISTORY_KEY, array() );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// Add timestamp.
		$metrics['timestamp_unix'] = time();

		// Add to history.
		$history[] = $metrics;

		// Clean up old entries.
		$cutoff = time() - ( self::HISTORY_RETENTION_DAYS * DAY_IN_SECONDS );
		$history = array_filter(
			$history,
			function ( $entry ) use ( $cutoff ) {
				$timestamp = isset( $entry['timestamp_unix'] ) ? $entry['timestamp_unix'] : 0;
				return $timestamp > $cutoff;
			}
		);

		// Limit to 1000 entries.
		if ( count( $history ) > 1000 ) {
			$history = array_slice( $history, -1000 );
		}

		update_option( self::METRICS_HISTORY_KEY, $history, false );
	}

	/**
	 * Get fallback metrics when services unavailable
	 *
	 * @return array Fallback metrics.
	 */
	protected function get_fallback_metrics() {
		return array(
			'health_status'       => 'unknown',
			'tool_execution'      => array(
				'avg_execution_time' => 0,
				'success_rate'       => 0,
				'cache_hit_rate'     => 0,
			),
			'load_balancing'      => array(
				'sync_async_ratio' => 0,
				'queue_depth'      => 0,
				'distributed_calls' => 0,
			),
			'resource_usage'      => array(
				'memory_utilization' => $this->get_memory_usage_percentage(),
				'api_rate_limits'    => array(),
				'token_consumption'  => array(),
			),
			'bottlenecks'         => array(),
			'timestamp'           => current_time( 'mysql' ),
			'available_capacity'  => 100,
		);
	}

	/**
	 * Get load monitor instance
	 *
	 * @return WP_MCP_AI_Tool_Load_Monitor|null Load monitor.
	 */
	protected function get_load_monitor() {
		if ( ! $this->load_monitor && class_exists( 'WP_MCP_AI_Tool_Load_Monitor' ) ) {
			$this->load_monitor = new WP_MCP_AI_Tool_Load_Monitor();
		}
		return $this->load_monitor;
	}

	/**
	 * Get load balancer instance
	 *
	 * @return WP_MCP_AI_Tool_Load_Balancer|null Load balancer.
	 */
	protected function get_load_balancer() {
		if ( ! $this->load_balancer && class_exists( 'WP_MCP_AI_Tool_Load_Balancer' ) ) {
			$this->load_balancer = new WP_MCP_AI_Tool_Load_Balancer();
		}
		return $this->load_balancer;
	}

	/**
	 * Get chain predictor instance
	 *
	 * @return WP_MCP_AI_Tool_Chain_Predictor|null Chain predictor.
	 */
	protected function get_chain_predictor() {
		if ( ! $this->chain_predictor && class_exists( 'WP_MCP_AI_Tool_Chain_Predictor' ) ) {
			$this->chain_predictor = new WP_MCP_AI_Tool_Chain_Predictor();
		}
		return $this->chain_predictor;
	}

	/**
	 * Clear metrics history
	 *
	 * @return bool Success status.
	 */
	public function clear_history() {
		delete_option( self::METRICS_HISTORY_KEY );
		wp_cache_delete( 'wp_mcp_ai_efficiency_health', 'mcp_ai_efficiency' );
		return true;
	}
}
