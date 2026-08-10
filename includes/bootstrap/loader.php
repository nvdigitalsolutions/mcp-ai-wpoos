<?php
/**
 * Class Loader — require_once chain
 *
 * Loads all plugin class files and init scripts in the correct dependency order.
 * This file is included by mcp-ai-wpoos.php after constants, autoload, and
 * helper functions are available.
 *
 * Output buffering around this block suppresses any incidental output that
 * could break JSON responses; it is skipped during Elementor AJAX requests
 * and editor page loads.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Autoload conditional: when WP_MCP_AI_AUTOLOAD_CLASSES is true (default),
// class-only files are skipped here because Composer's classmap resolves
// them on-demand. This helper checks whether autoloading is active AND the
// class defined in the file has already been loaded — if so, the require
// is skipped.
// To complete the autoload migration:
// 1. Run composer dump-autoload to build the classmap from composer.json.
// 2. Run the full test suite with WP_MCP_AI_AUTOLOAD_CLASSES enabled.
// 3. Once validated, remove the legacy path and this helper in v1.5.0.
if ( ! function_exists( 'wp_mcp_ai_class_exists_via_autoload' ) ) {
	/**
	 * Check whether a class-only file can be skipped under autoload.
	 *
	 * When WP_MCP_AI_AUTOLOAD_CLASSES is enabled and Composer's
	 * classmap has loaded the class, this helper returns true so
	 * the require_once in the loader chain can be skipped.
	 *
	 * Uses a filename-to-classname heuristic: strips the file
	 * prefix and maps dashes-to-underscores via ucwords into
	 * the canonical WP_MCP_AI_*, Interface_WP_MCP_AI_*, or
	 * WP_MCP_AI_Trait_* class name.
	 *
	 * @since 1.2.0
	 *
	 * @param string $file_path Absolute path to a class-only PHP file.
	 * @return bool True if the class is already loaded and the
	 *              require_once can be skipped.
	 */
	function wp_mcp_ai_class_exists_via_autoload( $file_path ) {
		if ( ! defined( 'WP_MCP_AI_AUTOLOAD_CLASSES' ) || ! WP_MCP_AI_AUTOLOAD_CLASSES ) {
			return false; // Autoloading disabled — force require.
		}
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		// Check if the file only defines a class/interface/trait and that
		// class is already loaded (by Composer's classmap autoloader).
		$basename = basename( $file_path, '.php' );
		if ( 0 === strpos( $basename, 'class-' ) ) {
			$classname = str_replace( '-', '_', substr( $basename, 6 ) );
			if ( 0 === strpos( $classname, 'wp_mcp_ai_' ) ) {
				$classname = substr( $classname, 10 );
			}
			$classname = 'WP_MCP_AI_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $classname ) ) );
			if ( class_exists( $classname, false ) || interface_exists( $classname, false ) || trait_exists( $classname, false ) ) {
				return true;
			}
		}
		if ( 0 === strpos( $basename, 'interface-' ) ) {
			$classname = str_replace( '-', '_', substr( $basename, 10 ) );
			if ( 0 === strpos( $classname, 'wp_mcp_ai_' ) ) {
				$classname = substr( $classname, 10 );
			}
			$classname = 'Interface_WP_MCP_AI_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $classname ) ) );
			if ( interface_exists( $classname, false ) ) {
				return true;
			}
		}
		if ( 0 === strpos( $basename, 'trait-' ) ) {
			$classname = str_replace( '-', '_', substr( $basename, 6 ) );
			if ( 0 === strpos( $classname, 'wp_mcp_ai_' ) ) {
				$classname = substr( $classname, 10 );
			}
			$classname = 'WP_MCP_AI_Trait_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $classname ) ) );
			if ( trait_exists( $classname, false ) ) {
				return true;
			}
		}
		return false;
	}
}

// ---------------------------------------------------------------------------
// Autoload migration (v1.2.0+)
//
// When WP_MCP_AI_AUTOLOAD_CLASSES is true (default), class-only files are not
// require_once'd here — Composer's classmap resolves them on-demand. Files with
// side effects (init scripts, hook registrations) are still required. The
// full manual require chain is preserved behind the constant set to false.
// To complete the migration:
// 1. composer dump-autoload (builds the classmap from composer.json)
// 2. Identify files with side effects (~30 in the loader) and keep their
// require_once calls.
// 3. Run the full test suite with the constant true.
// 4. Once validated, remove the legacy path in v1.5.0.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Output-buffer guard (suppress stray output from included files)
// ---------------------------------------------------------------------------

$wp_mcp_ai_is_ajax_request     = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
	|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );
