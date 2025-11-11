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
	 * Tier identifiers.
	 */
	const TIER_FREE       = 'free';
	const TIER_PRO        = 'pro';
	const TIER_ENTERPRISE = 'enterprise';

	/**
	 * Tier-based token limits (per user, per 24 hours).
	 *
	 * @var array
	 */
	protected static $tier_limits = array(
		self::TIER_FREE       => 50000,   // 50k tokens/day.
		self::TIER_PRO        => 200000,  // 200k tokens/day.
		self::TIER_ENTERPRISE => 1000000, // 1M tokens/day.
	);

	/**
	 * Role-based default tier assignments.
	 *
	 * @var array
	 */
	protected static $role_tier_map = array(
		'subscriber'    => self::TIER_FREE,
		'contributor'   => self::TIER_FREE,
		'author'        => self::TIER_PRO,
		'editor'        => self::TIER_PRO,
		'administrator' => self::TIER_ENTERPRISE,
	);

	/**
	 * Tool-specific limit multipliers.
	 *
	 * @var array
	 */
	protected static $tool_multipliers = array(
		'run_crawl4ai_job'       => 2.0,
		'search_content'         => 1.5,
		'web_search'             => 1.5,
		'submit_document_prompt' => 2.0,
	);

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

		// Register hourly cron job for forecast checks.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_hourly_forecast_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_hourly_forecast_check' );
		}

		// Hook cron job to alert checking.
		add_action( 'wp_mcp_ai_hourly_forecast_check', array( __CLASS__, 'check_and_send_alerts' ) );

		// Clean up cron on plugin deactivation.
		register_deactivation_hook( WP_MCP_AI_PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Clean up on plugin deactivation.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_hourly_forecast_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_hourly_forecast_check' );
		}
	}

	/**
	 * Get user's token limit tier.
	 *
	 * @param int $user_id User ID.
	 * @return string Tier identifier.
	 */
	public static function get_user_tier( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			/**
			 * Filter the default tier for guests.
			 *
			 * @since 1.0.0
			 *
			 * @param string $tier Default tier for non-logged-in users.
			 */
			return apply_filters( 'wp_mcp_ai_default_guest_tier', self::TIER_FREE );
		}

		// Check user meta for custom tier.
		$custom_tier = get_user_meta( $user_id, '_wp_mcp_ai_token_tier', true );

		if ( $custom_tier && isset( self::$tier_limits[ $custom_tier ] ) ) {
			// Check if tier has expired.
			$tier_expires = get_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', true );

			if ( $tier_expires && is_numeric( $tier_expires ) ) {
				if ( $tier_expires < current_time( 'timestamp', true ) ) {
					// Tier has expired, delete custom tier and proceed to role-based detection.
					delete_user_meta( $user_id, '_wp_mcp_ai_token_tier' );
					delete_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires' );
				} else {
					// Tier is still valid.
					return $custom_tier;
				}
			} else {
				// No expiration, tier is permanent.
				return $custom_tier;
			}
		}

		// Determine tier based on user role.
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			/**
			 * Filter the default tier for invalid users.
			 *
			 * @since 1.0.0
			 *
			 * @param string $tier    Default tier.
			 * @param int    $user_id User ID.
			 */
			return apply_filters( 'wp_mcp_ai_default_invalid_user_tier', self::TIER_FREE, $user_id );
		}

		foreach ( $user->roles as $role ) {
			if ( isset( self::$role_tier_map[ $role ] ) ) {
				/**
				 * Filter the tier for a user based on their role.
				 *
				 * @since 1.0.0
				 *
				 * @param string $tier    Tier identifier.
				 * @param int    $user_id User ID.
				 * @param string $role    User role.
				 */
				return apply_filters( 'wp_mcp_ai_user_tier_by_role', self::$role_tier_map[ $role ], $user_id, $role );
			}
		}

		/**
		 * Filter the default tier for users without a matching role.
		 *
		 * @since 1.0.0
		 *
		 * @param string $tier    Default tier.
		 * @param int    $user_id User ID.
		 */
		return apply_filters( 'wp_mcp_ai_default_user_tier', self::TIER_FREE, $user_id );
	}

	/**
	 * Get tier-based token limit for a user and tool.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @return int Token limit.
	 */
	public static function get_user_tool_limit( $user_id, $tool_slug ) {
		$tier = self::get_user_tier( $user_id );

		$base_limit = isset( self::$tier_limits[ $tier ] ) ? self::$tier_limits[ $tier ] : self::DEFAULT_GENERAL_LIMIT;

		// Apply tool-specific multipliers.
		$multiplier = self::get_tool_multiplier( $tool_slug );
		$limit      = (int) ( $base_limit * $multiplier );

		/**
		 * Filter the token limit for a user and tool.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $limit     Token limit.
		 * @param int    $user_id   User ID.
		 * @param string $tool_slug Tool identifier.
		 * @param string $tier      User's tier.
		 */
		return apply_filters( 'wp_mcp_ai_user_tool_limit', $limit, $user_id, $tool_slug, $tier );
	}

	/**
	 * Get tool-specific limit multiplier.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return float Multiplier.
	 */
	protected static function get_tool_multiplier( $tool_slug ) {
		$tool_slug = sanitize_key( $tool_slug );

		if ( isset( self::$tool_multipliers[ $tool_slug ] ) ) {
			return (float) self::$tool_multipliers[ $tool_slug ];
		}

		/**
		 * Filter the tool multiplier for token limits.
		 *
		 * @since 1.0.0
		 *
		 * @param float  $multiplier Default multiplier (1.0).
		 * @param string $tool_slug  Tool identifier.
		 */
		return apply_filters( 'wp_mcp_ai_tool_limit_multiplier', 1.0, $tool_slug );
	}

	/**
	 * Set custom tier for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $tier    Tier identifier.
	 * @param int    $expires Optional expiration timestamp.
	 * @return bool True on success.
	 */
	public static function set_user_tier( $user_id, $tier, $expires = 0 ) {
		$user_id = absint( $user_id );
		$tier    = sanitize_key( $tier );
		$expires = absint( $expires );

		if ( ! $user_id || ! isset( self::$tier_limits[ $tier ] ) ) {
			return false;
		}

		$old_tier = self::get_user_tier( $user_id );

		update_user_meta( $user_id, '_wp_mcp_ai_token_tier', $tier );

		if ( $expires > 0 ) {
			update_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', $expires );
		} else {
			delete_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires' );
		}

		/**
		 * Fires after a user's tier has been changed.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $user_id  User ID.
		 * @param string $old_tier Previous tier.
		 * @param string $new_tier New tier.
		 * @param int    $expires  Expiration timestamp (0 for no expiration).
		 */
		do_action( 'wp_mcp_ai_user_tier_changed', $user_id, $old_tier, $tier, $expires );

		return true;
	}

	/**
	 * Get token limit for a specific tool (backward compatibility).
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

		$limits                = self::get_all_limits();
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
				'hourly'       => array(),
			);
		}

		$timestamp = current_time( 'mysql', true );
		$date_key  = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );
		$hour_key  = gmdate( 'Y-m-d-H', current_time( 'timestamp', true ) );

		// Update totals.
		$usage[ $tool_slug ]['total_tokens'] = isset( $usage[ $tool_slug ]['total_tokens'] ) ? (int) $usage[ $tool_slug ]['total_tokens'] : 0;
		$usage[ $tool_slug ]['total_tokens'] += $tokens;

		$usage[ $tool_slug ]['requests'] = isset( $usage[ $tool_slug ]['requests'] ) ? (int) $usage[ $tool_slug ]['requests'] : 0;
		$usage[ $tool_slug ]['requests']++;

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

		// Update hourly usage.
		if ( ! isset( $usage[ $tool_slug ]['hourly'] ) || ! is_array( $usage[ $tool_slug ]['hourly'] ) ) {
			$usage[ $tool_slug ]['hourly'] = array();
		}

		if ( ! isset( $usage[ $tool_slug ]['hourly'][ $hour_key ] ) ) {
			$usage[ $tool_slug ]['hourly'][ $hour_key ] = 0;
		}

		$usage[ $tool_slug ]['hourly'][ $hour_key ] = (int) $usage[ $tool_slug ]['hourly'][ $hour_key ] + $tokens;

		// Clean up old daily entries (keep only last 30 days).
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp', true ) ) );
		foreach ( $usage[ $tool_slug ]['daily'] as $date => $count ) {
			if ( $date < $cutoff_date ) {
				unset( $usage[ $tool_slug ]['daily'][ $date ] );
			}
		}

		// Clean up old hourly entries (keep only last 7 days).
		$cutoff_hour = gmdate( 'Y-m-d-H', strtotime( '-7 days', current_time( 'timestamp', true ) ) );
		foreach ( $usage[ $tool_slug ]['hourly'] as $hour => $count ) {
			if ( $hour < $cutoff_hour ) {
				unset( $usage[ $tool_slug ]['hourly'][ $hour ] );
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

		// Get tier-based limit for this user and tool.
		$limit       = self::get_user_tool_limit( $user_id, $tool_slug );
		$daily_usage = self::get_user_tool_daily_usage( $user_id, $tool_slug );

		if ( $daily_usage >= $limit ) {
			$reset_time = self::get_daily_reset_time();
			$tier       = self::get_user_tier( $user_id );

			WP_MCP_AI_Logger::log_event(
				'tool_token_limit_exceeded',
				'User exceeded daily token limit for tool.',
				array(
					'user_id'     => $user_id,
					'tool_slug'   => $tool_slug,
					'usage'       => $daily_usage,
					'limit'       => $limit,
					'tier'        => $tier,
					'reset_time'  => $reset_time,
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
			 * @param string $tier       User's tier.
			 */
			do_action( 'wp_mcp_ai_tool_token_limit_exceeded', $user_id, $tool_slug, $daily_usage, $limit, $reset_time, $tier );

			// Orchestration Layer: Enforce budget constraint by throwing exception.
			/**
			 * Filter whether to enforce tool token budget limits.
			 *
			 * @param bool   $enforce    Whether to enforce limits. Default true.
			 * @param string $tool_slug  Tool identifier.
			 * @param int    $user_id    User ID.
			 * @param int    $usage      Current usage.
			 * @param int    $limit      Token limit.
			 * @param string $tier       User's tier.
			 */
			$enforce = apply_filters( 'wp_mcp_ai_enforce_tool_token_limits', true, $tool_slug, $user_id, $daily_usage, $limit, $tier );

			if ( $enforce ) {
				throw new Exception(
					sprintf(
						/* translators: 1: Tool name, 2: Daily limit, 3: Current tier, 4: Reset time */
						__( 'Daily token limit exceeded for tool "%1$s". Your %3$s tier limit is %2$d tokens per day. Limit resets at %4$s. Consider upgrading to a higher tier for increased limits.', 'wp-mcp-ai' ),
						$tool_slug,
						$limit,
						$tier,
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

		$date_key = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );

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
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp', true ) ) );

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
			$usage  = self::get_user_tool_usage( $user_id );
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
		$tomorrow = strtotime( 'tomorrow midnight', current_time( 'timestamp', true ) );
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
				'tool_slug'         => $tool_slug,
				'original_tokens'   => $result_tokens,
				'max_tokens'        => $max_result_tokens,
				'tier'              => $tier,
				'truncation_ratio'  => round( $max_result_tokens / $result_tokens, 2 ),
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
			'run_crawl4ai_job'      => true,
			'search_content'        => true,
			'get_recent_posts'      => true,
			'web_search'            => true,
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
	 * Get user's hourly usage for a tool.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param string $hour_key  Hour key (YYYY-MM-DD-HH format).
	 * @return int Tokens used in that hour.
	 */
	public static function get_user_tool_hourly_usage( $user_id, $tool_slug, $hour_key = '' ) {
		if ( empty( $hour_key ) ) {
			$hour_key = gmdate( 'Y-m-d-H', current_time( 'timestamp', true ) );
		}

		$usage = self::get_user_tool_usage( $user_id );

		if ( ! isset( $usage[ $tool_slug ]['hourly'][ $hour_key ] ) ) {
			return 0;
		}

		return (int) $usage[ $tool_slug ]['hourly'][ $hour_key ];
	}

	/**
	 * Get peak usage hour for a user and tool.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param int    $days      Number of days to analyze.
	 * @return array|null Peak hour data (hour, tokens, timestamp) or null.
	 */
	public static function get_peak_usage_hour( $user_id, $tool_slug, $days = 7 ) {
		$usage = self::get_user_tool_usage( $user_id );

		if ( ! isset( $usage[ $tool_slug ]['hourly'] ) || empty( $usage[ $tool_slug ]['hourly'] ) ) {
			return null;
		}

		$cutoff = gmdate( 'Y-m-d-H', strtotime( "-{$days} days", current_time( 'timestamp', true ) ) );
		$hourly = array_filter(
			$usage[ $tool_slug ]['hourly'],
			function ( $key ) use ( $cutoff ) {
				return $key >= $cutoff;
			},
			ARRAY_FILTER_USE_KEY
		);

		if ( empty( $hourly ) ) {
			return null;
		}

		arsort( $hourly );
		$peak_hour = key( $hourly );

		return array(
			'hour'      => $peak_hour,
			'tokens'    => $hourly[ $peak_hour ],
			'timestamp' => strtotime( $peak_hour . ':00:00' ),
		);
	}

	/**
	 * Forecast when user will exhaust daily token limit.
	 *
	 * Uses linear regression on last 7 days of hourly usage.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @return array|null Forecast data or null if insufficient data.
	 */
	public static function forecast_limit_exhaustion( $user_id, $tool_slug ) {
		$usage = self::get_user_tool_usage( $user_id );

		if ( ! isset( $usage[ $tool_slug ]['hourly'] ) || empty( $usage[ $tool_slug ]['hourly'] ) ) {
			return null;
		}

		// Get last 7 days of hourly data.
		$cutoff = gmdate( 'Y-m-d-H', strtotime( '-7 days', current_time( 'timestamp', true ) ) );
		$hourly = array_filter(
			$usage[ $tool_slug ]['hourly'],
			function ( $key ) use ( $cutoff ) {
				return $key >= $cutoff;
			},
			ARRAY_FILTER_USE_KEY
		);

		if ( count( $hourly ) < 24 ) {
			return null; // Insufficient data (need at least 24 hours).
		}

		// Calculate average hourly usage.
		$avg_hourly = array_sum( $hourly ) / count( $hourly );

		// Get current daily usage.
		$today_key   = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );
		$today_usage = isset( $usage[ $tool_slug ]['daily'][ $today_key ] ) ? (int) $usage[ $tool_slug ]['daily'][ $today_key ] : 0;

		// Get user's daily limit.
		$limit = self::get_user_tool_limit( $user_id, $tool_slug );

		// Calculate remaining tokens and hours.
		$remaining_tokens   = $limit - $today_usage;
		$hours_until_reset  = self::get_hours_until_daily_reset();

		// Forecast.
		$projected_usage = $today_usage + ( $avg_hourly * $hours_until_reset );

		return array(
			'will_exceed'       => $projected_usage > $limit,
			'current_usage'     => $today_usage,
			'projected_usage'   => (int) $projected_usage,
			'limit'             => $limit,
			'remaining_tokens'  => $remaining_tokens,
			'hours_until_reset' => $hours_until_reset,
			'avg_hourly_usage'  => (int) $avg_hourly,
			'confidence'        => self::calculate_forecast_confidence( $hourly ),
		);
	}

	/**
	 * Calculate confidence level of forecast (0-100%).
	 *
	 * Based on data consistency and recency.
	 *
	 * @param array $hourly_data Hourly usage data.
	 * @return int Confidence percentage.
	 */
	protected static function calculate_forecast_confidence( $hourly_data ) {
		$hours = count( $hourly_data );

		if ( $hours < 24 ) {
			return 30; // Low confidence with <1 day of data.
		}

		if ( $hours >= 168 ) {
			return 90; // High confidence with 7 days of data.
		}

		// Linear interpolation between 30% and 90%.
		return (int) ( 30 + ( ( $hours - 24 ) / 144 ) * 60 );
	}

	/**
	 * Get hours until daily limit resets.
	 *
	 * @return float Hours remaining.
	 */
	protected static function get_hours_until_daily_reset() {
		$now      = current_time( 'timestamp', true );
		$tomorrow = strtotime( 'tomorrow midnight', $now );
		return ( $tomorrow - $now ) / 3600;
	}

	/**
	 * Check if user should be alerted about approaching limit.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @return bool True if alert should be sent.
	 */
	public static function should_send_limit_alert( $user_id, $tool_slug ) {
		$forecast = self::forecast_limit_exhaustion( $user_id, $tool_slug );

		if ( ! $forecast || ! $forecast['will_exceed'] ) {
			return false;
		}

		// Only alert if confidence is high enough.
		if ( $forecast['confidence'] < 70 ) {
			return false;
		}

		// Check if alert was already sent today.
		$alert_key  = "wp_mcp_ai_limit_alert_{$user_id}_{$tool_slug}";
		$last_alert = get_transient( $alert_key );

		if ( false !== $last_alert ) {
			return false; // Already alerted.
		}

		// Set transient to prevent duplicate alerts.
		set_transient( $alert_key, time(), DAY_IN_SECONDS );

		return true;
	}

	/**
	 * Send limit alert to user.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param array  $forecast  Forecast data.
	 */
	public static function send_limit_alert( $user_id, $tool_slug, $forecast ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$tier = self::get_user_tier( $user_id );

		$subject = __( 'Token Limit Alert - Action Recommended', 'wp-mcp-ai' );

		$message = sprintf(
			/* translators: 1: User name, 2: Tool name, 3: Current usage, 4: Projected usage, 5: Limit, 6: Current tier */
			__(
				"Hi %1\$s,\n\n" .
				"Based on your recent usage patterns, you're projected to exceed your daily token limit for the '%2\$s' tool.\n\n" .
				"Current Usage: %3\$s tokens\n" .
				"Projected Usage: %4\$s tokens\n" .
				"Daily Limit: %5\$s tokens\n" .
				"Current Tier: %6\$s\n\n" .
				"To avoid service interruption, consider:\n" .
				"- Optimizing your queries\n" .
				"- Upgrading to a higher tier\n" .
				"- Spreading usage throughout the day\n\n" .
				"Thank you,\n" .
				"WP oOS Team",
				'wp-mcp-ai'
			),
			$user->display_name,
			$tool_slug,
			number_format_i18n( $forecast['current_usage'] ),
			number_format_i18n( $forecast['projected_usage'] ),
			number_format_i18n( $forecast['limit'] ),
			$tier
		);

		wp_mail( $user->user_email, $subject, $message );

		/**
		 * Fires after limit alert is sent.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $user_id   User ID.
		 * @param string $tool_slug Tool identifier.
		 * @param array  $forecast  Forecast data.
		 */
		do_action( 'wp_mcp_ai_limit_alert_sent', $user_id, $tool_slug, $forecast );

		WP_MCP_AI_Logger::log_event(
			'token_limit_alert_sent',
			'User alerted about approaching token limit.',
			array(
				'user_id'   => $user_id,
				'tool_slug' => $tool_slug,
				'forecast'  => $forecast,
			)
		);
	}

	/**
	 * Check and send alerts for all users with approaching limits.
	 *
	 * This method is designed to be called by a cron job.
	 */
	public static function check_and_send_alerts() {
		global $wpdb;

		$meta_key = self::USAGE_META_KEY;

		// Get all users with usage data.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		if ( empty( $user_ids ) ) {
			return;
		}

		$alerts_sent = 0;

		foreach ( $user_ids as $user_id ) {
			$usage = self::get_user_tool_usage( $user_id );

			foreach ( $usage as $tool_slug => $tool_data ) {
				if ( self::should_send_limit_alert( $user_id, $tool_slug ) ) {
					$forecast = self::forecast_limit_exhaustion( $user_id, $tool_slug );
					if ( $forecast ) {
						self::send_limit_alert( $user_id, $tool_slug, $forecast );
						++$alerts_sent;
					}
				}
			}
		}

		if ( $alerts_sent > 0 ) {
			WP_MCP_AI_Logger::log_event(
				'token_limit_alerts_batch',
				'Sent batch of token limit alerts.',
				array( 'alerts_sent' => $alerts_sent )
			);
		}
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
				'total_users'   => 0,
				'total_tokens'  => 0,
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

		$total_users   = 0;
		$total_tokens  = 0;
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

	/**
	 * Bulk assign tier to multiple users.
	 *
	 * @param array  $user_ids Array of user IDs.
	 * @param string $new_tier New tier to assign.
	 * @param string $expiry   Optional expiry date (YYYY-MM-DD).
	 * @return array Results (success/failure counts).
	 */
	public static function bulk_set_user_tiers( $user_ids, $new_tier, $expiry = '' ) {
		$results = array(
			'success' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			$results['errors'][] = __( 'Insufficient permissions.', 'wp-mcp-ai' );
			return $results;
		}

		if ( ! isset( self::$tier_limits[ $new_tier ] ) ) {
			$results['errors'][] = __( 'Invalid tier specified.', 'wp-mcp-ai' );
			return $results;
		}

		$expiry_timestamp = 0;
		if ( ! empty( $expiry ) ) {
			$expiry_timestamp = strtotime( $expiry . ' 23:59:59' );
			if ( ! $expiry_timestamp ) {
				$results['errors'][] = __( 'Invalid expiry date format.', 'wp-mcp-ai' );
				return $results;
			}
		}

		foreach ( $user_ids as $user_id ) {
			$user_id = absint( $user_id );

			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				++$results['failed'];
				continue;
			}

			if ( self::set_user_tier( $user_id, $new_tier, $expiry_timestamp ) ) {
				++$results['success'];
			} else {
				++$results['failed'];
			}
		}

		WP_MCP_AI_Logger::log_event(
			'bulk_tier_update',
			'Administrator performed bulk tier update.',
			array(
				'user_count' => count( $user_ids ),
				'new_tier'   => $new_tier,
				'expiry'     => $expiry,
				'results'    => $results,
			)
		);

		return $results;
	}

	/**
	 * Migrate existing users to tiered system.
	 *
	 * Assigns tiers based on user roles.
	 *
	 * @return array Migration results.
	 */
	public static function migrate_to_tiered_limits() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ),
			);
		}

		// Check if migration has already been performed.
		if ( get_option( 'wp_mcp_ai_tiered_limits_migrated' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Migration has already been performed.', 'wp-mcp-ai' ),
			);
		}

		// Get all users with usage data.
		$users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::USAGE_META_KEY
			)
		);

		$migrated = 0;

		foreach ( $users as $row ) {
			$user_id = absint( $row->user_id );
			$user    = get_userdata( $user_id );

			if ( ! $user ) {
				continue;
			}

			// Skip if user already has a custom tier.
			if ( get_user_meta( $user_id, '_wp_mcp_ai_token_tier', true ) ) {
				continue;
			}

			// Assign tier based on role.
			$tier = self::TIER_FREE;

			foreach ( $user->roles as $role ) {
				if ( isset( self::$role_tier_map[ $role ] ) ) {
					$tier = self::$role_tier_map[ $role ];
					break;
				}
			}

			update_user_meta( $user_id, '_wp_mcp_ai_token_tier', $tier );
			++$migrated;
		}

		update_option( 'wp_mcp_ai_tiered_limits_migrated', current_time( 'timestamp', true ) );

		WP_MCP_AI_Logger::log_event(
			'tiered_limits_migration',
			'Migrated users to tiered limit system.',
			array( 'users_migrated' => $migrated )
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: Number of users migrated */
				__( 'Successfully migrated %d users to the tiered limit system.', 'wp-mcp-ai' ),
				$migrated
			),
			'count'   => $migrated,
		);
	}
}
