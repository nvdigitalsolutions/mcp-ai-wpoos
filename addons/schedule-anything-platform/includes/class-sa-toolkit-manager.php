<?php
/**
 * Schedule Anything — Toolkit Manager
 *
 * Manages per-tenant toolkit feature flags and tier-based defaults.
 * Each tenant subsite gets toolkit activation flags based on their
 * subscription tier (Starter: 5, Professional: 15, Enterprise: 30).
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-tenant toolkit feature flag management.
 *
 * @since 0.1.0
 */
class SA_Toolkit_Manager {

	/**
	 * Option key prefix for per-tenant settings.
	 *
	 * In Multisite, options are already scoped per blog, so this
	 * uses the standard wp_mcp_ai_settings option that NV oOS
	 * already reads from.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'wp_mcp_ai_settings';

	/**
	 * Toolkit definitions: slug => feature flag key in settings.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	const TOOLKIT_FLAGS = array(
		'calendar-booking'        => 'enable_calendar_booking_toolkit',
		'crm'                     => 'enable_crm_toolkit',
		'ecommerce'               => 'enable_ecommerce_toolkit',
		'social-media'            => 'enable_social_media_toolkit',
		'analytics'               => 'enable_analytics_toolkit',
		'project-management'      => 'enable_project_management',
		'document-generation'     => 'enable_document_generation_toolkit',
		'multilingual'            => 'enable_multilingual_toolkit',
		'media'                   => 'enable_media_toolkit',
		'image-production'        => 'enable_image_production_toolkit',
		'video-production'        => 'enable_video_production_toolkit',
		'financial-planner'       => 'enable_financial_planner_toolkit',
		'dj-management'           => 'enable_dj_management_toolkit',
		'health-wellness'         => 'enable_health_wellness_management',
		'healthcare-imaging'      => 'enable_healthcare_imaging',
		'regulatory-registration' => 'enable_regulatory_registration_toolkit',
		'law-firm'                => 'enable_law_firm_toolkit',
		'cre-debt'                => 'enable_cre_debt_toolkit',
		'places'                  => 'enable_places_management',
		'quiz-system'             => 'enable_quiz_system',
		'eca-management'          => 'enable_eca_management',
		'site-creator'            => 'enable_site_creator_toolkit',
		'architect-agent'         => 'enable_architect_agent_toolkit',
		'architectural-design'    => 'enable_architectural_design_toolkit',
		'ai-tool-builder'         => 'enable_ai_tool_builder_toolkit',
		'chat-channels'           => 'enable_chat_channels_toolkit',
		'password-vault'          => 'enable_password_vault_manager',
		'extended-cognition'      => 'enable_extended_cognition_toolkit',
		'skills-manager'          => 'enable_skills_manager',
		'vector-storage'          => 'enable_vector_storage_pro',
	);

	/**
	 * Toolkit ordering — the order toolkits appear in the UI.
	 * Matches the order in the Pro README.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string>
	 */
	const TOOLKIT_ORDER = array(
		'calendar-booking',
		'project-management',
		'crm',
		'analytics',
		'social-media',
		'document-generation',
		'multilingual',
		'media',
		'image-production',
		'video-production',
		'financial-planner',
		'ecommerce',
		'chat-channels',
		'password-vault',
		'skills-manager',
		'site-creator',
		'architect-agent',
		'architectural-design',
		'ai-tool-builder',
		'dj-management',
		'health-wellness',
		'healthcare-imaging',
		'regulatory-registration',
		'law-firm',
		'cre-debt',
		'places',
		'quiz-system',
		'eca-management',
		'extended-cognition',
		'vector-storage',
	);

	/**
	 * Get the default toolkit flags for a given subscription tier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tier Subscription tier: 'starter', 'professional', 'enterprise'.
	 * @return array<string, bool> Toolkit slug => enabled bool.
	 */
	public static function get_defaults_for_tier( $tier ) {
		$flags = array();

		switch ( $tier ) {
			case 'starter':
				// 5 core toolkits.
				$enabled = array( 'calendar-booking', 'project-management', 'crm', 'analytics', 'social-media' );
				break;

			case 'professional':
				// 15 toolkits.
				$enabled = array(
					'calendar-booking',
					'project-management',
					'crm',
					'analytics',
					'social-media',
					'document-generation',
					'multilingual',
					'media',
					'image-production',
					'video-production',
					'financial-planner',
					'ecommerce',
					'chat-channels',
					'password-vault',
					'skills-manager',
				);
				break;

			case 'enterprise':
				// All 30 toolkits.
				$enabled = array_keys( self::TOOLKIT_FLAGS );
				break;

			default:
				$enabled = array( 'calendar-booking' );
				break;
		}

		// Build the flags array using the feature flag keys.
		foreach ( self::TOOLKIT_FLAGS as $slug => $flag_key ) {
			$flags[ $flag_key ] = in_array( $slug, $enabled, true );
		}

		return $flags;
	}