$wp_mcp_ai_is_elementor_ajax   = false;
$wp_mcp_ai_is_elementor_editor = false;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking action name, not processing data.
if ( $wp_mcp_ai_is_ajax_request && isset( $_REQUEST['action'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking action name, not processing data.
	$wp_mcp_ai_request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
	$wp_mcp_ai_is_elementor_ajax = ( strpos( $wp_mcp_ai_request_action, 'elementor' ) === 0 );
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification in its editor loader.
if ( ! $wp_mcp_ai_is_ajax_request && isset( $_GET['action'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification in its editor loader.
	$wp_mcp_ai_get_action          = sanitize_text_field( wp_unslash( $_GET['action'] ) );
	$wp_mcp_ai_is_elementor_editor = ( 'elementor' === $wp_mcp_ai_get_action );
}

$wp_mcp_ai_skip_buffering = $wp_mcp_ai_is_elementor_ajax || $wp_mcp_ai_is_elementor_editor;

if ( ! $wp_mcp_ai_skip_buffering ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional: graceful degradation when output buffering is disabled by host.
	if ( ! @ob_start() ) {
		ob_start();
	}
}

// ---------------------------------------------------------------------------
// Core admin settings components
// ---------------------------------------------------------------------------

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-renderer.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-renderer.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-validator.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-validator.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php';
}

// Load abstract settings section base class early so Pro addon sections can extend it.
// Must be loaded before the Pro addon is loaded to prevent fatal errors when Pro sections
// extend WP_MCP_AI_Settings_Section during plugin activation.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
}

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-chart-js-helper.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-chart-js-helper.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-analytics-dashboard.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-analytics-dashboard.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cost-calculator.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cost-calculator.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-analytics-engine.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-analytics-engine.php';
}

// ---------------------------------------------------------------------------
// Measurement subsystem (metrics, verifiers, reward functions)
// ---------------------------------------------------------------------------
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-measurement-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-measurement-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-collector.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-collector.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/interface-wp-mcp-ai-verifier.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/interface-wp-mcp-ai-verifier.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-verifier-base.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-verifier-base.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-verifier-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-verifier-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-reward-function-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-reward-function-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/verifiers/class-wp-mcp-ai-rule-verifier.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/verifiers/class-wp-mcp-ai-rule-verifier.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/verifiers/class-wp-mcp-ai-schema-verifier.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/verifiers/class-wp-mcp-ai-schema-verifier.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/verifiers/class-wp-mcp-ai-llm-judge-verifier.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/verifiers/class-wp-mcp-ai-llm-judge-verifier.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/rewards/class-wp-mcp-ai-reference-rewards.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/rewards/class-wp-mcp-ai-reference-rewards.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/budgets/class-wp-mcp-ai-budget-envelope.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/budgets/class-wp-mcp-ai-budget-envelope.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/budgets/class-wp-mcp-ai-budget-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/budgets/class-wp-mcp-ai-budget-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/exporters/class-wp-mcp-ai-otel-exporter.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/exporters/class-wp-mcp-ai-otel-exporter.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-case.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-case.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-suite.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-suite.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-suite-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-suite-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-runner.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-runner.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-counterfactual-runner.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-counterfactual-runner.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-regression-detector.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-regression-detector.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-run-store.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/eval/class-wp-mcp-ai-eval-run-store.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-stock-metrics.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-stock-metrics.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-tool-execution-observer.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-tool-execution-observer.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-chat-turn-metrics.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-chat-turn-metrics.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-chat-turn-observer.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-chat-turn-observer.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-sse-metrics.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-sse-metrics.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-sse-observer.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-sse-observer.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-event-store.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-event-store.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-persister.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-persister.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-retention.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-metric-retention.php';
}
if ( is_admin() ) {
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/measurement/class-wp-mcp-ai-admin-measurement-dashboard.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/measurement/class-wp-mcp-ai-admin-measurement-dashboard.php';
	}
}
require_once WP_MCP_AI_PATH . 'includes/measurement/class-wp-mcp-ai-measurement-bootstrap.php';
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-oauth-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-oauth-manager.php';
}

// ---------------------------------------------------------------------------
// Infrastructure utilities (must load early)
// ---------------------------------------------------------------------------

// Interface contracts and their WordPress/provider adapter implementations.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-options-store.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-options-store.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-capability-checker.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-capability-checker.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-http-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-http-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-bulk-operation.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-bulk-operation.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-cron-status-job-source.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-cron-status-job-source.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-service-status-source.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-service-status-source.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/wp/class-wp-mcp-ai-wp-options-store.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/wp/class-wp-mcp-ai-wp-options-store.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/wp/class-wp-mcp-ai-wp-capability-checker.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/wp/class-wp-mcp-ai-wp-capability-checker.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/http/class-wp-mcp-ai-wp-http-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/http/class-wp-mcp-ai-wp-http-client.php';
}

// HTTP helper prevents SSL issues with loopback addresses.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http-helper.php';
WP_MCP_AI_HTTP_Helper::init();

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cache-helper.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cache-helper.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-cache.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-cache.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-profession-search-helper.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-profession-search-helper.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-tool-presets-helper.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-tool-presets-helper.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-user-context-helper.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-user-context-helper.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-content-format-helper.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-content-format-helper.php';
}
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-api-context-fix.php';

