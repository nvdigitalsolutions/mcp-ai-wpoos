<?php
/**
 * Composer Autoload Bootstrap
 *
 * Loads Composer's autoloader with graceful degradation when development
 * dependencies are present in production but `putenv()` is disabled by the host.
 *
 * Must be included after WP_MCP_AI_PATH is defined (see includes/bootstrap/constants.php).
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

if ( ! file_exists( WP_MCP_AI_PATH . 'vendor/autoload.php' ) ) {
	return;
}

// Check if wp-phpunit dev dependency is present (shouldn't be in production).
$has_dev_deps = file_exists( WP_MCP_AI_PATH . 'vendor/wp-phpunit/wp-phpunit/__loaded.php' );

// Check if putenv is available.
$putenv_available = function_exists( 'putenv' );

// If dev dependencies are present but putenv is disabled, show warning and use fallback.
if ( $has_dev_deps && ! $putenv_available ) {
	if ( is_admin() ) {
		/**
		 * Display notice about dev dependencies in production.
		 */
		function wp_mcp_ai_dev_deps_error_notice() {
			$message = '<strong>Open Operator System</strong> detected development dependencies in production environment. Your hosting provider has disabled the <code>putenv()</code> function, which is required by testing libraries. Please reinstall dependencies with <code>composer install --no-dev</code> to resolve this issue. Until fixed, some plugin features may not work correctly.';
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				wp_kses_post( $message )
			);
		}

		/**
		 * Register dev deps error notice directly on admin_notices.
		 *
		 * This notice doesn't use translation functions, so there's no risk of
		 * early translation loading. However, we follow the same direct hook pattern
		 * as other admin notices for consistency.
		 */
		add_action( 'admin_notices', 'wp_mcp_ai_dev_deps_error_notice' );
	}

	// Log the issue.
	error_log( 'WP_MCP_AI: Development dependencies detected in production with putenv() disabled. Run: composer install --no-dev' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Development-only dependency warning; required to surface missing Composer autoloader during local development.

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

				'Symfony\\Contracts\\Service\\'     => 'vendor/symfony/service-contracts/',
				'Symfony\\Contracts\\HttpClient\\'  => 'vendor/symfony/http-client-contracts/',
				'Symfony\\Contracts\\Cache\\'       => 'vendor/symfony/cache-contracts/',
				'Symfony\\Component\\VarExporter\\' => 'vendor/symfony/var-exporter/',
				'Symfony\\Component\\HttpClient\\'  => 'vendor/symfony/http-client/',
				'Symfony\\Component\\Filesystem\\'  => 'vendor/symfony/filesystem/',
				'Symfony\\Component\\Process\\'     => 'vendor/symfony/process/',
				'Symfony\\Component\\Validator\\'   => 'vendor/symfony/validator/',
				'Symfony\\Contracts\\Translation\\' => 'vendor/symfony/translation-contracts/',
				'Symfony\\Component\\Cache\\'       => 'vendor/symfony/cache/',
				'Nyholm\\Psr7\\'                    => 'vendor/nyholm/psr7/src/',
				'Psr\\Log\\'                        => 'vendor/psr/log/Psr/Log/',
				'Psr\\Http\\Message\\'              => 'vendor/psr/http-message/src/',
				'Psr\\Http\\Client\\'               => 'vendor/psr/http-client/src/',
				'Psr\\Container\\'                  => 'vendor/psr/container/src/',
				'Psr\\Cache\\'                      => 'vendor/psr/cache/src/',

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

unset( $has_dev_deps, $putenv_available, $critical_files );
