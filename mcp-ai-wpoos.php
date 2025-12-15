<?php
/**
 * Plugin Name: Open Operator System Complete (WP oOS)
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
 * Description: Complete AI Assistant framework with OpenAI, Gemini, and Ollama integration. Includes 109 tools with base features and Pro add-on (WooCommerce, social media, GitHub, Google services, FFmpeg, WP-CLI, and more).
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.7.1
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
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check PHP version compatibility before loading any classes.
 *
 * This plugin requires PHP 7.4 or later. On older PHP versions, class files
 * will fail to parse with syntax errors like "unexpected token 'private'".
 * We check the version early to provide a clear error message instead of
 * cryptic parse errors.
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
	 * Register PHP version notice on init to avoid early translation loading.
	 *
	 * WordPress 6.7.0+ requires translations to be loaded at init or later.
	 */
	function wp_mcp_ai_register_php_version_notice() {
		add_action( 'admin_notices', 'wp_mcp_ai_php_version_notice' );
	}
	add_action( 'init', 'wp_mcp_ai_register_php_version_notice' );

	/**
	 * Prevent plugin activation on incompatible PHP versions.
	 */
	function wp_mcp_ai_deactivate_self() {
		deactivate_plugins( plugin_basename( WP_MCP_AI_FILE ) );
	}
	add_action( 'admin_init', 'wp_mcp_ai_deactivate_self' );

	// Stop execution - don't load any class files that will cause parse errors.
	return;
}

if ( ! defined( 'WP_MCP_AI_VERSION' ) ) {
	define( 'WP_MCP_AI_VERSION', '1.1.0' );
}
if ( ! defined( 'WP_MCP_AI_FILE' ) ) {
	define( 'WP_MCP_AI_FILE', __FILE__ );
}
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', plugin_dir_path( WP_MCP_AI_FILE ) );
}
if ( ! defined( 'WP_MCP_AI_URL' ) ) {
	define( 'WP_MCP_AI_URL', plugin_dir_url( WP_MCP_AI_FILE ) );
}

/**
 * Load Composer dependencies with error handling.
 *
 * Some hosting providers disable putenv() for security. If dev dependencies
 * (like wp-phpunit) are accidentally installed in production, they may try to
 * call putenv() causing a fatal error. We handle this gracefully.
 */
if ( file_exists( WP_MCP_AI_PATH . 'vendor/autoload.php' ) ) {
	// Check if wp-phpunit dev dependency is present (shouldn't be in production).
	$has_dev_deps = file_exists( WP_MCP_AI_PATH . 'vendor/wp-phpunit/wp-phpunit/__loaded.php' );

	// Check if putenv is available.
	$putenv_available = function_exists( 'putenv' );

	// If dev dependencies are present but putenv is disabled, show warning and skip autoload.
	if ( $has_dev_deps && ! $putenv_available ) {
		if ( is_admin() ) {
			/**
			 * Display notice about dev dependencies in production.
			 */
			function wp_mcp_ai_dev_deps_error_notice() {
				$message = '<strong>Open Operator System</strong> detected development dependencies in production environment. Your hosting provider has disabled the <code>putenv()</code> function, which is required by testing libraries. Please reinstall dependencies with <code>composer install --no-dev</code> to resolve this issue. Until fixed, some plugin features may not work correctly.';
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					wp_kses_post( $message )
				);
			}

			/**
			 * Register dev deps error notice on init to avoid early translation loading.
			 *
			 * WordPress 6.7.0+ requires translations to be loaded at init or later.
			 */
			function wp_mcp_ai_register_dev_deps_error_notice() {
				add_action( 'admin_notices', 'wp_mcp_ai_dev_deps_error_notice' );
			}
			add_action( 'init', 'wp_mcp_ai_register_dev_deps_error_notice' );
		}

		// Log the issue.
		error_log( 'WP_MCP_AI: Development dependencies detected in production with putenv() disabled. Run: composer install --no-dev' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// We can't safely load the autoloader, so we'll manually load critical production dependencies.
		// This allows the plugin to function (degraded) until the deployment is fixed.
		$critical_files = array(
			'vendor/symfony/deprecation-contracts/function.php',
			'vendor/symfony/polyfill-ctype/bootstrap.php',
			'vendor/symfony/polyfill-mbstring/bootstrap.php',
			'vendor/symfony/polyfill-php83/bootstrap.php',
		);

		foreach ( $critical_files as $file ) {
			$file_path = WP_MCP_AI_PATH . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}

		// Register a simple autoloader for production dependencies only.
		spl_autoload_register(
			function ( $class_name ) {
				// Map of production class prefixes to their paths (from composer's autoload_psr4.php).
				$prefix_map = array(
					'Rahul900day\\Tiktoken\\'           => 'vendor/rahul900day/tiktoken-php/src/',
					'Symfony\\Contracts\\Service\\'     => 'vendor/symfony/service-contracts/',
					'Symfony\\Contracts\\HttpClient\\'  => 'vendor/symfony/http-client-contracts/',
					'Symfony\\Contracts\\Cache\\'       => 'vendor/symfony/cache-contracts/',
					'Symfony\\Component\\VarExporter\\' => 'vendor/symfony/var-exporter/',
					'Symfony\\Component\\HttpClient\\'  => 'vendor/symfony/http-client/',
					'Symfony\\Component\\Filesystem\\'  => 'vendor/symfony/filesystem/',
					'Symfony\\Component\\Cache\\'       => 'vendor/symfony/cache/',
					'Nyholm\\Psr7\\'                    => 'vendor/nyholm/psr7/src/',
					'Psr\\Log\\'                        => 'vendor/psr/log/src/',
					'Psr\\Http\\Message\\'              => 'vendor/psr/http-message/src/',
					'Psr\\Http\\Client\\'               => 'vendor/psr/http-client/src/',
					'Psr\\Container\\'                  => 'vendor/psr/container/src/',
					'Psr\\Cache\\'                      => 'vendor/psr/cache/src/',
					'Http\\Discovery\\'                 => 'vendor/php-http/discovery/src/',
				);

				foreach ( $prefix_map as $prefix => $base_dir ) {
					$len = strlen( $prefix );
					if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
						continue;
					}

					$relative_class = substr( $class_name, $len );
					$file           = WP_MCP_AI_PATH . $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

					if ( file_exists( $file ) ) {
						require_once $file;
						return;
					}
				}
			}
		);

	} else {
		// Normal autoload when environment is correctly configured.
		require_once WP_MCP_AI_PATH . 'vendor/autoload.php';
	}
}