	/**
	 * Get the list of available toolkit slugs for a given tier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tier Subscription tier.
	 * @return array<string> Toolkit slugs.
	 */
	public static function get_toolkit_slugs_for_tier( $tier ) {
		$defaults = self::get_defaults_for_tier( $tier );
		$enabled  = array();

		foreach ( $defaults as $flag_key => $is_enabled ) {
			if ( $is_enabled ) {
				// Reverse-map the flag key back to the toolkit slug.
				$slug = array_search( $flag_key, self::TOOLKIT_FLAGS, true );
				if ( false !== $slug ) {
					$enabled[] = $slug;
				}
			}
		}

		return $enabled;
	}

	/**
	 * Get the preset IDs that should be installed for a given tier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tier Subscription tier.
	 * @return array<string> Preset IDs from WP_MCP_AI_Pro_Schedule_Presets.
	 */
	public static function get_presets_for_tier( $tier ) {
		$all_presets = array();

		if ( in_array( $tier, array( 'starter', 'professional', 'enterprise' ), true ) ) {
			// Core presets for all tiers.
			$all_presets = array_merge(
				$all_presets,
				array(
					'daily_sales_report',
					'abandoned_cart_followup',
					'daily_content_scheduler',
					'engagement_report',
					'daily_traffic_report',
				)
			);
		}

		if ( in_array( $tier, array( 'professional', 'enterprise' ), true ) ) {
			// Additional presets for Pro and Enterprise.
			$all_presets = array_merge(
				$all_presets,
				array(
					'weekly_performance_digest',
					'conversion_funnel_report',
					'seo_ranking_check',
					'cross_platform_post',
					'order_status_broadcast',
				)
			);
		}

		if ( 'enterprise' === $tier ) {
			// Enterprise gets all presets.
			if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
				$all_presets = array_keys( WP_MCP_AI_Pro_Schedule_Presets::get_presets() );
			}
		}

		return $all_presets;
	}

	/**
	 * Enable or disable a specific toolkit for the current tenant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $toolkit_slug The toolkit slug.
	 * @param bool   $enabled      Whether to enable or disable.
	 * @return bool True on success, false on failure.
	 */
	public static function toggle_toolkit( $toolkit_slug, $enabled ) {
		$slug = sanitize_key( $toolkit_slug );

		if ( ! isset( self::TOOLKIT_FLAGS[ $slug ] ) ) {
			return false;
		}

		$settings              = get_option( self::SETTINGS_OPTION, array() );
		$flag_key              = self::TOOLKIT_FLAGS[ $slug ];
		$settings[ $flag_key ] = (bool) $enabled;

		return update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Get all toolkits with their current status for the current tenant.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{slug: string, flag_key: string, enabled: bool}>
	 */
	public static function get_all_statuses() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$result   = array();

		foreach ( self::TOOLKIT_ORDER as $slug ) {
			if ( ! isset( self::TOOLKIT_FLAGS[ $slug ] ) ) {
				continue;
			}

			$flag_key = self::TOOLKIT_FLAGS[ $slug ];
			$result[] = array(
				'slug'     => $slug,
				'flag_key' => $flag_key,
				'enabled'  => ! empty( $settings[ $flag_key ] ),
			);
		}

		return $result;
	}

	/**
	 * Ensure this tenant has the default settings structure.
	 *
	 * Called when a new blog is created or when the plugin is activated
	 * on an existing blog. Does not overwrite existing settings.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tier Optional. Tier to seed. Default 'starter'.
	 * @return void
	 */
	public static function ensure_defaults( $tier = 'starter' ) {
		$existing = get_option( self::SETTINGS_OPTION, array() );

		// If settings already exist, don't overwrite.
		if ( ! empty( $existing ) ) {
			return;
		}

		$defaults = self::get_defaults_for_tier( $tier );
		update_option( self::SETTINGS_OPTION, $defaults );
	}
}
