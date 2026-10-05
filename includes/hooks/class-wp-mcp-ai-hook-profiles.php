<?php
/**
 * Assistant Hook Profiles — advisory runtime gates over lifecycle hooks.
 *
 * The plugin fires 60+ public lifecycle hooks. This class adds ECC-style
 * profile gating on top of them WITHOUT changing any hook's timing or
 * arguments: subscribers opt in by consulting `allows()` /
 * `is_disabled()` before doing heavy work (audit logging, trace capture,
 * cost tracking, session distillation).
 *
 * Profiles are ranked minimal (0) < standard (1) < strict (2). A subscriber
 * that requires `standard` runs under `standard` and `strict` but not under
 * `minimal`. Resolution order: assistant meta → filter → site option →
 * constant → `standard`.
 *
 * Runtime toggles mirror ECC's hook-profile controls:
 *   - Master switch:  WP_MCP_AI_HOOK_PROFILES_ENABLED constant or
 *     `wp_mcp_ai_hook_profiles_enabled` filter (default on).
 *   - Per-hook kill list: WP_MCP_AI_DISABLED_HOOKS constant (comma list)
 *     or `wp_mcp_ai_disabled_hooks` filter (array).
 *
 * @credit  Profile-gated hooks inspired by affaan-m/ECC hook profiles
 *          (MIT).
 * @package WP_MCP_AI
 * @since   1.1.97
 * @author  NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assistant hook-profile gates.
 *
 * All methods are static and side-effect free; the class is never
 * instantiated.
 *
 * @since 1.1.97
 */
class WP_MCP_AI_Hook_Profiles {

	/**
	 * Minimal profile: only the lightest subscribers run.
	 *
	 * @var string
	 */
	const PROFILE_MINIMAL = 'minimal';

	/**
	 * Standard profile (default): normal observer set.
	 *
	 * @var string
	 */
	const PROFILE_STANDARD = 'standard';

	/**
	 * Strict profile: every subscriber runs, including the heavy ones.
	 *
	 * @var string
	 */
	const PROFILE_STRICT = 'strict';

	/**
	 * Assistant meta key holding a per-assistant profile override.
	 *
	 * @var string
	 */
	const META_KEY = '_wp_mcp_ai_hook_profile';

	/**
	 * Site option holding the default profile for assistants without an
	 * explicit override.
	 *
	 * @var string
	 */
	const OPTION_DEFAULT_PROFILE = 'wp_mcp_ai_default_hook_profile';

	/**
	 * Ordered profile ranks.
	 *
	 * @return array<string,int> Profile slug => rank.
	 */
	public static function get_profile_ranks() {
		return array(
			self::PROFILE_MINIMAL  => 0,
			self::PROFILE_STANDARD => 1,
			self::PROFILE_STRICT   => 2,
		);
	}

	/**
	 * Coerce arbitrary input to a valid profile slug.
	 *
	 * Anything unrecognised falls back to `standard` so a bad meta value can
	 * never accidentally widen (or narrow) the profile silently.
	 *
	 * @param mixed $raw Candidate profile slug.
	 * @return string One of minimal|standard|strict.
	 */
	public static function normalize_profile( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = strtolower( trim( $raw ) );
		}

		$ranks = self::get_profile_ranks();

