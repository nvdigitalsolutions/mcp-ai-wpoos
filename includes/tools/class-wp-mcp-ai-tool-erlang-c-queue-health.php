<?php
/**
 * Tool: erlang_c_queue_health — real-time queue health monitor.
 *
 * Polls a configured contact-centre REST endpoint (or reads JetEngine CCT
 * data) for current queue depth and available agents, then applies Erlang C
 * to compute live service-level percentage.
 *
 * Fires the `wp_mcp_ai_queue_alert` action hook when SLA is at risk, and
 * stores timestamped snapshots in a WordPress option for trend reporting.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-erlang-c.php';

/**
 * Real-time queue health monitor using Erlang C.
 *
 * Accepts either direct metric inputs or fetches them from a configured
 * REST endpoint.  Triggers `wp_mcp_ai_queue_alert` when SLA is breached
 * and persists snapshots for trend analysis.
 *
 * @since 1.1.8
 */
class WP_MCP_AI_Tool_Erlang_C_Queue_Health implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Option key for queue health snapshots (rolling window, last 100 entries).
	 */
	const OPTION_SNAPSHOTS = 'wp_mcp_ai_queue_health_snapshots';

	/**
	 * Maximum stored snapshots.
	 */
	const MAX_SNAPSHOTS = 100;

	/**
	 * Queue endpoint URL option key.
	 */
	const OPTION_ENDPOINT = 'wp_mcp_ai_queue_health_endpoint';

	/**
	 * Queue endpoint auth token option key.
	 */
	const OPTION_TOKEN = 'wp_mcp_ai_queue_health_token';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'erlang_c_queue_health';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Queue Health Monitor', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Real-time queue health monitor. Accepts current queue depth, available agents, and arrival rate then applies Erlang C to calculate live service level. Fires wp_mcp_ai_queue_alert when SLA is at risk, stores snapshots for trend analysis, and optionally fetches metrics from a configured contact-centre REST endpoint.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'arrival_rate_per_hour'    => array(
					'type'        => 'number',
					'description' => __( 'Current contacts arriving per hour. Required when fetch_from_endpoint is false.', 'mcp-ai-wpoos' ),
					'minimum'     => 0.001,
				),
				'avg_handle_time'          => array(
					'type'        => 'number',
					'description' => __( 'Average handle time in seconds. Required when fetch_from_endpoint is false.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'current_agents'           => array(
					'type'        => 'integer',
					'description' => __( 'Number of agents currently available / logged in. Required when fetch_from_endpoint is false.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'queue_depth'              => array(
					'type'        => 'integer',
					'description' => __( 'Current number of contacts waiting in queue (informational, used in snapshot).', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
				),
				'target_service_level_pct' => array(
					'type'        => 'number',
					'description' => __( 'SLA threshold percentage. Alert fires when projected SL drops below this. Default 80.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 99.9,
				),
				'target_answer_time'       => array(
					'type'        => 'integer',
					'description' => __( 'Answer-time threshold in seconds. Default 20.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'fetch_from_endpoint'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true, fetch live metrics from the configured queue health endpoint instead of using inline parameters.', 'mcp-ai-wpoos' ),
				),
				'store_snapshot'           => array(
					'type'        => 'boolean',
					'description' => __( 'When true (default), persist this reading as a snapshot for trend analysis.', 'mcp-ai-wpoos' ),
				),
				'channel'                  => array(
					'type'        => 'string',
					'description' => __( 'Optional channel label for the snapshot (e.g. "voice", "chat").', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'operations_analytics',
			'pattern_compatibility' => array( 'event_driven', 'orchestrator' ),
			'profession_tags'       => array( 'contact_center_manager', 'site_administrator', 'operations_analyst' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',               // Persists snapshots to options.
			'state-changing',      // Writes snapshot data.
			'external-api',        // Optional WFM endpoint fetch via wp_remote_get().
			'requires-capability', // Requires manage_options.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the queue health monitor. Requires manage_options.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$target_sl_pct  = isset( $arguments['target_service_level_pct'] ) ? (float) $arguments['target_service_level_pct'] : 80.0;
		$target_time    = isset( $arguments['target_answer_time'] ) ? (int) $arguments['target_answer_time'] : 20;
		$target_sl_frac = min( 0.999, max( 0.001, $target_sl_pct / 100.0 ) );
		$store_snapshot = ! isset( $arguments['store_snapshot'] ) || (bool) $arguments['store_snapshot'];
		$channel        = isset( $arguments['channel'] ) ? sanitize_text_field( $arguments['channel'] ) : 'default';

		// Resolve metrics.
		if ( ! empty( $arguments['fetch_from_endpoint'] ) ) {
			$metrics = $this->fetch_endpoint_metrics();
			if ( is_wp_error( $metrics ) ) {
				return $metrics;
			}
		} else {
			$metrics = $this->resolve_inline_metrics( $arguments );
			if ( is_wp_error( $metrics ) ) {
				return $metrics;
			}
		}

		$arrival_rate   = $metrics['arrival_rate_per_hour'];
		$aht            = $metrics['avg_handle_time'];
		$current_agents = $metrics['current_agents'];
		$queue_depth    = isset( $metrics['queue_depth'] ) ? (int) $metrics['queue_depth'] : 0;

		// Erlang C calculations.
		$traffic    = WP_MCP_AI_Erlang_C::to_erlangs( $arrival_rate, $aht );
		$min_agents = WP_MCP_AI_Erlang_C::min_agents_for_sl( $traffic, $aht, $target_sl_frac, (float) $target_time );

		$prob_wait = WP_MCP_AI_Erlang_C::probability_wait( $traffic, $current_agents );
		$avg_wait  = WP_MCP_AI_Erlang_C::avg_wait_time( $traffic, $current_agents, $aht );
		$svc_level = WP_MCP_AI_Erlang_C::service_level( $traffic, $current_agents, $aht, (float) $target_time );
		$util      = WP_MCP_AI_Erlang_C::utilisation( $traffic, $current_agents );
		$is_stable = $traffic < (float) $current_agents;

		$agent_deficit = max( 0, $min_agents - $current_agents );
		$sla_at_risk   = $svc_level < $target_sl_frac;

		// Fire alert hook when SLA is at risk.
		if ( $sla_at_risk || ! $is_stable ) {
			/**
			 * Fired when queue health monitoring detects an SLA risk.
			 *
			 * @param array $health_data Current queue health snapshot.
			 * @param float $target_sl   Target service level fraction.
			 * @param array $context     Execution context.
			 */
			do_action(
				'wp_mcp_ai_queue_alert',
				array(
					'channel'        => $channel,
					'arrival_rate'   => $arrival_rate,
					'current_agents' => $current_agents,
					'min_agents'     => $min_agents,
					'agent_deficit'  => $agent_deficit,
					'svc_level'      => $svc_level,
					'target_sl'      => $target_sl_frac,
					'queue_depth'    => $queue_depth,
					'is_stable'      => $is_stable,
					'timestamp'      => time(),
				),
				$target_sl_frac,
				$context
			);
		}

		// Build snapshot.
		$snapshot = array(
			'timestamp'         => time(),
			'channel'           => $channel,
			'arrival_rate'      => round( $arrival_rate, 2 ),
			'avg_handle_time'   => $aht,
			'current_agents'    => $current_agents,
			'queue_depth'       => $queue_depth,
			'traffic_intensity' => round( $traffic, 4 ),
			'min_agents_needed' => $min_agents,
			'probability_wait'  => round( $prob_wait, 4 ),
			'avg_wait_sec'      => $is_stable ? round( $avg_wait, 2 ) : null,
			'service_level_pct' => round( $svc_level * 100, 2 ),
			'utilisation_pct'   => round( $util * 100, 2 ),
			'sla_at_risk'       => $sla_at_risk,
			'is_stable'         => $is_stable,
		);

		if ( $store_snapshot ) {
			$this->store_snapshot( $snapshot );
		}

		// Build response message.
		if ( ! $is_stable ) {
			$message = sprintf(
				/* translators: %d: agent count */
				__( '🔴 QUEUE OVERLOADED: %d agents insufficient for current traffic. Add agents immediately.', 'mcp-ai-wpoos' ),
				$current_agents
			);
		} elseif ( $sla_at_risk ) {
			$message = sprintf(
				/* translators: 1: actual SL %, 2: target SL %, 3: deficit */
				__( '🟡 SLA AT RISK: Current service level %1$s%% is below target %2$s%%. Need %3$d more agent(s).', 'mcp-ai-wpoos' ),
				round( $svc_level * 100, 1 ),
				$target_sl_pct,
				$agent_deficit
			);
		} else {
			$message = sprintf(
				/* translators: 1: SL %, 2: target SL %, 3: avg wait s */
				__( '🟢 SLA HEALTHY: Service level %1$s%% meets target %2$s%%. Avg wait %3$ss.', 'mcp-ai-wpoos' ),
				round( $svc_level * 100, 1 ),
				$target_sl_pct,
				round( $avg_wait, 1 )
			);
		}

		$result = array(
			'message'                  => $message,
			'status'                   => ! $is_stable ? 'overloaded' : ( $sla_at_risk ? 'at_risk' : 'healthy' ),
			'sla_at_risk'              => $sla_at_risk,
			'is_stable'                => $is_stable,
			'channel'                  => $channel,
			'metrics'                  => array(
				'arrival_rate_per_hour' => round( $arrival_rate, 2 ),
				'avg_handle_time_sec'   => $aht,
				'current_agents'        => $current_agents,
				'queue_depth'           => $queue_depth,
			),
			'erlang_c'                 => array(
				'traffic_intensity'    => round( $traffic, 4 ),
				'probability_wait_pct' => round( $prob_wait * 100, 2 ),
				'avg_wait_time_sec'    => $is_stable ? round( $avg_wait, 2 ) : null,
				'service_level_pct'    => round( $svc_level * 100, 2 ),
				'utilisation_pct'      => round( $util * 100, 2 ),
			),
			'recommendation'           => array(
				'min_agents_needed' => $min_agents,
				'agent_deficit'     => $agent_deficit,
				'agent_surplus'     => max( 0, $current_agents - $min_agents ),
			),
			'target_service_level_pct' => $target_sl_pct,
			'target_answer_time_sec'   => $target_time,
			'snapshot_stored'          => $store_snapshot,
		);

		/**
		 * Filter the queue health result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $snapshot  Raw snapshot data.
		 * @param array $context   Invocation context.
		 */
		return apply_filters( 'wp_mcp_ai_erlang_c_queue_health_result', $result, $snapshot, $context );
	}

	/**
	 * Resolve metrics from inline tool arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Metrics array or WP_Error.
	 */
	protected function resolve_inline_metrics( array $arguments ) {
		if ( ! isset( $arguments['arrival_rate_per_hour'] ) || ! is_numeric( $arguments['arrival_rate_per_hour'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'arrival_rate_per_hour is required when fetch_from_endpoint is false.', 'mcp-ai-wpoos' ) );
		}

		if ( ! isset( $arguments['avg_handle_time'] ) || ! is_numeric( $arguments['avg_handle_time'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'avg_handle_time is required when fetch_from_endpoint is false.', 'mcp-ai-wpoos' ) );
		}

		if ( ! isset( $arguments['current_agents'] ) || ! is_numeric( $arguments['current_agents'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'current_agents is required when fetch_from_endpoint is false.', 'mcp-ai-wpoos' ) );
		}

		return array(
			'arrival_rate_per_hour' => (float) $arguments['arrival_rate_per_hour'],
			'avg_handle_time'       => (float) $arguments['avg_handle_time'],
			'current_agents'        => max( 1, (int) $arguments['current_agents'] ),
			'queue_depth'           => isset( $arguments['queue_depth'] ) ? (int) $arguments['queue_depth'] : 0,
		);
	}

	/**
	 * Fetch live metrics from the configured queue health endpoint.
	 *
	 * Expects the endpoint to return JSON with keys:
	 *   arrival_rate_per_hour, avg_handle_time, current_agents, [queue_depth].
	 *
	 * @return array|WP_Error Metrics array or WP_Error.
	 */
	protected function fetch_endpoint_metrics() {
		$endpoint = get_option( self::OPTION_ENDPOINT, '' );
		$token    = get_option( self::OPTION_TOKEN, '' );

		if ( empty( $endpoint ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_endpoint',
				__( 'No queue health endpoint configured. Set wp_mcp_ai_queue_health_endpoint in site options or provide inline metrics.', 'mcp-ai-wpoos' )
			);
		}

		$endpoint = esc_url_raw( $endpoint );
		$args     = array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/json' ),
		);

		if ( ! empty( $token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . sanitize_text_field( $token );
		}

		$response = wp_remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wp_mcp_ai_endpoint_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'wp_mcp_ai_endpoint_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Queue health endpoint returned HTTP %d.', 'mcp-ai-wpoos' ),
					$code
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_endpoint_error', __( 'Queue health endpoint returned non-JSON response.', 'mcp-ai-wpoos' ) );
		}

		foreach ( array( 'arrival_rate_per_hour', 'avg_handle_time', 'current_agents' ) as $required_key ) {
			if ( ! isset( $decoded[ $required_key ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_endpoint_missing_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Queue health endpoint response is missing required field: %s.', 'mcp-ai-wpoos' ),
						$required_key
					)
				);
			}
		}

		return array(
			'arrival_rate_per_hour' => (float) $decoded['arrival_rate_per_hour'],
			'avg_handle_time'       => (float) $decoded['avg_handle_time'],
			'current_agents'        => max( 1, (int) $decoded['current_agents'] ),
			'queue_depth'           => isset( $decoded['queue_depth'] ) ? (int) $decoded['queue_depth'] : 0,
		);
	}

	/**
	 * Persist a snapshot to the rolling snapshot option.
	 *
	 * Keeps the last MAX_SNAPSHOTS entries.
	 *
	 * @param array $snapshot Snapshot data.
	 */
	protected function store_snapshot( array $snapshot ) {
		$snapshots = get_option( self::OPTION_SNAPSHOTS, array() );

		if ( ! is_array( $snapshots ) ) {
			$snapshots = array();
		}

		$snapshots[] = $snapshot;

		// Trim to max.
		if ( count( $snapshots ) > self::MAX_SNAPSHOTS ) {
			$snapshots = array_slice( $snapshots, -self::MAX_SNAPSHOTS );
		}

		update_option( self::OPTION_SNAPSHOTS, $snapshots, false );
	}
}
