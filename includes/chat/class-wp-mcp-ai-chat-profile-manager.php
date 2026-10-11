<?php
/**
 * Chat Profile Manager — server-side resolution and persistence.
 *
 * The manager is the trust boundary of the chat-profile feature: every
 * request resolves the active profile server-side (user meta → site default
 * → write) and never accepts the client as the enforcement authority.
 * A client-sent profile that differs from the resolved value is honoured
 * only when the requester holds the switch capability; otherwise it is
 * dropped and logged as an escalation attempt.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat profile manager.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Chat_Profile_Manager {

	/**
	 * Per-user meta key storing the selected profile slug.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const META_KEY = 'wp_mcp_ai_chat_profile';

	/**
	 * Capability required to upgrade to (or generally switch) chat profiles.
	 * Mapped to manage_options via map_meta_cap in register().
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const SWITCH_CAPABILITY = 'wp_mcp_ai_change_chat_profile';

	/**
	 * Settings keys.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const SETTING_ENABLED = 'chat_profile_enabled';

	/**
	 * Site-wide default profile setting key.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const SETTING_DEFAULT = 'default_chat_profile';

	/**
	 * Guest-surface profile setting key.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const SETTING_GUEST = 'guest_chat_profile';

	/**
	 * Request-local resolution cache.
	 *
	 * @since 2.2.0
	 * @var array<string, string>
	 */
	private static $resolved = array();

	/**
	 * Bootstrap hooks (capability mapping).
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Map the switch capability to manage_options.
	 *
	 * @since 2.2.0
	 *
	 * @param array  $caps    Required capabilities.
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Additional context.
	 * @return array
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		unset( $user_id, $args );
		if ( self::SWITCH_CAPABILITY === $cap ) {
			$caps = array( 'manage_options' );
		}
		return $caps;
	}

	/**
	 * Whether the chat-profile feature is enabled site-wide.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();
		return (bool) ( isset( $settings[ self::SETTING_ENABLED ] ) ? $settings[ self::SETTING_ENABLED ] : true );
	}

	/**
	 * Read the merged plugin settings array.
	 *
	 * @since 2.2.0
	 *
	 * @return array
	 */
	private static function get_settings() {
		if ( class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			if ( is_array( $settings ) ) {
				return $settings;
			}
		}
		$option = get_option( 'wp_mcp_ai_settings', array() );
		return is_array( $option ) ? $option : array();
	}

	/**
	 * Get the site-wide default profile slug.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public static function get_site_default_slug() {
		$settings = self::get_settings();
		$slug     = isset( $settings[ self::SETTING_DEFAULT ] ) ? (string) $settings[ self::SETTING_DEFAULT ] : '';
		return self::normalise_slug( $slug, WP_MCP_AI_Chat_Profile::PROFILE_WRITE );
	}

	/**
	 * Get the profile slug applied to guest surfaces (user_id 0).
	 *
	 * Guests cannot persist a selection, so the site default for guests is
	 * read-only unless an administrator explicitly opts out.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public static function get_guest_slug() {
		$settings = self::get_settings();
		$slug     = isset( $settings[ self::SETTING_GUEST ] ) ? (string) $settings[ self::SETTING_GUEST ] : '';
		return self::normalise_slug( $slug, WP_MCP_AI_Chat_Profile::PROFILE_READ_ONLY );
	}

	/**
	 * Validate a slug against the registry, falling back when unknown.
	 *
	 * @since 2.2.0
	 *
	 * @param string $slug     Candidate slug.
	 * @param string $fallback Fallback slug.
	 * @return string
	 */
	private static function normalise_slug( $slug, $fallback ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( '' !== $slug && null !== WP_MCP_AI_Chat_Profile_Registry::get_profile( $slug ) ) {
			return $slug;
		}
		return $fallback;
	}

	/**
	 * Read the profile slug persisted for a user.
	 *
	 * Empty string = no personal selection (site default applies).
	 *
	 * @since 2.2.0
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_user_slug( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		return self::normalise_slug( (string) $raw, '' );
	}

	/**
	 * Resolve the profile object governing a chat request.
	 *
	 * @since 2.2.0
	 *
	 * @param int         $user_id   User ID (0 = guest).
	 * @param string|null $requested Client-sent profile slug (advisory).
	 * @return WP_MCP_AI_Chat_Profile
	 */
	public static function resolve( $user_id, $requested = null ) {
		$slug    = self::resolve_slug( $user_id, $requested );
		$profile = WP_MCP_AI_Chat_Profile_Registry::get_profile( $slug );
		if ( null === $profile ) {
			$profile = WP_MCP_AI_Chat_Profile_Registry::get_profile( WP_MCP_AI_Chat_Profile::PROFILE_WRITE );
		}
		return $profile;
	}

	/**
	 * Resolve the governing profile slug for a chat request.
	 *
	 * Resolution order:
	 *   1. Feature disabled → `write` (zero behaviour change).
	 *   2. Guest (user_id 0) → guest setting (read-only by default).
	 *   3. Per-user meta (validated).
	 *   4. Site default setting.
	 *   5. `write` fail-safe.
	 *
	 * A client-sent profile is adopted only as a per-request override by
	 * users who hold the switch capability; anything else is dropped and
	 * logged as an escalation attempt.
	 *
	 * @since 2.2.0
	 *
	 * @param int         $user_id   User ID (0 = guest).
	 * @param string|null $requested  Client-sent profile slug (advisory).
	 * @return string Resolved profile slug.
	 */
	public static function resolve_slug( $user_id, $requested = null ) {
		$user_id   = (int) $user_id;
		$requested = ( null === $requested || '' === $requested ) ? null : (string) $requested;

		$cache_key = $user_id . ':' . ( null === $requested ? '' : $requested );
		if ( isset( self::$resolved[ $cache_key ] ) ) {
			return self::$resolved[ $cache_key ];
		}

		if ( ! self::is_enabled() ) {
			self::$resolved[ $cache_key ] = WP_MCP_AI_Chat_Profile::PROFILE_WRITE;
			return self::$resolved[ $cache_key ];
		}

		if ( $user_id <= 0 ) {
			self::$resolved[ $cache_key ] = self::get_guest_slug();
			return self::$resolved[ $cache_key ];
		}

		$slug = self::get_user_slug( $user_id );
		if ( '' === $slug ) {
			$slug = self::get_site_default_slug();
		}

		// Client override: honoured only for capability holders so the client
		// can never widen its own grant (skilder least-privilege rule).
		if ( null !== $requested && $requested !== $slug ) {
			$candidate = self::normalise_slug( $requested, '' );
			if ( '' !== $candidate && WP_MCP_AI_Chat_Profile_Registry::can_user_select( $user_id, $candidate ) ) {
				self::$resolved[ $cache_key ] = $candidate;
				return self::$resolved[ $cache_key ];
			}

			// Log the mismatch without throwing — the resolved value stands.
			if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
				WP_MCP_AI_Security_Audit_Logger::log_event(
					WP_MCP_AI_Security_Audit_Logger::EVENT_CHAT_PROFILE_ESCALATION_ATTEMPT,
					$user_id,
					array(
						'requested' => '' !== $candidate ? $candidate : $requested,
						'resolved'  => $slug,
					)
				);
			}
		}

		self::$resolved[ $cache_key ] = $slug;
		return self::$resolved[ $cache_key ];
	}

	/**
	 * Persist a profile selection for a user.
	 *
	 * @since 2.2.0
	 *
	 * @param int    $user_id       User ID.
	 * @param string $slug          Target profile slug.
	 * @param bool   $bypass_policy Skip the selection-policy check. Reserved
	 *                              for administrator surfaces (WP-CLI) that
	 *                              already imply server access.
	 * @return bool|WP_Error True on success; WP_Error when invalid or forbidden.
	 */
	public static function set_user_profile( $user_id, $slug, $bypass_policy = false ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_chat_profile_invalid_user', __( 'Guests cannot select a chat profile.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'wp_mcp_ai_chat_profile_disabled', __( 'Chat profiles are disabled site-wide.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}

		$slug = self::normalise_slug( (string) $slug, '' );
		if ( '' === $slug ) {
			return new WP_Error( 'wp_mcp_ai_chat_profile_invalid', __( 'Unknown chat profile.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}
		if ( ! $bypass_policy && ! WP_MCP_AI_Chat_Profile_Registry::can_user_select( $user_id, $slug ) ) {
			return new WP_Error( 'wp_mcp_ai_chat_profile_forbidden', __( 'You are not allowed to select this chat profile.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}

		// Selecting the site default clears the personal override.
		if ( self::get_site_default_slug() === $slug ) {
			delete_user_meta( $user_id, self::META_KEY );
		} else {
			update_user_meta( $user_id, self::META_KEY, $slug );
		}

		self::reset_cache();
		return true;
	}

	/**
	 * Flush the request-local resolution cache.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$resolved = array();
	}
}