// ---------------------------------------------------------------------------
// Core plugin classes
// ---------------------------------------------------------------------------

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-admin-settings.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-admin-settings.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-resource-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-resource-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-optional-components.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-optional-components.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-plugin-updater.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-plugin-updater.php';
}

// Initialize the plugin updater (GitHub releases for full builds).
WP_MCP_AI_Plugin_Updater::init();

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-error-handler.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-error-handler.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-activation-tracker.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-activation-tracker.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-root-security-key.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-root-security-key.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-nefarious-usage-monitor.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-nefarious-usage-monitor.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-proxy-utils.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-proxy-utils.php';
}

// Remote tester is excluded from production builds.
if ( file_exists( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-remote-tester.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-remote-tester.php';
}

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-privacy.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-privacy.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-site-health.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-site-health.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-credentials.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-credentials.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rate-limit-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rate-limit-manager.php';
}

// ---------------------------------------------------------------------------
// Security hardening (1.2.0) — encrypted key store, SSRF guard, helpers
// ---------------------------------------------------------------------------
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-api-key-store.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-api-key-store.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-url-guard.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-url-guard.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-concurrency-guard.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-concurrency-guard.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-cost-tracker.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-cost-tracker.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/exceptions/class-wp-mcp-ai-destructive-confirmation-required.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/exceptions/class-wp-mcp-ai-destructive-confirmation-required.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-destructive-ops-gate.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-destructive-ops-gate.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-request-guard.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-request-guard.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-csp-headers.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-csp-headers.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-security-audit-logger.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-security-audit-logger.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/helpers/api-key-helpers.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/helpers/api-key-helpers.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-validated-upload.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-validated-upload.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-object-access.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-object-access.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-media-worker-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-media-worker-client.php';
}

	// ---------------------------------------------------------------------------
	// Transparency & Compliance (Proposal 017) — AI disclosure, consent, provenance
	// ---------------------------------------------------------------------------
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-transparency-service.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-transparency-service.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-consent-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-consent-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-generation-provenance.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-generation-provenance.php';
}

	// ---------------------------------------------------------------------------
	// Dependency injection container and service layer
	// ---------------------------------------------------------------------------

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-container.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-container.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/container-helpers.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/container-helpers.php';
}
require_once WP_MCP_AI_PATH . 'includes/services-init.php';
require_once WP_MCP_AI_PATH . 'includes/agents-init.php';
require_once WP_MCP_AI_PATH . 'includes/content-assistant-init.php';

// ---------------------------------------------------------------------------
// AI provider clients and model infrastructure
// ---------------------------------------------------------------------------

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-selector.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-selector.php';
}
// Model Rate Limits CCT provides default model data regardless of JetEngine availability.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-rate-limits-cct.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-rate-limits-cct.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-config.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-config.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-catalog-migration.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-catalog-migration.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-discovery-service.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-discovery-service.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-router.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-router.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-dead-letter-queue.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-dead-letter-queue.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-batch-iterator.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-batch-iterator.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-artifact-helper.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-artifact-helper.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-lifecycle-descriptor.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-lifecycle-descriptor.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-data-budget-tracker.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-data-budget-tracker.php';
}
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-async-scheduler-bridge.php';
WP_MCP_AI_Async_Scheduler_Bridge::register_hooks();
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-queue-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-queue-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sla-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sla-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rabbitmq-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rabbitmq-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-queue-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-queue-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-default-assistants.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-default-assistants.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-openai-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-enhanced-openai-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-enhanced-openai-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-voice-provider.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-voice-provider.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-realtime-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-realtime-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-live-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-live-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-lm-studio-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-lm-studio-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-parser.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-parser.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-pack-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-pack-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-nvidia-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-nvidia-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-datasets-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-datasets-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-deepseek-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-deepseek-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openrouter-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-digitalocean-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-digitalocean-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-kimi-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-kimi-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-baseten-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-baseten-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-zai-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-zai-client.php';
}
// WP_MCP_AI_Embedded_Client is a Pro-only feature loaded by the Pro addon.

// Provider interface adapters (thin delegates over the concrete clients above).
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-openai-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-openai-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-gemini-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-gemini-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-ollama-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-ollama-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-anthropic-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-anthropic-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-cloudflare-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-cloudflare-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-nvidia-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-nvidia-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-lm-studio-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-lm-studio-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-openrouter-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-openrouter-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-digitalocean-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-digitalocean-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-baseten-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-baseten-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-deepseek-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-deepseek-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-kimi-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-kimi-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-zai-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-zai-provider-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-huggingface-provider-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/infrastructure/providers/class-wp-mcp-ai-huggingface-provider-client.php';
}

// ---------------------------------------------------------------------------
// WP 7.0 Connectors API bridge (no-op on WP < 7.0).
// Must load after all provider client classes but before the tool
// infrastructure so Credential_Resolver is available when
// WP_MCP_AI_Model_Config::get_available_providers() is called.
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/bridge/bridge-init.php';

