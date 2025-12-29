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
	 * Option name for storing tool model preferences.
	 */
	const MODEL_PREFERENCES_OPTION = 'wp_mcp_ai_tool_model_preferences';

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
		'run_crawl4ai_job'           => 2.0,
		'search_content'             => 1.5,
		'web_search'                 => 1.5,
		'submit_document_prompt'     => 2.0,
		// Design Professional tools - higher multipliers for resource-intensive operations.
		'generate_openai_image'      => 3.0,
		'generate_gemini_image'      => 3.0,
		'edit_gemini_image'          => 2.5,
		'generate_veo_video'         => 5.0,
		'check_video_status'         => 1.0,
		'analyze_video'              => 2.5,
		'extract_video_frames'       => 2.0,
		'generate_music'             => 3.5,
		'vision_object_localization' => 2.0,
		'vision_product_search'      => 2.0,
		'generate_image_alt_text'    => 1.5,
		'generate_image_caption'     => 1.5,
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

		// Hook into tier changes for audit logging.
		add_action( 'wp_mcp_ai_user_tier_changed', array( __CLASS__, 'log_tier_change' ), 10, 4 );

		// Clean up cron on plugin deactivation.
		register_deactivation_hook( WP_MCP_AI_PATH . 'mcp-ai-wpoos.php', array( __CLASS__, 'deactivate' ) );
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
	 * Get user's token limit tier with caching.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $use_cache Whether to use cached value. Default true.
	 * @return string Tier identifier.
	 */
	public static function get_user_tier( $user_id, $use_cache = true ) {
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

		// Check cache first if enabled.
		if ( $use_cache ) {
			$cache_key = "wp_mcp_ai_user_tier_{$user_id}";
			$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai' );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Check user meta for custom tier.
		$custom_tier = get_user_meta( $user_id, '_wp_mcp_ai_token_tier', true );

		if ( $custom_tier && isset( self::$tier_limits[ $custom_tier ] ) ) {
			// Check if tier has expired.
			$tier_expires = get_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', true );

			if ( $tier_expires && is_numeric( $tier_expires ) ) {
				if ( $tier_expires < time() ) {
					// Tier has expired, delete custom tier and proceed to role-based detection.
					delete_user_meta( $user_id, '_wp_mcp_ai_token_tier' );
					delete_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires' );
					self::invalidate_tier_cache( $user_id );
				} else {
					// Tier is still valid - cache and return.
					if ( $use_cache ) {
						wp_cache_set( "wp_mcp_ai_user_tier_{$user_id}", $custom_tier, 'wp_mcp_ai', HOUR_IN_SECONDS );
					}
					return $custom_tier;
				}
			} else {
				// No expiration, tier is permanent - cache and return.
				if ( $use_cache ) {
					wp_cache_set( "wp_mcp_ai_user_tier_{$user_id}", $custom_tier, 'wp_mcp_ai', HOUR_IN_SECONDS );
				}
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
			$tier = apply_filters( 'wp_mcp_ai_default_invalid_user_tier', self::TIER_FREE, $user_id );
			if ( $use_cache ) {
				wp_cache_set( "wp_mcp_ai_user_tier_{$user_id}", $tier, 'wp_mcp_ai', HOUR_IN_SECONDS );
			}
			return $tier;
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
				$tier = apply_filters( 'wp_mcp_ai_user_tier_by_role', self::$role_tier_map[ $role ], $user_id, $role );
				if ( $use_cache ) {
					wp_cache_set( "wp_mcp_ai_user_tier_{$user_id}", $tier, 'wp_mcp_ai', HOUR_IN_SECONDS );
				}
				return $tier;
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
		$tier = apply_filters( 'wp_mcp_ai_default_user_tier', self::TIER_FREE, $user_id );
		if ( $use_cache ) {
			wp_cache_set( "wp_mcp_ai_user_tier_{$user_id}", $tier, 'wp_mcp_ai', HOUR_IN_SECONDS );
		}
		return $tier;
	}

	/**
	 * Get tier information including daily limit.
	 *
	 * @param string $tier Tier identifier (free, pro, enterprise).
	 * @return array Tier information with daily_limit key.
	 */
	public static function get_tier_info( $tier ) {
		$tier = sanitize_key( $tier );

		// Get base limit from tier.
		$daily_limit = isset( self::$tier_limits[ $tier ] ) ? self::$tier_limits[ $tier ] : self::DEFAULT_GENERAL_LIMIT;

		/**
		 * Filter tier information.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $tier_info Tier information.
		 * @param string $tier      Tier identifier.
		 */
		return apply_filters(
			'wp_mcp_ai_tier_info',
			array(
				'tier'        => $tier,
				'daily_limit' => $daily_limit,
			),
			$tier
		);
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

		// Check persisted custom multipliers first.
		$custom = get_option( 'wp_mcp_ai_tool_multipliers', array() );
		if ( is_array( $custom ) && isset( $custom[ $tool_slug ] ) ) {
			return (float) $custom[ $tool_slug ];
		}

		// Check hardcoded multipliers.
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
	 * Get all tool multipliers.
	 *
	 * @return array Tool slug => multiplier pairs.
	 */
	public static function get_tool_multipliers() {
		// Start with hardcoded defaults.
		$multipliers = self::$tool_multipliers;

		// Merge with persisted custom multipliers.
		$custom = get_option( 'wp_mcp_ai_tool_multipliers', array() );
		if ( is_array( $custom ) ) {
			$multipliers = array_merge( $multipliers, $custom );
		}

		/**
		 * Filter all tool multipliers.
		 *
		 * @since 1.0.0
		 *
		 * @param array $multipliers Tool slug => multiplier pairs.
		 */
		return apply_filters( 'wp_mcp_ai_all_tool_multipliers', $multipliers );
	}

	/**
	 * Set tool multiplier.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param float  $multiplier Multiplier value.
	 * @return bool True on success.
	 */
	public static function set_tool_multiplier( $tool_slug, $multiplier ) {
		$tool_slug  = sanitize_key( $tool_slug );
		$multiplier = (float) $multiplier;

		if ( '' === $tool_slug || $multiplier < 0.1 || $multiplier > 10 ) {
			return false;
		}

		// Get current multipliers from option (persistent storage).
		$multipliers = get_option( 'wp_mcp_ai_tool_multipliers', array() );

		if ( ! is_array( $multipliers ) ) {
			$multipliers = array();
		}

		$multipliers[ $tool_slug ] = $multiplier;

		return update_option( 'wp_mcp_ai_tool_multipliers', $multipliers, false );
	}

	/**
	 * Get tool model preference.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return string Model preference ('default' or specific model identifier).
	 */
	public static function get_tool_model_preference( $tool_slug ) {
		$tool_slug = sanitize_key( $tool_slug );
		if ( '' === $tool_slug ) {
			return 'default';
		}

		$preferences = self::get_tool_model_preferences();

		return isset( $preferences[ $tool_slug ] ) ? $preferences[ $tool_slug ] : 'default';
	}

	/**
	 * Get all tool model preferences.
	 *
	 * @return array Tool slug => model preference pairs.
	 */
	public static function get_tool_model_preferences() {
		$preferences = get_option( self::MODEL_PREFERENCES_OPTION, array() );

		if ( ! is_array( $preferences ) ) {
			$preferences = array();
		}

		/**
		 * Filter all tool model preferences.
		 *
		 * @since 1.0.0
		 *
		 * @param array $preferences Tool slug => model preference pairs.
		 */
		return apply_filters( 'wp_mcp_ai_all_tool_model_preferences', $preferences );
	}

	/**
	 * Set tool model preference.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param string $model     Model identifier or 'default'.
	 * @return bool True on success.
	 */
	public static function set_tool_model_preference( $tool_slug, $model ) {
		$tool_slug = sanitize_key( $tool_slug );
		$model     = sanitize_text_field( $model );

		if ( '' === $tool_slug ) {
			return false;
		}

		// Get current preferences from option (persistent storage).
		$preferences = get_option( self::MODEL_PREFERENCES_OPTION, array() );

		if ( ! is_array( $preferences ) ) {
			$preferences = array();
		}

		// Store 'default' or the specific model.
		$preferences[ $tool_slug ] = $model;

		return update_option( self::MODEL_PREFERENCES_OPTION, $preferences, false );
	}

	/**
	 * Get available AI models from all configured providers.
	 *
	 * @return array Array of model options with provider labels.
	 */
	/**
	 * Get available models for tool preferences.
	 *
	 * @param string $tool_slug Optional tool slug to filter models by capability flags.
	 * @return array Available models grouped by provider.
	 */
	public static function get_available_models( $tool_slug = '' ) {
		$models = array(
			'default' => __( 'Default (use assistant/global setting)', 'wp-mcp-ai' ),
		);

		// Get settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Get tool capability flags if tool_slug is provided.
		$capability_flags   = array();
		$model_requirements = array();
		if ( ! empty( $tool_slug ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry         = WP_MCP_AI_Tool_Registry::get_instance();
			$tool             = $registry->get_tool( $tool_slug );
			$capability_flags = $registry->get_tool_capability_flags( $tool_slug );

			// Get model requirements if tool implements the interface.
			if ( $tool && method_exists( $tool, 'get_model_requirements' ) ) {
				$model_requirements = $tool->get_model_requirements();
			}
		}

		/**
		 * Filter the capability flags for a tool.
		 *
		 * This allows capability flags to be provided for unregistered tool slugs,
		 * such as temporary slugs used by the model config fallback dropdown.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $capability_flags Tool capability flags.
		 * @param string $tool_slug        Tool identifier.
		 */
		$capability_flags = apply_filters( 'wp_mcp_ai_tool_capability_flags', $capability_flags, $tool_slug );

		/**
		 * Filter the model requirements for a tool.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $model_requirements Model capability requirements.
		 * @param string $tool_slug          Tool identifier.
		 */
		$model_requirements = apply_filters( 'wp_mcp_ai_tool_model_requirements', $model_requirements, $tool_slug );

		// Determine if tool requires specific model capabilities.
		// Legacy support: Check old capability flags first.
		$requires_vision     = in_array( 'requires-vision-model', $capability_flags, true ) || in_array( 'vision', $model_requirements, true );
		$requires_multimodal = in_array( 'requires-multimodal-model', $capability_flags, true ) || in_array( 'multimodal', $model_requirements, true );
		$requires_image_gen  = in_array( 'requires-image-generation-model', $capability_flags, true ) || in_array( 'image-generation', $model_requirements, true ) || in_array( 'image-editing', $model_requirements, true );

		// OpenAI models.
		if ( ! empty( $settings['openai_api_key'] ) ) {
			$openai_models = array();

			// GPT-5.2 series (flagship - Dec 2025) - 400K context window.
			$openai_models['gpt-5.2']                = 'GPT-5.2 (Flagship)';
			$openai_models['gpt-5.2-2025-12-11']     = 'GPT-5.2 (Dec 2025)';
			$openai_models['gpt-5.2-pro']            = 'GPT-5.2 Pro (Advanced Reasoning)';
			$openai_models['gpt-5.2-pro-2025-12-11'] = 'GPT-5.2 Pro (Dec 2025)';
			$openai_models['gpt-5.2-instant']        = 'GPT-5.2 Instant (High Throughput)';
			$openai_models['gpt-5.2-thinking']       = 'GPT-5.2 Thinking (Deeper Analysis)';

			// GPT-5.1 series (Nov 2025).
			$openai_models['gpt-5.1']            = 'GPT-5.1';
			$openai_models['gpt-5.1-2025-11-13'] = 'GPT-5.1 (Nov 2025)';

			// GPT-5 series (Aug 2025).
			$openai_models['gpt-5']            = 'GPT-5';
			$openai_models['gpt-5-2025-08-07'] = 'GPT-5 (Aug 2025)';
			$openai_models['gpt-5-mini']       = 'GPT-5 Mini';
			$openai_models['gpt-5-nano']       = 'GPT-5 Nano';
			$openai_models['gpt-5-pro']        = 'GPT-5 Pro';

			// GPT-5 Codex variants (coding-optimized).
			if ( ! $requires_vision && ! $requires_multimodal ) {
				$openai_models['gpt-5-codex']      = 'GPT-5 Codex';
				$openai_models['gpt-5-codex-mini'] = 'GPT-5 Codex Mini';
			}

			// GPT-4.1 series (multimodal - vision capable).
			$openai_models['gpt-4.1']            = 'GPT-4.1';
			$openai_models['gpt-4.1-mini']       = 'GPT-4.1 Mini';
			$openai_models['gpt-4.1-nano']       = 'GPT-4.1 Nano';
			$openai_models['gpt-4.1-turbo']      = 'GPT-4.1 Turbo';
			$openai_models['gpt-4.1-2025-04-14'] = 'GPT-4.1 (Apr 2025)';

			// GPT-4o series (multimodal - vision capable).
			$openai_models['gpt-4o']            = 'GPT-4o';
			$openai_models['gpt-4o-mini']       = 'GPT-4o Mini';
			$openai_models['gpt-4o-2024-11-20'] = 'GPT-4o (Nov 2024)';
			$openai_models['gpt-4o-2024-08-06'] = 'GPT-4o (Aug 2024)';
			$openai_models['gpt-4o-2024-05-13'] = 'GPT-4o (May 2024)';
			$openai_models['chatgpt-4o-latest'] = 'ChatGPT-4o (Latest)';

			// Legacy models (text-only).
			if ( ! $requires_vision && ! $requires_multimodal ) {
				$openai_models['gpt-4-turbo']   = 'GPT-4 Turbo (Legacy)';
				$openai_models['gpt-4']         = 'GPT-4 (Legacy)';
				$openai_models['gpt-3.5-turbo'] = 'GPT-3.5 Turbo (Legacy)';
			}

			if ( ! empty( $openai_models ) ) {
				$models['openai_group'] = array(
					'label'   => __( 'OpenAI', 'wp-mcp-ai' ),
					'options' => $openai_models,
				);
			}
		}

		// Anthropic models.
		if ( ! empty( $settings['anthropic_api_key'] ) ) {
			$anthropic_models = array();

			// Claude 4 series (multimodal - vision capable) - 2025.
			$anthropic_models['claude-sonnet-4.5']          = 'Claude Sonnet 4.5 (Recommended)';
			$anthropic_models['claude-sonnet-4-5-20250929'] = 'Claude Sonnet 4.5 (Sep 2025)';
			$anthropic_models['claude-haiku-4.5']           = 'Claude Haiku 4.5 (Fastest)';
			$anthropic_models['claude-opus-4.1']            = 'Claude Opus 4.1 (Flagship)';
			$anthropic_models['claude-opus-4.0']            = 'Claude Opus 4.0';

			// Claude 3.5 series (legacy - for backward compatibility).
			$anthropic_models['claude-3-5-sonnet-20241022'] = 'Claude 3.5 Sonnet (Legacy)';
			$anthropic_models['claude-3-5-haiku-20241022']  = 'Claude 3.5 Haiku (Legacy)';

			if ( ! empty( $anthropic_models ) ) {
				$models['anthropic_group'] = array(
					'label'   => __( 'Anthropic (Claude)', 'wp-mcp-ai' ),
					'options' => $anthropic_models,
				);
			}
		}

		// Gemini models.
		if ( ! empty( $settings['gemini_api_key'] ) ) {
			$gemini_models = array();

			// Gemini 3 series (multimodal - latest generation) - Preview.
			$gemini_models['gemini-3-pro-preview'] = 'Gemini 3 Pro (Preview)';

			// Gemini 2.5 series (multimodal - text, image, video) - Stable.
			$gemini_models['gemini-2.5-pro']                   = 'Gemini 2.5 Pro';
			$gemini_models['gemini-2.5-flash']                 = 'Gemini 2.5 Flash (Recommended)';
			$gemini_models['gemini-2.5-flash-lite']            = 'Gemini 2.5 Flash Lite';
			$gemini_models['gemini-2.5-flash-preview-09-2025'] = 'Gemini 2.5 Flash (Sep 2025 Preview)';

			// Gemini 2.5 specialized models.
			$gemini_models['gemini-live-2.5-flash-preview']                = 'Gemini Live 2.5 Flash (Voice/Multimodal)';
			$gemini_models['gemini-2.5-flash-preview-native-audio-dialog'] = 'Gemini 2.5 Native Audio Dialog';
			$gemini_models['gemini-2.5-flash-preview-tts']                 = 'Gemini 2.5 Flash TTS';
			$gemini_models['gemini-2.5-pro-preview-tts']                   = 'Gemini 2.5 Pro TTS';

			// Image generation model - only for image generation/editing tools.
			if ( $requires_image_gen ) {
				$gemini_models['gemini-2.5-flash-image'] = 'Gemini 2.5 Flash Image';
			}

			// Gemini 2.0 series (stable).
			$gemini_models['gemini-2.0-flash']      = 'Gemini 2.0 Flash';
			$gemini_models['gemini-2.0-flash-lite'] = 'Gemini 2.0 Flash Lite';
			$gemini_models['gemini-2.0-flash-exp']  = 'Gemini 2.0 Flash (Experimental)';

			// Experimental models.
			$gemini_models['gemini-exp-1206'] = 'Gemini Exp 1206';
			$gemini_models['gemini-exp-1121'] = 'Gemini Exp 1121';

			// Gemini 1.5 series (legacy - for backward compatibility).
			$gemini_models['gemini-1.5-pro']   = 'Gemini 1.5 Pro (Legacy)';
			$gemini_models['gemini-1.5-flash'] = 'Gemini 1.5 Flash (Legacy)';

			// Gemma models (Google's open models - text-only).
			if ( ! $requires_vision && ! $requires_multimodal ) {
				$gemini_models['gemma-2-27b-it'] = 'Gemma 2 27B (Instruct)';
				$gemini_models['gemma-2-9b-it']  = 'Gemma 2 9B (Instruct)';
				$gemini_models['gemma-2-2b-it']  = 'Gemma 2 2B (Instruct)';
			}

			if ( ! empty( $gemini_models ) ) {
				$models['gemini_group'] = array(
					'label'   => __( 'Google Gemini & Gemma', 'wp-mcp-ai' ),
					'options' => $gemini_models,
				);
			}
		}

		// Ollama models (if configured).
		if ( ! empty( $settings['ollama_endpoint_url'] ) && ! empty( $settings['ollama_model'] ) ) {
			$ollama_models = array(
				$settings['ollama_model'] => $settings['ollama_model'],
			);

			// Add common Ollama models (Gemma, Llama, etc.) if model is one of them.
			$common_ollama_models = array(
				'llama3.2'       => 'Llama 3.2',
				'llama3.1'       => 'Llama 3.1',
				'llama3'         => 'Llama 3',
				'llama2'         => 'Llama 2',
				'mistral'        => 'Mistral',
				'mixtral'        => 'Mixtral',
				'gemma2'         => 'Gemma 2',
				'gemma'          => 'Gemma',
				'codellama'      => 'CodeLlama',
				'deepseek-coder' => 'DeepSeek Coder',
				'phi3'           => 'Phi-3',
				'qwen2.5'        => 'Qwen 2.5',
			);

			// Add common models that match vision/multimodal requirements.
			if ( ! $requires_vision && ! $requires_multimodal ) {
				foreach ( $common_ollama_models as $model_id => $model_name ) {
					if ( $model_id !== $settings['ollama_model'] ) {
						$ollama_models[ $model_id ] = $model_name;
					}
				}
			}

			$models['ollama_group'] = array(
				'label'   => __( 'Ollama (Local)', 'wp-mcp-ai' ),
				'options' => $ollama_models,
			);
		}

		// LM Studio models (if configured).
		if ( ! empty( $settings['lm_studio_endpoint_url'] ) && ! empty( $settings['lm_studio_model'] ) ) {
			$lm_studio_models = array(
				$settings['lm_studio_model'] => $settings['lm_studio_model'],
			);

			// Add common LM Studio models (popular models from lmstudio.ai - 2025).
			$common_lm_studio_models = array(
				// Qwen models (function calling, coding, vision) - Top performers.
				'qwen/qwen3-coder-30b'                    => 'Qwen 3 Coder 30B',
				'qwen/qwen3-vl-30b'                       => 'Qwen 3 Vision-Language 30B',
				'qwen/qwen2.5-coder-32b'                  => 'Qwen 2.5 Coder 32B',
				'qwen/qwen2.5-32b'                        => 'Qwen 2.5 32B',
				'qwen/qwen2.5-14b'                        => 'Qwen 2.5 14B',
				'qwen/qwen2.5-7b'                         => 'Qwen 2.5 7B',
				// Llama models (Meta's flagship).
				'meta-llama/llama-3.3-70b-instruct'       => 'Llama 3.3 70B Instruct',
				'meta-llama/llama-3.2-3b-instruct'        => 'Llama 3.2 3B Instruct',
				'meta-llama/llama-3.2-1b-instruct'        => 'Llama 3.2 1B Instruct',
				'meta-llama/llama-3.1-8b-instruct'        => 'Llama 3.1 8B Instruct',
				// Mistral models (efficient reasoning).
				'mistralai/mistral-large-2411'            => 'Mistral Large 2411',
				'mistralai/mistral-nemo-2407'             => 'Mistral Nemo 2407',
				'mistralai/mistral-7b-instruct-v0.3'      => 'Mistral 7B Instruct v0.3',
				'mistralai/mixtral-8x7b-instruct'         => 'Mixtral 8x7B Instruct',
				'mistralai/mixtral-8x22b-instruct'        => 'Mixtral 8x22B Instruct',
				// DeepSeek models (coding specialist).
				'deepseek-ai/deepseek-coder-33b-instruct' => 'DeepSeek Coder 33B Instruct',
				'deepseek-ai/deepseek-v3'                 => 'DeepSeek V3',
				'deepseek-ai/deepseek-r1'                 => 'DeepSeek R1 (Reasoning)',
				// Microsoft Phi models (small but capable).
				'microsoft/phi-4'                         => 'Phi-4',
				'microsoft/phi-3.5-mini-instruct'         => 'Phi-3.5 Mini Instruct',
				// Google Gemma models.
				'google/gemma-3-12b-it'                   => 'Gemma 3 12B Instruct',
				'google/gemma-2-27b-it'                   => 'Gemma 2 27B Instruct',
				'google/gemma-2-9b-it'                    => 'Gemma 2 9B Instruct',
				'google/gemma-2-2b-it'                    => 'Gemma 2 2B Instruct',
			);

			// Add common models that match vision/multimodal requirements.
			if ( ! $requires_vision && ! $requires_multimodal ) {
				foreach ( $common_lm_studio_models as $model_id => $model_name ) {
					if ( $model_id !== $settings['lm_studio_model'] ) {
						$lm_studio_models[ $model_id ] = $model_name;
					}
				}
			}

			$models['lm_studio_group'] = array(
				'label'   => __( 'LM Studio (Local)', 'wp-mcp-ai' ),
				'options' => $lm_studio_models,
			);
		}

		/**
		 * Filter available models for tool preferences.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $models    Available models grouped by provider.
		 * @param string $tool_slug Tool slug for context.
		 * @param array  $capability_flags Tool capability flags.
		 */
		return apply_filters( 'wp_mcp_ai_available_tool_models', $models, $tool_slug, $capability_flags );
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

		// Invalidate cache after tier update.
		self::invalidate_tier_cache( $user_id );

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

		// Record per-session usage.
		self::record_session_usage( $user_id, $tool_slug, $tokens, $context );

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
		$date_key  = gmdate( 'Y-m-d', time() );
		$hour_key  = gmdate( 'Y-m-d-H', time() );

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

		// Update hourly usage.
		if ( ! isset( $usage[ $tool_slug ]['hourly'] ) || ! is_array( $usage[ $tool_slug ]['hourly'] ) ) {
			$usage[ $tool_slug ]['hourly'] = array();
		}

		if ( ! isset( $usage[ $tool_slug ]['hourly'][ $hour_key ] ) ) {
			$usage[ $tool_slug ]['hourly'][ $hour_key ] = 0;
		}

		$usage[ $tool_slug ]['hourly'][ $hour_key ] = (int) $usage[ $tool_slug ]['hourly'][ $hour_key ] + $tokens;

		// Clean up old daily entries (keep only last 30 days).
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days', time() ) );
		foreach ( $usage[ $tool_slug ]['daily'] as $date => $count ) {
			if ( $date < $cutoff_date ) {
				unset( $usage[ $tool_slug ]['daily'][ $date ] );
			}
		}

		// Clean up old hourly entries (keep only last 7 days).
		$cutoff_hour = gmdate( 'Y-m-d-H', strtotime( '-7 days', time() ) );
		foreach ( $usage[ $tool_slug ]['hourly'] as $hour => $count ) {
			if ( $hour < $cutoff_hour ) {
				unset( $usage[ $tool_slug ]['hourly'][ $hour ] );
			}
		}

		update_user_meta( $user_id, self::USAGE_META_KEY, $usage );

		// Detect usage anomalies for security monitoring.
		self::detect_usage_anomaly( $user_id, $tool_slug, $tokens );

		// Check per-call limit after recording (for logging/alerting).
		self::check_per_call_limit_after( $user_id, $tool_slug, $tokens, $context );

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
	 * Check per-call token limit after execution.
	 *
	 * This is a non-blocking check that logs warnings when a single call
	 * exceeds the configured per-call limit. Used for monitoring and alerting.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param int    $tokens    Tokens used in this call.
	 * @param array  $context   Execution context.
	 */
	protected static function check_per_call_limit_after( $user_id, $tool_slug, $tokens, $context ) {
		// Check if per-call limits are enabled.
		$enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_call_limits', false );
		if ( ! $enabled ) {
			return;
		}

		$limit = absint( WP_MCP_AI_Settings_Registry::get_setting( 'per_call_token_limit', 10000 ) );

		// Skip if limit is 0 (unlimited).
		if ( 0 === $limit ) {
			return;
		}

		// Check if this call exceeded the limit.
		if ( $tokens > $limit ) {
			WP_MCP_AI_Logger::log_event(
				'per_call_token_limit_exceeded',
				'Single tool call exceeded per-call token limit.',
				array(
					'user_id'    => $user_id,
					'tool_slug'  => $tool_slug,
					'tokens'     => $tokens,
					'limit'      => $limit,
					'ratio'      => round( $tokens / max( $limit, 1 ), 2 ),
					'session_id' => isset( $context['session_id'] ) ? $context['session_id'] : 'unknown',
				)
			);

			/**
			 * Fires when a single tool call exceeds the per-call token limit.
			 *
			 * @since 1.1.0
			 *
			 * @param int    $user_id   User ID.
			 * @param string $tool_slug Tool identifier.
			 * @param int    $tokens    Tokens used in this call.
			 * @param int    $limit     Per-call token limit.
			 * @param array  $context   Execution context.
			 */
			do_action( 'wp_mcp_ai_per_call_limit_exceeded', $user_id, $tool_slug, $tokens, $limit, $context );
		}
	}

	/**
	 * Check per-session token limit.
	 *
	 * Verifies that the current session has not exceeded its token budget.
	 * Uses a safety buffer approach to account for tokens from the current call.
	 * Also checks if session was marked as over-budget from a previous call.
	 * Throws an exception if the limit is exceeded and enforcement is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param array  $context   Execution context.
	 * @throws Exception When session budget is exceeded and enforcement is enabled.
	 */
	protected static function check_per_session_limit( $user_id, $tool_slug, $context ) {
		// Check if per-session limits are enabled.
		$enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_session_limits', false );
		if ( ! $enabled ) {
			return;
		}

		$limit = absint( WP_MCP_AI_Settings_Registry::get_setting( 'per_session_token_limit', 50000 ) );

		// Skip if limit is 0 (unlimited).
		if ( 0 === $limit ) {
			return;
		}

		// Get session ID from context.
		$session_id = isset( $context['session_id'] ) ? sanitize_text_field( $context['session_id'] ) : '';
		if ( empty( $session_id ) ) {
			// No session tracking possible without session ID.
			return;
		}

		// Check if session was marked as over-budget from a previous call.
		$session_data = get_transient( "wp_mcp_ai_session_{$user_id}_{$session_id}" );
		if ( is_array( $session_data ) && ! empty( $session_data['over_budget'] ) ) {
			$session_usage = isset( $session_data['total_tokens'] ) ? (int) $session_data['total_tokens'] : 0;

			WP_MCP_AI_Logger::log_event(
				'per_session_already_over_budget',
				'Session was previously marked as over-budget, blocking call.',
				array(
					'user_id'    => $user_id,
					'session_id' => $session_id,
					'tool_slug'  => $tool_slug,
					'usage'      => $session_usage,
					'limit'      => $limit,
				)
			);

			/**
			 * Filter whether to enforce per-session token limits.
			 *
			 * @since 1.1.0
			 *
			 * @param bool   $enforce    Whether to enforce limits. Default true.
			 * @param int    $user_id    User ID.
			 * @param string $session_id Session identifier.
			 * @param int    $usage      Current session usage.
			 * @param int    $limit      Session token limit.
			 */
			$enforce = apply_filters( 'wp_mcp_ai_enforce_per_session_limits', true, $user_id, $session_id, $session_usage, $limit );

			if ( $enforce ) {
				throw new Exception(
					esc_html(
						sprintf(
							/* translators: 1: Session usage, 2: Session limit */
							__( 'Session token limit exceeded. This session has used %1$d tokens of the %2$d token limit. Please start a new session to continue.', 'wp-mcp-ai' ),
							$session_usage,
							$limit
						)
					)
				);
			}
		}

		// Get current session usage.
		$session_usage = self::get_session_usage( $user_id, $session_id );

		// Apply safety buffer (20% of limit) to account for tokens from current call.
		// This prevents sessions from exceeding their limit by allowing one more call.
		$safety_buffer   = (int) ( $limit * 0.20 );
		$effective_limit = $limit - $safety_buffer;

		/**
		 * Filter the safety buffer percentage for session limits.
		 *
		 * @since 1.1.0
		 *
		 * @param float  $buffer_percentage Safety buffer as percentage (0.20 = 20%).
		 * @param int    $limit             Session token limit.
		 * @param int    $session_usage     Current session usage.
		 */
		$buffer_percentage = apply_filters( 'wp_mcp_ai_session_limit_safety_buffer', 0.20, $limit, $session_usage );
		$safety_buffer     = (int) ( $limit * $buffer_percentage );
		$effective_limit   = max( $limit - $safety_buffer, 0 );

		if ( $session_usage >= $effective_limit ) {
			WP_MCP_AI_Logger::log_event(
				'per_session_token_limit_exceeded',
				'Session exceeded per-session token limit (with safety buffer).',
				array(
					'user_id'         => $user_id,
					'session_id'      => $session_id,
					'tool_slug'       => $tool_slug,
					'usage'           => $session_usage,
					'limit'           => $limit,
					'safety_buffer'   => $safety_buffer,
					'effective_limit' => $effective_limit,
				)
			);

			/**
			 * Fires when a session exceeds its token limit.
			 *
			 * @since 1.1.0
			 *
			 * @param int    $user_id    User ID.
			 * @param string $session_id Session identifier.
			 * @param int    $usage      Current session usage.
			 * @param int    $limit      Session token limit.
			 */
			do_action( 'wp_mcp_ai_per_session_limit_exceeded', $user_id, $session_id, $session_usage, $limit );

			/**
			 * Filter whether to enforce per-session token limits.
			 *
			 * @since 1.1.0
			 *
			 * @param bool   $enforce    Whether to enforce limits. Default true.
			 * @param int    $user_id    User ID.
			 * @param string $session_id Session identifier.
			 * @param int    $usage      Current session usage.
			 * @param int    $limit      Session token limit.
			 */
			$enforce = apply_filters( 'wp_mcp_ai_enforce_per_session_limits', true, $user_id, $session_id, $session_usage, $limit );

			if ( $enforce ) {
				throw new Exception(
					esc_html(
						sprintf(
							/* translators: 1: Session usage, 2: Session limit */
							__( 'Session token limit exceeded. This session has used %1$d tokens of the %2$d token limit. Please start a new session to continue.', 'wp-mcp-ai' ),
							$session_usage,
							$limit
						)
					)
				);
			}
		}
	}

	/**
	 * Record token usage for a session.
	 *
	 * Also checks if the session exceeded its limit after recording,
	 * and marks the session as over-budget to prevent further calls.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param int    $tokens    Tokens used.
	 * @param array  $context   Execution context.
	 */
	protected static function record_session_usage( $user_id, $tool_slug, $tokens, $context ) {
		// Skip if per-session limits are not enabled.
		$enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_per_session_limits', false );
		if ( ! $enabled ) {
			return;
		}

		// Get session ID from context.
		$session_id = isset( $context['session_id'] ) ? sanitize_text_field( $context['session_id'] ) : '';
		if ( empty( $session_id ) ) {
			return;
		}

		// Get or initialize session data.
		$session_data = get_transient( "wp_mcp_ai_session_{$user_id}_{$session_id}" );
		if ( ! is_array( $session_data ) ) {
			$session_data = array(
				'total_tokens' => 0,
				'tool_calls'   => array(),
				'started_at'   => time(),
				'over_budget'  => false,
			);
		}

		// Update session totals.
		$session_data['total_tokens']  = isset( $session_data['total_tokens'] ) ? (int) $session_data['total_tokens'] : 0;
		$session_data['total_tokens'] += $tokens;

		// Record this tool call.
		if ( ! isset( $session_data['tool_calls'][ $tool_slug ] ) ) {
			$session_data['tool_calls'][ $tool_slug ] = array(
				'count'  => 0,
				'tokens' => 0,
			);
		}

		++$session_data['tool_calls'][ $tool_slug ]['count'];
		$session_data['tool_calls'][ $tool_slug ]['tokens'] += $tokens;

		// Check if session exceeded limit after this call.
		$limit = absint( WP_MCP_AI_Settings_Registry::get_setting( 'per_session_token_limit', 50000 ) );
		if ( $limit > 0 && $session_data['total_tokens'] > $limit ) {
			// Mark session as over budget.
			$session_data['over_budget'] = true;

			WP_MCP_AI_Logger::log_event(
				'session_budget_exceeded_post_call',
				'Session exceeded budget after call execution.',
				array(
					'user_id'      => $user_id,
					'session_id'   => $session_id,
					'tool_slug'    => $tool_slug,
					'total_tokens' => $session_data['total_tokens'],
					'limit'        => $limit,
					'overage'      => $session_data['total_tokens'] - $limit,
				)
			);
		}

		// Store session data with 24-hour expiration.
		set_transient( "wp_mcp_ai_session_{$user_id}_{$session_id}", $session_data, DAY_IN_SECONDS );
	}

	/**
	 * Get token usage for a specific session.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Session identifier.
	 * @return int Total tokens used in this session.
	 */
	public static function get_session_usage( $user_id, $session_id ) {
		$session_id   = sanitize_text_field( $session_id );
		$session_data = get_transient( "wp_mcp_ai_session_{$user_id}_{$session_id}" );

		if ( ! is_array( $session_data ) || ! isset( $session_data['total_tokens'] ) ) {
			return 0;
		}

		return (int) $session_data['total_tokens'];
	}

	/**
	 * Get detailed session data.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Session identifier.
	 * @return array|null Session data or null if not found.
	 */
	public static function get_session_data( $user_id, $session_id ) {
		$session_id   = sanitize_text_field( $session_id );
		$session_data = get_transient( "wp_mcp_ai_session_{$user_id}_{$session_id}" );

		if ( ! is_array( $session_data ) ) {
			return null;
		}

		return $session_data;
	}

	/**
	 * Reset session usage.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Session identifier.
	 * @return bool True on success.
	 */
	public static function reset_session_usage( $user_id, $session_id ) {
		$session_id = sanitize_text_field( $session_id );
		return delete_transient( "wp_mcp_ai_session_{$user_id}_{$session_id}" );
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

		// Check per-session limits first (most restrictive).
		self::check_per_session_limit( $user_id, $tool_slug, $context );

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
					'user_id'    => $user_id,
					'tool_slug'  => $tool_slug,
					'usage'      => $daily_usage,
					'limit'      => $limit,
					'tier'       => $tier,
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
					esc_html(
						sprintf(
							/* translators: 1: Tool name, 2: Daily limit, 3: Current tier, 4: Reset time */
							__( 'Daily token limit exceeded for tool "%1$s". Your %3$s tier limit is %2$d tokens per day. Limit resets at %4$s. Consider upgrading to a higher tier for increased limits.', 'wp-mcp-ai' ),
							$tool_slug,
							$limit,
							$tier,
							$reset_time
						)
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Get user's hourly usage for a tool.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param string $hour_key  Hour key (YYYY-MM-DD-HH format).
	 * @return int Tokens used in that hour.
	 */
	public static function get_user_tool_hourly_usage( $user_id, $tool_slug, $hour_key = '' ) {
		if ( empty( $hour_key ) ) {
			$hour_key = gmdate( 'Y-m-d-H', time() );
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

		$cutoff = gmdate( 'Y-m-d-H', strtotime( "-{$days} days", time() ) );
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
		$cutoff = gmdate( 'Y-m-d-H', strtotime( '-7 days', time() ) );
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
		$today_key   = gmdate( 'Y-m-d', time() );
		$today_usage = isset( $usage[ $tool_slug ]['daily'][ $today_key ] ) ? (int) $usage[ $tool_slug ]['daily'][ $today_key ] : 0;

		// Get user's daily limit.
		$limit = self::get_user_tool_limit( $user_id, $tool_slug );

		// Calculate remaining tokens and hours.
		$remaining_tokens  = $limit - $today_usage;
		$hours_until_reset = self::get_hours_until_daily_reset();

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
		$now      = time();
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
				'WP oOS Team',
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
				'total_users'    => 0,
				'total_tokens'   => 0,
				'total_requests' => 0,
			);
		}

		// Get all users with tool usage data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		update_option( 'wp_mcp_ai_tiered_limits_migrated', time() );

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

	/**
	 * Export usage report as CSV.
	 *
	 * @param array $filters Report filters (date_range, tier, tool).
	 * @return string CSV content.
	 */
	public static function export_usage_report( $filters = array() ) {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// Get users matching filters.
		$users = self::get_filtered_users( $filters );

		$csv   = array();
		$csv[] = array(
			'User ID',
			'Username',
			'Email',
			'Tier',
			'Total Tokens',
			'Total Requests',
			'Last Used',
			'Limit',
			'Usage %',
		);

		foreach ( $users as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$tier  = self::get_user_tier( $user_id );
			$limit = self::get_user_tool_limit( $user_id, 'general_tools' );
			$usage = self::get_user_tool_usage( $user_id );

			$total_tokens   = 0;
			$total_requests = 0;
			$last_used      = '';

			foreach ( $usage as $tool_data ) {
				$total_tokens   += isset( $tool_data['total_tokens'] ) ? (int) $tool_data['total_tokens'] : 0;
				$total_requests += isset( $tool_data['requests'] ) ? (int) $tool_data['requests'] : 0;

				if ( isset( $tool_data['last_used'] ) && $tool_data['last_used'] > $last_used ) {
					$last_used = $tool_data['last_used'];
				}
			}

			$usage_pct = $limit > 0 ? round( ( $total_tokens / $limit ) * 100, 2 ) : 0;

			$csv[] = array(
				$user_id,
				$user->user_login,
				$user->user_email,
				$tier,
				$total_tokens,
				$total_requests,
				$last_used,
				$limit,
				$usage_pct . '%',
			);
		}

		// Convert to CSV string.
		ob_start();
		$output = fopen( 'php://output', 'w' );
		foreach ( $csv as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
		return ob_get_clean();
	}

	/**
	 * Get filtered users based on criteria.
	 *
	 * @param array $filters Filters (tier, tool, date_range).
	 * @return array User IDs.
	 */
	protected static function get_filtered_users( $filters = array() ) {
		global $wpdb;

		$meta_key = self::USAGE_META_KEY;

		// Start with all users who have usage data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		// Filter by tier if specified.
		if ( ! empty( $filters['tier'] ) ) {
			$tier     = sanitize_key( $filters['tier'] );
			$filtered = array();

			foreach ( $user_ids as $user_id ) {
				if ( self::get_user_tier( $user_id ) === $tier ) {
					$filtered[] = $user_id;
				}
			}

			$user_ids = $filtered;
		}

		// Filter by tool if specified.
		if ( ! empty( $filters['tool'] ) ) {
			$tool     = sanitize_key( $filters['tool'] );
			$filtered = array();

			foreach ( $user_ids as $user_id ) {
				$usage = self::get_user_tool_usage( $user_id );
				if ( isset( $usage[ $tool ] ) ) {
					$filtered[] = $user_id;
				}
			}

			$user_ids = $filtered;
		}

		return $user_ids;
	}

	/**
	 * Get user tier with caching for improved performance.
	 *
	 * Uses WordPress object cache with 1-hour TTL to reduce database queries.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return string Tier identifier.
	 */
	public static function get_user_tier_cached( $user_id ) {
		$user_id   = absint( $user_id );
		$cache_key = "wp_mcp_ai_user_tier_{$user_id}";
		$tier      = wp_cache_get( $cache_key, 'wp_mcp_ai' );

		if ( false === $tier ) {
			$tier = self::get_user_tier( $user_id );
			wp_cache_set( $cache_key, $tier, 'wp_mcp_ai', HOUR_IN_SECONDS );
		}

		return $tier;
	}

	/**
	 * Invalidate tier cache for a user.
	 *
	 * Called automatically when tier is updated.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public static function invalidate_tier_cache( $user_id ) {
		$user_id   = absint( $user_id );
		$cache_key = "wp_mcp_ai_user_tier_{$user_id}";
		wp_cache_delete( $cache_key, 'wp_mcp_ai' );
	}

	/**
	 * Preload tiers for multiple users into cache.
	 *
	 * Optimizes performance when displaying bulk user lists by reducing
	 * individual get_user_meta calls.
	 *
	 * @since 1.1.0
	 *
	 * @param array $user_ids Array of user IDs to preload.
	 * @return int Number of tiers preloaded.
	 */
	public static function preload_user_tiers( $user_ids ) {
		if ( empty( $user_ids ) || ! is_array( $user_ids ) ) {
			return 0;
		}

		$user_ids = array_map( 'absint', $user_ids );
		$user_ids = array_filter( $user_ids );

		if ( empty( $user_ids ) ) {
			return 0;
		}

		$preloaded = 0;

		foreach ( $user_ids as $user_id ) {
			// Get tier (without cache) and store in cache.
			$tier = self::get_user_tier( $user_id, false );
			wp_cache_set( "wp_mcp_ai_user_tier_{$user_id}", $tier, 'wp_mcp_ai', HOUR_IN_SECONDS );
			++$preloaded;
		}

		return $preloaded;
	}

	/**
	 * Detect unusual usage patterns (anomaly detection).
	 *
	 * Flags usage that is 5x the user's average hourly usage over the last 7 days.
	 * Logs anomalies for security monitoring and alerting.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id   User ID.
	 * @param string $tool_slug Tool identifier.
	 * @param int    $tokens    Tokens used in current request.
	 * @return bool True if anomaly detected, false otherwise.
	 */
	public static function detect_usage_anomaly( $user_id, $tool_slug, $tokens ) {
		$user_id   = absint( $user_id );
		$tool_slug = sanitize_key( $tool_slug );
		$tokens    = absint( $tokens );

		if ( ! $user_id || ! $tool_slug || $tokens <= 0 ) {
			return false;
		}

		// Get usage data.
		$usage = self::get_user_tool_usage( $user_id );

		if ( ! isset( $usage[ $tool_slug ]['hourly'] ) || empty( $usage[ $tool_slug ]['hourly'] ) ) {
			// Not enough data to detect anomaly.
			return false;
		}

		$hourly_data = $usage[ $tool_slug ]['hourly'];

		// Calculate average hourly usage.
		$avg_hourly = array_sum( $hourly_data ) / count( $hourly_data );

		// Flag if current request is 5x average.
		$threshold  = $avg_hourly * 5;
		$is_anomaly = $tokens > $threshold;

		if ( $is_anomaly ) {
			WP_MCP_AI_Logger::log_event(
				'usage_anomaly_detected',
				'Unusual token usage pattern detected.',
				array(
					'user_id'    => $user_id,
					'tool_slug'  => $tool_slug,
					'tokens'     => $tokens,
					'avg_hourly' => (int) $avg_hourly,
					'threshold'  => (int) $threshold,
					'multiplier' => round( $tokens / max( $avg_hourly, 1 ), 2 ),
				)
			);

			/**
			 * Fires when a usage anomaly is detected.
			 *
			 * @since 1.1.0
			 *
			 * @param int    $user_id   User ID.
			 * @param string $tool_slug Tool identifier.
			 * @param int    $tokens    Tokens used.
			 * @param float  $avg_hourly Average hourly usage.
			 */
			do_action( 'wp_mcp_ai_usage_anomaly_detected', $user_id, $tool_slug, $tokens, $avg_hourly );
		}

		return $is_anomaly;
	}

	/**
	 * Log tier changes for audit trail.
	 *
	 * Captures admin ID, IP address, user agent, and tier transition details.
	 * Called automatically via the wp_mcp_ai_user_tier_changed action.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id  User ID whose tier changed.
	 * @param string $old_tier Previous tier.
	 * @param string $new_tier New tier.
	 * @param int    $expires  Expiration timestamp (0 for no expiration).
	 */
	public static function log_tier_change( $user_id, $old_tier, $new_tier, $expires = 0 ) {
		$user_id  = absint( $user_id );
		$old_tier = sanitize_key( $old_tier );
		$new_tier = sanitize_key( $new_tier );
		$expires  = absint( $expires );

		$admin_id   = get_current_user_id();
		$ip_address = WP_MCP_AI_Logger::get_client_ip();
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		WP_MCP_AI_Logger::log_event(
			'token_tier_changed',
			'User token tier was modified.',
			array(
				'user_id'      => $user_id,
				'old_tier'     => $old_tier,
				'new_tier'     => $new_tier,
				'expires'      => $expires,
				'expires_date' => $expires > 0 ? gmdate( 'Y-m-d H:i:s', $expires ) : 'never',
				'changed_by'   => $admin_id,
				'ip_address'   => $ip_address,
				'user_agent'   => $user_agent,
			)
		);
	}

	/**
	 * Create database indexes for improved query performance.
	 *
	 * Adds indexes on user meta table for:
	 * - Token tier lookups (_wp_mcp_ai_token_tier)
	 * - Usage data queries (_wp_mcp_ai_tool_token_usage)
	 *
	 * This method is idempotent and safe to call multiple times.
	 *
	 * @since 1.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return bool True on success, false on failure.
	 */
	public static function create_database_indexes() {
		global $wpdb;

		// Check if indexes already exist to avoid errors.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tier_index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$wpdb->usermeta} WHERE Key_name = %s",
				'idx_wp_mcp_ai_token_tier'
			)
		);

		if ( ! $tier_index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin manages its own indexes for performance optimization.
			$wpdb->query(
				"ALTER TABLE {$wpdb->usermeta} 
				ADD INDEX idx_wp_mcp_ai_token_tier (meta_key(191), meta_value(20))"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$usage_index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$wpdb->usermeta} WHERE Key_name = %s",
				'idx_wp_mcp_ai_usage'
			)
		);

		if ( ! $usage_index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin manages its own indexes for performance optimization.
			$wpdb->query(
				"ALTER TABLE {$wpdb->usermeta} 
				ADD INDEX idx_wp_mcp_ai_usage (meta_key(191), user_id)"
			);
		}

		// Log index creation.
		WP_MCP_AI_Logger::log_event(
			'database_indexes_created',
			'Token manager database indexes created or verified.',
			array(
				'tier_index_existed'  => (bool) $tier_index_exists,
				'usage_index_existed' => (bool) $usage_index_exists,
			)
		);

		return true;
	}
}
