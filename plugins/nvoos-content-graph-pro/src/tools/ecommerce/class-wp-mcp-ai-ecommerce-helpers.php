<?php
/**
 * E-commerce Toolkit Helpers (ecosystem port — Wave F2, e-commerce data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-ecommerce-helpers.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; path/URL/version constants resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_*`. The init's four admin-page requires are
 * file-gated — the pages land with the F2 admin slice (wave-proof).
 *
 * @package NvoosContentGraphPro
 * @since 2.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the E-commerce Toolkit is enabled.
 *
 * The toolkit must be explicitly enabled in plugin settings (Pro features).
 *
 * @since 2.1.0
 *
 * @return bool True if enabled, false otherwise.
 */
function wp_mcp_ai_is_ecommerce_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_ecommerce_toolkit'] );
}