// ---------------------------------------------------------------------------
// Tool infrastructure and utilities
// ---------------------------------------------------------------------------

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/tool-response-helpers.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tool-response-helpers.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-request-context.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-request-context.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-usage-tracker.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-usage-tracker.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-token-limits.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-token-limits.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-token-db-optimizer.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-token-db-optimizer.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-token-tracking-database.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-token-tracking-database.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-enhanced-token-tracking.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-enhanced-token-tracking.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-recommendations.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-recommendations.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-text-chunker.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-text-chunker.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-prompt-optimizer.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-prompt-optimizer.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-document-summarizer.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-document-summarizer.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-transcript-recorder.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-transcript-recorder.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-crawl4ai-local-api.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-crawl4ai-local-api.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-response-attachments.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-response-attachments.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/crawler/class-wp-mcp-ai-crawler.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/crawler/class-wp-mcp-ai-crawler.php';
}
require_once WP_MCP_AI_PATH . 'includes/job-notifier-init.php';

// ---------------------------------------------------------------------------
// Async chat continuation — durable correlation between async jobs and the
// chat sessions that started them. Listens to wp_mcp_ai_job_completed (and
// _failed / _cancelled) at priority 20 (after Job_Notifier caches status at
// priority 10) so the chat session can be resumed off-hook and the LLM can
// produce a follow-up message. See docs/features/chat/async-continuation.md
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/chat-continuation-init.php';
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-response-cache.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-response-cache.php';
}

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-rest-endpoints.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-rest-endpoints.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-tool-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-tool-registry.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-professional-selector-shortcode.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-professional-selector-shortcode.php';
}

// WebLLM enqueue has been moved to the NV oOS Embedded addon.
// The require was: WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-webllm-enqueue.php'.

// Excluded from WordPress.org deployment due to CDN dependencies.
if ( file_exists( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-transformers-enqueue.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-transformers-enqueue.php';
}

// Excluded from WordPress.org deployment due to CDN dependencies.
if ( file_exists( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-webworker-enqueue.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-webworker-enqueue.php';
}

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcodes.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcodes.php';
}

// ---------------------------------------------------------------------------
// Status page: service status registry, default sources, CPT, shortcode.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-service-status-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-service-status-registry.php';
}
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-service-status-default-sources.php';
WP_MCP_AI_Service_Status_Default_Sources_Bootstrap::register();
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-service-status-cpt.php';
add_action( 'init', array( 'WP_MCP_AI_Service_Status_CPT', 'register' ), 11 );
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-status-rest-controller.php';
add_action( 'rest_api_init', array( 'WP_MCP_AI_Status_REST_Controller', 'register_routes' ) );
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-status-shortcode.php';
add_shortcode( 'nvoos_status', array( 'WP_MCP_AI_Status_Shortcode', 'render' ) );

// Pro addon (must load BEFORE tools-init.php so Pro tools are registered first).
// See above section for the Pro addon loader.

if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	$wp_mcp_ai_pro_addon_file = WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php';
	if ( file_exists( $wp_mcp_ai_pro_addon_file ) ) {
		require_once $wp_mcp_ai_pro_addon_file;
	}
	unset( $wp_mcp_ai_pro_addon_file );
}

// ---------------------------------------------------------------------------
// Orchestration, tools, validators, and repositories
// ---------------------------------------------------------------------------

require_once WP_MCP_AI_PATH . 'includes/orchestration-init.php';
require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
require_once WP_MCP_AI_PATH . 'includes/markup-init.php';
require_once WP_MCP_AI_PATH . 'includes/tools-init.php';
require_once WP_MCP_AI_PATH . 'includes/abilities/abilities-init.php';
require_once WP_MCP_AI_PATH . 'includes/data/data-init.php';
require_once WP_MCP_AI_PATH . 'includes/services/content-embedding-init.php';
require_once WP_MCP_AI_PATH . 'includes/validators/validated-tools-init.php';

// ---------------------------------------------------------------------------
// LLM Harnessing subsystem (Layers A-F: prompt cues, reasoning trace,
// tool router scoring, retrieval facade, self-refine loop, memory scoping).
// Behaviour-preserving by default — every layer is gated by a per-assistant
// harness profile that ships in the "off" state.
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/harness/harness-init.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-otel-span-exporter.php';
// Register span exporter — no-op unless `wp_mcp_ai_otel_endpoint` is configured.
WP_MCP_AI_Otel_Span_Exporter::register();
require_once WP_MCP_AI_PATH . 'includes/repositories-init.php';
require_once WP_MCP_AI_PATH . 'includes/paper-store/paper-store-init.php';
require_once WP_MCP_AI_PATH . 'includes/okf/okf-init.php';
require_once WP_MCP_AI_PATH . 'includes/professions/professions-init.php';
require_once WP_MCP_AI_PATH . 'includes/teams/teams-init.php';

