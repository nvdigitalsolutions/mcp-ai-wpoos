<?php
/**
 * Tool: erlang_c_staffing_advisor — multi-channel contact-centre staffing advisor.
 *
 * Combines Erlang C with:
 *  - Multi-channel concurrency (voice = 1, live-chat = 2-4, email = 8+).
 *  - Bot-containment-rate adjustment so only escalated contacts count.
 *  - Configurable WFM REST endpoint for live queue stats (optional).
 *  - Structured staffing-recommendation card output.
 *
 * Capability: edit_posts (general-purpose staffing planning tool).
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
 * Multi-channel Erlang C staffing advisor.
 *
 * Supports voice, live-chat, email, and custom channels with per-channel
 * concurrency factors and bot-containment rate adjustment.
 * Optionally pulls live queue statistics from a configured WFM REST endpoint.
 *
 * @since 1.1.8
 */
class WP_MCP_AI_Tool_Erlang_C_Staffing_Advisor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Default concurrency factors per channel type.
	 * Voice = 1 (synchronous), chat = 3 (industry average), email/async = 8.
	 */
	const CHANNEL_CONCURRENCY = array(
		'voice' => 1,
		'chat'  => 3,
		'email' => 8,
		'sms'   => 4,
		'other' => 1,
	);

	/**
	 * WFM endpoint option key.
	 */
	const OPTION_WFM_ENDPOINT = 'wp_mcp_ai_wfm_endpoint_url';

	/**
	 * WFM endpoint auth token option key.
	 */
	const OPTION_WFM_TOKEN = 'wp_mcp_ai_wfm_endpoint_token';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'erlang_c_staffing_advisor';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Contact Centre Staffing Advisor', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Multi-channel Erlang C staffing advisor. Calculates required agents per channel (voice, chat, email) with bot-containment-rate adjustment and optional live WFM endpoint integration. Returns a structured staffing recommendation with per-channel breakdowns.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'channels'                 => array(
					'type'        => 'array',
					'description' => __( 'Array of channel configurations. Each entry defines a channel name, arrival rate, AHT, and optional concurrency.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'                  => array(
								'type'        => 'string',
								'description' => __( 'Channel identifier, e.g. "voice", "chat", "email".', 'mcp-ai-wpoos' ),
							),
							'arrival_rate_per_hour' => array(
								'type'        => 'number',
								'description' => __( 'Total contacts per hour arriving on this channel (before bot containment).', 'mcp-ai-wpoos' ),
								'minimum'     => 0.001,
							),
							'avg_handle_time'       => array(
								'type'        => 'number',
								'description' => __( 'Average handle time in seconds for this channel.', 'mcp-ai-wpoos' ),
								'minimum'     => 1,
							),
							'concurrency_factor'    => array(
								'type'        => 'number',
								'description' => __( 'Simultaneous contacts one agent handles (1 = voice, 3 = chat default, 8 = email default). Overrides built-in channel defaults.', 'mcp-ai-wpoos' ),
								'minimum'     => 1,
								'maximum'     => 20,
							),
							'bot_containment_rate'  => array(
								'type'        => 'number',
								'description' => __( 'Fraction of contacts fully resolved by bots (0–1). E.g. 0.4 = 40% handled by AI without escalation. Default 0.', 'mcp-ai-wpoos' ),
								'minimum'     => 0,
								'maximum'     => 0.99,
							),
						),
						'required'   => array( 'name', 'arrival_rate_per_hour', 'avg_handle_time' ),
					),
					'minItems'    => 1,
				),
				'target_service_level_pct' => array(
					'type'        => 'number',
					'description' => __( 'Target service-level percentage across all channels. Default 80.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 99.9,
				),
				'target_answer_time'       => array(
					'type'        => 'integer',
					'description' => __( 'Answer-time threshold in seconds. Default 20 (80/20 industry standard).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'fetch_live_stats'         => array(
					'type'        => 'boolean',
					'description' => __( 'When true, attempt to pull live queue statistics from the configured WFM endpoint and overlay them on the recommendation.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'channels' ),
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
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'contact_center_manager', 'workforce_planner', 'operations_analyst' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // No state changes.
			'external-api',        // Optional WFM endpoint fetch via wp_remote_get().
			'requires-capability', // Requires edit_posts.
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
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to use the staffing advisor.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'edit_posts' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to use the staffing advisor.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		if ( empty( $arguments['channels'] ) || ! is_array( $arguments['channels'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'channels must be a non-empty array.', 'mcp-ai-wpoos' ) );
		}

		$target_sl_pct  = isset( $arguments['target_service_level_pct'] ) ? (float) $arguments['target_service_level_pct'] : 80.0;
		$target_time    = isset( $arguments['target_answer_time'] ) ? (int) $arguments['target_answer_time'] : 20;
		$target_sl_frac = min( 0.999, max( 0.001, $target_sl_pct / 100.0 ) );
		$fetch_live     = ! empty( $arguments['fetch_live_stats'] );

		$channel_results = array();
		$total_agents    = 0;
		$warnings        = array();

		foreach ( $arguments['channels'] as $idx => $channel ) {
			if ( ! is_array( $channel ) ) {
				continue;
			}

			$ch_name = isset( $channel['name'] ) ? sanitize_text_field( $channel['name'] ) : 'channel_' . $idx;

			if ( empty( $channel['arrival_rate_per_hour'] ) || ! is_numeric( $channel['arrival_rate_per_hour'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: channel name */
					__( 'Channel "%s" skipped: missing or invalid arrival_rate_per_hour.', 'mcp-ai-wpoos' ),
					$ch_name
				);
				continue;
			}

			if ( empty( $channel['avg_handle_time'] ) || ! is_numeric( $channel['avg_handle_time'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: channel name */
					__( 'Channel "%s" skipped: missing or invalid avg_handle_time.', 'mcp-ai-wpoos' ),
					$ch_name
				);
				continue;
			}

			$raw_arrival = (float) $channel['arrival_rate_per_hour'];
			$aht         = (float) $channel['avg_handle_time'];

			// Bot containment: only escalated volume reaches human agents.
			$containment = isset( $channel['bot_containment_rate'] ) ? (float) $channel['bot_containment_rate'] : 0.0;
			$containment = min( 0.99, max( 0.0, $containment ) );
			$net_arrival = $raw_arrival * ( 1.0 - $containment );

			// Concurrency factor.
			$ch_type     = strtolower( $ch_name );
			$default_con = isset( self::CHANNEL_CONCURRENCY[ $ch_type ] ) ? self::CHANNEL_CONCURRENCY[ $ch_type ] : 1;
			$concurrency = isset( $channel['concurrency_factor'] ) ? max( 1.0, (float) $channel['concurrency_factor'] ) : (float) $default_con;

			// Effective arrival per agent (concurrency-adjusted).
			$effective_arrival = $net_arrival / $concurrency;

			$traffic    = WP_MCP_AI_Erlang_C::to_erlangs( $effective_arrival, $aht );
			$min_agents = WP_MCP_AI_Erlang_C::min_agents_for_sl( $traffic, $aht, $target_sl_frac, (float) $target_time );

			$prob_wait = WP_MCP_AI_Erlang_C::probability_wait( $traffic, $min_agents );
			$avg_wait  = WP_MCP_AI_Erlang_C::avg_wait_time( $traffic, $min_agents, $aht );
			$svc_level = WP_MCP_AI_Erlang_C::service_level( $traffic, $min_agents, $aht, (float) $target_time );
			$util      = WP_MCP_AI_Erlang_C::utilisation( $traffic, $min_agents );

			$channel_results[ $ch_name ] = array(
				'channel'              => $ch_name,
				'raw_arrival_per_hour' => round( $raw_arrival, 2 ),
				'bot_containment_rate' => round( $containment, 3 ),
				'net_arrival_per_hour' => round( $net_arrival, 2 ),
				'avg_handle_time_sec'  => $aht,
				'concurrency_factor'   => $concurrency,
				'traffic_intensity'    => round( $traffic, 4 ),
				'agents_required'      => $min_agents,
				'probability_wait_pct' => round( $prob_wait * 100, 2 ),
				'avg_wait_time_sec'    => round( $avg_wait, 2 ),
				'service_level_pct'    => round( $svc_level * 100, 2 ),
				'utilisation_pct'      => round( $util * 100, 2 ),
			);

			$total_agents += $min_agents;
		}

		// Optional live stats overlay.
		$live_stats = null;
		if ( $fetch_live ) {
			$live_stats = $this->fetch_wfm_stats();
		}

		$message = sprintf(
			/* translators: 1: total agents, 2: SL %, 3: target time s */
			__( 'Total agents required across all channels: %1$d (to achieve %2$s%% SL within %3$ss).', 'mcp-ai-wpoos' ),
			$total_agents,
			$target_sl_pct,
			$target_time
		);

		$result = array(
			'message'                  => $message,
			'total_agents_required'    => $total_agents,
			'target_service_level_pct' => $target_sl_pct,
			'target_answer_time_sec'   => $target_time,
			'channels'                 => array_values( $channel_results ),
			'live_stats'               => $live_stats,
			'warnings'                 => $warnings,
		);

		/**
		 * Filter the staffing advisor result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		return apply_filters( 'wp_mcp_ai_erlang_c_staffing_advisor_result', $result, $arguments, $context );
	}

	/**
	 * Attempt to fetch live queue statistics from the configured WFM endpoint.
	 *
	 * Returns null when no endpoint is configured or the request fails.
	 *
	 * @return array|null Live stats array or null.
	 */
	protected function fetch_wfm_stats() {
		$endpoint = get_option( self::OPTION_WFM_ENDPOINT, '' );
		$token    = get_option( self::OPTION_WFM_TOKEN, '' );

		if ( empty( $endpoint ) ) {
			return null;
		}

		$endpoint = esc_url_raw( $endpoint );

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		if ( ! empty( $token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . sanitize_text_field( $token );
		}

		$response = wp_remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			/* translators: %d: HTTP status code returned by the WFM endpoint */
			return array( 'error' => sprintf( __( 'WFM endpoint returned HTTP %d.', 'mcp-ai-wpoos' ), $code ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array( 'error' => __( 'WFM endpoint returned non-JSON response.', 'mcp-ai-wpoos' ) );
		}

		return $decoded;
	}
}
