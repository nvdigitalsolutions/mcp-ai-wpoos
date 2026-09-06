<?php
/**
 * Agentic Workflow Optimizer (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Agentic_Workflow_Optimizer`:
 * byte-identical cache group/expiration/compression-threshold constants,
 * the constructor-driven enable gate
 * (`WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS`) + hook registration
 * (`wp_mcp_ai_before_tool_execute` cache lookup, `wp_mcp_ai_after_tool_execute`
 * cache store, the agentic-loop metrics pair, the tool-result-content
 * compression filter), the cacheable-tool allowlist + filter, cache-key
 * normalization, the gzip/base64 result-compression contract (10KB
 * threshold, 20% savings gate, Accept-Encoding gating), the metrics
 * lifecycle (`start_metrics_collection`/`record_metric`/
 * `log_performance_metrics`/`get_metrics`), the low-necessity iteration
 * tracker (5-consecutive nudge), the iteration prediction (95th
 * percentile + 20% buffer, clamped 5–50, default 15), and the
 * percentile math.
 *
 * Documented deviations:
 *  - Text domain — n/a (no translatable strings in the ported surface).
 *  - The logging path resolves per install mode: `logging_enabled()`
 *    reads the base `WP_MCP_AI_Admin_Settings::get_setting('enable_logging')`
 *    monolith (method_exists-guarded) / the `wp_mcp_ai_settings` option
 *    standalone; `log_event()` delegates to the base
 *    `WP_MCP_AI_Logger::log_event()` monolith and is dormant standalone
 *    (documented — the standalone logger lands with a later wave).
 *  - Constructor-driven hooks only (byte-identical to the base — the
 *    optimizer registers itself when instantiated by its consumers,
 *    which land with D-UI-6/E-UI-2).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Agentic Workflow Optimizer class.
 *
 * @since 2.1.0
 */
class AgenticWorkflowOptimizer {

	/**
	 * Cache group for tool results.
	 */
	const CACHE_GROUP = 'wp_mcp_ai_tool_results';

	/**
	 * Cache expiration time in seconds (5 minutes).
	 */
	const CACHE_EXPIRATION = 300;

	/**
	 * Maximum size for tool result before compression (10KB).
	 */
	const COMPRESSION_THRESHOLD = 10240;

	/**
	 * Maximum number of consecutive low-necessity iterations before
	 * the agent receives a nudge to re-evaluate its tool choices.
	 *
	 * @var int
	 */
	const MAX_LOW_NECESSITY_ITERATIONS = 5;

	/**
	 * Performance metrics for the current request.
	 *
	 * @var array
	 */
	protected $metrics = array();

	/**
	 * Whether optimizations are enabled.
	 *
	 * @var bool
	 */
	protected $enabled;

	/**
	 * Tracks the count of low-necessity iterations per session.
	 *
	 * @var array<string,int>
	 */
	private static $low_necessity_count = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->enabled = ! defined( 'WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS' ) || ! WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS;

		if ( $this->enabled ) {
			$this->init_hooks();
		}
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init_hooks(): void {
		// Tool execution caching.
		add_filter( 'wp_mcp_ai_before_tool_execute', array( $this, 'check_tool_cache' ), 10, 4 );
		add_action( 'wp_mcp_ai_after_tool_execute', array( $this, 'cache_tool_result' ), 10, 4 );

		// Performance metrics.
		add_action( 'wp_mcp_ai_agentic_loop_start', array( $this, 'start_metrics_collection' ) );
		add_action( 'wp_mcp_ai_agentic_loop_complete', array( $this, 'log_performance_metrics' ) );

		// Result compression for large responses.
		add_filter( 'wp_mcp_ai_tool_result_content', array( $this, 'maybe_compress_result' ), 10, 2 );
	}

	/**
	 * Check if tool result is cached.
	 *
	 * @param mixed  $pre       Pre-execution result (null to continue).
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed Cached result or null to continue.
	 */
	public function check_tool_cache( $pre, $tool_name, $arguments, $context ) { // phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Parameter reserved for the filter contract.
		// Respect upstream filter decisions.
		if ( null !== $pre ) {
			return $pre;
		}

		// Only cache idempotent tools (read-only operations).
		if ( ! $this->is_cacheable_tool( $tool_name ) ) {
			return null;
		}

		$cache_key = $this->get_cache_key( $tool_name, $arguments );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$this->record_metric( 'cache_hit', $tool_name );
			return $cached;
		}

