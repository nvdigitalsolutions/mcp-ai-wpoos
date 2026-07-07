<?php
/**
 * DSpark Orchestration Hooks
 *
 * Lightweight data collectors that feed the DSpark Efficiency dashboard.
 * These hooks increment counters and update transients for the admin UI
 * widgets without adding significant per-request overhead.
 *
 * @package WP_MCP_AI
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_DSpark_Hooks' ) ) {
	/**
	 * DSpark data collection hooks.
	 *
	 * @since 1.6.0
	 */
	class WP_MCP_AI_DSpark_Hooks {

		/**
		 * Register all DSpark data-collection hooks.
		 *
		 * @since 1.6.0
		 * @return void
		 */
		public static function register() {
			add_filter( 'wp_mcp_ai_orchestration_depth_tier', array( __CLASS__, 'count_depth_tier' ), 10, 1 );
			add_filter( 'wp_mcp_ai_tiered_model_selection', array( __CLASS__, 'track_routing_cost' ), 10, 2 );
		}

		/**
		 * Increment depth tier usage counter.
		 *
		 * Hooks into wp_mcp_ai_orchestration_depth_tier to count how often
		 * each tier is selected, powering the tier distribution widget.
		 *
		 * @since 1.6.0
		 *
		 * @param string $tier The resolved tier constant.
		 * @return string Unmodified tier.
		 */
		public static function count_depth_tier( $tier ) {
			$counts = get_option(
				'wp_mcp_ai_depth_tier_counts',
				array(
					'deep'     => 0,
					'standard' => 0,
					'shallow'  => 0,
					'minimal'  => 0,
				)
			);

			if ( isset( $counts[ $tier ] ) ) {
				++$counts[ $tier ];

				// Soft cap at 10,000 to prevent unbounded growth.
				$total = array_sum( $counts );
				if ( $total > 10000 ) {
					foreach ( $counts as &$c ) {
						$c = (int) ( $c * 0.9 );
					}
					unset( $c );
				}

				update_option( 'wp_mcp_ai_depth_tier_counts', $counts, false );
			}

			return $tier;
		}

		/**
		 * Track tiered model routing for cost savings estimation.
		 *
		 * Hooks into wp_mcp_ai_tiered_model_selection to count draft vs
		 * verification requests and estimate cost savings.
		 *
		 * @since 1.6.0
		 *
		 * @param array  $config    The routing configuration.
		 * @param string $task_type Task category.
		 * @return array Unmodified config.
		 */
		public static function track_routing_cost( $config, $task_type ) {
			$data = get_transient( 'wp_mcp_ai_routing_cost_data' );

			if ( ! is_array( $data ) ) {
				$data = array(
					'draft_tokens'          => 0,
					'verification_tokens'   => 0,
					'draft_requests'        => 0,
					'verification_requests' => 0,
				);
			}

			// Approximate: average request is ~500 tokens.
			$avg_tokens = 500;

			if ( isset( $config['tier'] ) && 'draft' === $config['tier'] ) {
				$data['draft_requests'] = isset( $data['draft_requests'] ) ? $data['draft_requests'] + 1 : 1;
				$data['draft_tokens']   = isset( $data['draft_tokens'] ) ? $data['draft_tokens'] + $avg_tokens : $avg_tokens;
			} else {
				$data['verification_requests'] = isset( $data['verification_requests'] ) ? $data['verification_requests'] + 1 : 1;
				$data['verification_tokens']   = isset( $data['verification_tokens'] ) ? $data['verification_tokens'] + $avg_tokens : $avg_tokens;
			}

			// Estimate savings: draft models are ~10x cheaper.
			$total_requests = $data['draft_requests'] + $data['verification_requests'];
			if ( $total_requests > 0 ) {
				$all_verification_cost     = $total_requests * $avg_tokens;
				$actual_cost               = $data['verification_tokens'] + ( $data['draft_tokens'] * 0.1 );
				$data['estimated_savings'] = $all_verification_cost > 0
					? max( 0, ( 1 - ( $actual_cost / $all_verification_cost ) ) * 100 )
					: 0;
			}

			set_transient( 'wp_mcp_ai_routing_cost_data', $data, HOUR_IN_SECONDS );

			return $config;
		}
	}
}
