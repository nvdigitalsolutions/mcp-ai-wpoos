<?php
/**
 * Enhanced Token Tracking Integration
 *
 * Integrates the enhanced token tracking database with the existing
 * WP_MCP_AI_Usage_Tracker to record detailed usage with cost attribution.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced Token Tracking Integration class.
 *
 * Hooks into existing usage tracking to record enhanced data including
 * provider, model, input/output tokens, and real-time cost calculation.
 */
class WP_MCP_AI_Enhanced_Token_Tracking {

	/**
	 * Initialize the enhanced tracking integration.
	 */
	public static function init() {
		// Hook into after usage recorded to capture enhanced data.
		add_action( 'wp_mcp_ai_after_usage_recorded', array( __CLASS__, 'record_enhanced_usage' ), 10, 6 );

		// Hook into tool execution to capture tool-specific usage.
		add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'record_tool_usage' ), 10, 4 );

		// Initialize the database.
		WP_MCP_AI_Token_Tracking_Database::init();

		// Schedule cleanup task.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_token_tracking' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_token_tracking' );
		}

		add_action( 'wp_mcp_ai_cleanup_token_tracking', array( __CLASS__, 'cleanup_old_records' ) );
	}

	/**
	 * Record enhanced usage data after chat usage is recorded.
	 *
	 * Hooked to 'wp_mcp_ai_after_usage_recorded'.
	 *
	 * @param int    $user_id      Acting user identifier.
	 * @param int    $assistant_id Assistant identifier.
	 * @param string $provider     Provider key (e.g. openai, gemini).
	 * @param string $model        Model identifier.
	 * @param array  $totals       Updated totals for the model.
	 * @param array  $usage        Usage delta applied to the totals.
	 */
	public static function record_enhanced_usage( $user_id, $assistant_id, $provider, $model, $totals, $usage ) {
		// Extract token counts.
		$input_tokens  = isset( $usage['prompt_tokens'] ) ? absint( $usage['prompt_tokens'] ) : 0;
		$output_tokens = isset( $usage['completion_tokens'] ) ? absint( $usage['completion_tokens'] ) : 0;

		// If we don't have separate input/output, use total_tokens.
		if ( 0 === $input_tokens && 0 === $output_tokens ) {
			$total_tokens = isset( $usage['total_tokens'] ) ? absint( $usage['total_tokens'] ) : 0;
			// Estimate 60/40 split for input/output.
			$input_tokens  = intval( $total_tokens * 0.6 );
			$output_tokens = intval( $total_tokens * 0.4 );
		}

		// Get tool name from assistant if available.
		$tool = 'chat'; // Default to 'chat' for chat completions.

		// Calculate cost using the Cost Calculator.
		$cost_usd = 0.0;
		if ( class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			$cost_usd = WP_MCP_AI_Cost_Calculator::calculate_cost(
				$provider,
				$model,
				$input_tokens,
				$output_tokens
			);
		}

		// Record is NOT estimated since we have actual provider/model data.
		$is_estimated = false;

		// Record the enhanced usage.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			$tool,
			$provider,
			$model,
			$input_tokens,
			$output_tokens,
			$cost_usd,
			$is_estimated
		);
	}

	/**
	 * Record tool-specific usage.
	 *
	 * Hooked to 'wp_mcp_ai_after_tool_execution'.
	 *
	 * @param string $tool_name Tool name/slug.
	 * @param array  $result    Tool execution result.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 */
	public static function record_tool_usage( $tool_name, $result, $arguments, $context ) {
		// Only record if we have token usage data.
		if ( ! isset( $context['token_usage'] ) || ! is_array( $context['token_usage'] ) ) {
			return;
		}

		$token_usage = $context['token_usage'];

		// Extract user ID from context.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Extract provider and model from context.
		$provider = isset( $context['provider'] ) ? sanitize_text_field( $context['provider'] ) : '';
		$model    = isset( $context['model'] ) ? sanitize_text_field( $context['model'] ) : '';

		// If not in context, try to determine from settings.
		if ( ! $provider || ! $model ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( is_array( $settings ) ) {
				$provider = $provider ? $provider : ( isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai' );
				$model    = $model ? $model : ( isset( $settings['model'] ) ? $settings['model'] : 'gpt-4o-mini' );
			}
		}

		// Extract token counts.
		$input_tokens  = isset( $token_usage['prompt_tokens'] ) ? absint( $token_usage['prompt_tokens'] ) : 0;
		$output_tokens = isset( $token_usage['completion_tokens'] ) ? absint( $token_usage['completion_tokens'] ) : 0;

		if ( 0 === $input_tokens && 0 === $output_tokens ) {
			$total_tokens = isset( $token_usage['total_tokens'] ) ? absint( $token_usage['total_tokens'] ) : 0;
			// Estimate 50/50 split for tools.
			$input_tokens  = intval( $total_tokens * 0.5 );
			$output_tokens = intval( $total_tokens * 0.5 );
		}

		// Calculate cost.
		$cost_usd = 0.0;
		if ( class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			$cost_usd = WP_MCP_AI_Cost_Calculator::calculate_cost(
				$provider,
				$model,
				$input_tokens,
				$output_tokens
			);
		}

		// Record is estimated if we had to infer provider/model.
		$is_estimated = ! isset( $context['provider'] ) || ! isset( $context['model'] );

		// Record the tool usage.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			$tool_name,
			$provider,
			$model,
			$input_tokens,
			$output_tokens,
			$cost_usd,
			$is_estimated
		);
	}

	/**
	 * Clean up old token tracking records.
	 *
	 * Removes records older than the retention period (default: 90 days).
	 */
	public static function cleanup_old_records() {
		$settings        = WP_MCP_AI_Admin_Settings::get_settings();
		$retention_days  = isset( $settings['token_tracking_retention_days'] ) ? absint( $settings['token_tracking_retention_days'] ) : 90;

		$deleted = WP_MCP_AI_Token_Tracking_Database::cleanup_old_records( $retention_days );

		if ( $deleted > 0 ) {
			error_log( sprintf( 'WP MCP AI: Cleaned up %d old token tracking records (older than %d days)', $deleted, $retention_days ) );
		}
	}

	/**
	 * Get enhanced usage statistics for a user.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @return array Enhanced usage statistics.
	 */
	public static function get_user_statistics( $user_id, $start_date = null, $end_date = null ) {
		if ( null === $start_date ) {
			$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		}

		if ( null === $end_date ) {
			$end_date = current_time( 'mysql' );
		}

		// Get cost summary.
		$cost_summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary(
			$user_id,
			$start_date,
			$end_date
		);

		// Get detailed usage.
		$usage_records = WP_MCP_AI_Token_Tracking_Database::get_user_usage(
			$user_id,
			$start_date,
			$end_date
		);

		// Aggregate by provider.
		$by_provider = array();
		// Aggregate by tool.
		$by_tool = array();

		foreach ( $usage_records as $record ) {
			$provider = $record['provider'];
			$tool     = $record['tool'];

			// By provider.
			if ( ! isset( $by_provider[ $provider ] ) ) {
				$by_provider[ $provider ] = array(
					'total_tokens' => 0,
					'total_cost'   => 0.0,
					'records'      => 0,
				);
			}
			$by_provider[ $provider ]['total_tokens'] += intval( $record['total_tokens'] );
			$by_provider[ $provider ]['total_cost']   += floatval( $record['cost_usd'] );
			$by_provider[ $provider ]['records']++;

			// By tool.
			if ( ! isset( $by_tool[ $tool ] ) ) {
				$by_tool[ $tool ] = array(
					'total_tokens' => 0,
					'total_cost'   => 0.0,
					'records'      => 0,
				);
			}
			$by_tool[ $tool ]['total_tokens'] += intval( $record['total_tokens'] );
			$by_tool[ $tool ]['total_cost']   += floatval( $record['cost_usd'] );
			$by_tool[ $tool ]['records']++;
		}

		return array(
			'summary'     => $cost_summary,
			'by_provider' => $by_provider,
			'by_tool'     => $by_tool,
			'total_records' => count( $usage_records ),
		);
	}

	/**
	 * Backfill historical data from user meta.
	 *
	 * Migrates existing usage data from WP_MCP_AI_Usage_Tracker's user meta
	 * to the new enhanced tracking database.
	 *
	 * @param int $user_id Optional user ID to migrate (default: all users).
	 * @return array Migration results with counts.
	 */
	public static function backfill_historical_data( $user_id = null ) {
		$results = array(
			'users_processed'   => 0,
			'records_created'   => 0,
			'records_estimated' => 0,
			'errors'            => 0,
		);

		// Get users to process.
		$user_ids = array();
		if ( $user_id ) {
			$user_ids = array( absint( $user_id ) );
		} else {
			// Get all users who have usage data.
			global $wpdb;
			$meta_key = WP_MCP_AI_Usage_Tracker::USER_META_KEY;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$meta_key
				)
			);
		}

		foreach ( $user_ids as $uid ) {
			$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $uid );

			if ( ! is_array( $usage ) || empty( $usage ) ) {
				continue;
			}

			$results['users_processed']++;

			// Process each provider/model combination.
			foreach ( $usage as $provider => $models ) {
				if ( ! is_array( $models ) ) {
					continue;
				}

				foreach ( $models as $model => $data ) {
					if ( ! is_array( $data ) ) {
						continue;
					}

					// Extract token counts.
					$prompt_tokens     = isset( $data['prompt_tokens'] ) ? absint( $data['prompt_tokens'] ) : 0;
					$completion_tokens = isset( $data['completion_tokens'] ) ? absint( $data['completion_tokens'] ) : 0;

					if ( 0 === $prompt_tokens && 0 === $completion_tokens ) {
						$total_tokens = isset( $data['total_tokens'] ) ? absint( $data['total_tokens'] ) : 0;
						if ( 0 === $total_tokens ) {
							continue; // No usage data.
						}
						// Estimate 60/40 split.
						$prompt_tokens     = intval( $total_tokens * 0.6 );
						$completion_tokens = intval( $total_tokens * 0.4 );
					}

					// Record as historical/estimated data.
					$record_id = WP_MCP_AI_Token_Tracking_Database::record_usage(
						$uid,
						'historical', // Mark as historical migration.
						$provider,
						$model,
						$prompt_tokens,
						$completion_tokens,
						null, // Let it calculate cost.
						true  // Mark as estimated.
					);

					if ( $record_id ) {
						$results['records_created']++;
						$results['records_estimated']++;
					} else {
						$results['errors']++;
					}
				}
			}
		}

		return $results;
	}
}
