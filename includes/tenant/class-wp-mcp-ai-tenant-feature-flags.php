<?php
/**
 * Tenant Feature Flags
 *
 * Manages the global and per-toolkit feature flags that gate tenant
 * isolation.  This allows a gradual rollout — global off by default, then
 * enabled per-toolkit, then globally on.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenant feature flag manager.
 */
class WP_MCP_AI_Tenant_Feature_Flags {

	/**
	 * Global option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_tenant_isolation_enabled';

	/**
	 * Per-toolkit option key prefix.
	 *
	 * @var string
	 */
	const TOOLKIT_OPTION_PREFIX = 'wp_mcp_ai_tenant_isolation_toolkit_';

	/**
	 * Whether tenant isolation is globally enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		// Allow override via constant for wp-config.php control.
		if ( defined( 'WP_MCP_AI_TENANT_ISOLATION' ) ) {
			return (bool) WP_MCP_AI_TENANT_ISOLATION;
		}

		return (bool) get_option( self::OPTION_KEY, false );
	}

	/**
	 * Enable tenant isolation globally.
	 *
	 * @return void
	 */
	public static function enable(): void {
		update_option( self::OPTION_KEY, true, false );
	}

	/**
	 * Disable tenant isolation globally.
	 *
	 * @return void
	 */
	public static function disable(): void {
		update_option( self::OPTION_KEY, false, false );
	}

	/**
	 * Whether a specific toolkit has tenant isolation enabled.
	 *
	 * If globally enabled, all toolkits are enabled unless explicitly
	 * opted out.  If globally disabled, only explicitly opted-in toolkits
	 * are enabled.
	 *
	 * @param string $toolkit_slug Toolkit slug (e.g. 'crm', 'eca-management').
	 * @return bool
	 */
	public static function is_toolkit_enabled( string $toolkit_slug ): bool {
		$global = self::is_enabled();

		if ( $global ) {
			// Global on — check for explicit opt-out.
			$opt_out = get_option( self::TOOLKIT_OPTION_PREFIX . $toolkit_slug . '_opt_out', false );
			return ! $opt_out;
		}

		// Global off — check for explicit opt-in.
		return (bool) get_option( self::TOOLKIT_OPTION_PREFIX . $toolkit_slug, false );
	}

	/**
	 * Enable tenant isolation for a specific toolkit.
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return void
	 */
	public static function enable_toolkit( string $toolkit_slug ): void {
		update_option( self::TOOLKIT_OPTION_PREFIX . $toolkit_slug, true, false );
	}

	/**
	 * Disable tenant isolation for a specific toolkit.
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return void
	 */
	public static function disable_toolkit( string $toolkit_slug ): void {
		update_option( self::TOOLKIT_OPTION_PREFIX . $toolkit_slug, false, false );
	}

	/**
	 * Opt a toolkit out of global tenant isolation.
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return void
	 */
	public static function opt_out_toolkit( string $toolkit_slug ): void {
		update_option( self::TOOLKIT_OPTION_PREFIX . $toolkit_slug . '_opt_out', true, false );
	}

	/**
	 * Assert that tenant isolation is active — throws if not.
	 *
	 * Use this in tool execute() methods that require isolation.
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return void
	 * @throws \RuntimeException When tenant isolation is not active for the toolkit.
	 */
	public static function require_isolation( string $toolkit_slug ): void {
		if ( ! self::is_toolkit_enabled( $toolkit_slug ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: toolkit slug */
					esc_html__( 'Tenant isolation is not enabled for toolkit "%s".', 'mcp-ai-wpoos' ),
					esc_html( $toolkit_slug )
				)
			);
		}
	}

	/**
	 * Get list of all toolkits with tenant isolation enabled.
	 *
	 * @return string[] Array of toolkit slugs.
	 */
	public static function get_enabled_toolkits(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value = '1'",
				$wpdb->esc_like( self::TOOLKIT_OPTION_PREFIX ) . '%'
			)
		);
		// phpcs:enable

		$toolkits = array();
		$prefix_len = strlen( self::TOOLKIT_OPTION_PREFIX );

		foreach ( $results as $option_name ) {
			$slug = substr( $option_name, $prefix_len );
			// Skip opt-out entries.
			if ( substr( $slug, -8 ) === '_opt_out' ) {
				continue;
			}
			$toolkits[] = $slug;
		}

		return $toolkits;
	}
}
