<?php
/**
 * Schedule Anything — Toolkit Adapter
 *
 * Bridges the Schedule Anything REST surface to the canonical per-tenant
 * toolkit flag map owned by the platform plugin's SA_Toolkit_Manager.
 * When the platform plugin is absent the adapter degrades to an empty
 * catalogue (the Schedule Anything surface is a platform feature), so the
 * Pro addon remains loadable standalone.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit data adapter for the Schedule Anything surface.
 *
 * Single source of truth for toolkit slugs is SA_Toolkit_Manager::TOOLKIT_FLAGS
 * (platform plugin). No slug map is duplicated here (Proposal 081, D2).
 *
 * @since 1.2.8
 */
class WP_MCP_AI_SA_Toolkit_Adapter {

	/**
	 * Whether the platform plugin is active in this tenant.
	 *
	 * @since 1.2.8
	 *
	 * @return bool
	 */
	public static function is_platform_active() {
		return class_exists( 'SA_Toolkit_Manager' );
	}

	/**
	 * The tenant's subscription tier (platform only).
	 *
	 * @since 1.2.8
	 *
	 * @return string 'starter', 'professional', 'enterprise', or 'unknown'.
	 */
	public static function get_tier() {
		if ( ! self::is_platform_active() ) {
			return 'unknown';
		}

		$meta = get_option( 'sa_tenant_meta', array() );

		return isset( $meta['tier'] ) ? (string) $meta['tier'] : 'starter';
	}

	/**
	 * List toolkits with their per-tenant status.
	 *
	 * @since 1.2.8
	 *
	 * @return array<int, array{slug: string, flag_key: string, enabled: bool}>
	 */
	public static function get_toolkits() {
		if ( ! self::is_platform_active() ) {
			return array();
		}

		$statuses = SA_Toolkit_Manager::get_all_statuses();

		return is_array( $statuses ) ? $statuses : array();
	}

	/**
	 * Whether a toolkit slug exists in the canonical map.
	 *
	 * @since 1.2.8
	 *
	 * @param string $slug Toolkit slug.
	 * @return bool
	 */
	public static function is_known_slug( $slug ) {
		if ( ! self::is_platform_active() ) {
			return false;
		}

		$flags = SA_Toolkit_Manager::TOOLKIT_FLAGS;

		return isset( $flags[ $slug ] );
	}

	/**
	 * Whether the current tenant's tier entitles the given toolkit.
	 *
	 * The tier check is server-side (Proposal 081 §2.1): the SPA never
	 * decides what is allowed.
	 *
	 * @since 1.2.8
	 *
	 * @param string $slug Toolkit slug.
	 * @return bool True when the toolkit is available for the tenant's tier.
	 */
	public static function is_entitled( $slug ) {
		if ( ! self::is_platform_active() ) {
			return false;
		}

		$tier = self::get_tier();
		if ( 'unknown' === $tier ) {
			return true; // Platform present but tier unset — treat as entitled (v1, Proposal 081 §8.1).
		}

		$allowed = SA_Toolkit_Manager::get_toolkit_slugs_for_tier( $tier );

		return is_array( $allowed ) && in_array( $slug, $allowed, true );
	}

	/**
	 * Toggle a toolkit for the current tenant.
	 *
	 * @since 1.2.8
	 *
	 * @param string $slug    Toolkit slug.
	 * @param bool   $enabled Desired state.
	 * @return true|WP_Error
	 */
	public static function toggle_toolkit( $slug, $enabled ) {
		if ( ! self::is_platform_active() ) {
			return new WP_Error(
				'sa_platform_required',
				__( 'The Schedule Anything Platform plugin is required to manage toolkits.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		if ( ! self::is_known_slug( $slug ) ) {
			return new WP_Error(
				'sa_toolkit_not_found',
				__( 'Unknown toolkit slug.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( ! self::is_entitled( $slug ) ) {
			return new WP_Error(
				'sa_toolkit_not_entitled',
				sprintf(
					/* translators: 1: toolkit slug, 2: tenant tier */
					__( 'Toolkit "%1$s" is not available on the %2$s tier.', 'mcp-ai-wpoos-pro' ),
					$slug,
					self::get_tier()
				),
				array( 'status' => 403 )
			);
		}

		$result = SA_Toolkit_Manager::toggle_toolkit( $slug, (bool) $enabled );

		if ( ! $result ) {
			return new WP_Error(
				'sa_toolkit_toggle_failed',
				__( 'Failed to update the toolkit setting.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}
}
