<?php
/**
 * Mesh router with AI-powered peer selection and load balancing.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Mesh;

use NvoosContentGraphAiPlatform\Federation\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intelligent routing for mesh compute pooling across distributed WordPress sites.
 *
 * Supports:
 * - AI-powered peer selection based on capacity, load, and response times
 * - Automatic failover and retry logic
 * - Compute hub designation for sites with larger models (Ollama, cloud)
 * - Load balancing across multiple hubs
 * - Health tracking and monitoring
 * - Support for Cloudways, SiteGround, and local deployments
 */
class MeshRouter {
	/**
	 * Log a mesh routing event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $event   Event slug.
	 * @param string $message Event message.
	 * @param array  $context Event context.
	 */
	private static function log_event( $event, $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $event, $message, $context );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $event, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Option name for storing peer health metrics.
	 */
	const HEALTH_METRICS_OPTION = 'wp_mcp_ai_mesh_health_metrics';

	/**
	 * Option name for storing routing statistics.
	 */
	const ROUTING_STATS_OPTION = 'wp_mcp_ai_mesh_routing_stats';

	/**
	 * Maximum age of health metrics in seconds (5 minutes).
	 */
	const HEALTH_METRICS_MAX_AGE = 300;

	/**
	 * Maximum number of retry attempts for failed requests.
	 */
	const MAX_RETRY_ATTEMPTS = 3;

	/**
	 * Assistant meta key for compute hub configuration.
	 */
	const META_COMPUTE_HUB_CONFIG = '_wp_mcp_ai_compute_hub_config';

	/**
	 * Divisor for load estimation calculation.
	 */
	const LOAD_ESTIMATION_DIVISOR = 20;

	/**
	 * Time window for arrival rate estimation (seconds).
	 */
	const ARRIVAL_RATE_TIME_WINDOW = 60.0;

	/**
	 * Default arrival rate when no data available (jobs per second).
	 */
	const DEFAULT_ARRIVAL_RATE = 0.01;

	/**
	 * Weight for utilization score in capacity calculation.
	 */
	const CAPACITY_UTILIZATION_WEIGHT = 0.6;

	/**
	 * Weight for queue score in capacity calculation.
	 */
	const CAPACITY_QUEUE_WEIGHT = 0.4;

	/**
	 * Multiplier for utilization to percentage conversion.
	 */
	const UTILIZATION_TO_PERCENTAGE = 100;

	/**
	 * Multiplier for queue length scoring.
	 */
	const QUEUE_LENGTH_MULTIPLIER = 20;

	/**
	 * Circuit breaker: consecutive failures before opening circuit.
	 */
	const CIRCUIT_BREAKER_FAILURE_THRESHOLD = 5;

	/**
	 * Circuit breaker: seconds before attempting recovery (half-open state).
	 */
	const CIRCUIT_BREAKER_TIMEOUT = 30;

	/**
	 * Circuit breaker: option name for storing circuit states.
	 */
	const CIRCUIT_BREAKER_OPTION = 'wp_mcp_ai_mesh_circuit_states';

	/**
	 * Exponential backoff: initial delay in milliseconds.
	 */
	const BACKOFF_INITIAL_DELAY_MS = 100;

	/**
	 * Exponential backoff: multiplier for each retry.
	 */
	const BACKOFF_MULTIPLIER = 2;

	/**
	 * Exponential backoff: maximum delay in milliseconds.
	 */
	const BACKOFF_MAX_DELAY_MS = 5000;