// ---------------------------------------------------------------------------
// HITL Approval Queue (Phase 2 — Human-in-the-Loop)
//
// JetEngine compatibility note: CPT registrations are intentionally placed at
// init priorities 11-14 (above JetEngine's own init window of 1-10) to avoid
// racing with JetEngine CCT module init. register_cron() is also deferred to
// an init hook rather than called during file loading, so it does not
// interfere with JetEngine's cron-based CCT table caching.
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-approval-queue.php';
add_action( 'init', array( 'WP_MCP_AI_Approval_Queue', 'register_cpt' ), 11 );
add_action( 'init', array( 'WP_MCP_AI_Approval_Queue', 'register_cron' ), 1 );

// ---------------------------------------------------------------------------
// PR-E: base-plugin job-source adapters (transcript mining, Crawl4AI, HITL)
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/services/job-sources/job-sources-init.php';

// ---------------------------------------------------------------------------
// Phase 3 — Workflow CPT + Engine V2
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-cpt.php';
add_action( 'init', array( 'WP_MCP_AI_Workflow_CPT', 'register_cpt' ), 12 );
add_action( 'init', array( 'WP_MCP_AI_Workflow_CPT', 'register_meta' ), 12 );
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-engine-v2.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-engine-v2.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-dispatcher.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-dispatcher.php';
}

// ---------------------------------------------------------------------------
// Phase 4 — Workflow Run CPT (durable execution event log)
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-run-cpt.php';
add_action( 'init', array( 'WP_MCP_AI_Workflow_Run_CPT', 'register_cpt' ), 13 );
add_action( 'init', array( 'WP_MCP_AI_Workflow_Run_CPT', 'register_meta' ), 13 );

// ---------------------------------------------------------------------------
// Phase 5 — Triggers, Webhooks, Sub-Agents
// ---------------------------------------------------------------------------
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-trigger-registry.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-trigger-registry.php';
}
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-trigger-cpt.php';
add_action( 'init', array( 'WP_MCP_AI_Workflow_Trigger_CPT', 'register_cpt' ), 14 );
add_action( 'init', array( 'WP_MCP_AI_Workflow_Trigger_CPT', 'register_meta' ), 14 );
add_action( 'init', array( 'WP_MCP_AI_Workflow_Trigger_CPT', 'register_all_triggers' ), 20 );
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-outbound-webhook.php';
add_action(
	'init',
	function () {
		WP_MCP_AI_Outbound_Webhook::get_instance();
	}
);

// ---------------------------------------------------------------------------
// A2A Protocol system
// ---------------------------------------------------------------------------

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-agent-card.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-agent-card.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-wellknown.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-wellknown.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-task-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-task-manager.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-message-translator.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-message-translator.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-client.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-client.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-push-notifications.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-push-notifications.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-webhook-handler.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/a2a/class-wp-mcp-ai-a2a-webhook-handler.php';
}

// ---------------------------------------------------------------------------
// Federation system
// ---------------------------------------------------------------------------

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-settings.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-settings.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-wellknown.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-wellknown.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ai-peer-cpt.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ai-peer-cpt.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-peer-sync.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-peer-sync.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-peer-tester.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-peer-tester.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-peer-test-rest.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-peer-test-rest.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-peer-verifier.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-peer-verifier.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-rate-limiter.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-rate-limiter.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sse-rate-limiter.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sse-rate-limiter.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-directory-rest.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-directory-rest.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation.php';
}

// ---------------------------------------------------------------------------
// ISO 27001 REST APIs
// ---------------------------------------------------------------------------

// The asset inventory class must load outside is_admin() because the REST
// endpoint at /assets/discover runs in non-admin context (is_admin() returns
// false for /wp-json/* requests).  The admin UI wrapper stays inside the
// is_admin() guard below.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-asset-inventory.php';
WP_MCP_AI_Asset_Inventory::get_instance();

// Same for security-training and supplier-security: their REST endpoints also
// resolve get_instance() outside of admin context.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-security-training.php';
WP_MCP_AI_Security_Training::get_instance();

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-supplier-security.php';
WP_MCP_AI_Supplier_Security::get_instance();

if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-asset-inventory-rest.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-asset-inventory-rest.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-security-training-rest.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-security-training-rest.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-supplier-security-rest.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-supplier-security-rest.php';
}

// ---------------------------------------------------------------------------
// Third-party plugin integrations (full version only, or when Pro is active)
// ---------------------------------------------------------------------------