		return isset( $ranks[ $raw ] ) ? $raw : self::PROFILE_STANDARD;
	}

	/**
	 * Resolve the effective profile for an assistant.
	 *
	 * Resolution order (first match wins):
	 *  1. Assistant meta `_wp_mcp_ai_hook_profile`
	 *  2. Filter `wp_mcp_ai_hook_profile`
	 *  3. Site option `wp_mcp_ai_default_hook_profile`
	 *  4. Constant `WP_MCP_AI_HOOK_PROFILE`
	 *  5. Default `standard`
	 *
	 * @param int $assistant_id Assistant post ID (0 = site-level resolution).
	 * @return string Profile slug.
	 */
	public static function get_profile( $assistant_id = 0 ) {
		$assistant_id = absint( $assistant_id );

		if ( $assistant_id > 0 ) {
			$meta = get_post_meta( $assistant_id, self::META_KEY, true );
			if ( '' !== (string) $meta ) {
				return self::normalize_profile( $meta );
			}
		}

		/**
		 * Filters the resolved hook profile for an assistant.
		 *
		 * @since 1.1.97
		 *
		 * @param string $profile      Current profile slug.
		 * @param int    $assistant_id Assistant post ID.
		 */
		$profile = apply_filters( 'wp_mcp_ai_hook_profile', '', $assistant_id );
		if ( '' !== (string) $profile ) {
			return self::normalize_profile( $profile );
		}

		$option = get_option( self::OPTION_DEFAULT_PROFILE, '' );
		if ( '' !== (string) $option ) {
			return self::normalize_profile( $option );
		}

		if ( defined( 'WP_MCP_AI_HOOK_PROFILE' ) && '' !== (string) constant( 'WP_MCP_AI_HOOK_PROFILE' ) ) {
			return self::normalize_profile( constant( 'WP_MCP_AI_HOOK_PROFILE' ) );
		}

		return self::PROFILE_STANDARD;
	}

	/**
	 * Whether the hook-profile system is enabled at all.
	 *
	 * When disabled, `allows()` always returns true and `is_disabled()`
	 * always returns false — legacy all-subscribers-on behavior.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'WP_MCP_AI_HOOK_PROFILES_ENABLED' ) && false === constant( 'WP_MCP_AI_HOOK_PROFILES_ENABLED' ) ) {
			return false;
		}

		/**
		 * Filters whether hook-profile gating is active.
		 *
		 * @since 1.1.97
		 *
		 * @param bool $enabled Whether gating is active. Default true.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_hook_profiles_enabled', true );
	}

	/**
	 * Whether a specific hook is disabled by the runtime kill list.
	 *
	 * Kill-list sources: `WP_MCP_AI_DISABLED_HOOKS` constant (comma
	 * separated) and the `wp_mcp_ai_disabled_hooks` filter (array).
	 *
	 * @param string $hook         Hook name.
	 * @param int    $assistant_id Assistant post ID (for filtering).
	 * @return bool True when the hook is disabled.
	 */
	public static function is_disabled( $hook, $assistant_id = 0 ) {
		$hook = (string) $hook;
		if ( '' === $hook ) {
			return false;
		}

		$disabled = array();

		if ( defined( 'WP_MCP_AI_DISABLED_HOOKS' ) && is_string( constant( 'WP_MCP_AI_DISABLED_HOOKS' ) ) ) {
			foreach ( explode( ',', constant( 'WP_MCP_AI_DISABLED_HOOKS' ) ) as $entry ) {
				$entry = sanitize_key( trim( $entry ) );
				if ( '' !== $entry ) {
					$disabled[] = $entry;
				}
			}
		}

		/**
		 * Filters the per-hook disable list.
		 *
		 * @since 1.1.97
		 *
		 * @param array  $disabled     Hook names to disable.
		 * @param string $hook         Hook being consulted.
		 * @param int    $assistant_id Assistant post ID.
		 */
		$disabled = apply_filters( 'wp_mcp_ai_disabled_hooks', $disabled, $hook, absint( $assistant_id ) );

		return in_array( $hook, $disabled, true );
	}

	/**
	 * Whether a subscriber may run a hook under the assistant's profile.
	 *
	 * Combined gate: master switch on AND hook not disabled AND the
	 * resolved profile rank is at least the required rank.
	 *
	 * @param string $hook             Hook name being consulted.
	 * @param int    $assistant_id     Assistant post ID.
	 * @param string $required_profile Minimum profile the subscriber needs.
	 * @return bool True when the subscriber may run.
	 */
	public static function allows( $hook, $assistant_id, $required_profile ) {
		if ( ! self::is_enabled() ) {
			return true;
		}

		if ( self::is_disabled( $hook, $assistant_id ) ) {
			return false;
		}

		$ranks    = self::get_profile_ranks();
		$profile  = self::get_profile( $assistant_id );
		$required = self::normalize_profile( $required_profile );

		return $ranks[ $profile ] >= $ranks[ $required ];
	}
}
