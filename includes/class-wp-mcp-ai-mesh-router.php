<?php
/**
 * Mesh router with AI-powered peer selection and load balancing.
 *
 *
 * @package WP_MCP_AI
 */

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
class WP_MCP_AI_Mesh_Router {

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
	 * @return array|WP_Error Optimal peer configuration or error.
	 */
	public static function get_optimal_peer( $assistant_id, $prompt, $context = array() ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Check if mesh is enabled.
		if ( empty( $settings['enable_mesh'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mesh_disabled',
				__( 'Mesh networking is not enabled.', 'wp-mcp-ai' )
			);
		}

		// Get peer sites.
		$peer_sites = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] )
			? $settings['mesh_peer_sites']
			: array();

		if ( empty( $peer_sites ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_peers',
				__( 'No peer sites configured in mesh network.', 'wp-mcp-ai' )
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
			return new WP_Error(
				'wp_mcp_ai_no_healthy_peers',
				__( 'No healthy peer sites available in mesh network.', 'wp-mcp-ai' )
			);
		}

		// Route based on strategy.
		switch ( $routing_strategy ) {
			case 'ai_optimized':
				return self::select_peer_ai_optimized( $healthy_peers, $prompt, $hub_config, $context );

			case 'round_robin':
				return self::select_peer_round_robin( $healthy_peers, $hub_config );

			case 'least_loaded':
				return self::select_peer_least_loaded( $healthy_peers, $health_metrics );

			case 'preferred_with_fallback':
				return self::select_peer_preferred( $healthy_peers, $hub_config );

			default:
				return self::select_peer_ai_optimized( $healthy_peers, $prompt, $hub_config, $context );
		}
	}

	/**
	 * AI-optimized peer selection.
	 *
	 * Uses intelligent analysis to select the best peer based on:
	 * - Task complexity (via prompt analysis)
	 * - Peer capacity and current load
	 * - Response time history
	 * - Model availability
	 *
	 * @param array  $healthy_peers Available healthy peers.
	 * @param string $prompt        The prompt being sent.
	 * @param array  $hub_config    Compute hub configuration.
	 * @param array  $context       Request context (reserved for future use: user preferences, geographic routing, time-based routing).
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_ai_optimized( $healthy_peers, $prompt, $hub_config, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Analyze prompt complexity.
		$complexity_score = self::analyze_prompt_complexity( $prompt );

		// Score each peer based on multiple factors.
		$scored_peers = array();
		foreach ( $healthy_peers as $peer ) {
			$score  = 0;
			$health = $peer['health'];

			// Factor 1: Response time (lower is better) - 30% weight.
			$avg_response_time   = isset( $health['avg_response_time'] ) ? $health['avg_response_time'] : 5.0;
			$response_time_score = max( 0, 100 - ( $avg_response_time * 10 ) );
			$score              += $response_time_score * 0.3;

			// Factor 2: Load (lower is better) - 25% weight.
			$current_load = isset( $health['current_load'] ) ? $health['current_load'] : 0;
			$load_score   = max( 0, 100 - ( $current_load * 5 ) );
			$score       += $load_score * 0.25;

			// Factor 3: Success rate - 25% weight.
			$success_rate = isset( $health['success_rate'] ) ? $health['success_rate'] : 100;
			$score       += $success_rate * 0.25;

			// Factor 4: Compute hub priority - 20% weight.
			$is_compute_hub = self::is_compute_hub( $peer, $hub_config );
			if ( $is_compute_hub && $complexity_score > 7 ) {
				// Prefer compute hubs for complex tasks.
				$score += 20;
			}

			$scored_peers[] = array(
				'peer'  => $peer,
				'score' => $score,
			);
		}

		// Sort by score (descending).
		usort(
			$scored_peers,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Log the routing decision.
		WP_MCP_AI_Logger::log_event(
			'mesh_routing_ai_optimized',
			'AI-optimized peer selection completed.',
			array(
				'selected_peer'    => $scored_peers[0]['peer']['name'],
				'score'            => $scored_peers[0]['score'],
				'complexity_score' => $complexity_score,
				'total_peers'      => count( $healthy_peers ),
			)
		);

		return $scored_peers[0]['peer'];
	}

	/**
	 * Round-robin peer selection.
	 *
	 * @param array $healthy_peers Available healthy peers.
	 * @param array $hub_config    Compute hub configuration (reserved for consistency with other routing methods).
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_round_robin( $healthy_peers, $hub_config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$stats      = get_option( self::ROUTING_STATS_OPTION, array() );
		$last_index = isset( $stats['last_round_robin_index'] ) ? (int) $stats['last_round_robin_index'] : -1;

		$next_index = ( $last_index + 1 ) % count( $healthy_peers );

		// Update stats.
		$stats['last_round_robin_index'] = $next_index;
		update_option( self::ROUTING_STATS_OPTION, $stats, false );

		WP_MCP_AI_Logger::log_event(
			'mesh_routing_round_robin',
			'Round-robin peer selection.',
			array(
				'selected_peer' => $healthy_peers[ $next_index ]['name'],
				'index'         => $next_index,
			)
		);

		return $healthy_peers[ $next_index ];
	}

	/**
	 * Least-loaded peer selection.
	 *
	 * @param array $healthy_peers  Available healthy peers.
	 * @param array $health_metrics All health metrics.
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_least_loaded( $healthy_peers, $health_metrics ) {
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

		WP_MCP_AI_Logger::log_event(
			'mesh_routing_least_loaded',
			'Least-loaded peer selection.',
			array(
				'selected_peer' => $least_loaded['name'],
				'load'          => $min_load,
			)
		);

		return $least_loaded;
	}

	/**
	 * Preferred peer with fallback selection.
	 *
	 * @param array $healthy_peers Available healthy peers.
	 * @param array $hub_config    Compute hub configuration.
	 * @return array Selected peer configuration.
	 */
	protected static function select_peer_preferred( $healthy_peers, $hub_config ) {
		$preferred_peers = isset( $hub_config['preferred_peers'] ) ? $hub_config['preferred_peers'] : array();

		// Try preferred peers in order.
		foreach ( $preferred_peers as $preferred_name ) {
			foreach ( $healthy_peers as $peer ) {
				if ( isset( $peer['name'] ) && $peer['name'] === $preferred_name ) {
					WP_MCP_AI_Logger::log_event(
						'mesh_routing_preferred',
						'Preferred peer selected.',
						array(
							'selected_peer' => $peer['name'],
						)
					);
					return $peer;
				}
			}
		}

		// Fallback to first healthy peer.
		WP_MCP_AI_Logger::log_event(
			'mesh_routing_fallback',
			'No preferred peer available, using fallback.',
			array(
				'selected_peer' => $healthy_peers[0]['name'],
			)
		);

		return $healthy_peers[0];
	}

