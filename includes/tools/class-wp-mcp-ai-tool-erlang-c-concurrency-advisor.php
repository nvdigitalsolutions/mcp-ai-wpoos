<?php
/**
 * Tool: erlang_c_concurrency_advisor — AI session concurrency optimizer.
 *
 * Applies Erlang C queuing theory to the plugin's own chat session metrics to
 * recommend how many concurrent AI assistant sessions the site should allow.
 * Uses WordPress transient/option counters already maintained by the plugin.
 * No external API calls; fully self-contained.
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
 * Advises on AI chat session concurrency using Erlang C.
 *
 * Reads observed chat-activity counters from WordPress options/transients
 * and applies the Erlang C formula to recommend the optimal number of
 * concurrent assistant sessions.  Admin-only (manage_options).
 *
 * @since 1.1.8
 */
class WP_MCP_AI_Tool_Erlang_C_Concurrency_Advisor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Option key for storing rolling chat-request counts.
	 */
	const OPTION_CHAT_REQUESTS = 'wp_mcp_ai_chat_request_counts';

	/**
	 * Fallback default session duration in seconds when no data is available.
	 */
	const DEFAULT_SESSION_DURATION = 120;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'erlang_c_concurrency_advisor';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'AI Session Concurrency Advisor', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyses observed AI chat arrival rates and session durations from this site\'s activity counters, then applies Erlang C queuing theory to recommend the optimal number of concurrent assistant sessions. Helps admins decide how many parallel AI sessions to allow to meet a target response-time SLA. Requires manage_options capability.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Override observed arrival rate (AI chat requests per hour). When omitted the tool reads the site\'s stored activity counters.', 'mcp-ai-wpoos' ),
					'minimum'     => 0.001,
				),
				'avg_session_duration'     => array(
					'type'        => 'number',
					'description' => __( 'Override average AI session duration in seconds. When omitted the stored average or a 120 s default is used.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'target_service_level_pct' => array(
					'type'        => 'number',
					'description' => __( 'Target service-level percentage (0–100). Default 80.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 99.9,
				),
				'target_answer_time'       => array(
					'type'        => 'integer',
					'description' => __( 'Target queue-wait threshold in seconds. Default 5 s for AI sessions (lower than voice 20 s because users expect fast AI responses).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'window_hours'             => array(
					'type'        => 'integer',
					'description' => __( 'Observation window in hours to compute the rolling average arrival rate. Default 1.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 168,
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
			'pattern_compatibility' => array( 'sequential', 'orchestrator' ),
			'profession_tags'       => array( 'site_administrator', 'operations_analyst' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads site counters.
			'local-only',          // No external API calls.
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
				__( 'You do not have permission to use the AI concurrency advisor. Requires manage_options.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Observation window.
		$window_hours = isset( $arguments['window_hours'] ) ? max( 1, min( 168, (int) $arguments['window_hours'] ) ) : 1;

		// Resolve arrival rate.
		if ( isset( $arguments['arrival_rate_per_hour'] ) && is_numeric( $arguments['arrival_rate_per_hour'] ) ) {
			$arrival_rate = (float) $arguments['arrival_rate_per_hour'];
			$data_source  = 'provided';
		} else {
			list( $arrival_rate, $data_source ) = $this->read_arrival_rate( $window_hours );
		}

		if ( $arrival_rate <= 0 ) {
			return new WP_Error(
				'wp_mcp_ai_no_data',
				__( 'No chat-request data found. Please provide arrival_rate_per_hour or generate some chat activity first.', 'mcp-ai-wpoos' )
			);
		}

		// Resolve average session duration.
		if ( isset( $arguments['avg_session_duration'] ) && is_numeric( $arguments['avg_session_duration'] ) ) {
			$avg_duration    = (float) $arguments['avg_session_duration'];
			$duration_source = 'provided';
		} else {
			list( $avg_duration, $duration_source ) = $this->read_avg_session_duration();
		}

		$avg_duration = max( 1.0, $avg_duration );

		// Target SL.
		$target_sl_pct  = isset( $arguments['target_service_level_pct'] ) ? (float) $arguments['target_service_level_pct'] : 80.0;
		$target_time    = isset( $arguments['target_answer_time'] ) ? (int) $arguments['target_answer_time'] : 5;
		$target_sl_frac = min( 0.999, max( 0.001, $target_sl_pct / 100.0 ) );

		// Compute Erlang C.
		$traffic_intensity = WP_MCP_AI_Erlang_C::to_erlangs( $arrival_rate, $avg_duration );
		$min_slots         = WP_MCP_AI_Erlang_C::min_agents_for_sl( $traffic_intensity, $avg_duration, $target_sl_frac, (float) $target_time );

		// Also compute a comfortable +20 % headroom recommendation.
		$recommended_slots = (int) ceil( $min_slots * 1.2 );

		$prob_wait = WP_MCP_AI_Erlang_C::probability_wait( $traffic_intensity, $recommended_slots );
		$avg_wait  = WP_MCP_AI_Erlang_C::avg_wait_time( $traffic_intensity, $recommended_slots, $avg_duration );
		$svc_level = WP_MCP_AI_Erlang_C::service_level( $traffic_intensity, $recommended_slots, $avg_duration, (float) $target_time );
		$util      = WP_MCP_AI_Erlang_C::utilisation( $traffic_intensity, $recommended_slots );

		// Current setting if available.
		$current_slots = (int) get_option( 'wp_mcp_ai_max_concurrent_sessions', 0 );

		$message = sprintf(
			/* translators: 1: recommended slots, 2: target SL %, 3: target time s */
			__( 'Recommend %1$d concurrent AI sessions to achieve %2$s%% of chats queued ≤ %3$ss (includes 20%% headroom over the Erlang C minimum).', 'mcp-ai-wpoos' ),
			$recommended_slots,
			$target_sl_pct,
			$target_time
		);

		$result = array(
			'message'                  => $message,
			'observation'              => array(
				'arrival_rate_per_hour' => round( $arrival_rate, 2 ),
				'avg_session_duration'  => round( $avg_duration, 1 ),
				'data_source'           => $data_source,
				'duration_source'       => $duration_source,
				'window_hours'          => $window_hours,
			),
			'erlang_c'                 => array(
				'traffic_intensity'  => round( $traffic_intensity, 4 ),
				'min_slots_required' => $min_slots,
				'recommended_slots'  => $recommended_slots,
			),
			'with_recommended_slots'   => array(
				'probability_wait_pct' => round( $prob_wait * 100, 2 ),
				'avg_wait_time_sec'    => round( $avg_wait, 2 ),
				'service_level_pct'    => round( $svc_level * 100, 2 ),
				'utilisation_pct'      => round( $util * 100, 2 ),
			),
			'current_setting'          => $current_slots > 0 ? $current_slots : null,
			'setting_adequate'         => $current_slots > 0 ? ( $current_slots >= $min_slots ) : null,
			'target_service_level_pct' => $target_sl_pct,
			'target_answer_time_sec'   => $target_time,
		);

		/**
		 * Filter the concurrency advisor result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		return apply_filters( 'wp_mcp_ai_erlang_c_concurrency_advisor_result', $result, $arguments, $context );
	}

	/**
	 * Read rolling arrival rate from plugin activity counters.
	 *
	 * Returns the hourly average derived from the stored request-count data.
	 *
	 * @param int $window_hours Rolling window size in hours.
	 * @return array{ 0: float, 1: string } [arrival_rate_per_hour, data_source].
	 */
	protected function read_arrival_rate( $window_hours ) {
		$counts = get_option( self::OPTION_CHAT_REQUESTS, array() );

		if ( empty( $counts ) || ! is_array( $counts ) ) {
			// Fall back to recent-activity logs.
			return array( $this->estimate_from_recent_activity( $window_hours ), 'activity_log' );
		}

		// Counts are expected as [ 'YYYY-MM-DD HH' => count, … ] buckets.
		$cutoff = time() - ( $window_hours * 3600 );
		$total  = 0;

		foreach ( $counts as $bucket => $count ) {
			// Parse bucket as a date string.
			$ts = strtotime( (string) $bucket );
			if ( false !== $ts && $ts >= $cutoff ) {
				$total += (int) $count;
			}
		}

		$rate = $window_hours > 0 ? (float) $total / (float) $window_hours : 0.0;
		return array( $rate, 'request_counts_option' );
	}

	/**
	 * Estimate arrival rate from the wp_mcp_ai_recent_activity option.
	 *
	 * @param int $window_hours Rolling window size in hours.
	 * @return float Estimated hourly arrival rate.
	 */
	protected function estimate_from_recent_activity( $window_hours ) {
		$activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		if ( empty( $activity ) || ! is_array( $activity ) ) {
			return 0.0;
		}

		$cutoff = time() - ( $window_hours * 3600 );
		$count  = 0;

		foreach ( $activity as $entry ) {
			if ( ! isset( $entry['timestamp'] ) ) {
				continue;
			}

			$ts = is_numeric( $entry['timestamp'] ) ? (int) $entry['timestamp'] : strtotime( (string) $entry['timestamp'] );

			if ( $ts >= $cutoff ) {
				++$count;
			}
		}

		return $window_hours > 0 ? (float) $count / (float) $window_hours : 0.0;
	}

	/**
	 * Read average session duration from stored plugin metadata.
	 *
	 * @return array{ 0: float, 1: string } [avg_duration_seconds, data_source].
	 */
	protected function read_avg_session_duration() {
		$stored = get_option( 'wp_mcp_ai_avg_session_duration', 0 );

		if ( $stored > 0 ) {
			return array( (float) $stored, 'stored_average' );
		}

		return array( (float) self::DEFAULT_SESSION_DURATION, 'default' );
	}
}
