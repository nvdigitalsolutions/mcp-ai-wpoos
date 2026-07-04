<?php
/**
 * Plugin Constants
 *
 * Defines all plugin-wide constants. Must be included after WP_MCP_AI_FILE has
 * been set in the main plugin file, because PATH and URL are derived from it.
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

if ( ! defined( 'WP_MCP_AI_VERSION' ) ) {
	define( 'WP_MCP_AI_VERSION', '1.1.36' );
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', plugin_dir_path( WP_MCP_AI_FILE ) );
}

if ( ! defined( 'WP_MCP_AI_URL' ) ) {
	define( 'WP_MCP_AI_URL', plugin_dir_url( WP_MCP_AI_FILE ) );
}

/**
 * Base version mode flag — legacy/backward-compatibility only.
 *
 * This constant previously restricted the tool registry to a subset of tools.
 * That restriction has been removed: all tools in includes/tools/ are always
 * attempted on every installation; tools for optional third-party plugins
 * (WooCommerce, JetEngine, etc.) self-report as unavailable via is_available()
 * when those plugins are not installed.
 *
 * The constant is preserved so that:
 *  1. Third-party code relying on the wp_mcp_ai_base_version filter continues to work.
 *  2. The wp_mcp_ai_is_base_version() helper can still be used by callers that
 *     need to detect the private/custom build entry point (mcp-ai-wpoos-base.php).
 *     That entry point is excluded from the WordPress.org distribution ZIP via
 *     .distignore so it never fires for WordPress.org users.
 *
 * The Pro addon (addons/pro/) is a genuine extension — it adds brand-new tools
 * that do not exist in the base plugin. It does NOT unlock or gate any tool that
 * is already present in includes/tools/.
 *
 * @var bool False = all base tools always load (default). True = private/custom
 *           build mode set by mcp-ai-wpoos-base.php (excluded from WP.org ZIP).
 */
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', false );
}

/**
 * Shell tools allowed.
 *
 * Defaults to false. Pro tools that invoke shell commands (execute_shell_command,
 * git_operations, search_codebase, check_tool_compliance, and the document-generation
 * tools that shell out to wkhtmltopdf / pdftk) will refuse to execute unless this
 * constant is explicitly set to true by the site operator in wp-config.php.
 *
 * define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', true );
 */
if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) ) {
	define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', false );
}

/**
 * Use TypeScript-compiled assets instead of legacy JS.
 *
 * When enabled, the plugin loads esbuild-compiled assets from
 * assets/js/dist/ (TypeScript source) instead of the traditional
 * assets/js/*.min.js files. Defaults to false for backward
 * compatibility.
 *
 * Enable in wp-config.php:
 *   define( 'WP_MCP_AI_USE_TS_BUILD', true );
 *
 * Alternatively, enable via the admin UI at:
 *   NV oOS → Orchestration → Settings → "Use TypeScript-Compiled Assets"
 *
 * Requires running `npm run build:js:ts` first to produce the dist/ files.
 *
 * @since 1.2.0
 * @var bool
 */
if ( ! defined( 'WP_MCP_AI_USE_TS_BUILD' ) ) {
	define( 'WP_MCP_AI_USE_TS_BUILD', false );
}

/**
 * Legacy chat.js frontend active.
 *
 * Defaults to true (legacy mode). When set to false in wp-config.php the
 * [mcp_ai_chat] shortcode is no longer registered and the chat-bundle.min.js
 * asset is not enqueued. Use this together with the [nvoos_chat_spa] shortcode
 * (from the NV oOS Chat SPA addon) to fully migrate to the React frontend.
 *
 * define( 'WP_MCP_AI_LEGACY_CHAT_JS', false );
 */
if ( ! defined( 'WP_MCP_AI_LEGACY_CHAT_JS' ) ) {
	define( 'WP_MCP_AI_LEGACY_CHAT_JS', true );
}

/**
 * Tone.js live-coding eval allowed.
 *
 * Defaults to false. The Algorave addon's live-coder shortcode compiles
 * user-typed JavaScript with `new Function('Tone', code)`. That gives the
 * compiled code full access to the parent page's DOM, cookies, and fetch.
 * The Strudel mini-notation engine remains the safe default; this constant
 * unlocks the Tone.js engine for trusted operators.
 *
 * define( 'WP_MCP_AI_ALLOW_TONEJS_EVAL', true );
 */
if ( ! defined( 'WP_MCP_AI_ALLOW_TONEJS_EVAL' ) ) {
	define( 'WP_MCP_AI_ALLOW_TONEJS_EVAL', false );
}

/**
 * Pro Dashboard enabled.
 *
 * Defaults to true. Set to false to disable Pro Dashboard features.
 */
if ( ! defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) ) {
	define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );
}