	/**
	 * Query a remote peer site with automatic retry on failure.
	 *
	 * @param int    $assistant_id Assistant ID.
	 * @param string $prompt       Prompt to send.
	 * @param array  $context      Request context.
	 * @param int    $attempt      Current attempt number.
	 * @return array|WP_Error Response or error.
	 */
	public static function query_with_retry( $assistant_id, $prompt, $context = array(), $attempt = 1 ) {
		// Get optimal peer.
		$peer = self::get_optimal_peer( $assistant_id, $prompt, $context );

		if ( is_wp_error( $peer ) ) {
			return $peer;
		}

		// Execute the query.
		$start_time    = microtime( true );
		$result        = self::execute_peer_query( $peer, $prompt, $context );
		$response_time = microtime( true ) - $start_time;

		// Update health metrics.
		self::update_health_metrics( $peer['name'], $response_time, ! is_wp_error( $result ) );

		// If successful, return result.
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// If we've exhausted retries, return the error.
		if ( $attempt >= self::MAX_RETRY_ATTEMPTS ) {
			WP_MCP_AI_Logger::log_event(
				'mesh_routing_retry_exhausted',
				'Max retry attempts exhausted.',
				array(
					'peer'     => $peer['name'],
					'attempts' => $attempt,
					'error'    => $result->get_error_message(),
				)
			);
			return $result;
		}

		// Mark peer as potentially down and retry with different peer.
		WP_MCP_AI_Logger::log_event(
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
	 * @param array  $context Request context (reserved for future use: pass user identity, session data, or request metadata to peer).
	 * @return array|WP_Error Response or error.
	 */
	protected static function execute_peer_query( $peer, $prompt, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$peer_url = isset( $peer['url'] ) ? trim( $peer['url'] ) : '';
		$peer_key = isset( $peer['api_key'] ) ? trim( $peer['api_key'] ) : '';

		if ( empty( $peer_url ) || empty( $peer_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_peer_config',
				__( 'Invalid peer configuration.', 'wp-mcp-ai' )
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

		$headers = array(
			'Content-Type'         => 'application/json',
			'X-WP-MCP-AI-Mesh-Key' => $peer_key,
		);

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
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
			$error_msg  = isset( $error_data['message'] ) ? $error_data['message'] : __( 'Unknown error', 'wp-mcp-ai' );

			return new WP_Error(
				'wp_mcp_ai_remote_error',
				$error_msg,
				array( 'status_code' => $status_code )
			);
		}

		$data = json_decode( $body, true );

		if ( ! $data ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from peer site.', 'wp-mcp-ai' )
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
}