if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	/**
	 * Check if Open Operator System (WP oOS) Core is loaded.
	 *
	 * This function serves as a marker for add-ons (like Open Operator System Pro) to verify that
	 * the core plugin is active and ready before registering their features.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always returns true when plugin is loaded.
	 */
	function wp_mcp_ai_core_loaded() {
		return true;
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_required_chat_capability' ) ) {
	/**
	 * Retrieve the capability required to access the chat interface.
	 *
	 * Site owners can filter the returned capability to relax access controls.
	 * For example, allow subscribers (with the `read` capability) or even
	 * unauthenticated visitors by returning `'public'` or an empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $assistant_id Assistant post ID, when known.
	 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
	 *
	 * @return string|false Capability string. Return `'public'` to allow any visitor,
	 *                      or a falsy value to skip the check entirely.
	 */
	function wp_mcp_ai_get_required_chat_capability( $assistant_id = 0, $context = 'general' ) {
		$assistant_id = absint( $assistant_id );
		$context      = $context ? sanitize_key( $context ) : 'general';

		/**
		 * Filters the capability required to use the front-end chat interface.
		 *
		 * Returning `'public'`, `false`, or an empty string disables the capability
		 * check, making the chat available to all visitors who satisfy the
		 * authentication requirements.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability  Capability required to access the chat. Defaults to `edit_posts`.
		 * @param int    $assistant_id Assistant post ID, when available.
		 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
		 */
		$capability = apply_filters( 'wp_mcp_ai_chat_capability', 'edit_posts', $assistant_id, $context );

		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		return $capability;
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_effective_chat_capability' ) ) {
	/**
	 * Get the effective capability required for a specific assistant.
	 *
	 * This function checks the assistant's required_capability meta first,
	 * then falls back to the global capability filter.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
	 *
	 * @return string|false Capability string. Return `'public'` to allow any visitor,
	 *                      or a falsy value to skip the check entirely.
	 */
	function wp_mcp_ai_get_effective_chat_capability( $assistant_id = 0, $context = 'general' ) {
		$assistant_id = absint( $assistant_id );

		// Check if assistant has a specific capability requirement.
		if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$required_capability = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_REQUIRED_CAPABILITY, true );

			if ( is_string( $required_capability ) ) {
				$required_capability = WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( $required_capability );

				// If assistant has a specific capability set (even if empty), use it.
				if ( '' !== $required_capability ) {
					return $required_capability;
				}
			}
		}

		// Fall back to the global capability setting.
		return wp_mcp_ai_get_required_chat_capability( $assistant_id, $context );
	}
}

if ( ! function_exists( 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	/**
	 * Provide a fallback Crawl4AI base URL from the environment when available.
	 *
	 * @param string $base_url Base URL stored in the plugin settings.
	 * @param array  $settings Entire plugin settings array.
	 * @param array  $context  Execution context passed to the tool.
	 * @return string
	 */
	function wp_mcp_ai_filter_crawl4ai_base_url( $base_url, $settings, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter callback signature requires these parameters.
		if ( ! empty( $base_url ) ) {
			return $base_url;
		}

		if ( defined( 'WP_MCP_AI_CRAWL4AI_BASE_URL' ) && WP_MCP_AI_CRAWL4AI_BASE_URL ) {
			return WP_MCP_AI_CRAWL4AI_BASE_URL;
		}

		$environment_candidates = array(
			'WP_MCP_AI_CRAWL4AI_BASE_URL',
			'CRAWL4AI_BASE_URL',
		);

		foreach ( $environment_candidates as $env_key ) {
			$candidate = getenv( $env_key );
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return $candidate;
			}
		}

		return $base_url;
	}
}

if ( ! has_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	add_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url', 5, 3 );
}

if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) ) {
	/**
	 * Check if base version mode is enabled.
	 *
	 * Full version is enabled by default, providing all 105+ tools.
	 * To enable base version mode (only core 74 tools), add this to wp-config.php:
	 * define( 'WP_MCP_AI_BASE_VERSION', true );
	 *
	 * Base version mode excludes tools requiring third-party plugins
	 * (WooCommerce, JetEngine, Elementor, etc.) and external API integrations.
	 *
	 * @return bool Whether base version mode is active.
	 */
	function wp_mcp_ai_is_base_version() {
		return defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION;
	}
}

// Start output buffering early to catch any warnings/notices from includes.
// Suppress any output that could break JSON responses later.
// Skip buffering during Elementor AJAX requests and editor page loads to avoid interfering with Elementor operations.
$is_ajax_request     = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
	|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );
$is_elementor_ajax   = false;
$is_elementor_editor = false;

