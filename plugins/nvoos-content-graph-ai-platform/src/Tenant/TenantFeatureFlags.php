<?php
/**
 * Tenant Feature Flags (Wave E4, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Tenant_Feature_Flags`:
 * byte-identical `wp_mcp_ai_tenant_isolation_enabled` option key, the
 * `wp_mcp_ai_tenant_isolation_toolkit_` per-toolkit prefix (with the
 * `_opt_out` suffix variant), the `WP_MCP_AI_TENANT_ISOLATION` constant
 * override, the settings-toggle read (`wp_mcp_ai_settings` →
 * `enable_tenant_isolation`), the global-on → opt-out / global-off →
 * opt-in toolkit resolution, the `require_isolation()` RuntimeException,
 * and the LIKE-based enabled-toolkit scan.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Tenant
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tenant;

/**
 * Tenant feature flag manager.
 *
 * Manages the global and per-toolkit feature flags that gate tenant
 * isolation (gradual rollout — global off by default, then per-toolkit,
 * then globally on).
 *
 * @since 2.1.0
 */
class TenantFeatureFlags {

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

		// Check the admin settings toggle (Tools → Features → Multi-Tenant Isolation).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['enable_tenant_isolation'] ) ) {
			return true;
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
	 * opted out. If globally disabled, only explicitly opted-in toolkits
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
					esc_html__( 'Tenant isolation is not enabled for toolkit "%s".', 'nvoos-content-graph-ai-platform' ),
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

		$toolkits   = array();
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
