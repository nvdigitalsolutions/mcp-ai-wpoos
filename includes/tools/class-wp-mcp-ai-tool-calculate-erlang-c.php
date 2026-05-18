<?php
/**
 * Tool: calculate_erlang_c — Erlang C staffing and queue calculator.
 *
 * Pure-PHP implementation of the Erlang C formula.  No external dependencies.
 * Useful for any business that uses the AI assistant for operational-planning
 * queries (contact centres, service desks, healthcare, e-commerce support).
 *
 * Industry default: 80/20 service level (80 % of contacts answered ≤ 20 s).
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
 * Exposes Erlang C calculations to the AI assistant.
 *
 * Computes: probability of waiting, average wait time, service-level
 * attainment, agent utilisation, and minimum agents required.
 *
 * @since 1.1.8
 */
class WP_MCP_AI_Tool_Calculate_Erlang_C implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'calculate_erlang_c';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Calculate Erlang C', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Applies the Erlang C queuing formula to calculate contact-centre or AI-chat staffing. Given an arrival rate, average handle time, and number of agents, returns the probability of waiting, average wait time, agent utilisation, and service-level attainment. Can also find the minimum agents required to meet a target service level (default 80 % answered within 20 s).', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'arrival_rate'             => array(
					'type'        => 'number',
					'description' => __( 'Number of contacts (calls / chats / tasks) arriving per hour. Must be greater than 0.', 'mcp-ai-wpoos' ),
					'minimum'     => 0.001,
				),
				'avg_handle_time'          => array(
					'type'        => 'number',
					'description' => __( 'Average handle time per contact in seconds (talk/chat time + wrap-up). Must be greater than 0.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'num_agents'               => array(
					'type'        => 'integer',
					'description' => __( 'Number of agents (parallel workers). When omitted, the tool finds the minimum agents required to meet the service-level target.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'target_service_level_pct' => array(
					'type'        => 'number',
					'description' => __( 'Target service-level percentage (0–100). Default 80 (80 % of contacts answered within the target time).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 99.9,
				),
				'target_answer_time'       => array(
					'type'        => 'integer',
					'description' => __( 'Target answer-time threshold in seconds. Default 20 (industry 80/20 standard).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'concurrency_factor'       => array(
					'type'        => 'number',
					'description' => __( 'For chat / async channels: number of simultaneous conversations one agent can handle (e.g. 3 for live-chat). Default 1 (voice / synchronous).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 10,
				),
			),
			'required'             => array( 'arrival_rate', 'avg_handle_time' ),
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
			'pattern_compatibility' => array( 'sequential', 'experimentation' ),
			'profession_tags'       => array( 'contact_center_manager', 'operations_analyst', 'workforce_planner' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',    // Only computes, no state changes.
			'local-only',   // Pure PHP math, no external API calls.
			'cacheable',    // Deterministic for same inputs.
			'idempotent',   // Safe to call multiple times.
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
				__( 'You must be authenticated to use the Erlang C calculator.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'edit_posts' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to use the Erlang C calculator.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		// Validate required inputs.
		if ( ! isset( $arguments['arrival_rate'] ) || ! is_numeric( $arguments['arrival_rate'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'arrival_rate must be a positive number.', 'mcp-ai-wpoos' ) );
		}

		if ( ! isset( $arguments['avg_handle_time'] ) || ! is_numeric( $arguments['avg_handle_time'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'avg_handle_time must be a positive number.', 'mcp-ai-wpoos' ) );
		}

		$arrival_rate = (float) $arguments['arrival_rate'];
		$aht          = (float) $arguments['avg_handle_time'];

		if ( $arrival_rate <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'arrival_rate must be greater than 0.', 'mcp-ai-wpoos' ) );
		}

		if ( $aht <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'avg_handle_time must be greater than 0.', 'mcp-ai-wpoos' ) );
		}

		// Optional parameters with defaults.
		$target_sl_pct = isset( $arguments['target_service_level_pct'] ) ? (float) $arguments['target_service_level_pct'] : 80.0;
		$target_time   = isset( $arguments['target_answer_time'] ) ? (int) $arguments['target_answer_time'] : 20;
		$concurrency   = isset( $arguments['concurrency_factor'] ) ? (float) $arguments['concurrency_factor'] : 1.0;

		// Apply concurrency: effective arrival rate per agent-equivalent.
		$effective_arrival = $arrival_rate / $concurrency;

		// Convert to Erlangs (traffic intensity).
		$traffic_intensity = WP_MCP_AI_Erlang_C::to_erlangs( $effective_arrival, $aht );

		// Target fraction (0–1).
		$target_sl_fraction = min( 0.999, max( 0.001, $target_sl_pct / 100.0 ) );

		// If num_agents provided, compute metrics for that agent count.
		if ( isset( $arguments['num_agents'] ) && null !== $arguments['num_agents'] ) {
			$agents = max( 1, (int) $arguments['num_agents'] );

			$prob_wait  = WP_MCP_AI_Erlang_C::probability_wait( $traffic_intensity, $agents );
			$avg_wait   = WP_MCP_AI_Erlang_C::avg_wait_time( $traffic_intensity, $agents, $aht );
			$svc_level  = WP_MCP_AI_Erlang_C::service_level( $traffic_intensity, $agents, $aht, (float) $target_time );
			$util       = WP_MCP_AI_Erlang_C::utilisation( $traffic_intensity, $agents );
			$min_agents = WP_MCP_AI_Erlang_C::min_agents_for_sl( $traffic_intensity, $aht, $target_sl_fraction, (float) $target_time );

			$is_stable = $traffic_intensity < (float) $agents;

			$message = $is_stable
				? sprintf(
					/* translators: 1: agents, 2: probability %, 3: wait s, 4: SL %, 5: util % */
					__( '%1$d agents: P(wait)=%2$s%%, avg wait=%3$ss, SL(%4$ss)=%5$s%%, utilisation=%6$s%%', 'mcp-ai-wpoos' ),
					$agents,
					round( $prob_wait * 100, 1 ),
					round( $avg_wait, 1 ),
					$target_time,
					round( $svc_level * 100, 1 ),
					round( $util * 100, 1 )
				)
				: sprintf(
					/* translators: %d: agents */
					__( 'Queue is UNSTABLE with %d agents (traffic exceeds capacity). Add more agents.', 'mcp-ai-wpoos' ),
					$agents
				);

			$result = array(
				'message'              => $message,
				'input'                => array(
					'arrival_rate_per_hour'    => $arrival_rate,
					'avg_handle_time_seconds'  => $aht,
					'num_agents'               => $agents,
					'concurrency_factor'       => $concurrency,
					'target_service_level_pct' => $target_sl_pct,
					'target_answer_time_sec'   => $target_time,
				),
				'traffic_intensity'    => round( $traffic_intensity, 4 ),
				'is_stable'            => $is_stable,
				'probability_wait'     => round( $prob_wait, 4 ),
				'probability_wait_pct' => round( $prob_wait * 100, 2 ),
				'avg_wait_time_sec'    => $is_stable ? round( $avg_wait, 2 ) : null,
				'service_level_pct'    => round( $svc_level * 100, 2 ),
				'utilisation_pct'      => round( $util * 100, 2 ),
				'agents_needed'        => $min_agents,
				'agents_surplus'       => $is_stable ? ( $agents - $min_agents ) : null,
			);
		} else {
			// Find minimum agents to meet the service-level target.
			$min_agents = WP_MCP_AI_Erlang_C::min_agents_for_sl( $traffic_intensity, $aht, $target_sl_fraction, (float) $target_time );

			$prob_wait = WP_MCP_AI_Erlang_C::probability_wait( $traffic_intensity, $min_agents );
			$avg_wait  = WP_MCP_AI_Erlang_C::avg_wait_time( $traffic_intensity, $min_agents, $aht );
			$svc_level = WP_MCP_AI_Erlang_C::service_level( $traffic_intensity, $min_agents, $aht, (float) $target_time );
			$util      = WP_MCP_AI_Erlang_C::utilisation( $traffic_intensity, $min_agents );

			$message = sprintf(
				/* translators: 1: min agents, 2: SL %, 3: target time s, 4: arrival/h, 5: AHT s */
				__( 'Minimum %1$d agents needed to achieve %2$s%% of contacts answered within %3$ss (arrival rate %4$s/hr, AHT %5$ss).', 'mcp-ai-wpoos' ),
				$min_agents,
				$target_sl_pct,
				$target_time,
				$arrival_rate,
				$aht
			);

			$result = array(
				'message'              => $message,
				'input'                => array(
					'arrival_rate_per_hour'    => $arrival_rate,
					'avg_handle_time_seconds'  => $aht,
					'concurrency_factor'       => $concurrency,
					'target_service_level_pct' => $target_sl_pct,
					'target_answer_time_sec'   => $target_time,
				),
				'traffic_intensity'    => round( $traffic_intensity, 4 ),
				'agents_needed'        => $min_agents,
				'probability_wait'     => round( $prob_wait, 4 ),
				'probability_wait_pct' => round( $prob_wait * 100, 2 ),
				'avg_wait_time_sec'    => round( $avg_wait, 2 ),
				'service_level_pct'    => round( $svc_level * 100, 2 ),
				'utilisation_pct'      => round( $util * 100, 2 ),
			);
		}

		/**
		 * Filter the Erlang C calculation result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		return apply_filters( 'wp_mcp_ai_calculate_erlang_c_result', $result, $arguments, $context );
	}
}