// Check for Elementor AJAX requests.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking action name, not processing data.
if ( $is_ajax_request && isset( $_REQUEST['action'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking action name, not processing data.
	$request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
	$is_elementor_ajax = ( strpos( $request_action, 'elementor' ) === 0 );
}

// Check for Elementor editor page loads.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification in its editor loader.
if ( ! $is_ajax_request && isset( $_GET['action'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification in its editor loader.
	$get_action          = sanitize_text_field( wp_unslash( $_GET['action'] ) );
	$is_elementor_editor = ( 'elementor' === $get_action );
}

$skip_buffering = $is_elementor_ajax || $is_elementor_editor;

if ( ! $skip_buffering ) {
	// Suppress errors on ob_start() because some hosting environments have output buffering disabled.
	// If error suppression fails, we fall back to unsuppressed call for better debugging.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional: graceful degradation when output buffering is disabled by host.
	if ( ! @ob_start() ) {
		ob_start(); // Fallback without error suppression.
	}
}

// Load admin settings component classes.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-base.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings-renderer.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-validator.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-chart-js-helper.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-analytics-dashboard.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cost-calculator.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-analytics-engine.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-oauth-manager.php';

// Load HTTP helper early to prevent SSL issues with loopback addresses.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http-helper.php';
WP_MCP_AI_HTTP_Helper::init();

// Load cache helper early for performance optimization.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cache-helper.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-cache.php';

// Load REST API context parameter fix to prevent caching issues.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-api-context-fix.php';

require_once WP_MCP_AI_PATH . 'includes/class-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-resource-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-error-handler.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-root-security-key.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-nefarious-usage-monitor.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-proxy-utils.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-remote-tester.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-credentials.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rate-limit-manager.php';

// Load dependency injection container (Phase 4 refactoring - Milestone 10).
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-container.php';
require_once WP_MCP_AI_PATH . 'includes/container-helpers.php';

// Load service layer (Phase 4 refactoring - Milestone 8).
// This includes token budget manager and performance monitor services.
require_once WP_MCP_AI_PATH . 'includes/services-init.php';

// Token budget manager is now loaded via services-init.php.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-selector.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-config.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-mesh-router.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-queue-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-assistant-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-enhanced-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-lm-studio-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php';
require_once WP_MCP_AI_PATH . 'includes/tool-response-helpers.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-request-context.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-usage-tracker.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-token-limits.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-token-db-optimizer.php';

// Phase 7 Week 5-6: Enhanced token tracking with real-time cost attribution.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-token-tracking-database.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-enhanced-token-tracking.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-recommendations.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-text-chunker.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-document-summarizer.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chat-transcript-recorder.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-crawl4ai-local-api.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-response-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/crawler/class-wp-mcp-ai-crawler.php';
require_once WP_MCP_AI_PATH . 'includes/job-notifier-init.php';
require_once WP_MCP_AI_PATH . 'includes/class-rest-endpoints.php';
require_once WP_MCP_AI_PATH . 'includes/class-tool-registry.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcodes.php';

// Load Pro addon early so it can register tool hooks before tool registry initializes.
// This must happen BEFORE tools-init.php is loaded to ensure Pro tools are included
// in the initial tool registration when wp_mcp_ai_register_tools action fires.
if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	$pro_addon_file = WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php';
	if ( file_exists( $pro_addon_file ) ) {
		require_once $pro_addon_file;
	}
}

require_once WP_MCP_AI_PATH . 'includes/tools-init.php';
require_once WP_MCP_AI_PATH . 'includes/tools/remove-background.php';

// Load validated tools (Symfony Phase 2 - requires PHP 8.0+).
require_once WP_MCP_AI_PATH . 'includes/validators/validated-tools-init.php';

// Container and services already loaded earlier (after rate-limit-manager, before model-selector).

// Load repository layer (Phase 4 refactoring - Milestone 9).
require_once WP_MCP_AI_PATH . 'includes/repositories-init.php';

// Load profession management system (separates profession data from hardcoded arrays).
require_once WP_MCP_AI_PATH . 'includes/professions/professions-init.php';

// Load team management system.
require_once WP_MCP_AI_PATH . 'includes/teams/teams-init.php';

// Service layer already loaded earlier (after container, before model-selector).

// Load federation system components.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-wellknown.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ai-peer-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-peer-verifier.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation-directory-rest.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-federation.php';

// Load third-party plugin integrations only when not in base version mode.
if ( ! wp_mcp_ai_is_base_version() ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-endpoint-report.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-tool-handlers.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetformbuilder-tool-handlers.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-assistants-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-ai-peers-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-rate-limits-cct.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-pricing-checker.php';
	// Performance monitor CCT is now loaded via services-init.php.
	require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-performance-blocks.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-elementor-integration.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-chatkit-integration.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-simple-jwt-login-integration.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-auth0-github.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-media.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-comments.php';
	require_once WP_MCP_AI_PATH . 'includes/integrations/github-integration-init.php';
}

// Load assistant builder blocks for all versions (base and full).
// These blocks provide Gutenberg block editor support for the AI Chat, Assistant Selector,
// Tools Grid, Knowledge Base, and full Assistant Builder components.
require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-assistant-builder-blocks.php';

// Clean any output that may have been generated during includes.
// Only clean the buffer if we started it (i.e., not during Elementor AJAX requests or editor page loads).
if ( ! $skip_buffering ) {
	ob_end_clean();
}

// Note: Performance section moved to Pro addon (uses exec for PHPUnit tests).
// Frontend AJAX handlers for Performance widgets are now only available with Pro addon.

if ( is_admin() ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-cron-manager.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-token-manager.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-crawl4ai-monitor.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-monitor-admin.php';
	WP_MCP_AI_Security_Monitor_Admin::init();

	// Load diagnostic pages (always available under Tools menu).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-dashboard-diagnostic.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-rest-context-diagnostic.php';
	WP_MCP_AI_REST_Context_Diagnostic::init();

	// Load new modular settings dashboard system.
	require_once WP_MCP_AI_PATH . 'includes/admin/settings-dashboard-init.php';
	// Load Auth0 Setup wizard (submenu of wp-mcp-ai-dashboard).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-auth0-setup.php';
	wp_mcp_ai_container()->get( 'admin.auth0_setup' );

	// Load test assistant page (submenu of AI Assistants CPT).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-assistant.php';
	wp_mcp_ai_container()->get( 'admin.test_assistant' );

	// Load test profession page (submenu of AI Professions CPT).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-profession.php';
	wp_mcp_ai_container()->get( 'admin.test_profession' );

	// Load test team page (submenu of AI Teams CPT).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-team.php';
	wp_mcp_ai_container()->get( 'admin.test_team' );

	// Load add assistant page (submenu of AI Assistants CPT - renamed to Create Assistant).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-assistant-page.php';
	WP_MCP_AI_Add_Assistant_Page::init();

	// Load build assistant page (submenu of AI Assistants CPT).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-build-assistant-page.php';
	WP_MCP_AI_Build_Assistant_Page::init();

	// Load add team page (submenu of AI Assistants CPT).
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-add-team-page.php';
	WP_MCP_AI_Add_Team_Page::init();

	// Load create assistant button and modal.
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php';
	WP_MCP_AI_Admin_Create_Assistant_Button::init();

	// Load create team button and modal.
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-create-team-button.php';
	WP_MCP_AI_Admin_Create_Team_Button::init();

	// Load media library columns for AI usage display.
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-media-library-columns.php';
	WP_MCP_AI_Admin_Media_Library_Columns::init();

	// Load master key rotation admin interface.
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-key-rotation.php';
	WP_MCP_AI_Admin_Key_Rotation::init();

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
			'settings'   => '<a href="' . esc_url( $settings_link ) . '">' . esc_html__( 'Settings', 'wp-mcp-ai' ) . '</a>',
			'diagnostic' => '<a href="' . esc_url( $diagnostic_link ) . '">' . esc_html__( 'Diagnostic', 'wp-mcp-ai' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Register plugin action links on init to avoid early translation loading.
	 *
	 * WordPress 6.7.0+ requires translations to be loaded at init or later.
	 */
	function wp_mcp_ai_register_plugin_action_links() {
		add_filter( 'plugin_action_links_' . plugin_basename( WP_MCP_AI_FILE ), 'wp_mcp_ai_add_plugin_action_links' );
	}
	add_action( 'init', 'wp_mcp_ai_register_plugin_action_links' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-stdio-transport.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cli-command.php';
}

WP_MCP_AI_Message_Attachments::init();
WP_MCP_AI_Response_Attachments::init();

// Initialize model config system.
WP_MCP_AI_Model_Config::init();

// Initialize REST API context parameter fix.
WP_MCP_AI_REST_API_Context_Fix::init();

WP_MCP_AI_HTTP::bootstrap();

// Initialize third-party plugin integrations only when not in base version mode.
if ( ! wp_mcp_ai_is_base_version() ) {
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

/**
 * Translation Loading Note:
 *
 * As of WordPress 6.7+, translation loading is handled automatically via just-in-time (JIT)
 * loading based on the "Text Domain" and "Domain Path" headers in the plugin file.
 * No explicit load_plugin_textdomain() call is needed.
 *
 * WordPress will automatically load translations when translation functions (__(), _e(), etc.)
 * are first called, using the metadata from the plugin headers.
 *
 * This prevents the "_load_textdomain_just_in_time was called incorrectly" warning that
 * occurred when explicit loading conflicted with automatic JIT loading.
 *
 * @since 1.0.0
 */

if ( ! class_exists( 'WP_MCP_AI' ) ) {
	/**
	 * Main plugin container class.
	 */
	final class WP_MCP_AI {
		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI
		 */
		private static $instance;

		/**
		 * Admin settings instance.
		 *
		 * @var WP_MCP_AI_Admin_Settings
		 */
		public $admin_settings;

		/**
		 * Assistant CPT instance.
		 *
		 * @var WP_MCP_AI_Assistant_CPT
		 */
		public $assistant_cpt;

		/**
		 * Crawl4AI Local API instance.
		 *
		 * @var WP_MCP_AI_Crawl4AI_Local_API
		 */
		public $crawl4ai_local_api;

		/**
		 * REST controller instance.
		 *
		 * @var WP_MCP_AI_REST
		 */
		public $rest_controller;

		/**
		 * Shortcodes instance.
		 *
		 * @var WP_MCP_AI_Shortcodes
		 */
		public $shortcodes;

		/**
		 * Admin cron manager instance.
		 *
		 * @var WP_MCP_AI_Admin_Cron_Manager
		 */
		public $admin_cron_manager;

		/**
		 * Admin token manager instance.
		 *
		 * @var WP_MCP_AI_Admin_Token_Manager
		 */
		public $admin_token_manager;

		/**
		 * Admin Crawl4AI monitor instance.
		 *
		 * @var WP_MCP_AI_Admin_Crawl4AI_Monitor
		 */
		public $admin_crawl4ai_monitor;

		/**
		 * Resource manager instance.
		 *
		 * @var WP_MCP_AI_Resource_Manager
		 */
		public $resource_manager;

		/**
		 * Federation system instance.
		 *
		 * @var WP_MCP_AI_Federation
		 */
		public $federation;

		/**
		 * Output buffer level when starting Elementor AJAX buffering.
		 *
		 * @var int|null
		 */
		private $elementor_buffer_level = null;

		/**
		 * Returns the singleton instance.
		 *
		 * @return WP_MCP_AI
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor to prevent direct instantiation.
		 */
		private function __construct() {
			// Bootstrap happens via the bootstrap() method.
		}

		/**
		 * Bootstrap the plugin.
		 */
		public function bootstrap() {
			// Check root security key first if required.
			$security_key = WP_MCP_AI_Root_Security_Key::get_instance();
			if ( ! $security_key->can_initialize() ) {
				// Block initialization when security key is required but not verified.
				// Admin interface will still load to allow key verification.
				return;
			}

			// Initialize nefarious usage monitor first to protect all operations.
			$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
			$monitor->init();

			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$registry->init();

			// Get container for dependency management.
			$container = wp_mcp_ai_container();

			// Initialize language model clients and router through container.
			$router = $container->get( 'router' );

			$this->resource_manager = WP_MCP_AI_Resource_Manager::instance();

			// Initialize core components through container.
			$this->assistant_cpt      = $container->get( 'assistant_cpt' );
			$this->crawl4ai_local_api = $container->get( 'crawl4ai_local_api' );
			$this->rest_controller    = $container->get( 'rest_controller' );
			$this->shortcodes         = $container->get( 'shortcodes' );
			$this->federation         = $container->get( 'federation' );

			if ( is_admin() ) {
				$this->admin_cron_manager     = $container->get( 'admin.cron_manager' );
				$this->admin_token_manager    = $container->get( 'admin.token_manager' );
				$this->admin_crawl4ai_monitor = $container->get( 'admin.crawl4ai_monitor' );
			}

			// Maintain backward compatibility with code that accesses $GLOBALS directly.
			$GLOBALS['wp_mcp_ai_resource_manager']   = $this->resource_manager;
			$GLOBALS['wp_mcp_ai_assistant_cpt']      = $this->assistant_cpt;
			$GLOBALS['wp_mcp_ai_crawl4ai_local_api'] = $this->crawl4ai_local_api;
			$GLOBALS['wp_mcp_ai_rest_controller']    = $this->rest_controller;
			$GLOBALS['wp_mcp_ai_shortcodes']         = $this->shortcodes;

			if ( is_admin() ) {
				$GLOBALS['wp_mcp_ai_admin_cron_manager']     = $this->admin_cron_manager;
				$GLOBALS['wp_mcp_ai_admin_token_manager']    = $this->admin_token_manager;
				$GLOBALS['wp_mcp_ai_admin_crawl4ai_monitor'] = $this->admin_crawl4ai_monitor;
			}

			WP_MCP_AI_Crawler::init();

			WP_MCP_AI_Usage_Tracker::init();
			WP_MCP_AI_Tool_Token_Limits::init();

			// Initialize database optimizations for token management.
			if ( class_exists( 'WP_MCP_AI_Token_DB_Optimizer' ) ) {
				WP_MCP_AI_Token_DB_Optimizer::init();
			}

			// Phase 7 Week 5-6: Initialize enhanced token tracking with real-time cost attribution.
			if ( class_exists( 'WP_MCP_AI_Enhanced_Token_Tracking' ) ) {
				WP_MCP_AI_Enhanced_Token_Tracking::init();
			}

			if ( class_exists( 'WP_MCP_AI_Elementor_Integration' ) ) {
				// Check if Elementor widgets are enabled in settings.
				// Defaults to true for backward compatibility.
				$settings        = get_option( 'wp_mcp_ai_settings', array() );
				$widgets_enabled = isset( $settings['enable_elementor_widgets'] ) ? (bool) $settings['enable_elementor_widgets'] : true;

				if ( $widgets_enabled ) {
					WP_MCP_AI_Elementor_Integration::maybe_init();
				}
			}

			// Initialize Gutenberg blocks for AI Assistant Builder.
			if ( class_exists( 'WP_MCP_AI_Assistant_Builder_Blocks' ) ) {
				WP_MCP_AI_Assistant_Builder_Blocks::init();
			}

			// Disable wp-auth-check in Elementor editor to prevent JavaScript errors.
			add_action( 'admin_enqueue_scripts', array( $this, 'disable_auth_check_in_elementor' ), 20 );

			// Suppress debug output during Elementor AJAX requests.
			add_action( 'admin_init', array( $this, 'suppress_debug_in_elementor_ajax' ), 1 );
		}

		/**
		 * Suppress debug output during Elementor AJAX requests.
		 *
		 * Prevents PHP warnings, notices, and deprecation messages from breaking
		 * Elementor's JSON responses when WP_DEBUG is enabled.
		 */
		public function suppress_debug_in_elementor_ajax() {
			// Only apply to AJAX requests.
			// Use function_exists check for backwards compatibility with WordPress < 4.7.0.
			$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
				|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

			if ( ! $is_ajax ) {
				return;
			}

			// Check if this is an Elementor-related AJAX request.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_REQUEST['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

			// Check if this is an Elementor action.
			if ( strpos( $action, 'elementor' ) === 0 ) {
				// Suppress display_errors to prevent debug output from breaking JSON responses.
				// We cannot use WP_DEBUG_DISPLAY here because we need to suppress errors
				// specifically for Elementor AJAX requests to prevent breaking the editor.
				// Error suppression is intentional: some hosts disable ini_set changes,
				// and we prefer graceful degradation over throwing warnings.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.NoSilencedErrors.Discouraged -- Required for Elementor editor compatibility.
					@ini_set( 'display_errors', '0' );
				}

				// Track the current buffer level before starting our buffer.
				// This allows us to clean only the buffer(s) we create.
				$this->elementor_buffer_level = ob_get_level();

				// Start output buffering to catch any stray output that could break JSON responses.
				// This protects against any echoed content, warnings, or notices that occur
				// during the Elementor save process.
				ob_start();

				// Register a shutdown function to clean the buffer before Elementor sends its response.
				// We use priority 0 to run before most other shutdown handlers.
				add_action( 'shutdown', array( $this, 'clean_elementor_output_buffer' ), 0 );
			}
		}

		/**
		 * Clean the output buffer during Elementor AJAX requests.
		 *
		 * This runs during shutdown to ensure any stray output is discarded
		 * before Elementor sends its JSON response.
		 */
		public function clean_elementor_output_buffer() {
			// Check if this is an Elementor AJAX request by verifying the action parameter.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_REQUEST['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

			// Only clean buffer for Elementor actions to avoid interfering with other code.
			if ( strpos( $action, 'elementor' ) !== 0 ) {
				return;
			}

			// Only clean the buffer if we created one.
			if ( null === $this->elementor_buffer_level ) {
				return;
			}

			// Get the current output buffer level.
			$current_level = ob_get_level();

			// Clean buffers down to the level we recorded before starting buffering.
			// This ensures we only clean the buffer(s) we created, not existing ones.
			// We use > (not >=) because elementor_buffer_level is the level BEFORE we started.
			while ( $current_level > 0 && $current_level > $this->elementor_buffer_level ) {
				ob_end_clean();
				$current_level = ob_get_level();
			}

			// Reset the tracked level.
			$this->elementor_buffer_level = null;
		}

		/**
		 * Disable wp-auth-check heartbeat in Elementor editor.
		 *
		 * Prevents JavaScript errors related to missing DOM elements.
		 * Also prevents debug output from breaking JSON responses when WP_DEBUG is enabled.
		 * Elementor uses a query parameter to identify editor mode, which is
		 * validated by Elementor's own nonce verification in its editor loader.
		 */
		public function disable_auth_check_in_elementor() {
			// Check if Elementor is active and in editor mode.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			if ( ! isset( $_GET['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
			$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );

			// Elementor editor uses 'elementor' action parameter.
			// This is a safe check as Elementor's editor loader validates capabilities and nonces.
			if ( 'elementor' === $action && current_user_can( 'edit_posts' ) ) {
				remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

				// Prevent debug output from breaking Elementor's JSON responses.
				// When WP_DEBUG is enabled, PHP warnings/notices can break the editor.
				// We cannot use WP_DEBUG_DISPLAY here because we need to suppress errors
				// specifically for Elementor editor to prevent breaking the UI.
				// Error suppression is intentional: some hosts disable ini_set changes,
				// and we prefer graceful degradation over throwing warnings.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.NoSilencedErrors.Discouraged -- Required for Elementor editor compatibility.
					@ini_set( 'display_errors', '0' );
				}
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_bootstrap' ) ) {
	/**
	 * Bootstrap the plugin once all dependencies are loaded.
	 *
	 * Instantiates the main plugin singleton and calls its bootstrap method
	 * to initialize all core components (REST API, tool registry, assistants, etc.).
	 */
	function wp_mcp_ai_bootstrap() {
		// Instantiate the main plugin singleton and bootstrap it.
		$plugin = WP_MCP_AI::instance();
		$plugin->bootstrap();

		/**
		 * Fires after Open Operator System has completed its bootstrap process.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wp_mcp_ai_bootstrapped' );

		// Note: Pro addon is now loaded earlier (before tools-init.php) to ensure
		// Pro tools can register hooks before tool registry initialization.
		// The wp_mcp_ai_maybe_load_pro_addon() function is kept for backward
		// compatibility but is no longer called from here.
	}
}

if ( ! has_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_bootstrap', 20 );
}

if ( ! function_exists( 'wp_mcp_ai_maybe_load_pro_addon' ) ) {
	/**
	 * Auto-load pro addon if present and not already loaded as separate plugin.
	 *
	 * This allows the combined plugin to include pro addon functionality
	 * when the pro addon is bundled in the addons/pro directory.
	 *
	 * @since 1.0.0
	 */
	function wp_mcp_ai_maybe_load_pro_addon() {
		// Check if pro addon is already loaded as a separate plugin.
		if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		// Check if pro addon exists in the addons directory.
		$pro_addon_file = WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php';
		if ( ! file_exists( $pro_addon_file ) ) {
			return;
		}

		// Load the pro addon.
		require_once $pro_addon_file;

		// Initialize pro addon if it has an init function.
		if ( function_exists( 'wp_mcp_ai_pro_init' ) ) {
			wp_mcp_ai_pro_init();
		}
	}
}

/**
 * Initialize async tool executor during plugin bootstrap.
 *
 * Ensures the executor's init() method is called early enough to register
 * the wp_mcp_ai_async_tool_execution cron hook handler. Without this,
 * async tool cron jobs would be scheduled but never execute.
 *
 * SOC: Executor handles execution logic, this hook ensures initialization timing.
 */
if ( ! has_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_init_async_executor' ) ) {
	add_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_init_async_executor', 5 );
}

if ( ! function_exists( 'wp_mcp_ai_init_async_executor' ) ) {
	/**
	 * Initialize the async tool executor.
	 *
	 * Called during wp_mcp_ai_bootstrapped action to ensure the executor
	 * registers its cron hook handler before any async jobs might run.
	 */
	function wp_mcp_ai_init_async_executor() {
		wp_mcp_ai_get_async_tool_executor();
	}
}

/**
 * Ensure file cleanup cron jobs are scheduled.
 *
 * Called on plugins_loaded to handle plugin upgrades where activation
 * hook doesn't fire. Checks if cron events exist and schedules them if missing.
 */
if ( ! has_action( 'plugins_loaded', 'wp_mcp_ai_ensure_cleanup_cron_scheduled' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_ensure_cleanup_cron_scheduled', 25 );
}

if ( ! function_exists( 'wp_mcp_ai_ensure_cleanup_cron_scheduled' ) ) {
	/**
	 * Ensure cleanup cron jobs are scheduled on every plugin load.
	 *
	 * This ensures existing installations get the cron jobs when they upgrade,
	 * not just on fresh activations.
	 */
	function wp_mcp_ai_ensure_cleanup_cron_scheduled() {
		// Schedule Gemini file cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_gemini_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_gemini_files' );
		}

		// Schedule OpenAI file cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_openai_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_openai_files' );
		}
	}
}

/**
 * Register cron job handlers for file cleanup.
 */
if ( ! has_action( 'wp_mcp_ai_cleanup_gemini_files', 'wp_mcp_ai_cleanup_gemini_files_handler' ) ) {
	add_action( 'wp_mcp_ai_cleanup_gemini_files', 'wp_mcp_ai_cleanup_gemini_files_handler' );
}

if ( ! has_action( 'wp_mcp_ai_cleanup_openai_files', 'wp_mcp_ai_cleanup_openai_files_handler' ) ) {
	add_action( 'wp_mcp_ai_cleanup_openai_files', 'wp_mcp_ai_cleanup_openai_files_handler' );
}

if ( ! function_exists( 'wp_mcp_ai_cleanup_gemini_files_handler' ) ) {
	/**
	 * Cron job handler for cleaning up old Gemini files.
	 *
	 * Runs daily to remove files older than 24 hours from Gemini File API
	 * and clear associated cache entries.
	 */
	function wp_mcp_ai_cleanup_gemini_files_handler() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';

		$file_service = new WP_MCP_AI_Gemini_File_Service();

		// Cleanup files older than 24 hours.
		$result = $file_service->cleanup_old_files( 24 * HOUR_IN_SECONDS );

		WP_MCP_AI_Logger::log_event(
			'gemini_file_cleanup_cron',
			'Daily Gemini file cleanup completed.',
			$result
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_cleanup_openai_files_handler' ) ) {
	/**
	 * Cron job handler for cleaning up old OpenAI files.
	 *
	 * Runs daily to remove files older than 24 hours from OpenAI File API
	 * and clear associated cache entries.
	 */
	function wp_mcp_ai_cleanup_openai_files_handler() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-openai-file-service.php';

		$file_service = new WP_MCP_AI_OpenAI_File_Service();

		// Cleanup files older than 24 hours.
		$result = $file_service->cleanup_old_files( 24 * HOUR_IN_SECONDS );

		WP_MCP_AI_Logger::log_event(
			'openai_file_cleanup_cron',
			'Daily OpenAI file cleanup completed.',
			$result
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_iterate_network_sites' ) ) {
	/**
	 * Helper function to iterate through all sites in a multisite network.
	 *
	 * @param callable $callback Callback function to execute for each site.
	 * @param string   $action   Action name for error logging (e.g., 'activation', 'deactivation').
	 * @return void
	 */
	function wp_mcp_ai_iterate_network_sites( $callback, $action = 'operation' ) {
		if ( ! is_multisite() || ! is_callable( $callback ) ) {
			return;
		}

		/**
		 * Filters the arguments for get_sites() when iterating network sites.
		 *
		 * Allows customization of site retrieval, including pagination for large networks.
		 *
		 * @param array $args Arguments passed to get_sites(). Default: array( 'number' => 0 ).
		 */
		$get_sites_args = apply_filters(
			'wp_mcp_ai_iterate_network_sites_args',
			array( 'number' => 0 )
		);

		// Get sites in the network.
		$sites = get_sites( $get_sites_args );

		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			try {
				call_user_func( $callback );
			} catch ( Exception $e ) {
				// Log the error and continue with remaining sites.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production error logging required for multisite activation/deactivation tracking.
				error_log( sprintf( 'Open Operator System %s failed for site %d: %s', $action, $site->blog_id, $e->getMessage() ) );
			}
			restore_current_blog();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_new_site_activation' ) ) {
	/**
	 * Activate plugin on a newly created site in a multisite network.
	 *
	 * @param int|WP_Site $blog WordPress 5.1+ passes a WP_Site object, earlier versions pass blog ID.
	 * @return void
	 */
	function wp_mcp_ai_new_site_activation( $blog ) {
		if ( ! is_plugin_active_for_network( plugin_basename( WP_MCP_AI_FILE ) ) ) {
			return;
		}

		// Handle both WP_Site object (WP 5.1+) and blog ID (earlier versions).
		if ( is_object( $blog ) && isset( $blog->blog_id ) ) {
			$blog_id = (int) $blog->blog_id;
		} elseif ( is_numeric( $blog ) ) {
			$blog_id = (int) $blog;
		} else {
			// Invalid parameter, log error and return.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production error logging required for debugging multisite activation issues.
			error_log( 'Open Operator System: Invalid blog parameter passed to new_site_activation' );
			return;
		}

		switch_to_blog( $blog_id );
		try {
			wp_mcp_ai_activate_single_site();
		} catch ( Exception $e ) {
			// Log the error but don't break the site creation process.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production error logging required for debugging multisite activation issues.
			error_log( sprintf( 'Open Operator System activation failed for site %d: %s', $blog_id, $e->getMessage() ) );
		}
		restore_current_blog();
	}
}

if ( ! has_action( 'wp_initialize_site', 'wp_mcp_ai_new_site_activation' ) ) {
	add_action( 'wp_initialize_site', 'wp_mcp_ai_new_site_activation' );
	add_action( 'wpmu_new_blog', 'wp_mcp_ai_new_site_activation' );
}

if ( ! function_exists( 'wp_mcp_ai_check_activation_security' ) ) {
	/**
	 * Check site security during plugin activation.
	 *
	 * @return void
	 */
	function wp_mcp_ai_check_activation_security() {
		// Allow users to bypass security check with a constant.
		if ( defined( 'WP_MCP_AI_SKIP_SECURITY_CHECK' ) && WP_MCP_AI_SKIP_SECURITY_CHECK ) {
			return;
		}

		// Load the security check tool.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-site-security.php';

		// Use container to create instance (supports dependency injection for testing).
		$security_tool = wp_mcp_ai_make( 'WP_MCP_AI_Tool_Check_Site_Security' );
		$result        = $security_tool->execute( array(), array( 'user_id' => get_current_user_id() ) );

		// Store result for admin notice display.
		if ( ! is_wp_error( $result ) ) {
			set_transient( 'wp_mcp_ai_activation_security_check', $result, HOUR_IN_SECONDS );
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_activation_security_notice' ) ) {
	/**
	 * Display security warning notice after plugin activation.
	 *
	 * @return void
	 */
	function wp_mcp_ai_activation_security_notice() {
		$security_check = get_transient( 'wp_mcp_ai_activation_security_check' );

		if ( ! $security_check || ! is_array( $security_check ) ) {
			return;
		}

		// Delete transient so notice only shows once.
		delete_transient( 'wp_mcp_ai_activation_security_check' );

		$risk_level = isset( $security_check['risk_level'] ) ? $security_check['risk_level'] : 'unknown';
		$is_safe    = isset( $security_check['is_safe_to_use'] ) ? $security_check['is_safe_to_use'] : false;

		// Only show notice for high and critical risk levels.
		if ( 'critical' !== $risk_level && 'high' !== $risk_level ) {
			return;
		}

		$recommendation = isset( $security_check['recommendation'] ) ? $security_check['recommendation'] : '';
		$summary        = isset( $security_check['summary'] ) ? $security_check['summary'] : array();
		$checks         = isset( $security_check['checks'] ) ? $security_check['checks'] : array();

		$notice_class = 'critical' === $risk_level ? 'notice-error' : 'notice-warning';

		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<h3><?php esc_html_e( 'Open Operator System Security Warning', 'wp-mcp-ai' ); ?></h3>
			<p><strong><?php echo esc_html( $recommendation ); ?></strong></p>
			
			<?php if ( ! empty( $summary ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: number of critical issues, 2: number of warnings */
						esc_html__( 'Security Check Results: %1$d critical issue(s), %2$d warning(s)', 'wp-mcp-ai' ),
						isset( $summary['critical'] ) ? absint( $summary['critical'] ) : 0,
						isset( $summary['warning'] ) ? absint( $summary['warning'] ) : 0
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $checks ) ) : ?>
				<ul style="list-style-type: disc; margin-left: 20px;">
					<?php foreach ( $checks as $check ) : ?>
						<?php if ( isset( $check['severity'] ) && in_array( $check['severity'], array( 'critical', 'warning' ), true ) ) : ?>
							<li>
								<strong><?php echo esc_html( isset( $check['name'] ) ? $check['name'] : '' ); ?>:</strong>
								<?php echo esc_html( isset( $check['message'] ) ? $check['message'] : '' ); ?>
								<?php if ( ! empty( $check['action'] ) ) : ?>
									<br><em><?php echo esc_html( $check['action'] ); ?></em>
								<?php endif; ?>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'This plugin handles sensitive AI API keys and data. Using it on an insecure site puts your API keys and user data at risk.', 'wp-mcp-ai' ); ?>
			</p>
			<p>
				<em>
					<?php
					printf(
						/* translators: %s: code snippet for wp-config.php */
						esc_html__( 'To bypass this security check, add %s to your wp-config.php file. Only do this if you understand the risks.', 'wp-mcp-ai' ),
						'<code>define( \'WP_MCP_AI_SKIP_SECURITY_CHECK\', true );</code>'
					);
					?>
				</em>
			</p>
		</div>
		<?php
	}
}

/**
 * Register activation security notice on init to avoid early translation loading.
 *
 * WordPress 6.7.0+ requires translations to be loaded at init or later.
 */
function wp_mcp_ai_register_activation_security_notice() {
	add_action( 'admin_notices', 'wp_mcp_ai_activation_security_notice' );
}
add_action( 'init', 'wp_mcp_ai_register_activation_security_notice' );

if ( ! function_exists( 'wp_mcp_ai_activate' ) ) {
	/**
	 * Plugin activation handler.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	function wp_mcp_ai_activate( $network_wide = false ) {
		// Ensure network_wide is a boolean.
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_activate_single_site', 'activation' );
		} else {
			wp_mcp_ai_activate_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_activate_single_site' ) ) {
	/**
	 * Activate the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_activate_single_site() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Check site security and store result for display in admin notice.
		wp_mcp_ai_check_activation_security();

		// Schedule file cleanup cron job (daily).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_gemini_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_gemini_files' );
		}
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_openai_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_openai_files' );
		}

		// Note: We intentionally do not call WP_MCP_AI_Assistant_CPT::register_post_type() here
		// to avoid triggering translation loading before the init action (WordPress 6.7+ requirement).
		// The post type will be registered on the next page load via the init hook.
		flush_rewrite_rules();
	}
}

register_activation_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_activate' );

if ( ! function_exists( 'wp_mcp_ai_deactivate' ) ) {
	/**
	 * Plugin deactivation handler.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
	 * @return void
	 */
	function wp_mcp_ai_deactivate( $network_wide = false ) {
		// Ensure network_wide is a boolean.
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_deactivate_single_site', 'deactivation' );
		} else {
			wp_mcp_ai_deactivate_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_deactivate_single_site' ) ) {
	/**
	 * Deactivate the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_deactivate_single_site() {
		// Unschedule file cleanup cron jobs.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_cleanup_gemini_files' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_cleanup_gemini_files' );
		}
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_cleanup_openai_files' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_cleanup_openai_files' );
		}

		flush_rewrite_rules();
	}
}

register_deactivation_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_deactivate' );

if ( ! function_exists( 'wp_mcp_ai_uninstall' ) ) {
	/**
	 * Plugin uninstall handler.
	 *
	 * @return void
	 */
	function wp_mcp_ai_uninstall() {
		if ( is_multisite() ) {
			wp_mcp_ai_iterate_network_sites( 'wp_mcp_ai_uninstall_single_site', 'uninstall' );
		} else {
			wp_mcp_ai_uninstall_single_site();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_uninstall_single_site' ) ) {
	/**
	 * Uninstall the plugin on a single site.
	 *
	 * @return void
	 */
	function wp_mcp_ai_uninstall_single_site() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, WP_MCP_AI_Admin_Settings::get_default_settings() );

		if ( empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}

		/**
		 * Fires before Open Operator System performs its uninstall cleanup routines.
		 */
		do_action( 'wp_mcp_ai_before_uninstall_cleanup' );

		$assistant_ids = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $assistant_ids ) ) {
			foreach ( $assistant_ids as $assistant_id ) {
				wp_delete_post( $assistant_id, true );
			}
		}

		$settings_deleted = delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		/**
		 * Fires after Open Operator System completes its uninstall cleanup routines.
		 *
		 * @param array $summary Summary of cleanup actions performed.
		 */
		do_action(
			'wp_mcp_ai_after_uninstall_cleanup',
			array(
				'assistants_deleted' => is_array( $assistant_ids ) ? count( $assistant_ids ) : 0,
				'settings_deleted'   => (bool) $settings_deleted,
			)
		);
	}
}

register_uninstall_hook( WP_MCP_AI_FILE, 'wp_mcp_ai_uninstall' );

if ( ! function_exists( 'wp_mcp_ai_extend_upload_mimes' ) ) {
	/**
	 * Ensure additional file-search formats can be uploaded when enabled.
	 *
	 * @param array|string $mimes Allowed mime types keyed by file extension.
	 * @return array
	 */
	function wp_mcp_ai_extend_upload_mimes( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			$mimes = array();
		}

		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			return $mimes;
		}

		$allowed_sets = WP_MCP_AI_Message_Attachments::get_allowed_mime_types();
		$file_mimes   = isset( $allowed_sets['file'] ) ? (array) $allowed_sets['file'] : array();

		$jsonl_candidates = array(
			'application/jsonl',
			'application/x-ndjson',
		);

		$selected_jsonl_mime = '';

		foreach ( $jsonl_candidates as $candidate ) {
			if ( in_array( $candidate, $file_mimes, true ) ) {
				$selected_jsonl_mime = $candidate;
				break;
			}
		}

		if ( '' !== $selected_jsonl_mime ) {
			$mimes['jsonl'] = $selected_jsonl_mime;
		}

		if ( in_array( 'application/x-ndjson', $file_mimes, true ) ) {
			$mimes['ndjson'] = 'application/x-ndjson';
		} elseif ( '' !== $selected_jsonl_mime ) {
			$mimes['ndjson'] = $selected_jsonl_mime;
		}

		if ( in_array( 'text/markdown', $file_mimes, true ) ) {
			$mimes['md']       = 'text/markdown';
			$mimes['markdown'] = 'text/markdown';
		}

		return $mimes;
	}
}

if ( ! has_filter( 'upload_mimes', 'wp_mcp_ai_extend_upload_mimes' ) ) {
	add_filter( 'upload_mimes', 'wp_mcp_ai_extend_upload_mimes' );
}

/**
 * Setup cache invalidation hooks for assistant changes.
 *
 * Ensures caches are cleared when assistants are created, updated, or deleted.
 *
 * @since 1.0.0
 */
function wp_mcp_ai_setup_cache_invalidation_hooks() {
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	// Invalidate caches when assistant posts are saved or deleted.
	add_action( 'save_post_mcp_ai_assistant', 'wp_mcp_ai_invalidate_assistant_cache_on_save', 10, 1 );
	add_action( 'delete_post', 'wp_mcp_ai_invalidate_assistant_cache_on_delete', 10, 1 );
	add_action( 'wp_trash_post', 'wp_mcp_ai_invalidate_assistant_cache_on_delete', 10, 1 );
	add_action( 'untrash_post', 'wp_mcp_ai_invalidate_assistant_cache_on_save', 10, 1 );

	// Invalidate when assistant meta is updated.
	add_action( 'updated_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update', 10, 4 );
	add_action( 'added_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update', 10, 4 );
	add_action( 'deleted_post_meta', 'wp_mcp_ai_invalidate_assistant_cache_on_meta_update', 10, 4 );

	// REST API cache invalidation hooks.
	if ( class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
		add_action( 'save_post_mcp_ai_assistant', array( 'WP_MCP_AI_REST_Cache', 'invalidate_on_assistant_save' ), 10, 1 );
		add_action( 'delete_post', array( 'WP_MCP_AI_REST_Cache', 'invalidate_on_assistant_delete' ), 10, 1 );
		add_action( 'wp_trash_post', array( 'WP_MCP_AI_REST_Cache', 'invalidate_on_assistant_delete' ), 10, 1 );
	}
}

/**
 * Invalidate cache when assistant is saved.
 *
 * @param int $post_id Post ID.
 */
function wp_mcp_ai_invalidate_assistant_cache_on_save( $post_id ) {
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $post_id );
}

/**
 * Invalidate cache when assistant is deleted.
 *
 * @param int $post_id Post ID.
 */
function wp_mcp_ai_invalidate_assistant_cache_on_delete( $post_id ) {
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	$post = get_post( $post_id );
	if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
		WP_MCP_AI_Cache_Helper::invalidate_assistant_caches();
	}
}

/**
 * Invalidate cache when assistant meta is updated.
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $object_id  Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 */
function wp_mcp_ai_invalidate_assistant_cache_on_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback signature requires all parameters.
	if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
		return;
	}

	// Only invalidate for assistant meta keys.
	if ( 0 === strpos( $meta_key, 'mcp_ai_' ) ) {
		$post = get_post( $object_id );
		if ( $post && 'mcp_ai_assistant' === $post->post_type ) {
			WP_MCP_AI_Cache_Helper::invalidate_assistant_cache( $object_id );
		}
	}
}

// Initialize cache invalidation hooks.
add_action( 'init', 'wp_mcp_ai_setup_cache_invalidation_hooks', 20 );