	/**
	 * Get the optimal peer for a given request using AI-powered analysis.
	 *
	 * Analyzes:
	 * - Current load (recent request count)
	 * - Response time history
	 * - Model availability and capacity
	 * - Peer health status
	 * - Geographic proximity (if configured)
	 * - Compute hub priority
	 *
	 * @param int    $assistant_id Assistant ID making the request.
	 * @param string $prompt       The prompt being sent.
	 * @param array  $context      Request context.
	 * @return array|\WP_Error Optimal peer configuration or error.
	 */
	public static function get_optimal_peer( $assistant_id, $prompt, $context = array() ) {
		$settings = Settings::get_settings();

		// Check if mesh is enabled.
		if ( empty( $settings['enable_mesh'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mesh_disabled',
				__( 'Mesh networking is not enabled.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Get peer sites.
		$peer_sites = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] )
			? $settings['mesh_peer_sites']
			: array();

		if ( empty( $peer_sites ) ) {
			return new \WP_Error(
				'wp_mcp_ai_no_peers',
				__( 'No peer sites configured in mesh network.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Get compute hub configuration for this assistant.
		$hub_config = get_post_meta( $assistant_id, self::META_COMPUTE_HUB_CONFIG, true );
		if ( ! is_array( $hub_config ) ) {
			$hub_config = array();
		}

		// Get routing strategy.
		$routing_strategy = isset( $hub_config['routing_strategy'] ) ? $hub_config['routing_strategy'] : 'ai_optimized';

		// Get health metrics for all peers.
		$health_metrics = self::get_health_metrics();

		// Filter out unhealthy peers.
		$healthy_peers = array();
		foreach ( $peer_sites as $peer ) {
			$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
			if ( empty( $peer_name ) ) {
				continue;
			}

			$health = self::get_peer_health( $peer_name, $health_metrics );
			if ( 'down' !== $health['status'] ) {
				$healthy_peers[] = array_merge( $peer, array( 'health' => $health ) );
			}
		}

		if ( empty( $healthy_peers ) ) {
			return new \WP_Error(
				'wp_mcp_ai_no_healthy_peers',
				__( 'No healthy peer sites available in mesh network.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Route based on strategy.
		switch ( $routing_strategy ) {
			case 'ai_optimized':
				return self::select_peer_ai_optimized( $healthy_peers, $prompt, $hub_config, $context );

			case 'round_robin':
				return self::select_peer_round_robin( $healthy_peers, $hub_config, $context );

			case 'least_loaded':
				return self::select_peer_least_loaded( $healthy_peers, $health_metrics, $context );

			case 'preferred_with_fallback':
				return self::select_peer_preferred( $healthy_peers, $hub_config, $context );

			default:
				return self::select_peer_ai_optimized( $healthy_peers, $prompt, $hub_config, $context );
		}
	}

	/**
	 * AI-optimized peer selection with Little's Law capacity prediction.
	 *
	 * Uses intelligent analysis to select the best peer based on:
	 * - Task complexity (via prompt analysis)
	 * - Peer capacity and current load (using Little's Law)
	 * - Response time history
	 * - Model availability
	 * - Predicted queue wait time
	 * - Geographic proximity (context-aware)
	 * - User preferences (context-aware)
	 *
	 * @param array  $healthy_peers Available healthy peers.
	 * @param string $prompt        The prompt being sent.
	 * @param array  $hub_config    Compute hub configuration.
	 * @param array  $context       Request context (user preferences, geographic routing, session data).
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_ai_optimized( $healthy_peers, $prompt, $hub_config, $context ) {
		// Analyze prompt complexity.
		$complexity_score = self::analyze_prompt_complexity( $prompt );

		// Score each peer based on multiple factors.
		$scored_peers = array();
		foreach ( $healthy_peers as $peer ) {
			$score  = 0;
			$health = $peer['health'];

			// Factor 1: Response time (lower is better) - 20% weight.
			$avg_response_time   = isset( $health['avg_response_time'] ) ? $health['avg_response_time'] : 5.0;
			$response_time_score = max( 0, 100 - ( $avg_response_time * 10 ) );
			$score              += $response_time_score * 0.2;

			// Factor 2: Current load (lower is better) - 15% weight.
			$current_load = isset( $health['current_load'] ) ? $health['current_load'] : 0;
			$load_score   = max( 0, 100 - ( $current_load * 5 ) );
			$score       += $load_score * 0.15;

			// Factor 3: Success rate - 15% weight.
			$success_rate = isset( $health['success_rate'] ) ? $health['success_rate'] : 100;
			$score       += $success_rate * 0.15;

			// Factor 4: Little's Law capacity analysis - 15% weight.
			$capacity_score = self::calculate_peer_capacity_score( $health, $avg_response_time );
			$score         += $capacity_score * 0.15;

			// Factor 5: Compute hub priority - 15% weight.
			$is_compute_hub = self::is_compute_hub( $peer, $hub_config );
			if ( $is_compute_hub && $complexity_score > 7 ) {
				// Prefer compute hubs for complex tasks.
				$score += 15;
			}

			// Factor 6: Geographic proximity - 10% weight (NEW).
			$geo_score = self::calculate_geographic_score( $peer, $context );
			$score    += $geo_score * 0.1;

			// Factor 7: User preference matching - 10% weight (NEW).
			$preference_score = self::calculate_preference_score( $peer, $context );
			$score           += $preference_score * 0.1;

			$scored_peers[] = array(
				'peer'             => $peer,
				'score'            => $score,
				'capacity_score'   => $capacity_score,
				'geo_score'        => $geo_score,
				'preference_score' => $preference_score,
			);
		}

		// Sort by score (descending).
		usort(
			$scored_peers,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Log the routing decision with context awareness.
		self::log_event(
			'mesh_routing_ai_optimized',
			'AI-optimized peer selection completed with context-aware routing.',
			array(
				'selected_peer'    => $scored_peers[0]['peer']['name'],
				'score'            => $scored_peers[0]['score'],
				'capacity_score'   => $scored_peers[0]['capacity_score'],
				'geo_score'        => $scored_peers[0]['geo_score'],
				'preference_score' => $scored_peers[0]['preference_score'],
				'complexity_score' => $complexity_score,
				'total_peers'      => count( $healthy_peers ),
				'context'          => self::sanitize_context_for_logging( $context ),
			)
		);

		return $scored_peers[0]['peer'];
	}

	/**
	 * Round-robin peer selection with optional hub prioritization.
	 *
	 * Selects peers in a rotating fashion. If hub_config specifies
	 * hub_only mode, only compute hubs are included in rotation.
	 *
	 * @param array $healthy_peers Available healthy peers.
	 * @param array $hub_config    Compute hub configuration.
	 * @param array $context       Request context (for future session affinity).
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_round_robin( $healthy_peers, $hub_config, $context = array() ) {
		// Filter to compute hubs only if hub_only mode is enabled.
		if ( ! empty( $hub_config['hub_only'] ) ) {
			$hub_peers = array();
			foreach ( $healthy_peers as $peer ) {
				if ( self::is_compute_hub( $peer, $hub_config ) ) {
					$hub_peers[] = $peer;
				}
			}

			// Use compute hubs if available, otherwise fall back to all peers.
			if ( ! empty( $hub_peers ) ) {
				$healthy_peers = $hub_peers;
			}
		}

		$stats      = get_option( self::ROUTING_STATS_OPTION, array() );
		$last_index = isset( $stats['last_round_robin_index'] ) ? (int) $stats['last_round_robin_index'] : -1;

		$next_index = ( $last_index + 1 ) % count( $healthy_peers );

		// Update stats.
		$stats['last_round_robin_index'] = $next_index;
		update_option( self::ROUTING_STATS_OPTION, $stats, false );

		self::log_event(
			'mesh_routing_round_robin',
			'Round-robin peer selection.',
			array(
				'selected_peer' => $healthy_peers[ $next_index ]['name'],
				'index'         => $next_index,
				'hub_only_mode' => ! empty( $hub_config['hub_only'] ),
				'context'       => self::sanitize_context_for_logging( $context ),
			)
		);

		return $healthy_peers[ $next_index ];
	}

	/**
	 * Least-loaded peer selection with optional context awareness.
	 *
	 * @param array $healthy_peers  Available healthy peers.
	 * @param array $health_metrics All health metrics.
	 * @param array $context        Request context (for future load-aware features).
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_least_loaded( $healthy_peers, $health_metrics, $context = array() ) {
		$least_loaded = null;
		$min_load     = PHP_INT_MAX;

		foreach ( $healthy_peers as $peer ) {
			$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
			$health    = self::get_peer_health( $peer_name, $health_metrics );
			$load      = isset( $health['current_load'] ) ? $health['current_load'] : 0;

			if ( $load < $min_load ) {
				$min_load     = $load;
				$least_loaded = $peer;
			}
		}

		self::log_event(
			'mesh_routing_least_loaded',
			'Least-loaded peer selection.',
			array(
				'selected_peer' => $least_loaded['name'],
				'load'          => $min_load,
				'context'       => self::sanitize_context_for_logging( $context ),
			)
		);

		return $least_loaded;
	}

	/**
	 * Preferred peer with fallback selection.
	 *
	 * @param array $healthy_peers Available healthy peers.
	 * @param array $hub_config    Compute hub configuration with preferred_peers list.
	 * @param array $context       Request context (for future preference features).
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_preferred( $healthy_peers, $hub_config, $context = array() ) {
		$preferred_peers = isset( $hub_config['preferred_peers'] ) ? $hub_config['preferred_peers'] : array();

		// Try preferred peers in order.
		foreach ( $preferred_peers as $preferred_name ) {
			foreach ( $healthy_peers as $peer ) {
				if ( isset( $peer['name'] ) && $peer['name'] === $preferred_name ) {
					self::log_event(
						'mesh_routing_preferred',
						'Preferred peer selected.',
						array(
							'selected_peer' => $peer['name'],
							'context'       => self::sanitize_context_for_logging( $context ),
						)
					);
					return $peer;
				}
			}
		}

		// Fallback to first healthy peer.
		self::log_event(
			'mesh_routing_fallback',
			'No preferred peer available, using fallback.',
			array(
				'selected_peer' => $healthy_peers[0]['name'],
				'context'       => self::sanitize_context_for_logging( $context ),
			)
		);

		return $healthy_peers[0];
	}

	/**
	 * Query a remote peer site with automatic retry on failure.
	 *
	 * Implements:
	 * - Circuit breaker pattern
	 * - Exponential backoff with jitter
	 * - Automatic failover
	 * - Dead letter queue integration
	 *
	 * @param int    $assistant_id Assistant ID.
	 * @param string $prompt       Prompt to send.
	 * @param array  $context      Request context.
	 * @param int    $attempt      Current attempt number.
	 * @return array|\WP_Error Response or error.
	 */
	public static function query_with_retry( $assistant_id, $prompt, $context = array(), $attempt = 1 ) {
		// Get optimal peer.
		$peer = self::get_optimal_peer( $assistant_id, $prompt, $context );

		if ( is_wp_error( $peer ) ) {
			return $peer;
		}

		// Check circuit breaker BEFORE attempting request.
		$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
		if ( self::is_circuit_open( $peer_name ) ) {
			self::log_event(
				'mesh_circuit_breaker_blocked',
				'Request blocked by open circuit breaker.',
				array(
					'peer'    => $peer_name,
					'attempt' => $attempt,
				)
			);

			// Circuit is open - immediately try different peer.
			return self::query_with_retry( $assistant_id, $prompt, $context, $attempt + 1 );
		}

		// Apply exponential backoff delay (except on first attempt).
		if ( $attempt > 1 ) {
			$delay_microseconds = self::calculate_backoff_delay( $attempt );
			usleep( $delay_microseconds );

			self::log_event(
				'mesh_exponential_backoff',
				'Applied exponential backoff before retry.',
				array(
					'attempt'  => $attempt,
					'delay_ms' => $delay_microseconds / 1000,
					'peer'     => $peer_name,
				)
			);
		}

		// Execute the query.
		$start_time    = microtime( true );
		$result        = self::execute_peer_query( $peer, $prompt, $context );
		$response_time = microtime( true ) - $start_time;
		$success       = ! is_wp_error( $result );

		// Update health metrics.
		self::update_health_metrics( $peer_name, $response_time, $success );

		// Update circuit breaker based on result.
		self::update_circuit_breaker( $peer_name, $success );

		// If successful, return result.
		if ( $success ) {
			return $result;
		}

		// If we've exhausted retries, move to dead letter queue and return error.
		if ( $attempt >= self::MAX_RETRY_ATTEMPTS ) {
			self::log_event(
				'mesh_routing_retry_exhausted',
				'Max retry attempts exhausted.',
				array(
					'peer'     => $peer_name,
					'attempts' => $attempt,
					'error'    => $result->get_error_message(),
				)
			);

			// Move to dead letter queue if available.
			if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
				// Build retry history.
				$retry_history = array();
				for ( $i = 1; $i <= $attempt; $i++ ) {
					$retry_history[] = array(
						'attempt'   => $i,
						'timestamp' => time() - ( ( $attempt - $i ) * 5 ), // Approximate timing.
						'result'    => 'failed',
					);
				}

				// Generate unique identifier for this failed mesh query.
				$identifier = md5( $peer_name . $prompt . time() );

				\WP_MCP_AI_Dead_Letter_Queue::add(
					\WP_MCP_AI_Dead_Letter_Queue::TYPE_MESH_QUERY,
					$identifier,
					array(
						'assistant_id' => $assistant_id,
						'peer_name'    => $peer_name,
						'peer_url'     => isset( $peer['url'] ) ? $peer['url'] : '',
						'prompt'       => $prompt,
						'context'      => $context,
					),
					sprintf(
						'Mesh query failed after %d attempts: %s',
						$attempt,
						$result->get_error_message()
					),
					$retry_history
				);
			}

			return $result;
		}

		// Mark peer as potentially down and retry with different peer.
		self::log_event(
			'mesh_routing_retry',
			'Retrying with different peer after failure.',
			array(
				'failed_peer' => $peer['name'],
				'attempt'     => $attempt,
				'error'       => $result->get_error_message(),
			)
		);

		return self::query_with_retry( $assistant_id, $prompt, $context, $attempt + 1 );
	}

	/**
	 * Execute a query to a peer site.
	 *
	 * @param array  $peer    Peer configuration.
	 * @param string $prompt  Prompt to send.
	 * @param array  $context Request context (user identity, session data, request metadata).
	 * @return array|\WP_Error Response or error.
	 */
	protected static function execute_peer_query( $peer, $prompt, $context ) {
		$peer_url = isset( $peer['url'] ) ? trim( $peer['url'] ) : '';
		$peer_key = isset( $peer['api_key'] ) ? trim( $peer['api_key'] ) : '';

		if ( empty( $peer_url ) || empty( $peer_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_peer_config',
				__( 'Invalid peer configuration.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$endpoint_url = trailingslashit( $peer_url ) . 'wp-json/mcp-ai/v1/chat';

		$body = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		// Propagate context metadata if available.
		if ( ! empty( $context['metadata'] ) ) {
			$body['metadata'] = $context['metadata'];
		}

		$headers = array(
			'Content-Type'         => 'application/json',
			'X-WP-MCP-AI-Mesh-Key' => $peer_key,
		);

		// Propagate trace ID for distributed tracing.
		if ( ! empty( $context['trace_id'] ) ) {
			$headers['X-Trace-ID'] = sanitize_text_field( $context['trace_id'] );
		}

		// Propagate user ID for user-specific operations.
		if ( ! empty( $context['user_id'] ) ) {
			$headers['X-User-ID'] = absint( $context['user_id'] );
		}

		// Propagate session ID for session affinity tracking.
		if ( ! empty( $context['session_id'] ) ) {
			$headers['X-Session-ID'] = sanitize_text_field( $context['session_id'] );
		}

		$settings = Settings::get_settings();
		$timeout  = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;
		$timeout  = max( 30, $timeout );

		$response = wp_remote_post(
			$endpoint_url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_data = json_decode( $body, true );
			$error_msg  = isset( $error_data['message'] ) ? $error_data['message'] : __( 'Unknown error', 'nvoos-content-graph-ai-platform' );

			return new \WP_Error(
				'wp_mcp_ai_remote_error',
				$error_msg,
				array( 'status_code' => $status_code )
			);
		}

		$data = json_decode( $body, true );

		if ( ! $data ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from peer site.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $data;
	}

	/**
	 * Analyze prompt complexity for routing decisions.
	 *
	 * Returns a score from 1-10 based on:
	 * - Prompt length
	 * - Presence of complex keywords
	 * - Question complexity indicators
	 *
	 * @param string $prompt The prompt to analyze.
	 * @return int Complexity score (1-10).
	 */
	protected static function analyze_prompt_complexity( $prompt ) {
		$score = 5; // Base score.

		$prompt_lower = strtolower( $prompt );
		$word_count   = str_word_count( $prompt );

		// Length factor.
		if ( $word_count > 100 ) {
			$score += 2;
		} elseif ( $word_count > 50 ) {
			++$score;
		}

		// Complex keywords.
		$complex_keywords = array( 'analyze', 'detailed', 'comprehensive', 'in-depth', 'complex', 'research', 'explain thoroughly' );
		foreach ( $complex_keywords as $keyword ) {
			if ( false !== strpos( $prompt_lower, $keyword ) ) {
				++$score;
				break;
			}
		}

		// Multiple questions indicator.
		if ( substr_count( $prompt, '?' ) > 1 ) {
			++$score;
		}

		return min( 10, max( 1, $score ) );
	}

	/**
	 * Check if a peer is configured as a compute hub.
	 *
	 * @param array $peer       Peer configuration.
	 * @param array $hub_config Compute hub configuration.
	 * @return bool True if peer is a compute hub.
	 */
	protected static function is_compute_hub( $peer, $hub_config ) {
		$compute_hubs = isset( $hub_config['compute_hubs'] ) ? $hub_config['compute_hubs'] : array();
		$peer_name    = isset( $peer['name'] ) ? $peer['name'] : '';

		return in_array( $peer_name, $compute_hubs, true );
	}

	/**
	 * Get health metrics for all peers.
	 *
	 * @return array Health metrics keyed by peer name.
	 */
	protected static function get_health_metrics() {
		$metrics = get_option( self::HEALTH_METRICS_OPTION, array() );

		// Clean old metrics.
		$current_time = time();
		foreach ( $metrics as $peer_name => $metric ) {
			$last_update = isset( $metric['last_update'] ) ? $metric['last_update'] : 0;
			if ( ( $current_time - $last_update ) > self::HEALTH_METRICS_MAX_AGE ) {
				unset( $metrics[ $peer_name ] );
			}
		}

		return $metrics;
	}

	/**
	 * Get health information for a specific peer.
	 *
	 * @param string $peer_name      Peer name.
	 * @param array  $health_metrics All health metrics.
	 * @return array Health information.
	 */
	protected static function get_peer_health( $peer_name, $health_metrics = null ) {
		if ( null === $health_metrics ) {
			$health_metrics = self::get_health_metrics();
		}

		if ( ! isset( $health_metrics[ $peer_name ] ) ) {
			return array(
				'status'            => 'unknown',
				'current_load'      => 0,
				'avg_response_time' => 5.0,
				'success_rate'      => 100,
			);
		}

		return $health_metrics[ $peer_name ];
	}

	/**
	 * Update health metrics for a peer after a request.
	 *
	 * @param string $peer_name     Peer name.
	 * @param float  $response_time Response time in seconds.
	 * @param bool   $success       Whether the request succeeded.
	 */
	protected static function update_health_metrics( $peer_name, $response_time, $success ) {
		$metrics = get_option( self::HEALTH_METRICS_OPTION, array() );

		if ( ! isset( $metrics[ $peer_name ] ) ) {
			$metrics[ $peer_name ] = array(
				'current_load'      => 0,
				'avg_response_time' => 0,
				'success_count'     => 0,
				'failure_count'     => 0,
				'total_requests'    => 0,
			);
		}

		$peer_metrics = $metrics[ $peer_name ];

		// Update response time (rolling average).
		$total                             = $peer_metrics['total_requests'];
		$current_avg                       = isset( $peer_metrics['avg_response_time'] ) ? $peer_metrics['avg_response_time'] : 0;
		$peer_metrics['avg_response_time'] = ( ( $current_avg * $total ) + $response_time ) / ( $total + 1 );

		// Update success/failure counts.
		if ( $success ) {
			++$peer_metrics['success_count'];
		} else {
			++$peer_metrics['failure_count'];
		}

		++$peer_metrics['total_requests'];

		// Calculate success rate.
		$peer_metrics['success_rate'] = ( $peer_metrics['success_count'] / $peer_metrics['total_requests'] ) * 100;

		// Estimate current load based on recent requests.
		$peer_metrics['current_load'] = $peer_metrics['total_requests'] % self::LOAD_ESTIMATION_DIVISOR;

		// Determine status.
		if ( $peer_metrics['success_rate'] < 50 ) {
			$peer_metrics['status'] = 'down';
		} elseif ( $peer_metrics['success_rate'] < 80 ) {
			$peer_metrics['status'] = 'degraded';
		} else {
			$peer_metrics['status'] = 'healthy';
		}

		$peer_metrics['last_update'] = time();

		$metrics[ $peer_name ] = $peer_metrics;
		update_option( self::HEALTH_METRICS_OPTION, $metrics, false );
	}

	/**
	 * Get compute hub configuration for an assistant.
	 *
	 * @param int $assistant_id Assistant ID.
	 * @return array Compute hub configuration.
	 */
	public static function get_hub_config( $assistant_id ) {
		$config = get_post_meta( $assistant_id, self::META_COMPUTE_HUB_CONFIG, true );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		// Set defaults.
		$defaults = array(
			'routing_strategy' => 'ai_optimized',
			'preferred_peers'  => array(),
			'compute_hubs'     => array(),
			'enable_retry'     => true,
			'max_retries'      => self::MAX_RETRY_ATTEMPTS,
		);

		return array_merge( $defaults, $config );
	}

	/**
	 * Update compute hub configuration for an assistant.
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param array $config       Compute hub configuration.
	 * @return bool Success status.
	 */
	public static function update_hub_config( $assistant_id, $config ) {
		return update_post_meta( $assistant_id, self::META_COMPUTE_HUB_CONFIG, $config );
	}

	/**
	 * Calculate peer capacity score using Little's Law.
	 *
	 * Little's Law: L = λ × W
	 * - L = average number of items in system
	 * - λ (lambda) = arrival rate
	 * - W = average wait time
	 *
	 * Score reflects how much capacity the peer has available:
	 * - 100 = Peer has excellent capacity
	 * - 50 = Peer is at moderate load
	 * - 0 = Peer is overloaded
	 *
	 * @param array $health         Peer health metrics.
	 * @param float $service_time   Expected service time for this request (seconds).
	 * @return float Capacity score (0-100).
	 */
	protected static function calculate_peer_capacity_score( $health, $service_time ) {
		// Get current load metrics.
		$current_load      = isset( $health['current_load'] ) ? floatval( $health['current_load'] ) : 0;
		$avg_response_time = isset( $health['avg_response_time'] ) ? floatval( $health['avg_response_time'] ) : $service_time;
		$total_requests    = isset( $health['total_requests'] ) ? intval( $health['total_requests'] ) : 0;

		// Estimate arrival rate (λ) based on recent activity.
		// Assume requests are spread over last 60 seconds.
		$time_window  = self::ARRIVAL_RATE_TIME_WINDOW;
		$arrival_rate = $total_requests > 0 ? ( $current_load / $time_window ) : self::DEFAULT_ARRIVAL_RATE;

		// Calculate utilization (ρ = λ × service_time).
		$utilization = $arrival_rate * $avg_response_time;

		// Calculate queue length using Little's Law.
		// L = λ × W, where W is wait time.
		$wait_time    = max( 0, $avg_response_time - $service_time );
		$queue_length = $arrival_rate * $wait_time;

		// Score based on utilization and queue depth.
		// Perfect score when utilization < 50% and no queue.
		$utilization_score = max( 0, 100 - ( $utilization * self::UTILIZATION_TO_PERCENTAGE ) );
		$queue_score       = max( 0, 100 - ( $queue_length * self::QUEUE_LENGTH_MULTIPLIER ) );

		// Combined capacity score (weighted average).
		$capacity_score = ( $utilization_score * self::CAPACITY_UTILIZATION_WEIGHT ) + ( $queue_score * self::CAPACITY_QUEUE_WEIGHT );

		return max( 0, min( 100, $capacity_score ) );
	}

	/**
	 * Get predicted wait time for a peer using Little's Law.
	 *
	 * Estimates how long a new request would wait in queue before processing.
	 *
	 * @param array $health       Peer health metrics.
	 * @param float $service_time Expected service time (seconds).
	 * @return float Predicted wait time in seconds.
	 */
	public static function get_predicted_wait_time( $health, $service_time ) {
		$current_load      = isset( $health['current_load'] ) ? floatval( $health['current_load'] ) : 0;
		$avg_response_time = isset( $health['avg_response_time'] ) ? floatval( $health['avg_response_time'] ) : $service_time;
		$total_requests    = isset( $health['total_requests'] ) ? intval( $health['total_requests'] ) : 0;

		// Estimate arrival rate.
		$time_window  = self::ARRIVAL_RATE_TIME_WINDOW;
		$arrival_rate = $total_requests > 0 ? ( $current_load / $time_window ) : self::DEFAULT_ARRIVAL_RATE;

		// Little's Law: L = λ × W.
		// Solve for W (wait time): W = L / λ.
		$queue_length = $arrival_rate * ( $avg_response_time - $service_time );
		$wait_time    = $queue_length > 0 ? ( $queue_length / max( self::DEFAULT_ARRIVAL_RATE, $arrival_rate ) ) : 0;

		return max( 0, $wait_time );
	}

	/**
	 * Get mesh network capacity metrics using Little's Law.
	 *
	 * Analyzes overall mesh health and capacity across all peers.
	 *
	 * @return array Mesh capacity metrics.
	 */
	public static function get_mesh_capacity_metrics() {
		$settings   = Settings::get_settings();
		$peer_sites = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] )
			? $settings['mesh_peer_sites']
			: array();

		if ( empty( $peer_sites ) ) {
			return array(
				'error'       => __( 'No peer sites configured.', 'nvoos-content-graph-ai-platform' ),
				'total_peers' => 0,
			);
		}

		$health_metrics = self::get_health_metrics();

		$total_capacity      = 0;
		$total_utilization   = 0;
		$total_queue_length  = 0;
		$healthy_peer_count  = 0;
		$degraded_peer_count = 0;
		$down_peer_count     = 0;

		foreach ( $peer_sites as $peer ) {
			$peer_name = isset( $peer['name'] ) ? $peer['name'] : '';
			if ( empty( $peer_name ) ) {
				continue;
			}

			$health = self::get_peer_health( $peer_name, $health_metrics );

			// Count peer status.
			if ( 'healthy' === $health['status'] ) {
				++$healthy_peer_count;
			} elseif ( 'degraded' === $health['status'] ) {
				++$degraded_peer_count;
			} else {
				++$down_peer_count;
				continue; // Skip down peers in calculations.
			}

			// Calculate peer metrics.
			$avg_response_time = isset( $health['avg_response_time'] ) ? floatval( $health['avg_response_time'] ) : 5.0;
			$current_load      = isset( $health['current_load'] ) ? floatval( $health['current_load'] ) : 0;
			$total_requests    = isset( $health['total_requests'] ) ? intval( $health['total_requests'] ) : 0;

			$arrival_rate = $total_requests > 0 ? ( $current_load / 60.0 ) : 0.01;
			$utilization  = $arrival_rate * $avg_response_time;
			$queue_length = $arrival_rate * max( 0, $avg_response_time - 2.0 ); // Assume 2s baseline.

			$total_capacity     += self::calculate_peer_capacity_score( $health, 2.0 );
			$total_utilization  += $utilization;
			$total_queue_length += $queue_length;
		}

		$total_peers  = count( $peer_sites );
		$active_peers = $healthy_peer_count + $degraded_peer_count;

		return array(
			'total_peers'        => $total_peers,
			'healthy_peers'      => $healthy_peer_count,
			'degraded_peers'     => $degraded_peer_count,
			'down_peers'         => $down_peer_count,
			'avg_capacity_score' => $active_peers > 0 ? ( $total_capacity / $active_peers ) : 0,
			'avg_utilization'    => $active_peers > 0 ? ( $total_utilization / $active_peers ) : 0,
			'total_queue_length' => $total_queue_length,
			'mesh_health'        => self::calculate_mesh_health_status( $healthy_peer_count, $degraded_peer_count, $down_peer_count ),
			'recommended_action' => self::get_mesh_recommendation( $healthy_peer_count, $degraded_peer_count, $down_peer_count, $total_utilization / max( 1, $active_peers ) ),
		);
	}

	/**
	 * Calculate overall mesh health status.
	 *
	 * @param int $healthy_count  Number of healthy peers.
	 * @param int $degraded_count Number of degraded peers.
	 * @param int $down_count     Number of down peers.
	 * @return string Health status (excellent, good, warning, critical).
	 */
	protected static function calculate_mesh_health_status( $healthy_count, $degraded_count, $down_count ) {
		$total = $healthy_count + $degraded_count + $down_count;

		if ( 0 === $total ) {
			return 'critical';
		}

		$healthy_ratio = $healthy_count / $total;

		if ( $healthy_ratio >= 0.9 ) {
			return 'excellent';
		} elseif ( $healthy_ratio >= 0.7 ) {
			return 'good';
		} elseif ( $healthy_ratio >= 0.5 ) {
			return 'warning';
		} else {
			return 'critical';
		}
	}

	/**
	 * Get mesh recommendation based on current metrics.
	 *
	 * @param int   $healthy_count    Number of healthy peers.
	 * @param int   $degraded_count   Number of degraded peers.
	 * @param int   $down_count       Number of down peers.
	 * @param float $avg_utilization  Average utilization across peers.
	 * @return string Recommendation message.
	 */
	protected static function get_mesh_recommendation( $healthy_count, $degraded_count, $down_count, $avg_utilization ) {
		// Critical: No healthy peers.
		if ( 0 === $healthy_count ) {
			return __( 'CRITICAL: No healthy peers available. Add new peers or investigate network issues immediately.', 'nvoos-content-graph-ai-platform' );
		}

		// Warning: High utilization.
		if ( $avg_utilization > 0.8 ) {
			return __( 'HIGH UTILIZATION: Mesh network is operating at >80% capacity. Consider adding more peer sites.', 'nvoos-content-graph-ai-platform' );
		}

		// Warning: Too many degraded peers.
		$total = $healthy_count + $degraded_count + $down_count;
		if ( $degraded_count > ( $total * 0.3 ) ) {
			return __( 'DEGRADED PEERS: More than 30% of peers are degraded. Check peer health and network connectivity.', 'nvoos-content-graph-ai-platform' );
		}

		// Warning: Some peers down.
		if ( $down_count > 0 ) {
			return sprintf(
				/* translators: %d: number of down peers */
				__( '%d peer(s) are down. Monitor health metrics and consider removing or replacing failed peers.', 'nvoos-content-graph-ai-platform' ),
				$down_count
			);
		}

		// All good.
		return __( 'Mesh network is healthy and operating within optimal parameters.', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * Calculate geographic proximity score for a peer.
	 *
	 * Scores peers based on geographic region proximity to reduce latency.
	 *
	 * @param array $peer    Peer configuration.
	 * @param array $context Request context with geo_region.
	 * @return float Score from 0-100 (100 = same region, 50 = nearby, 0 = far).
	 */
	protected static function calculate_geographic_score( $peer, $context ) {
		// If no geographic context provided, return neutral score.
		if ( empty( $context['geo_region'] ) ) {
			return 50.0; // Neutral - no preference.
		}

		$request_region = strtolower( sanitize_text_field( $context['geo_region'] ) );
		$peer_region    = isset( $peer['region'] ) ? strtolower( sanitize_text_field( $peer['region'] ) ) : '';

		// Peer region not specified - neutral score.
		if ( empty( $peer_region ) ) {
			return 50.0;
		}

		// Exact region match - highest score.
		if ( $request_region === $peer_region ) {
			return 100.0;
		}

		// Check for regional proximity (e.g., us-east and us-west are nearby).
		if ( self::are_regions_nearby( $request_region, $peer_region ) ) {
			return 75.0;
		}

		// Different continents - lower score.
		return 25.0;
	}

	/**
	 * Calculate user preference matching score for a peer.
	 *
	 * Scores peers based on how well they match user preferences.
	 *
	 * @param array $peer    Peer configuration.
	 * @param array $context Request context with user preferences.
	 * @return float Score from 0-100.
	 */
	protected static function calculate_preference_score( $peer, $context ) {
		// If no preferences provided, return neutral score.
		if ( empty( $context['preferences'] ) || ! is_array( $context['preferences'] ) ) {
			return 50.0; // Neutral - no preference.
		}

		$preferences = $context['preferences'];
		$score       = 50.0; // Start neutral.

		// Check preferred regions list.
		if ( ! empty( $preferences['preferred_regions'] ) && is_array( $preferences['preferred_regions'] ) ) {
			$peer_region = isset( $peer['region'] ) ? strtolower( $peer['region'] ) : '';
			if ( ! empty( $peer_region ) && in_array( $peer_region, array_map( 'strtolower', $preferences['preferred_regions'] ), true ) ) {
				$score += 30.0; // Bonus for being in preferred region list.
			}
		}

		// Check max latency preference.
		if ( ! empty( $preferences['max_latency_ms'] ) && isset( $peer['health']['avg_response_time'] ) ) {
			$peer_latency_ms = $peer['health']['avg_response_time'] * 1000; // Convert to ms.
			if ( $peer_latency_ms <= $preferences['max_latency_ms'] ) {
				$score += 20.0; // Bonus for meeting latency requirement.
			} else {
				$score -= 20.0; // Penalty for exceeding latency requirement.
			}
		}

		// Check compute hub requirement.
		if ( ! empty( $preferences['require_compute_hub'] ) && self::is_compute_hub( $peer, array() ) ) {
			$score += 20.0; // Bonus for being a compute hub when required.
		}

		// Clamp score to 0-100 range.
		return max( 0.0, min( 100.0, $score ) );
	}

	/**
	 * Check if two regions are geographically nearby.
	 *
	 * @param string $region1 First region code.
	 * @param string $region2 Second region code.
	 * @return bool True if regions are nearby.
	 */
	protected static function are_regions_nearby( $region1, $region2 ) {
		// Define region proximity groups.
		$proximity_groups = array(
			'north_america' => array( 'us-east', 'us-west', 'us-central', 'ca-east', 'ca-west' ),
			'europe'        => array( 'eu-west', 'eu-central', 'eu-north', 'uk' ),
			'asia_pacific'  => array( 'ap-south', 'ap-southeast', 'ap-northeast', 'ap-east' ),
			'south_america' => array( 'sa-east', 'sa-west' ),
		);

		// Check if both regions are in the same proximity group.
		foreach ( $proximity_groups as $group ) {
			if ( in_array( $region1, $group, true ) && in_array( $region2, $group, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize context for logging.
	 *
	 * Removes sensitive data from context before logging.
	 *
	 * @param array $context Request context.
	 * @return array Sanitized context safe for logging.
	 */
	protected static function sanitize_context_for_logging( $context ) {
		if ( empty( $context ) || ! is_array( $context ) ) {
			return array();
		}

		$safe_context = array();

		// Include non-sensitive fields.
		$safe_fields = array( 'geo_region', 'trace_id', 'session_id' );
		foreach ( $safe_fields as $field ) {
			if ( isset( $context[ $field ] ) ) {
				$safe_context[ $field ] = $context[ $field ];
			}
		}

		// Include user ID (safe for internal logging).
		if ( ! empty( $context['user_id'] ) ) {
			$safe_context['user_id'] = absint( $context['user_id'] );
		}

		// Include preferences summary (without sensitive data).
		if ( ! empty( $context['preferences'] ) && is_array( $context['preferences'] ) ) {
			$safe_context['preferences'] = array();
			if ( isset( $context['preferences']['preferred_regions'] ) ) {
				$safe_context['preferences']['preferred_regions'] = $context['preferences']['preferred_regions'];
			}
			if ( isset( $context['preferences']['max_latency_ms'] ) ) {
				$safe_context['preferences']['max_latency_ms'] = $context['preferences']['max_latency_ms'];
			}
		}

		return $safe_context;
	}

	/**
	 * Check if circuit breaker is open for a peer.
	 *
	 * Circuit breaker states:
	 * - closed: Normal operation, requests pass through
	 * - open: Too many failures, block all requests
	 * - half_open: Testing recovery, allow limited requests
	 *
	 * @param string $peer_name Peer name.
	 * @return bool True if circuit is open (should block requests).
	 */
	protected static function is_circuit_open( $peer_name ) {
		$circuits = get_option( self::CIRCUIT_BREAKER_OPTION, array() );

		if ( ! isset( $circuits[ $peer_name ] ) ) {
			return false; // No circuit state = closed (allow requests).
		}

		$circuit = $circuits[ $peer_name ];

		// If circuit is closed, allow requests.
		if ( 'closed' === $circuit['state'] ) {
			return false;
		}

		// If circuit is open, check if timeout has elapsed for recovery attempt.
		if ( 'open' === $circuit['state'] ) {
			$time_since_open = time() - $circuit['opened_at'];
			if ( $time_since_open >= self::CIRCUIT_BREAKER_TIMEOUT ) {
				// Move to half-open state for testing.
				self::set_circuit_state( $peer_name, 'half_open' );
				return false; // Allow one request to test.
			}
			return true; // Circuit still open, block requests.
		}

		// If circuit is half-open, allow request (will test recovery).
		return false;
	}

	/**
	 * Update circuit breaker state based on request result.
	 *
	 * @param string $peer_name Peer name.
	 * @param bool   $success   Whether the request succeeded.
	 */
	protected static function update_circuit_breaker( $peer_name, $success ) {
		$circuits = get_option( self::CIRCUIT_BREAKER_OPTION, array() );

		if ( ! isset( $circuits[ $peer_name ] ) ) {
			$circuits[ $peer_name ] = array(
				'state'                => 'closed',
				'consecutive_failures' => 0,
				'opened_at'            => 0,
			);
		}

		$circuit = &$circuits[ $peer_name ];

		if ( $success ) {
			// Success - reset failures and close circuit.
			$circuit['consecutive_failures'] = 0;
			if ( 'half_open' === $circuit['state'] || 'open' === $circuit['state'] ) {
				self::log_event(
					'mesh_circuit_breaker_closed',
					'Circuit breaker closed after successful recovery.',
					array( 'peer' => $peer_name )
				);
			}
			$circuit['state'] = 'closed';
		} else {
			// Failure - increment counter.
			$circuit['consecutive_failures'] = ( $circuit['consecutive_failures'] ?? 0 ) + 1;

			// If in half-open state and failed, reopen circuit.
			if ( 'half_open' === $circuit['state'] ) {
				$circuit['state']     = 'open';
				$circuit['opened_at'] = time();
				self::log_event(
					'mesh_circuit_breaker_reopened',
					'Circuit breaker reopened after failed recovery test.',
					array( 'peer' => $peer_name )
				);
			}

			// If threshold reached, open circuit.
			if ( $circuit['consecutive_failures'] >= self::CIRCUIT_BREAKER_FAILURE_THRESHOLD ) {
				if ( 'closed' === $circuit['state'] ) {
					$circuit['state']     = 'open';
					$circuit['opened_at'] = time();
					self::log_event(
						'mesh_circuit_breaker_opened',
						'Circuit breaker opened due to consecutive failures.',
						array(
							'peer'     => $peer_name,
							'failures' => $circuit['consecutive_failures'],
						)
					);
				}
			}
		}

		update_option( self::CIRCUIT_BREAKER_OPTION, $circuits, false );
	}

	/**
	 * Set circuit breaker state.
	 *
	 * @param string $peer_name Peer name.
	 * @param string $state     State: 'closed', 'open', or 'half_open'.
	 */
	protected static function set_circuit_state( $peer_name, $state ) {
		$circuits = get_option( self::CIRCUIT_BREAKER_OPTION, array() );

		if ( ! isset( $circuits[ $peer_name ] ) ) {
			$circuits[ $peer_name ] = array(
				'state'                => $state,
				'consecutive_failures' => 0,
				'opened_at'            => 0,
			);
		} else {
			$circuits[ $peer_name ]['state'] = $state;
			if ( 'open' === $state ) {
				$circuits[ $peer_name ]['opened_at'] = time();
			}
		}

		update_option( self::CIRCUIT_BREAKER_OPTION, $circuits, false );
	}

	/**
	 * Calculate exponential backoff delay for retry.
	 *
	 * Uses exponential backoff with jitter to prevent thundering herd.
	 *
	 * @param int $attempt Current attempt number (1-indexed).
	 * @return int Delay in microseconds.
	 */
	protected static function calculate_backoff_delay( $attempt ) {
		// Calculate base delay: initial_delay * (multiplier ^ (attempt - 1)).
		$base_delay = self::BACKOFF_INITIAL_DELAY_MS * pow( self::BACKOFF_MULTIPLIER, $attempt - 1 );

		// Cap at max delay.
		$base_delay = min( $base_delay, self::BACKOFF_MAX_DELAY_MS );

		// Add jitter (random ±25%).
		$jitter    = $base_delay * 0.25;
		$min_delay = $base_delay - $jitter;
		$max_delay = $base_delay + $jitter;
		$delay     = wp_rand( (int) $min_delay, (int) $max_delay );

		// Convert milliseconds to microseconds for usleep().
		return $delay * 1000;
	}
}
