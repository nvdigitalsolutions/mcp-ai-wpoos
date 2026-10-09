<?php
/**
 * Chat Profile Registry — single source of truth for chat profiles.
 *
 * Registers the two built-in profiles (`write` and `read-only`) and exposes
 * the filterable catalogue consumed by the manager, the enforcement gate, the
 * REST controller, and the SPA runtime localization.
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
 * Chat profile registry.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Chat_Profile_Registry {

	/**
	 * Request-local profile cache.
	 *
	 * @since 2.2.0
	 * @var array<string, WP_MCP_AI_Chat_Profile>|null
	 */
	private static $profiles = null;

	/**
	 * Get every registered profile, keyed by slug.
	 *
	 * @since 2.2.0
	 *
	 * @return array<string, WP_MCP_AI_Chat_Profile>
	 */
	public static function get_profiles() {
		if ( null !== self::$profiles ) {
			return self::$profiles;
		}

		$definitions = array(
			array(
				'slug'        => WP_MCP_AI_Chat_Profile::PROFILE_WRITE,
				'label'       => __( 'Full access', 'mcp-ai-wpoos' ),
				'description' => __( 'All tools allowed. Destructive actions still require confirmation.', 'mcp-ai-wpoos' ),
				'gated_flags' => array(),
				'is_default'  => true,
			),
			array(
				'slug'                => WP_MCP_AI_Chat_Profile::PROFILE_READ_ONLY,
				'label'               => __( 'Read-only', 'mcp-ai-wpoos' ),
				'description'         => __( 'Reads allowed. Writes, state changes and destructive actions are blocked.', 'mcp-ai-wpoos' ),
				'gated_flags'         => WP_MCP_AI_Chat_Profile::READ_ONLY_GATED_FLAGS,
				'allowed_tool_slugs'  => array(),
				'required_capability' => 'read',
			),
		);

		/**
		 * Filter the registered chat profiles.
		 *
		 * Pro addons can register additional profiles (e.g. `publish-only`,
		 * `commerce`) without touching the enforcement gate.
		 *
		 * @since 2.2.0
		 *
		 * @param array $definitions Profile definition arrays.
		 */
		$definitions = apply_filters( 'wp_mcp_ai_chat_profiles', $definitions );

		$profiles = array();
		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$profile = WP_MCP_AI_Chat_Profile::from_array( $definition );
			if ( $profile instanceof WP_MCP_AI_Chat_Profile && '' !== $profile->get_slug() ) {
				$profiles[ $profile->get_slug() ] = $profile;
			}
		}

		// The write profile is the fail-safe floor: without it the manager has
		// no non-restrictive fallback and a misconfigured filter would lock
		// every chat out of tool access.
		if ( ! isset( $profiles[ WP_MCP_AI_Chat_Profile::PROFILE_WRITE ] ) ) {
			$profiles[ WP_MCP_AI_Chat_Profile::PROFILE_WRITE ] = WP_MCP_AI_Chat_Profile::from_array(
				array(
					'slug'        => WP_MCP_AI_Chat_Profile::PROFILE_WRITE,
					'label'       => __( 'Full access', 'mcp-ai-wpoos' ),
					'description' => __( 'All tools allowed. Destructive actions still require confirmation.', 'mcp-ai-wpoos' ),
					'is_default'  => true,
				)
			);
		}

		self::$profiles = $profiles;
		return self::$profiles;
	}

	/**
	 * Get a single profile by slug.
	 *
	 * @since 2.2.0
	 *
	 * @param string $slug Profile slug.
	 * @return WP_MCP_AI_Chat_Profile|null Null when unknown.
	 */
	public static function get_profile( $slug ) {
		$profiles = self::get_profiles();
		return isset( $profiles[ (string) $slug ] ) ? $profiles[ (string) $slug ] : null;
	}

	/**
	 * List profiles a given user is allowed to SELECT.
	 *
	 * Selection eligibility is a capability question, not an enforcement
	 * question — a user runs under whatever profile the manager resolves for
	 * them regardless of what they may select here.
	 *
	 * @since 2.2.0
	 *
	 * @param int $user_id User ID (0 = guest, selects nothing).
	 * @return array<string, WP_MCP_AI_Chat_Profile>
	 */
	public static function list_selectable_for_user( $user_id ) {
		$user_id  = (int) $user_id;
		$profiles = self::get_profiles();

		if ( 0 === $user_id ) {
			return array();
		}

		$selectable = array();
		foreach ( $profiles as $slug => $profile ) {
			if ( self::can_user_select( $user_id, $slug ) ) {
				$selectable[ $slug ] = $profile;
			}
		}
		return $selectable;
	}

	/**
	 * Whether a user may select a specific profile.
	 *
	 * Implements the downgrade/upgrade policy: switching to the write profile
	 * is an upgrade and requires the `wp_mcp_ai_change_chat_profile` capability
	 * (mapped to manage_options), while switching to a less privileged profile
	 * only requires its own declared capability (default `read`).
	 *
	 * @since 2.2.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug    Target profile slug.
	 * @return bool
	 */
	public static function can_user_select( $user_id, $slug ) {
		$user_id = (int) $user_id;
		$profile = self::get_profile( $slug );
		if ( null === $profile ) {
			return false;
		}
		if ( 0 === $user_id ) {
			return false;
		}

		// Upgrading to the non-restrictive profile is always the privileged
		// direction — never let a downgraded user self-elevate.
		if ( ! $profile->is_restrictive() ) {
			return user_can( $user_id, 'wp_mcp_ai_change_chat_profile' );
		}

		$cap = $profile->get_required_capability();
		return '' === $cap || user_can( $user_id, $cap ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Profile-declared capability resolved per profile (e.g. read); dynamic by design.
	}

	/**
	 * Flush the request-local cache (tests and long-lived processes).
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$profiles = null;
	}
}
