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
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @param mixed  $result    Tool execution result.
	 */
	public static function record_tool_usage( $tool_name, $arguments, $context, $result ) {
		$token_usage = null;
		$provider    = '';
		$model       = '';
		$source      = ''; // Track where we got provider/model info from.

		// Priority 1: Check if result contains usage/provider/model information.
		// This handles tools that use Gemini client or other API clients that return this data.
		if ( is_array( $result ) ) {
			if ( isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
				$token_usage = $result['usage'];
			}
			if ( isset( $result['provider'] ) && ! empty( $result['provider'] ) ) {
				$provider = sanitize_text_field( $result['provider'] );
				$source   = 'result';
			}
			if ( isset( $result['model'] ) && ! empty( $result['model'] ) ) {
				$model  = sanitize_text_field( $result['model'] );
				$source = 'result';
			}
		}

		// Priority 2: Check context for token usage data and provider/model.
		if ( ! $token_usage && isset( $context['token_usage'] ) && is_array( $context['token_usage'] ) ) {
			$token_usage = $context['token_usage'];
		}

		// If no usage data found, we can't track anything.
		if ( ! $token_usage ) {
			return;
		}

		// Extract user ID from context.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		// Priority 3: Extract provider and model from context if not in result.
		if ( ! $provider && isset( $context['provider'] ) && ! empty( $context['provider'] ) ) {
			$provider = sanitize_text_field( $context['provider'] );
			$source   = 'context';
		}
		if ( ! $model && isset( $context['model'] ) && ! empty( $context['model'] ) ) {
			$model  = sanitize_text_field( $context['model'] );
			$source = 'context';
		}

		// Priority 4: Fall back to default settings as last resort.
		if ( ! $provider || ! $model ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( is_array( $settings ) ) {
				if ( ! $provider ) {
					$provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';
				}
				if ( ! $model ) {
					$model = isset( $settings['model'] ) ? $settings['model'] : 'gpt-4o-mini';
				}
			}
			if ( ! $source ) {
				$source = 'settings';
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

		// Record is estimated if we had to infer provider/model from settings.
		// If we got it from result or context, it's actual data.
		$is_estimated = ( 'settings' === $source );

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
		$settings       = WP_MCP_AI_Admin_Settings::get_settings();
		$retention_days = isset( $settings['token_tracking_retention_days'] ) ? absint( $settings['token_tracking_retention_days'] ) : 90;

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
			++$by_provider[ $provider ]['records'];

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
			++$by_tool[ $tool ]['records'];
		}

		return array(
			'summary'       => $cost_summary,
			'by_provider'   => $by_provider,
			'by_tool'       => $by_tool,
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

			++$results['users_processed'];

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
						++$results['records_created'];
						++$results['records_estimated'];
					} else {
						++$results['errors'];
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Migrate historical token tracking records to correct provider/model misattributions.
	 *
	 * Identifies records where tools that use specific providers (like Gemini)
	 * were incorrectly tracked with the default provider, and corrects them.
	 *
	 * @param bool $dry_run If true, only reports what would be changed without making updates.
	 * @param int  $limit   Maximum number of records to process in one batch (default: 1000).
	 * @return array Migration results with counts and details.
	 */
	public static function migrate_provider_misattributions( $dry_run = true, $limit = 1000 ) {
		global $wpdb;

		$results = array(
			'total_checked'           => 0,
			'records_updated'         => 0,
			'dry_run'                 => $dry_run,
			'updates'                 => array(),
			'total_gemini_records'    => 0,
			'correctly_attributed'    => 0,
			'total_needing_migration' => 0,
		);

		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();

		// Define tool patterns that indicate specific providers.
		// IMPORTANT: Only include tools that EXPLICITLY use Gemini (have "gemini" in tool name).
		// Tools like analyze_comment_content can use either OpenAI or Gemini based on settings,
		// so we should NOT migrate them as they might legitimately use OpenAI.
		$provider_patterns = array(
			'gemini' => array(
				'tools'  => array(
					'generate_gemini_image', // Gemini-only tool.
					'edit_gemini_image',     // Gemini-only tool.
				),
				'models' => array(
					'gemini-1.5-pro',
					'gemini-1.5-flash',
					'gemini-2.0-flash',
					'gemini-pro',
					'gemini-2.5-flash-image',
				),
			),
		);

		$gemini_tools = $provider_patterns['gemini']['tools'];
		$placeholders = implode( ', ', array_fill( 0, count( $gemini_tools ), '%s' ) );

		// First, get total count of ALL Gemini tool records to provide context.
		$count_query = "
			SELECT COUNT(*) as total
			FROM {$table_name}
			WHERE tool IN ({$placeholders})
		";

		$count_prepare_args = $gemini_tools;
		$count_prepared_query = $wpdb->prepare( $count_query, $count_prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total_gemini_records = $wpdb->get_var( $count_prepared_query );
		$results['total_gemini_records'] = intval( $total_gemini_records );

		// Count misattributed records (all records that need migration, not just the limited batch).
		$misattributed_count_query = "
			SELECT COUNT(*) as total
			FROM {$table_name}
			WHERE tool IN ({$placeholders})
			AND provider != 'gemini'
		";

		$misattributed_count_prepared = $wpdb->prepare( $misattributed_count_query, $gemini_tools ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total_misattributed = $wpdb->get_var( $misattributed_count_prepared );
		$total_misattributed = intval( $total_misattributed );

		// Calculate correctly attributed records.
		$results['correctly_attributed'] = $results['total_gemini_records'] - $total_misattributed;

		// Find records that likely have provider misattributions (limited batch for processing).
		// We look for Gemini tools that are NOT already marked with gemini provider.
		$query = "
			SELECT id, user_id, tool, provider, model, input_tokens, output_tokens, cost_usd, is_estimated
			FROM {$table_name}
			WHERE tool IN ({$placeholders})
			AND provider != 'gemini'
			ORDER BY id ASC
			LIMIT %d
		";

		$prepare_args   = array_merge( $gemini_tools, array( $limit ) );
		$prepared_query = $wpdb->prepare( $query, $prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$records = $wpdb->get_results( $prepared_query, ARRAY_A );

		$results['total_checked'] = count( $records );
		$results['total_needing_migration'] = $total_misattributed;

		foreach ( $records as $record ) {
			$record_id     = intval( $record['id'] );
			$tool          = $record['tool'];
			$old_provider  = $record['provider'];
			$old_model     = $record['model'];
			$input_tokens  = intval( $record['input_tokens'] );
			$output_tokens = intval( $record['output_tokens'] );

			// Determine the correct provider and model.
			$new_provider = 'gemini';
			$new_model    = self::infer_gemini_model_from_tool( $tool, $old_model );

			// Recalculate cost with correct provider/model.
			$new_cost = 0.0;
			if ( class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
				$new_cost = WP_MCP_AI_Cost_Calculator::calculate_cost(
					$new_provider,
					$new_model,
					$input_tokens,
					$output_tokens
				);
			}

			// Track the change.
			$results['updates'][] = array(
				'id'           => $record_id,
				'tool'         => $tool,
				'old_provider' => $old_provider,
				'new_provider' => $new_provider,
				'old_model'    => $old_model,
				'new_model'    => $new_model,
				'old_cost'     => floatval( $record['cost_usd'] ),
				'new_cost'     => $new_cost,
			);

			// Apply update if not dry run.
			if ( ! $dry_run ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					$table_name,
					array(
						'provider'     => $new_provider,
						'model'        => $new_model,
						'cost_usd'     => $new_cost,
						'is_estimated' => 0, // Now it's actual, not estimated.
					),
					array( 'id' => $record_id ),
					array( '%s', '%s', '%f', '%d' ),
					array( '%d' )
				);

				if ( false !== $updated ) {
					++$results['records_updated'];
				}
			}
		}

		// If dry run, records_updated should equal updates found.
		if ( $dry_run ) {
			$results['records_updated'] = count( $results['updates'] );
		}

		return $results;
	}

	/**
	 * Infer the correct Gemini model based on tool name and old model.
	 *
	 * @param string $tool      Tool name.
	 * @param string $old_model Previous model name (might be OpenAI model).
	 * @return string Inferred Gemini model.
	 */
	private static function infer_gemini_model_from_tool( $tool, $old_model ) {
		// Image-related Gemini tools use the Gemini image model.
		if ( in_array( $tool, array( 'generate_gemini_image', 'edit_gemini_image' ), true ) ) {
			return 'gemini-2.5-flash-image';
		}

		// Default to flash if the tool is unknown but assumed to be Gemini.
		return 'gemini-1.5-flash';
	}
}
