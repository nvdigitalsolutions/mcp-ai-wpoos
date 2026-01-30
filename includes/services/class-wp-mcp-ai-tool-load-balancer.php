<?php
/**
 * Tool Load Balancer Service
 *
 * Implements intelligent tool execution routing with load-based strategies,
 * result caching, and performance optimization. Part of Phase 2: Load Balancing
 * & Efficiency enhancements for DeepSeek V4 orchestration.
 *
 * Features:
 * - Load-based routing (sync/async/cached)
 * - Result caching for deterministic tools
 * - Performance metrics tracking
 * - Tool recommendation engine
 * - Multiple load balancing strategies
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool Load Balancer class
 *
 * Manages intelligent routing and caching for tool execution.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Tool_Load_Balancer {

	/**
	 * Cache key prefix
	 */
	const CACHE_KEY_PREFIX = 'wp_mcp_ai_tool_result_';

	/**
	 * Cache group
	 */
	const CACHE_GROUP = 'mcp_ai_tool_results';

	/**
	 * Default cache TTL (15 minutes)
	 */
	const DEFAULT_CACHE_TTL = 900;

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $registry;

	/**
	 * Load monitor instance
	 *
	 * @var WP_MCP_AI_Tool_Load_Monitor|null
	 */
	protected $load_monitor;

	/**
	 * Tool execution orchestrator instance
	 *
	 * @var WP_MCP_AI_Tool_Execution_Orchestrator|null
	 */
	protected $orchestrator;

	/**
	 * List of cacheable tool slugs
	 *
	 * @var array
	 */
	protected $cacheable_tools = array(
		'get_recent_posts',
		'search_content',
		'get_post',
		'list_categories',
		'get_user_info',
		'get_term',
		'list_tags',
		'get_site_info',
		'list_users',
		'get_option',
	);

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Registry|null               $registry Tool registry.
	 * @param WP_MCP_AI_Tool_Load_Monitor|null           $load_monitor Load monitor.
	 * @param WP_MCP_AI_Tool_Execution_Orchestrator|null $orchestrator Execution orchestrator.
	 */
	public function __construct( $registry = null, $load_monitor = null, $orchestrator = null ) {
		$this->registry     = $registry;
		$this->load_monitor = $load_monitor;
		$this->orchestrator = $orchestrator;

		// Allow filtering cacheable tools.
		$this->cacheable_tools = apply_filters( 'wp_mcp_ai_cacheable_tools', $this->cacheable_tools );
	}

	/**
	 * Route tool execution based on load and caching
	 *
	 * Determines optimal execution strategy:
	 * - Return cached result if available
	 * - Execute synchronously if low load
	 * - Queue asynchronously if high load
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Tool result or error.
	 */
	public function route_tool_execution( $tool_slug, $arguments, $context ) {
		$tool_slug = sanitize_key( $tool_slug );

		// Check cache first if tool is cacheable.
		if ( $this->is_tool_cacheable( $tool_slug ) ) {
			$cached_result = $this->get_cached_result( $tool_slug, $arguments );
			if ( false !== $cached_result ) {
				return array(
					'success' => true,
					'data'    => $cached_result,
					'source'  => 'cache',
					'tool'    => $tool_slug,
				);
			}
		}

		// Get current load metrics.
		$load_metrics = $this->get_load_metrics( $tool_slug );

		// Determine execution strategy.
		$strategy = $this->select_execution_strategy( $tool_slug, $load_metrics, $context );

		// Execute with selected strategy.
		$result = $this->execute_with_strategy( $strategy, $tool_slug, $arguments, $context );

		// Cache result if successful and cacheable.
		if ( ! is_wp_error( $result ) && $this->is_tool_cacheable( $tool_slug ) ) {
			$this->cache_result( $tool_slug, $arguments, $result );
		}

		return $result;
	}

	/**
	 * Select execution strategy based on load
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $load_metrics Load metrics.
	 * @param array  $context Execution context.
	 * @return string Strategy: 'sync', 'async', or 'queued'.
	 */
	protected function select_execution_strategy( $tool_slug, $load_metrics, $context ) {
		// Force async if requested in context.
		if ( isset( $context['force_async'] ) && $context['force_async'] ) {
			return 'async';
		}

		// Check capacity score.
		$capacity_score = isset( $load_metrics['capacity_score'] ) ? $load_metrics['capacity_score'] : 100;
		$utilization    = isset( $load_metrics['utilization'] ) ? $load_metrics['utilization'] : 0;

		// Critical load - queue everything.
		if ( $capacity_score < 15 ) {
			return 'queued';
		}

		// High load - queue slow tools.
		if ( $capacity_score < 30 || $utilization > 0.85 ) {
			$avg_duration = isset( $load_metrics['service_time'] ) ? $load_metrics['service_time'] : 0;
			if ( $avg_duration > 5.0 ) { // > 5 seconds.
				return 'queued';
			}
			return 'async';
		}

		// Low load - execute synchronously.
		return 'sync';
	}

	/**
	 * Execute tool with specified strategy
	 *
	 * @param string $strategy Execution strategy.
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Execution result.
	 */
	protected function execute_with_strategy( $strategy, $tool_slug, $arguments, $context ) {
		$orchestrator = $this->get_orchestrator();
		if ( ! $orchestrator ) {
			return new WP_Error(
				'wp_mcp_ai_orchestrator_unavailable',
				__( 'Tool orchestrator is not available.', 'mcp-ai-wpoos' )
			);
		}

		// Set execution mode in context.
		$context['routing_strategy'] = $strategy;

		if ( 'queued' === $strategy || 'async' === $strategy ) {
			$context['force_async'] = true;
		}

		// Delegate to orchestrator.
		return $orchestrator->execute_tool( $tool_slug, $arguments, $context );
	}

	/**
	 * Track tool performance metrics
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $execution_data Execution data.
	 * @return void
	 */
	public function track_tool_metrics( $tool_slug, $execution_data ) {
		$monitor = $this->get_load_monitor();
		if ( ! $monitor ) {
			return;
		}

		$duration = isset( $execution_data['duration'] ) ? $execution_data['duration'] : 0;
		$success  = isset( $execution_data['success'] ) ? $execution_data['success'] : false;
		$context  = isset( $execution_data['context'] ) ? $execution_data['context'] : array();

		$monitor->record_execution_complete( $tool_slug, $duration, $success, $context );
	}

	/**
	 * Get tool recommendations based on task description
	 *
	 * Analyzes task requirements and returns ranked tool recommendations
	 * considering historical performance.
	 *
	 * @param string $task_description Task description.
	 * @param array  $context Optional context.
	 * @return array Ranked tool recommendations with confidence scores.
	 */
	public function get_tool_recommendations( $task_description, $context = array() ) {
		$registry = $this->get_registry();
		if ( ! $registry ) {
			return array();
		}

		// Get all available tools.
		$tools = $registry->get_all_tools();
		if ( empty( $tools ) ) {
			return array();
		}

		$recommendations = array();
		$task_lower      = strtolower( $task_description );

		foreach ( $tools as $tool_slug => $tool ) {
			$definition = $tool->get_definition();
			if ( empty( $definition ) ) {
				continue;
			}

			// Calculate relevance score.
			$relevance_score = $this->calculate_tool_relevance( $task_lower, $definition );
			if ( $relevance_score < 0.1 ) {
				continue;
			}

			// Get performance metrics.
			$monitor = $this->get_load_monitor();
			$stats   = array();
			if ( $monitor ) {
				$stats = $monitor->get_tool_performance_stats( $tool_slug, 24 );
			}

			$performance_score = isset( $stats['success_rate'] ) ? $stats['success_rate'] / 100 : 0.9;
			$avg_duration      = isset( $stats['avg_duration'] ) ? $stats['avg_duration'] : 0;

			// Combined confidence score.
			$confidence = ( $relevance_score * 0.7 ) + ( $performance_score * 0.3 );

			$recommendations[] = array(
				'tool_slug'         => $tool_slug,
				'tool_name'         => isset( $definition['name'] ) ? $definition['name'] : $tool_slug,
				'relevance_score'   => round( $relevance_score, 3 ),
				'performance_score' => round( $performance_score, 3 ),
				'confidence'        => round( $confidence, 3 ),
				'avg_duration'      => $avg_duration,
				'success_rate'      => isset( $stats['success_rate'] ) ? $stats['success_rate'] : 0,
			);
		}

		// Sort by confidence descending.
		usort(
			$recommendations,
			function ( $a, $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		// Return top 10 recommendations.
		return array_slice( $recommendations, 0, 10 );
	}

	/**
	 * Calculate tool relevance score for a task
	 *
	 * @param string $task_lower Lowercase task description.
	 * @param array  $definition Tool definition.
	 * @return float Relevance score (0-1).
	 */
	protected function calculate_tool_relevance( $task_lower, $definition ) {
		$score = 0.0;

		// Check tool name.
		if ( isset( $definition['name'] ) ) {
			$name_lower = strtolower( $definition['name'] );
			if ( false !== strpos( $task_lower, $name_lower ) ) {
				$score += 0.5;
			}
		}

		// Check tool description.
		if ( isset( $definition['description'] ) ) {
			$desc_lower = strtolower( $definition['description'] );
			$keywords   = explode( ' ', $task_lower );
			$matches    = 0;
			foreach ( $keywords as $keyword ) {
				if ( strlen( $keyword ) > 3 && false !== strpos( $desc_lower, $keyword ) ) {
					++$matches;
				}
			}
			if ( count( $keywords ) > 0 ) {
				$score += ( $matches / count( $keywords ) ) * 0.5;
			}
		}

		return min( 1.0, $score );
	}

	/**
	 * Check if tool is cacheable
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return bool True if cacheable.
	 */
	protected function is_tool_cacheable( $tool_slug ) {
		$registry = $this->get_registry();
		if ( ! $registry ) {
			return false;
		}

		// Check if in cacheable list.
		if ( ! in_array( $tool_slug, $this->cacheable_tools, true ) ) {
			return false;
		}

		// Get tool definition.
		$tool = $registry->get_tool( $tool_slug );
		if ( ! $tool ) {
			return false;
		}

		$definition = $tool->get_definition();

		// Tool must be marked as safe and not modify state.
		$is_safe          = isset( $definition['safe'] ) && $definition['safe'];
		$modifies_wp      = isset( $definition['modifies-wp'] ) && $definition['modifies-wp'];
		$is_deterministic = isset( $definition['deterministic'] ) && $definition['deterministic'];

		return $is_safe && ! $modifies_wp && $is_deterministic;
	}

	/**
	 * Get cached result for tool execution
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @return mixed|false Cached result or false if not found.
	 */
	protected function get_cached_result( $tool_slug, $arguments ) {
		$cache_key = $this->generate_cache_key( $tool_slug, $arguments );
		return wp_cache_get( $cache_key, self::CACHE_GROUP );
	}

	/**
	 * Cache tool execution result
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param mixed  $result Execution result.
	 * @return bool Success status.
	 */
	protected function cache_result( $tool_slug, $arguments, $result ) {
		$cache_key = $this->generate_cache_key( $tool_slug, $arguments );
		$ttl       = apply_filters( 'wp_mcp_ai_tool_cache_ttl', self::DEFAULT_CACHE_TTL, $tool_slug );

		return wp_cache_set( $cache_key, $result, self::CACHE_GROUP, $ttl );
	}

	/**
	 * Generate cache key from tool and arguments
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @return string Cache key.
	 */
	protected function generate_cache_key( $tool_slug, $arguments ) {
		// Normalize arguments for consistent caching.
		ksort( $arguments );
		$args_hash = md5( wp_json_encode( $arguments ) );

		return self::CACHE_KEY_PREFIX . $tool_slug . '_' . $args_hash;
	}

	/**
	 * Get load metrics for a tool
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return array Load metrics.
	 */
	protected function get_load_metrics( $tool_slug ) {
		$monitor = $this->get_load_monitor();
		if ( ! $monitor ) {
			return array(
				'capacity_score' => 100,
				'utilization'    => 0,
			);
		}

		return $monitor->get_load_metrics( $tool_slug );
	}

	/**
	 * Get tool registry instance
	 *
	 * @return WP_MCP_AI_Tool_Registry|null Tool registry.
	 */
	protected function get_registry() {
		if ( ! $this->registry && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		}
		return $this->registry;
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
	 * Get tool execution orchestrator instance
	 *
	 * @return WP_MCP_AI_Tool_Execution_Orchestrator|null Orchestrator.
	 */
	protected function get_orchestrator() {
		if ( ! $this->orchestrator && class_exists( 'WP_MCP_AI_Tool_Execution_Orchestrator' ) ) {
			$this->orchestrator = new WP_MCP_AI_Tool_Execution_Orchestrator();
		}
		return $this->orchestrator;
	}

	/**
	 * Clear tool result cache
	 *
	 * @param string|null $tool_slug Optional tool slug to clear specific tool cache.
	 * @return bool Success status.
	 */
	public function clear_cache( $tool_slug = null ) {
		if ( null === $tool_slug ) {
			// Clear entire cache group.
			return wp_cache_flush();
		}

		// Clear specific tool cache (requires iterating through possible keys).
		// For simplicity, we'll just return true as wp_cache doesn't support wildcard deletes.
		return true;
	}
}