if ( wp_mcp_ai_should_load_integrations() ) {
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-endpoint-report.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-endpoint-report.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-tool-handlers.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-tool-handlers.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetformbuilder-tool-handlers.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetformbuilder-tool-handlers.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-cct.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-cct.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-assistants-cct.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-assistants-cct.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-ai-peers-cct.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-ai-peers-cct.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-submissions-cct.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-submissions-cct.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-agent-memories-cct.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-agent-memories-cct.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-bridge.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-bridge.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-reader.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-reader.php';
	}
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-migrator.php';
	WP_MCP_AI_Agent_Memory_CCT_Migrator::bootstrap();
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-pricing-checker.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-pricing-checker.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-performance-blocks.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-performance-blocks.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/renderers/class-wp-mcp-ai-scheduled-result-renderer.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/renderers/class-wp-mcp-ai-scheduled-result-renderer.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-scheduled-result-block.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-scheduled-result-block.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chatkit-integration.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chatkit-integration.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-simple-jwt-login-integration.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-simple-jwt-login-integration.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-auth0-github.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-auth0-github.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-media.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-media.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-comments.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-comments.php';
	}
	require_once WP_MCP_AI_PATH . 'includes/integrations/github-integration-init.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/meta-integration-init.php';
	// Cloudways integration has been migrated to the Pro addon's cloudways-toolkit.
	// The settings fields (cloudways_email, cloudways_api_key, etc.) remain in base.
	// AJAX connection handlers have been updated to use the API v2 client.
	require_once WP_MCP_AI_PATH . 'includes/integrations/cloudflare-integration-init.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/mailjet-integration-init.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/quickbooks-integration-init.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/sitekit-integration-init.php';
} elseif ( wp_mcp_ai_is_jetengine_available() ) {
	// Base version with JetEngine: only load minimal CCT for chat transcript storage.
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-cct.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-cct.php';
	}
	// Agent-memory durable backing store also loads in the minimal path so
	// every JetEngine-enabled site benefits from the persistent memory tier.
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-agent-memories-cct.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-agent-memories-cct.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-bridge.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-bridge.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-reader.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-reader.php';
	}
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-agent-memory-cct-migrator.php';
	WP_MCP_AI_Agent_Memory_CCT_Migrator::bootstrap();
}

// MemPalace Capture Framework Phase A — capture service + tier manager are
// transport-agnostic (work on transient-only sites too), so they always load.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-capture-service.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-capture-service.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-tier-manager.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-tier-manager.php';
}

// Memory Layer 2026 Enhancements Phase 1 — privacy filter must load before
// any memory write happens so the `wp_mcp_ai_memory_pre_store_transform`
// hook is registered at priority 5 (before user transforms at priority 10).
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-privacy-filter.php';
WP_MCP_AI_Memory_Privacy_Filter::bootstrap();

// Memory Layer 2026 Enhancements Phase 3 — auto-capture service (default OFF).
// Hooks `wp_mcp_ai_tool_executed` and `wp_mcp_ai_before_chat_request`
// silently. Master kill: filter `wp_mcp_ai_memory_auto_capture_enabled`.
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-auto-capture-service.php';
	WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();

	// Transparency & Compliance (Proposal 017) — boot AI disclosure, consent, provenance.
	// Registers REST header injection, consent endpoint, and provenance hooks.
	WP_MCP_AI_Transparency_Service::boot();

	// Memory Layer 2026 Enhancements Phase 4 — RRF fusion retrieval service.
// Stateless / static; no bootstrap hook required. Loaded eagerly here so
// `WP_MCP_AI_Vector_Context_Service::search_context_rrf()` can resolve it
// without lazy `require_once` calls inside the hot retrieval path.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-rrf-fusion-service.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-memory-rrf-fusion-service.php';
}

// DSpark efficiency hooks — data collectors for the orchestration dashboard.
// Registers filters that count depth tiers and track routing cost savings.
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-dspark-hooks.php';
WP_MCP_AI_DSpark_Hooks::register();

// Elementor integration is available for all versions.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-elementor-integration.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-elementor-integration.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-quick-actions-handler.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-quick-actions-handler.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-assistant-builder-blocks.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-assistant-builder-blocks.php';
}

// Global chat bubble frontend (settings-driven, no widget needed).
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-bubble-frontend.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-bubble-frontend.php';
}

// ---------------------------------------------------------------------------
// Clean output buffer
// ---------------------------------------------------------------------------

if ( ! $wp_mcp_ai_skip_buffering ) {
	ob_end_clean();
}

unset(
	$wp_mcp_ai_is_ajax_request,
	$wp_mcp_ai_is_elementor_ajax,
	$wp_mcp_ai_is_elementor_editor,
	$wp_mcp_ai_skip_buffering
);

// ---------------------------------------------------------------------------
// Settings section autoloader — MUST be available outside is_admin() so CLI
// test runs can resolve WP_MCP_AI_Section_* classes during instantiation.
// ---------------------------------------------------------------------------
require_once WP_MCP_AI_PATH . 'includes/admin/settings-dashboard-init.php';

// ---------------------------------------------------------------------------
// Admin-only includes
// ---------------------------------------------------------------------------