		$this->record_metric( 'cache_miss', $tool_name );
		return null;
	}

	/**
	 * Cache tool execution result.
	 *
	 * @param mixed  $result    Tool execution result.
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return void
	 */
	public function cache_tool_result( $result, $tool_name, $arguments, $context ): void { // phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Parameter reserved for future context-aware caching.
		// Don't cache errors or non-cacheable tools.
		if ( is_wp_error( $result ) || ! $this->is_cacheable_tool( $tool_name ) ) {
			return;
		}

		$cache_key = $this->get_cache_key( $tool_name, $arguments );
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_EXPIRATION );
	}

	/**
	 * Check if a tool's results can be cached.
	 *
	 * Only read-only tools that don't depend on time or user context should be cached.
	 *
	 * @param string $tool_name Tool name.
	 * @return bool Whether tool is cacheable.
	 */
	protected function is_cacheable_tool( $tool_name ) {
		// Define cacheable tools (read-only operations).
		$cacheable_tools = array(
			'get_site_summary',
			'search_content',
			'get_recent_posts',
			'search_attachments',
			'get_elementor_templates',
			'get_jetengine_items',
			'get_woo_products',
			'get_rankmath_seo',
			'web_search', // Web search results can be cached for short periods.
		);

		/**
		 * Filter cacheable tools list.
		 *
		 * @param array  $cacheable_tools List of cacheable tool names.
		 * @param string $tool_name       Current tool name.
		 */
		$cacheable_tools = apply_filters( 'wp_mcp_ai_cacheable_tools', $cacheable_tools, $tool_name );

		return in_array( $tool_name, $cacheable_tools, true );
	}

	/**
	 * Generate cache key for tool execution.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return string Cache key.
	 */
	protected function get_cache_key( $tool_name, $arguments ) {
		$normalized_args = $this->normalize_arguments( $arguments );
		return 'tool_' . md5( $tool_name . wp_json_encode( $normalized_args ) );
	}

	/**
	 * Normalize arguments for consistent cache keys.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Normalized arguments.
	 */
	protected function normalize_arguments( $arguments ) {
		if ( ! is_array( $arguments ) ) {
			return array();
		}

		// Sort keys for consistency.
		ksort( $arguments );

		// Recursively normalize nested arrays.
		array_walk_recursive(
			$arguments,
			function ( &$value ) {
				if ( is_string( $value ) ) {
					$value = trim( $value );
				}
			}
		);

		return $arguments;
	}

	/**
	 * Maybe compress large tool results.
	 *
	 * @param string $content Tool result content (JSON encoded).
	 * @param array  $result  Raw result data.
	 * @return string Possibly compressed content.
	 */
	public function maybe_compress_result( $content, $result ) { // phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Parameter reserved for content-type detection.
		// Only compress if content is large enough.
		if ( strlen( $content ) < self::COMPRESSION_THRESHOLD ) {
			return $content;
		}

		// Don't compress if client doesn't support it.
		if ( ! $this->client_supports_compression() ) {
			return $content;
		}

		$compressed = gzencode( $content, 6 ); // Medium compression level.

		if ( false === $compressed ) {
			return $content;
		}

		// Only use compression if it actually saves space.
		if ( strlen( $compressed ) < strlen( $content ) * 0.8 ) {
			$this->record_metric( 'compression_saved', strlen( $content ) - strlen( $compressed ) );

			return wp_json_encode(
				array(
					'compressed'    => true,
					'data'          => base64_encode( $compressed ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used for binary data transport (compressed workflow state), not for code obfuscation.
					'original_size' => strlen( $content ),
				)
			);
		}

		return $content;
	}

	/**
	 * Check if client supports compression.
	 *
	 * @return bool Whether compression is supported.
	 */
	protected function client_supports_compression() {
		// Check if gzencode is available.
		if ( ! function_exists( 'gzencode' ) ) {
			return false;
		}

		// Check Accept-Encoding header for gzip support.
		if ( isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
			$encoding = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) );
			return false !== stripos( $encoding, 'gzip' );
		}

		return false;
	}

	/**
	 * Start collecting performance metrics.
	 *
	 * @return void
	 */
	public function start_metrics_collection(): void {
		$this->metrics = array(
			'start_time'        => microtime( true ),
			'start_memory'      => memory_get_usage(),
			'iterations'        => 0,
			'tool_executions'   => 0,
			'cache_hits'        => 0,
			'cache_misses'      => 0,
			'compression_saved' => 0,
		);
	}

	/**
	 * Record a metric.
	 *
	 * @param string $metric Metric name.
	 * @param mixed  $value  Metric value (increments if numeric).
	 * @return void
	 */
	protected function record_metric( $metric, $value = 1 ): void {
		if ( ! isset( $this->metrics[ $metric ] ) ) {
			$this->metrics[ $metric ] = 0;
		}

		if ( is_numeric( $value ) ) {
			$this->metrics[ $metric ] += $value;
		} else {
			$this->metrics[ $metric ] = $value;
		}
	}

	/**
	 * Log performance metrics.
	 *
	 * @return void
	 */
	public function log_performance_metrics(): void {
		if ( empty( $this->metrics ) ) {
			return;
		}

		$this->metrics['end_time']    = microtime( true );
		$this->metrics['end_memory']  = memory_get_usage();
		$this->metrics['duration']    = $this->metrics['end_time'] - $this->metrics['start_time'];
		$this->metrics['memory_used'] = $this->metrics['end_memory'] - $this->metrics['start_memory'];

		// Log metrics if logging is enabled.
		if ( $this->logging_enabled() ) {
			$this->log_event(
				'agentic_workflow_performance',
				'Agentic workflow completed',
				$this->metrics
			);
		}

		// Fire action for custom metric handling.
		do_action( 'wp_mcp_ai_agentic_metrics', $this->metrics );
	}

	/**
	 * Get current metrics.
	 *
	 * @return array Performance metrics.
	 */
	public function get_metrics() {
		return $this->metrics;
	}

	/**
	 * Clear all tool result caches.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		// WordPress object cache doesn't have a group-wide flush in all implementations.
		// This is a best-effort clear.
		wp_cache_flush();
	}

	/**
	 * Record a low-necessity iteration and return whether a nudge is needed.
	 *
	 * Called after each agentic iteration where all tool calls were read-only
	 * and didn't make meaningful progress toward the goal. After
	 * MAX_LOW_NECESSITY_ITERATIONS consecutive such iterations, returns true
	 * to signal that the agent should re-evaluate necessity.
	 *
	 * @param string $session_id Chat session ID.
	 * @return bool True if the agent should be nudged.
	 */
	public static function track_low_necessity_iteration( $session_id ) {
		$session_id = sanitize_key( (string) $session_id );
		if ( '' === $session_id ) {
			$session_id = 'default';
		}

		if ( ! isset( self::$low_necessity_count[ $session_id ] ) ) {
			self::$low_necessity_count[ $session_id ] = 0;
		}

		++self::$low_necessity_count[ $session_id ];

		return self::$low_necessity_count[ $session_id ] >= self::MAX_LOW_NECESSITY_ITERATIONS;
	}

	/**
	 * Reset the low-necessity counter when a meaningful tool call is made.
	 *
	 * @param string $session_id Chat session ID.
	 * @return void
	 */
	public static function reset_low_necessity_count( $session_id ): void {
		$session_id = sanitize_key( (string) $session_id );
		if ( '' === $session_id ) {
			$session_id = 'default';
		}
		self::$low_necessity_count[ $session_id ] = 0;
	}

	/**
	 * Predict optimal max iterations for assistant based on history.
	 *
	 * Analyzes past conversations to suggest optimal iteration limits.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return int Recommended max iterations.
	 */
	public function predict_optimal_iterations( $assistant_id ) {
		// Get historical iteration counts from transcripts.
		$history = $this->get_iteration_history( $assistant_id );

		if ( empty( $history ) ) {
			return 15; // Default for chat-client.
		}

		// Calculate 95th percentile to handle outliers.
		$percentile_95 = $this->calculate_percentile( $history, 95 );

		// Add 20% buffer for safety.
		$recommended = ceil( $percentile_95 * 1.2 );

		// Enforce bounds.
		return max( 5, min( 50, $recommended ) );
	}

	/**
	 * Get iteration history for assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Array of iteration counts.
	 */
	protected function get_iteration_history( $assistant_id ) { // phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Parameter reserved for future implementation.
		// This would query chat transcripts for historical data.
		// Placeholder implementation.
		return array();
	}

	/**
	 * Calculate percentile from array of values.
	 *
	 * @param array $values     Array of numeric values.
	 * @param int   $percentile Percentile to calculate (0-100).
	 * @return float Percentile value.
	 */
	protected function calculate_percentile( $values, $percentile ) {
		if ( empty( $values ) ) {
			return 0;
		}

		sort( $values );
		$index = ( $percentile / 100 ) * ( count( $values ) - 1 );
		$lower = floor( $index );
		$upper = ceil( $index );

		if ( $lower === $upper ) {
			return $values[ $lower ];
		}

		$fraction = $index - $lower;
		return $values[ $lower ] + ( $fraction * ( $values[ $upper ] - $values[ $lower ] ) );
	}

	// ── Per-mode logging seams ────────────────────────────────────────────────

	/**
	 * Whether event logging is enabled.
	 *
	 * Monolith: the base admin settings registry (method_exists-guarded).
	 * Standalone: the `wp_mcp_ai_settings` option read directly
	 * (identical effective behavior — the established `CronManager`
	 * pattern).
	 *
	 * @return bool
	 */
	protected function logging_enabled() {
		if ( defined( 'WP_MCP_AI_PATH' )
			&& class_exists( 'WP_MCP_AI_Admin_Settings' )
			&& is_callable( array( 'WP_MCP_AI_Admin_Settings', 'get_setting' ) )
		) {
			return (bool) \WP_MCP_AI_Admin_Settings::get_setting( 'enable_logging' );
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_logging'] );
	}

	/**
	 * Log an event.
	 *
	 * Monolith: the base logger. Standalone: dormant (documented — the
	 * standalone logger lands with a later wave).
	 *
	 * @param string $type    Event type.
	 * @param string $message Human-readable message.
	 * @param array  $context Event context.
	 * @return void
	 */
	protected function log_event( $type, $message, $context = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $type, $message, $context );
		}
	}
}
