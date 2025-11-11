<?php
/**
 * Manages per-tool token usage limits and tracking.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages token limits and usage tracking at the tool level.
 *
 * This class provides:
 * - Configurable token limits per tool (e.g., crawl4ai vs general tools)
 * - Tracking of token usage per tool per user
 * - Flagging/limiting when tool-specific limits are exceeded
 */
class WP_MCP_AI_Tool_Token_Limits {

	/**
	 * Option name for storing tool token limits configuration.
	 */
	const LIMITS_OPTION = 'wp_mcp_ai_tool_token_limits';

	/**
	 * User meta key for storing per-tool token usage.
	 */
	const USAGE_META_KEY = '_wp_mcp_ai_tool_token_usage';

	/**
	 * Default token limit for general tools (per user, per 24 hours).
	 */
	const DEFAULT_GENERAL_LIMIT = 100000;

	/**
	 * Default token limit for crawl4ai tool (per user, per 24 hours).
	 */
	const DEFAULT_CRAWL4AI_LIMIT = 200000;

	/**
	 * Bootstrap the tool token limits system.
	 */
	public static function init() {
		// Clean up expired usage data periodically.
		add_action( 'wp_mcp_ai_daily_cleanup', array( __CLASS__, 'cleanup_expired_usage' ) );

		// Hook into usage tracking to record per-tool usage.
		add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'record_tool_usage' ), 10, 4 );

		// Hook into before tool execution to check limits.
		add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'check_tool_limit' ), 5, 3 );
	}

	/**
	 * Get token limit for a specific tool.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return int Token limit.
	 */
	public static function get_tool_limit( $tool_slug ) {
		$limits = self::get_all_limits();

		if ( isset( $limits[ $tool_slug ] ) ) {
			return max( 0, absint( $limits[ $tool_slug ] ) );
		}

		// Default limits for known tools.
		if ( 'run_crawl4ai_job' === $tool_slug ) {
			return self::DEFAULT_CRAWL4AI_LIMIT;
		}

		return self::DEFAULT_GENERAL_LIMIT;
	}

	/**
	 * Set token limit for a specific tool.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param int    $limit     Token limit.
	 * @return bool True on success.
	 */
	public static function set_tool_limit( $tool_slug, $limit ) {
		$tool_slug = sanitize_key( $tool_slug );
		$limit     = max( 0, absint( $limit ) );

		if ( '' === $tool_slug ) {
			return false;
		}

		$limits               = self::get_all_limits();
		$limits[ $tool_slug ] = $limit;

		return update_option( self::LIMITS_OPTION, $limits, false );
	}

	/**
	 * Get all configured tool token limits.
	 *
	 * @return array Array of tool_slug => limit pairs.
	 */
	public static function get_all_limits() {
		$limits = get_option( self::LIMITS_OPTION, array() );

		if ( ! is_array( $limits ) ) {
			$limits = array();
		}

		return $limits;
	}

	/**
	 * Record token usage for a tool execution.
	 *
	 * Hooks into wp_mcp_ai_after_tool_execution.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @param mixed  $result    Tool result.
	 */
	public static function record_tool_usage( $tool_slug, $arguments, $context, $result ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id ) {
			return;
		}

		// Estimate tokens used by the tool result.
		$tokens = self::estimate_tokens( $result );

		if ( $tokens <= 0 ) {
			return;
		}

		$usage = self::get_user_tool_usage( $user_id );

		// Initialize tool entry if not exists.
		if ( ! isset( $usage[ $tool_slug ] ) || ! is_array( $usage[ $tool_slug ] ) ) {
			$usage[ $tool_slug ] = array(
				'total_tokens' => 0,
				'requests'     => 0,
				'first_used'   => '',
				'last_used'    => '',
				'daily'        => array(),
			);
		}

		$timestamp = current_time( 'mysql', true );
		$date_key  = gmdate( 'Y-m-d', time() );

		// Update totals.
		$usage[ $tool_slug ]['total_tokens']  = isset( $usage[ $tool_slug ]['total_tokens'] ) ? (int) $usage[ $tool_slug ]['total_tokens'] : 0;
		$usage[ $tool_slug ]['total_tokens'] += $tokens;

		$usage[ $tool_slug ]['requests'] = isset( $usage[ $tool_slug ]['requests'] ) ? (int) $usage[ $tool_slug ]['requests'] : 0;
		++$usage[ $tool_slug ]['requests'];

		$usage[ $tool_slug ]['last_used'] = $timestamp;

		if ( empty( $usage[ $tool_slug ]['first_used'] ) ) {
			$usage[ $tool_slug ]['first_used'] = $timestamp;
		}

		// Update daily usage.
		if ( ! isset( $usage[ $tool_slug ]['daily'] ) || ! is_array( $usage[ $tool_slug ]['daily'] ) ) {
			$usage[ $tool_slug ]['daily'] = array();
		}

		if ( ! isset( $usage[ $tool_slug ]['daily'][ $date_key ] ) ) {
			$usage[ $tool_slug ]['daily'][ $date_key ] = 0;
		}

		$usage[ $tool_slug ]['daily'][ $date_key ] = (int) $usage[ $tool_slug ]['daily'][ $date_key ] + $tokens;

		// Clean up old daily entries (keep only last 30 days).
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days', time() ) );
		foreach ( $usage[ $tool_slug ]['daily'] as $date => $count ) {
			if ( $date < $cutoff_date ) {
				unset( $usage[ $tool_slug ]['daily'][ $date ] );
			}
		}

		update_user_meta( $user_id, self::USAGE_META_KEY, $usage );

		/**
		 * Fires after tool token usage has been recorded.
		 *
		 * @param int    $user_id   User ID.
		 * @param string $tool_slug Tool identifier.
		 * @param int    $tokens    Tokens used.
		 * @param array  $context   Execution context.
		 */
		do_action( 'wp_mcp_ai_tool_token_usage_recorded', $user_id, $tool_slug, $tokens, $context );
	}

	/**
	 * Check if user has exceeded their token limit for a tool.
	 *
	 * Hooks into wp_mcp_ai_before_tool_execution.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @throws Exception When budget is exceeded and enforcement is enabled.
	 */
	public static function check_tool_limit( $tool_slug, $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id ) {
			return;
		}

		// Check if user has exceeded their daily limit for this tool.
		$limit       = self::get_tool_limit( $tool_slug );
		$daily_usage = self::get_user_tool_daily_usage( $user_id, $tool_slug );

		if ( $daily_usage >= $limit ) {
			$reset_time = self::get_daily_reset_time();

			WP_MCP_AI_Logger::log_event(
				'tool_token_limit_exceeded',
				'User exceeded daily token limit for tool.',
				array(
					'user_id'    => $user_id,
					'tool_slug'  => $tool_slug,
					'usage'      => $daily_usage,
					'limit'      => $limit,
					'reset_time' => $reset_time,
				)
			);

			/**
			 * Fires when a user exceeds their tool token limit.
			 *
			 * @param int    $user_id    User ID.
			 * @param string $tool_slug  Tool identifier.
			 * @param int    $usage      Current usage.
			 * @param int    $limit      Token limit.
			 * @param string $reset_time Time when limit resets.
			 */
			do_action( 'wp_mcp_ai_tool_token_limit_exceeded', $user_id, $tool_slug, $daily_usage, $limit, $reset_time );

			// Orchestration Layer: Enforce budget constraint by throwing exception.
			/**
			 * Filter whether to enforce tool token budget limits.
			 *
			 * @param bool   $enforce    Whether to enforce limits. Default true.
			 * @param string $tool_slug  Tool identifier.
			 * @param int    $user_id    User ID.
			 * @param int    $usage      Current usage.
			 * @param int    $limit      Token limit.
			 */
			$enforce = apply_filters( 'wp_mcp_ai_enforce_tool_token_limits', true, $tool_slug, $user_id, $daily_usage, $limit );

			if ( $enforce ) {
				throw new Exception(
					sprintf(
						/* translators: 1: Tool name, 2: Daily limit, 3: Reset time */
						__( 'Daily token limit exceeded for tool "%1$s". Your limit is %2$d tokens per day. Limit resets at %3$s.', 'wp-mcp-ai' ),
						$tool_slug,
						$limit,
						$reset_time
					)
				);
			}
		}
	}

	/**
	 * Get user's token usage for a specific tool today.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @return int Tokens used today.
	 */
	public static function get_user_tool_daily_usage( $user_id, $tool_slug ) {
		$usage = self::get_user_tool_usage( $user_id );

		if ( ! isset( $usage[ $tool_slug ]['daily'] ) || ! is_array( $usage[ $tool_slug ]['daily'] ) ) {
			return 0;
		}

		$date_key = gmdate( 'Y-m-d', time() );

		return isset( $usage[ $tool_slug ]['daily'][ $date_key ] ) ? (int) $usage[ $tool_slug ]['daily'][ $date_key ] : 0;
	}

	/**
	 * Get user's total token usage for all tools.
	 *
	 * @param int $user_id User ID.
	 * @return array Array of tool_slug => usage_data pairs.
	 */
	public static function get_user_tool_usage( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$usage = get_user_meta( $user_id, self::USAGE_META_KEY, true );

		return is_array( $usage ) ? $usage : array();
	}

	/**
	 * Reset user's tool token usage for a specific tool.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier. If empty, resets all tools.
	 * @return bool True on success.
	 */
	public static function reset_user_tool_usage( $user_id, $tool_slug = '' ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		if ( '' === $tool_slug ) {
			// Reset all tool usage.
			return delete_user_meta( $user_id, self::USAGE_META_KEY );
		}

		$tool_slug = sanitize_key( $tool_slug );
		if ( '' === $tool_slug ) {
			return false;
		}

		$usage = self::get_user_tool_usage( $user_id );

		if ( isset( $usage[ $tool_slug ] ) ) {
			unset( $usage[ $tool_slug ] );
			return update_user_meta( $user_id, self::USAGE_META_KEY, $usage );
		}

		return true;
	}

	/**
	 * Clean up expired usage data for all users.
	 *
	 * Removes daily usage entries older than 30 days.
	 */
	public static function cleanup_expired_usage() {
		global $wpdb;

		$meta_key    = self::USAGE_META_KEY;
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days', time() ) );

		// Get all users with tool usage data.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		if ( empty( $user_ids ) ) {
			return;
		}

		$cleaned = 0;

		foreach ( $user_ids as $user_id ) {
			$usage   = self::get_user_tool_usage( $user_id );
			$updated = false;

			foreach ( $usage as $tool_slug => $tool_data ) {
				if ( ! isset( $tool_data['daily'] ) || ! is_array( $tool_data['daily'] ) ) {
					continue;
				}

				foreach ( $tool_data['daily'] as $date => $count ) {
					if ( $date < $cutoff_date ) {
						unset( $usage[ $tool_slug ]['daily'][ $date ] );
						$updated = true;
					}
				}
			}

			if ( $updated ) {
				update_user_meta( $user_id, self::USAGE_META_KEY, $usage );
				++$cleaned;
			}
		}

		if ( $cleaned > 0 ) {
			WP_MCP_AI_Logger::log_event(
				'tool_usage_cleanup',
				'Cleaned expired tool usage data.',
				array( 'users_cleaned' => $cleaned )
			);
		}
	}

	/**
	 * Estimate token count for a given result.
	 *
	 * Uses a simple heuristic: ~4 characters per token.
	 *
	 * @param mixed $result Tool result to estimate.
	 * @return int Estimated token count.
	 */
	protected static function estimate_tokens( $result ) {
		if ( is_string( $result ) ) {
			return max( 1, (int) ( strlen( $result ) / 4 ) );
		}

		if ( is_array( $result ) || is_object( $result ) ) {
			$json = wp_json_encode( $result );
			return max( 1, (int) ( strlen( $json ) / 4 ) );
		}

		return 1;
	}

	/**
	 * Get the time when daily limits reset (midnight GMT).
	 *
	 * @return string Formatted time string.
	 */
	protected static function get_daily_reset_time() {
		$tomorrow = strtotime( 'tomorrow midnight', time() );
		return gmdate( 'Y-m-d H:i:s', $tomorrow );
	}

	/**
	 * Orchestration Layer: Adjust tool result to fit within budget constraints.
	 *
	 * This method predicts and adjusts the tool result size to ensure it fits
	 * within the orchestration layer's token budget, preventing API limit overruns
	 * in subsequent agentic loop iterations.
	 *
	 * @param mixed  $result    Tool execution result.
	 * @param string $tool_slug Tool identifier.
	 * @param array  $context   Execution context.
	 * @return mixed Adjusted result that fits within budget.
	 */
	public static function adjust_tool_result_for_budget( $result, $tool_slug, $context = array() ) {
		// Get the resource manager to determine workload tier.
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
		$tier         = $resource_mgr->get_workload_tier();

		// Estimate tokens in the result.
		$result_tokens = self::estimate_tokens( $result );

		// Get maximum allowed tokens for tool results based on workload tier.
		$max_result_tokens = self::get_max_tool_result_tokens( $tier, $tool_slug );

		/**
		 * Filter the maximum tokens allowed for a tool result.
		 *
		 * @param int    $max_tokens Maximum tokens for this tool result.
		 * @param string $tool_slug  Tool identifier.
		 * @param string $tier       Workload tier.
		 * @param array  $context    Execution context.
		 */
		$max_result_tokens = apply_filters( 'wp_mcp_ai_tool_result_max_tokens', $max_result_tokens, $tool_slug, $tier, $context );

		// If result is within budget, return as-is.
		if ( $result_tokens <= $max_result_tokens ) {
			return $result;
		}

		// Orchestration Layer: Predict overflow and adjust.
		WP_MCP_AI_Logger::log_event(
			'tool_result_truncated',
			'Tool result exceeded budget and was truncated by orchestration layer.',
			array(
				'tool_slug'        => $tool_slug,
				'original_tokens'  => $result_tokens,
				'max_tokens'       => $max_result_tokens,
				'tier'             => $tier,
				'truncation_ratio' => round( $max_result_tokens / $result_tokens, 2 ),
			)
		);

		// Truncate the result to fit within budget.
		return self::truncate_result( $result, $max_result_tokens );
	}

	/**
	 * Get maximum allowed tokens for tool results based on workload tier.
	 *
	 * @param string $tier      Workload tier ('low', 'medium', 'high').
	 * @param string $tool_slug Tool identifier.
	 * @return int Maximum tokens allowed.
	 */
	protected static function get_max_tool_result_tokens( $tier, $tool_slug ) {
		// Base limits by tier (conservative to leave room for conversation context).
		$tier_limits = array(
			'low'    => 500,    // Low tier: very limited.
			'medium' => 2000,   // Medium tier: moderate.
			'high'   => 8000,   // High tier: generous.
		);

		$base_limit = isset( $tier_limits[ $tier ] ) ? $tier_limits[ $tier ] : $tier_limits['medium'];

		// Special handling for known high-output tools.
		$high_output_tools = array(
			'run_crawl4ai_job'       => true,
			'search_content'         => true,
			'get_recent_posts'       => true,
			'web_search'             => true,
			'submit_document_prompt' => true,
		);

		// Allow 2x tokens for high-output tools.
		if ( isset( $high_output_tools[ $tool_slug ] ) ) {
			$base_limit *= 2;
		}

		return $base_limit;
	}

	/**
	 * Truncate a result to fit within token budget.
	 *
	 * @param mixed $result     Tool result to truncate.
	 * @param int   $max_tokens Maximum tokens allowed.
	 * @return mixed Truncated result.
	 */
	protected static function truncate_result( $result, $max_tokens ) {
		// For string results, truncate directly.
		if ( is_string( $result ) ) {
			$target_chars = $max_tokens * 4; // 4 chars per token estimate.
			if ( strlen( $result ) > $target_chars ) {
				$truncated = substr( $result, 0, $target_chars );
				return $truncated . "\n\n[... Result truncated by orchestration layer to fit within budget constraints ...]";
			}
			return $result;
		}

		// For array results, try to preserve structure.
		if ( is_array( $result ) ) {
			// If result has common fields, try intelligent truncation.
			if ( isset( $result['markdown'] ) && is_string( $result['markdown'] ) ) {
				// Markdown field is often the largest - truncate it.
				$result['markdown'] = self::truncate_result( $result['markdown'], (int) ( $max_tokens * 0.7 ) );
			}

			if ( isset( $result['html'] ) && is_string( $result['html'] ) ) {
				// HTML field can also be large - truncate it.
				$result['html'] = self::truncate_result( $result['html'], (int) ( $max_tokens * 0.7 ) );
			}

			if ( isset( $result['content'] ) && is_string( $result['content'] ) ) {
				// Content field - truncate it.
				$result['content'] = self::truncate_result( $result['content'], (int) ( $max_tokens * 0.8 ) );
			}

			// Check if truncation was enough.
			$result_tokens = self::estimate_tokens( $result );
			if ( $result_tokens > $max_tokens ) {
				// Still too large - convert to summary.
				$json = wp_json_encode( $result );
				return self::truncate_result( $json, $max_tokens );
			}

			return $result;
		}

		// For objects, convert to array and truncate.
		if ( is_object( $result ) ) {
			return self::truncate_result( (array) $result, $max_tokens );
		}

		// For other types, return as-is.
		return $result;
	}

	/**
	 * Get usage statistics for a specific tool across all users.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return array Usage statistics.
	 */
	public static function get_tool_statistics( $tool_slug ) {
		global $wpdb;

		$meta_key  = self::USAGE_META_KEY;
		$tool_slug = sanitize_key( $tool_slug );

		if ( '' === $tool_slug ) {
			return array(
				'total_users'    => 0,
				'total_tokens'   => 0,
				'total_requests' => 0,
			);
		}

		// Get all users with tool usage data.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		if ( empty( $user_ids ) ) {
			return array(
				'total_users'    => 0,
				'total_tokens'   => 0,
				'total_requests' => 0,
			);
		}

		$total_users    = 0;
		$total_tokens   = 0;
		$total_requests = 0;

		foreach ( $user_ids as $user_id ) {
			$usage = self::get_user_tool_usage( $user_id );

			if ( isset( $usage[ $tool_slug ] ) && is_array( $usage[ $tool_slug ] ) ) {
				++$total_users;

				if ( isset( $usage[ $tool_slug ]['total_tokens'] ) ) {
					$total_tokens += (int) $usage[ $tool_slug ]['total_tokens'];
				}

				if ( isset( $usage[ $tool_slug ]['requests'] ) ) {
					$total_requests += (int) $usage[ $tool_slug ]['requests'];
				}
			}
		}

		return array(
			'tool_slug'      => $tool_slug,
			'total_users'    => $total_users,
			'total_tokens'   => $total_tokens,
			'total_requests' => $total_requests,
			'limit'          => self::get_tool_limit( $tool_slug ),
		);
	}
}