if ( is_admin() ) {
	// User profile — chat memory preferences (per-user recovery toggle).
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-user-profile-memory.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-user-profile-memory.php';
	}
	WP_MCP_AI_User_Profile_Memory::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-scripts.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-scripts.php';
	}
	WP_MCP_AI_Admin_Scripts::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-cron-manager.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-cron-manager.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-dlq-manager.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-dlq-manager.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-token-manager.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-token-manager.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-crawl4ai-monitor.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-crawl4ai-monitor.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-monitor-admin.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-monitor-admin.php';
	}
	WP_MCP_AI_Security_Monitor_Admin::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-multi-agent-dashboard.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-multi-agent-dashboard.php';
	}

	// Phase 2–5 admin UI (approvals queue, workflow run timeline, DAG builder, triggers).
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-run-timeline.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-run-timeline.php';
	}
	new WP_MCP_AI_Admin_Run_Timeline();
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-approvals.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-approvals.php';
	}
	new WP_MCP_AI_Admin_Approvals();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-dag-builder.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-dag-builder.php';
	}
	new WP_MCP_AI_Admin_DAG_Builder();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-workflow-triggers.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-workflow-triggers.php';
	}
	new WP_MCP_AI_Admin_Workflow_Triggers();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php';
	}
	new WP_MCP_AI_Admin_Slash_Commands_Dashboard();

	// ISO 27001 compliance systems.
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-asset-inventory.php';
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-asset-inventory-admin.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-asset-inventory-admin.php';
	}
	WP_MCP_AI_Asset_Inventory::get_instance();

	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-security-training.php';
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-training-admin.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-training-admin.php';
	}
	WP_MCP_AI_Security_Training::get_instance();

	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-supplier-security.php';
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-supplier-security-admin.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-supplier-security-admin.php';
	}
	WP_MCP_AI_Supplier_Security::get_instance();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-information-labelling.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-information-labelling.php';
	}
	WP_MCP_AI_Information_Labelling::get_instance();

	// Transparency & Compliance admin settings (Proposal 017).
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-transparency-settings.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-transparency-settings.php';
	}
	WP_MCP_AI_Admin_Transparency_Settings::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-incident-learning.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-incident-learning.php';
	}
	WP_MCP_AI_Incident_Learning::get_instance();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-security-audit.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-security-audit.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-audit-admin.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-audit-admin.php';
	}
	WP_MCP_AI_Security_Audit::get_instance();

	// Diagnostic pages.
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-dashboard-diagnostic.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-dashboard-diagnostic.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-rest-context-diagnostic.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-rest-context-diagnostic.php';
	}
	WP_MCP_AI_REST_Context_Diagnostic::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-auth0-setup.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-auth0-setup.php';
	}
	wp_mcp_ai_container()->get( 'admin.auth0_setup' );
	wp_mcp_ai_container()->get( 'admin.settings' );

	// Optional/dev-only admin pages.
	$wp_mcp_ai_optional_admin_pages = array(
		'class-wp-mcp-ai-admin-test-assistant'           => 'admin.test_assistant',
		'class-wp-mcp-ai-admin-test-profession'          => 'admin.test_profession',
		'class-wp-mcp-ai-admin-test-model'               => 'admin.test_model',
		'class-wp-mcp-ai-admin-test-team'                => 'admin.test_team',
		'class-wp-mcp-ai-admin-profession-settings'      => 'admin.profession_settings',
		'class-wp-mcp-ai-admin-team-settings'            => 'admin.team_settings',
		'class-wp-mcp-ai-admin-profession-research-page' => 'admin.profession_research',
		'class-wp-mcp-ai-admin-team-research-page'       => 'admin.team_research',
	);
	foreach ( $wp_mcp_ai_optional_admin_pages as $wp_mcp_ai_file => $wp_mcp_ai_service ) {
		$wp_mcp_ai_page_path = WP_MCP_AI_PATH . 'includes/admin/' . $wp_mcp_ai_file . '.php';
		if ( file_exists( $wp_mcp_ai_page_path ) ) {
			require_once $wp_mcp_ai_page_path;
			wp_mcp_ai_container()->get( $wp_mcp_ai_service );
		}
	}
	unset( $wp_mcp_ai_optional_admin_pages, $wp_mcp_ai_file, $wp_mcp_ai_service, $wp_mcp_ai_page_path );

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-assistant-page.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-assistant-page.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-datasets-admin-page.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-datasets-admin-page.php';
	}
	WP_MCP_AI_Add_Assistant_Page::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-build-assistant-page.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-build-assistant-page.php';
	}
	WP_MCP_AI_Build_Assistant_Page::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-team-page.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-team-page.php';
	}
	WP_MCP_AI_Add_Team_Page::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php';
	}
	WP_MCP_AI_Admin_Create_Assistant_Button::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-team-button.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-team-button.php';
	}
	WP_MCP_AI_Admin_Create_Team_Button::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-media-library-columns.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-media-library-columns.php';
	}
	WP_MCP_AI_Admin_Media_Library_Columns::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-key-rotation.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-key-rotation.php';
	}
	WP_MCP_AI_Admin_Key_Rotation::init();

	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-model-manager-ajax.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-model-manager-ajax.php';
	}
	// WP_MCP_AI_Embedded_Model_Ajax is a Pro-only feature loaded by the Pro addon.
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-iso27001-badge.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-iso27001-badge.php';
	}

	// Pro Dashboard.
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/data/class-wp-mcp-ai-compliance-data.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/data/class-wp-mcp-ai-compliance-data.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-database.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-database.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-license.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-license.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-report-generator.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-report-generator.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-helper.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-helper.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-diagnostic.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-diagnostic.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-chart-settings.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-chart-settings.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-settings.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-settings.php';
	}

	new WP_MCP_AI_Pro_Database();
	new WP_MCP_AI_Pro_License();
	WP_MCP_AI_Pro_Dashboard::get_instance();

	/**
	 * Add plugin action links in the plugins list.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	function wp_mcp_ai_add_plugin_action_links( $links ) {
		$settings_link   = admin_url( 'admin.php?page=wp-mcp-ai-dashboard' );
		$diagnostic_link = admin_url( 'tools.php?page=wp-mcp-ai-diagnostic' );

		$plugin_links = array(
			'settings'   => '<a href="' . esc_url( $settings_link ) . '">Settings</a>',
			'diagnostic' => '<a href="' . esc_url( $diagnostic_link ) . '">Diagnostic</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	add_filter( 'plugin_action_links_' . plugin_basename( WP_MCP_AI_FILE ), 'wp_mcp_ai_add_plugin_action_links' );
}

// ---------------------------------------------------------------------------
// Always-on initialisation (REST API, WP-CLI, etc.)
// ---------------------------------------------------------------------------

// Pro Dashboard REST API must be registered for all request types (not just admin).
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-rest.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-rest.php';
}
new WP_MCP_AI_Pro_Dashboard_REST();

// Phase 2–5 REST controllers.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-approval-controller.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-approval-controller.php';
}
add_action(
	'rest_api_init',
	function () {
		$controller = new WP_MCP_AI_REST_Approval_Controller();
		$controller->register_routes();
	}
);
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-workflow-cpt-controller.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-workflow-cpt-controller.php';
}
add_action(
	'rest_api_init',
	function () {
		$controller = new WP_MCP_AI_REST_Workflow_CPT_Controller();
		$controller->register_routes();
	}
);
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-workflow-run-controller.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-workflow-run-controller.php';
}
add_action(
	'rest_api_init',
	function () {
		$controller = new WP_MCP_AI_REST_Workflow_Run_Controller();
		$controller->register_routes();
	}
);
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-triggers-controller.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-triggers-controller.php';
}
add_action(
	'rest_api_init',
	function () {
		$controller = new WP_MCP_AI_REST_Triggers_Controller();
		$controller->register_routes();
	}
);

new WP_MCP_AI_Mesh_Peer_Test_REST();

// Security Center REST controller.
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-security-posture.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-security-posture.php';
}
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-security-center-controller.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-security-center-controller.php';
}
add_action(
	'rest_api_init',
	function () {
		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$controller->register_routes();
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-stdio-transport.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-stdio-transport.php';
	}
	if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cli-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cli-command.php';
	}
}

WP_MCP_AI_Message_Attachments::init();
WP_MCP_AI_Response_Attachments::init();
WP_MCP_AI_Model_Config::init();
WP_MCP_AI_REST_API_Context_Fix::init();
WP_MCP_AI_HTTP::bootstrap();

// Bootstrap third-party integration handlers after class files are loaded.
if ( wp_mcp_ai_should_load_integrations() ) {
	if ( class_exists( 'WP_MCP_AI_JetEngine_Tool_Handlers' ) ) {
		WP_MCP_AI_JetEngine_Tool_Handlers::bootstrap();
	}
	if ( class_exists( 'WP_MCP_AI_JetFormBuilder_Tool_Handlers' ) ) {
		WP_MCP_AI_JetFormBuilder_Tool_Handlers::bootstrap();
	}
	if ( class_exists( 'WP_MCP_AI_ChatKit_Integration' ) ) {
		WP_MCP_AI_ChatKit_Integration::init();
	}
	if ( class_exists( 'WP_MCP_AI_Simple_JWT_Login_Integration' ) ) {
		WP_MCP_AI_Simple_JWT_Login_Integration::init();
	}
	if ( class_exists( 'WP_MCP_AI_Integration_Auth0_Github' ) ) {
		WP_MCP_AI_Integration_Auth0_Github::init();
	}
	if ( class_exists( 'WP_MCP_AI_Integration_WordPress_Gravatar' ) ) {
		WP_MCP_AI_Integration_WordPress_Gravatar::init();
	}
	if ( class_exists( 'WP_MCP_AI_Media' ) ) {
		WP_MCP_AI_Media::get_instance();
	}
	if ( class_exists( 'WP_MCP_AI_Comments' ) ) {
		WP_MCP_AI_Comments::get_instance();
	}
}

// ---------------------------------------------------------------------------
// Multi-Tenant Isolation Subsystem
// ---------------------------------------------------------------------------
if ( ! wp_mcp_ai_class_exists_via_autoload( WP_MCP_AI_PATH . 'includes/tenant/init.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tenant/init.php';
}
