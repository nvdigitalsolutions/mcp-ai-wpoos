<?php
/**
 * Plugin Name: NV Digital Open Operator System (oOS)
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
	 * Description: AI Assistant framework with 15 AI providers (OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, Z.AI, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama & more). Includes 265+ tools for content management, media generation, research, and site operations out of the box. Optional Pro addon (PHP 8.1+) adds advanced AI toolkits on top. Framework-agnostic OOS core extracted for cross-platform use (Laravel, Craft CMS adapters).
	 * Version: 1.1.58
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.10
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mcp-ai-wpoos
 * Domain Path: /languages
 * Network: true
 *
 * @package WP_MCP_AI
 *
 * phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Main plugin entry point; file is intentionally named after the plugin slug, not a class.
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevent double-loading of the plugin.
 * This check must come before any constants are defined to ensure we exit early
 * if the plugin has already been loaded through another entry point.
 */
if ( function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	return;
}

/**
 * WP_MCP_AI_FILE must be defined here — it is tied to __FILE__ in this specific file.
 * All other plugin constants are centralised in includes/bootstrap/constants.php.
 */
if ( ! defined( 'WP_MCP_AI_FILE' ) ) {
	define( 'WP_MCP_AI_FILE', __FILE__ );
}

/**
 * Check PHP version compatibility before loading any classes.
 *
 * This plugin requires PHP 7.4 or later. On older PHP versions, class files
 * will fail to parse with syntax errors. We check the version early to provide
 * a clear error message instead of cryptic parse errors.
 */
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	/**
	 * Display admin notice for PHP version incompatibility.
	 */
	function wp_mcp_ai_php_version_notice() {
		$message = sprintf(
			'<strong>Open Operator System</strong> requires PHP version %2$s or higher. You are running PHP version %1$s. Please contact your hosting provider to upgrade PHP.',
			PHP_VERSION,
			'7.4.0'
		);
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Prevent plugin activation on incompatible PHP versions.
	 */
	function wp_mcp_ai_deactivate_self() {
		deactivate_plugins( plugin_basename( WP_MCP_AI_FILE ) );
	}

	add_action( 'admin_notices', 'wp_mcp_ai_php_version_notice' );
	add_action( 'admin_init', 'wp_mcp_ai_deactivate_self' );

	return;
}

// ---------------------------------------------------------------------------
// Pre-populate the l10n global with a NOOP_Translations instance before
// loader.php runs. This prevents WordPress 6.7+ from firing
// _load_textdomain_just_in_time warnings when translation functions
// (__(), esc_html__(), etc.) are called during plugin bootstrap (pre-init).
//
// NOOP_Translations is a WordPress core class (wp-includes/pomo/mo.php,
// loaded before plugins) that returns strings unchanged — exactly what
// English sites need when no .mo files exist.
//
// When real .mo files are loaded later on 'init' by WordPress's automatic
// locale machinery, load_textdomain() merges them into the existing object.
// ---------------------------------------------------------------------------
global $l10n;
if ( ! isset( $l10n['mcp-ai-wpoos'] ) && class_exists( 'NOOP_Translations' ) ) {
	$l10n['mcp-ai-wpoos'] = new NOOP_Translations();
}

// Load bootstrap files in dependency order.
require_once __DIR__ . '/includes/bootstrap/constants.php';
require_once __DIR__ . '/includes/bootstrap/autoload.php';
require_once __DIR__ . '/includes/bootstrap/helpers.php';
require_once __DIR__ . '/includes/bootstrap/cron.php';
require_once __DIR__ . '/includes/bootstrap/hooks.php';
require_once __DIR__ . '/includes/bootstrap/loader.php';
require_once __DIR__ . '/includes/bootstrap/activation.php';
require_once __DIR__ . '/includes/class-wp-mcp-ai-plugin.php';
require_once __DIR__ . '/includes/bootstrap/oos-bridge.php';

// Register lifecycle hooks (must reference WP_MCP_AI_FILE, defined above).
register_activation_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_activate' );
register_deactivation_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_deactivate' );
register_uninstall_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_uninstall' );

// Boot the plugin on plugins_loaded.
if ( ! has_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap', 20 );
}

// Load plugin textdomain for bundled translations.
add_action( 'init', 'wp_mcp_ai_load_textdomain' );
function wp_mcp_ai_load_textdomain() {
	load_plugin_textdomain(
		'mcp-ai-wpoos',
		false,
		dirname( plugin_basename( WP_MCP_AI_FILE ) ) . '/languages/'
	);
}

// ---------------------------------------------------------------------------
// Export Provider Registration (Backup & Restore)
// ---------------------------------------------------------------------------

/**
 * Register core export providers for the Backup & Restore feature.
 *
 * Fires on admin_init so providers are available when the Settings
 * Management page renders. Addons hook into the same action to
 * register their own providers.
 *
 * @since 1.2.0
 *
 * @return void
 */
function wp_mcp_ai_register_export_providers(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! class_exists( 'WP_MCP_AI_Export_Manager' ) ) {
		// Autoload will handle this, but ensure the file is discoverable.
		$export_dir = WP_MCP_AI_PATH . 'includes/admin/export/';
		require_once $export_dir . 'interface-wp-mcp-ai-export-provider.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-base.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-manager.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-core-settings.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-toolkit-options.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-addon-options.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-assistants.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-cpts.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-custom-tables.php';
		require_once $export_dir . 'class-wp-mcp-ai-export-provider-federation.php';
	}

	$manager = WP_MCP_AI_Export_Manager::instance();

	// Core providers (always available).
	$manager->register( new WP_MCP_AI_Export_Provider_Core_Settings() );
	$manager->register( new WP_MCP_AI_Export_Provider_Toolkit_Options() );
	$manager->register( new WP_MCP_AI_Export_Provider_Addon_Options() );
	$manager->register( new WP_MCP_AI_Export_Provider_Assistants() );
	$manager->register( new WP_MCP_AI_Export_Provider_CPTs() );
	$manager->register( new WP_MCP_AI_Export_Provider_Custom_Tables() );
	$manager->register( new WP_MCP_AI_Export_Provider_Federation() );

	/**
	 * Fires when export providers are being registered.
	 *
	 * Addons should hook here to register their own providers
	 * via $manager->register().
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_Export_Manager $manager The export manager instance.
	 */
	do_action( 'wp_mcp_ai_register_export_providers', $manager );
}

add_action( 'admin_init', 'wp_mcp_ai_register_export_providers', 20 );
