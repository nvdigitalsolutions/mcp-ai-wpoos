<?php
/**
 * PHPUnit bootstrap file.
 *
 * Sets up the WordPress test environment and loads the plugin.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

$_tests_dir  = getenv( 'WP_TESTS_DIR' );
$plugin_root = dirname( __DIR__ );

if ( ! $_tests_dir ) {
	$vendor_tests_dir = $plugin_root . '/vendor/wp-phpunit/wp-phpunit';
	if ( file_exists( $vendor_tests_dir . '/includes/functions.php' ) ) {
		$_tests_dir = $vendor_tests_dir;
	} else {
		$_tests_dir = sys_get_temp_dir() . '/wordpress-tests-lib';
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress tests directory at {$_tests_dir}.\n" );
	fwrite( STDERR, "Run 'composer run test:install' to download the WordPress testing framework or install the 'wp-phpunit/wp-phpunit' composer package.\n" );
	exit( 1 );
}

$wordpress_path = getenv( 'WP_CORE_DIR' );
if ( ! $wordpress_path ) {
	// ── WordPress Studio detection ──────────────────────────────
	// Studio stores sites in platform-specific directories.
	// On Windows: %LOCALAPPDATA%\WordPress Studio\sites\{slug}
	// On macOS:   ~/Library/Application Support/WordPress Studio/sites/{slug}
	$studio_site_slug = getenv( 'WP_STUDIO_SITE_SLUG' );
	if ( $studio_site_slug ) {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$studio_base = getenv( 'LOCALAPPDATA' ) . '/WordPress Studio/sites';
		} else {
			$studio_base = getenv( 'HOME' ) . '/Library/Application Support/WordPress Studio/sites';
		}
		$studio_path = $studio_base . '/' . $studio_site_slug . '/app/public';
		if ( file_exists( $studio_path . '/wp-load.php' ) ) {
			$wordpress_path = $studio_path;
			fwrite( STDOUT, "\n✓ Using WordPress Studio site: {$studio_site_slug}\n" );
			fwrite( STDOUT, "  Path: {$wordpress_path}\n\n" );
		}
	}

	// ── Auto-detect Studio sites (when no slug specified) ──────
	if ( ! $wordpress_path ) {
		$studio_candidates = array();
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$studio_base = getenv( 'LOCALAPPDATA' ) . '/WordPress Studio/sites';
		} else {
			$studio_base = getenv( 'HOME' ) . '/Library/Application Support/WordPress Studio/sites';
		}
		if ( is_dir( $studio_base ) ) {
			$sites = scandir( $studio_base );
			if ( $sites ) {
				foreach ( $sites as $site ) {
					if ( '.' === $site || '..' === $site ) {
						continue;
					}
					$candidate = $studio_base . '/' . $site . '/app/public/wp-load.php';
					if ( file_exists( $candidate ) ) {
						$studio_candidates[] = $studio_base . '/' . $site . '/app/public';
					}
				}
			}
		}
		if ( 1 === count( $studio_candidates ) ) {
			$wordpress_path = $studio_candidates[0];
			fwrite( STDOUT, "\n✓ Auto-detected WordPress Studio site: {$wordpress_path}\n\n" );
		} elseif ( count( $studio_candidates ) > 1 ) {
			fwrite( STDOUT, "\nMultiple WordPress Studio sites found:\n" );
			foreach ( $studio_candidates as $i => $candidate ) {
				fwrite( STDOUT, "  [{$i}] {$candidate}\n" );
			}
			fwrite( STDOUT, "\nSet WP_STUDIO_SITE_SLUG environment variable to choose one.\n" );
			fwrite( STDOUT, "Example: WP_STUDIO_SITE_SLUG=mysite vendor/bin/phpunit\n\n" );
		}
	}

	// ── Codex environment ──────────────────────────────────────
	if ( ! $wordpress_path ) {
		$codex_path = $plugin_root . '/.codex-wordpress/wordpress';
		if ( file_exists( $codex_path . '/wp-load.php' ) ) {
			$wordpress_path = $codex_path;
		}
	}

	// ── Docker / wp-env ────────────────────────────────────────
	if ( ! $wordpress_path ) {
		$wp_env_path = $plugin_root . '/.wp-env/wordpress';
		if ( file_exists( $wp_env_path . '/wp-load.php' ) ) {
			$wordpress_path = $wp_env_path;
		}
	}
}

if ( ! $wordpress_path ) {
	fwrite( STDERR, "\nCould not locate a WordPress installation.\n\n" );
	fwrite( STDERR, "Options:\n" );
	fwrite( STDERR, "  1. WordPress Studio:  Set WP_STUDIO_SITE_SLUG=your-site-slug\n" );
	fwrite( STDERR, "  2. Codex environment: Run 'bin/codex-startup.sh'\n" );
	fwrite( STDERR, "  3. Any WP install:    Set WP_CORE_DIR=/path/to/wordpress\n" );
	fwrite( STDERR, "  4. wp-env:            Run 'npx wp-env start'\n\n" );
	exit( 1 );
}

// Determine the test database directory.
// For Studio environments, use a temp-dir based path to avoid
// polluting the Studio site's own database directory.
$studio_slug = getenv( 'WP_STUDIO_SITE_SLUG' );
if ( $studio_slug ) {
	$tests_db_dir = sys_get_temp_dir() . '/wp-mcp-ai-tests-database/' . $studio_slug;
} else {
	$tests_db_dir = $plugin_root . '/.codex-wordpress/tests-database';
}
if ( ! is_dir( $tests_db_dir ) && ! mkdir( $tests_db_dir, 0775, true ) && ! is_dir( $tests_db_dir ) ) {
	fwrite( STDERR, "Unable to create the SQLite directory at {$tests_db_dir}.\n" );
	exit( 1 );
}

// Wire up SQLite Database Integration drop-in when using SQLite.
// The fixture provides a db.php drop-in that the WP test bootstrap loads.
$_sqlite_fixture = $plugin_root . '/tests/fixtures/sqlite-database-integration';
if ( is_dir( $_sqlite_fixture ) && ! getenv( 'WP_DB_HOST' ) ) {
	// The drop-in is activated in wp-tests-config.php via DB_TYPE=sqlite.
	// We just ensure the directory exists for the test environment to find it.
	if ( ! defined( 'WP_TESTS_SQLITE_DROPIN_DIR' ) ) {
		define( 'WP_TESTS_SQLITE_DROPIN_DIR', $_sqlite_fixture );
	}
}

$polyfills_root = $plugin_root . '/vendor/yoast/phpunit-polyfills';
if ( file_exists( $polyfills_root . '/phpunitpolyfills-autoload.php' ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_root );
}

$tests_config = $plugin_root . '/tests/wp-tests-config.php';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $tests_config );
}

if ( ! defined( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	define( 'WP_PHPUNIT__TESTS_CONFIG', $tests_config );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', $tests_config );

// Enable full version for tests to load all integration classes.
// Individual tests can use the wp_mcp_ai_base_version filter to test base version behavior.
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', false );
}

require_once $_tests_dir . '/includes/functions.php';
require_once __DIR__ . '/helpers/trait-wp-mcp-ai-docx-test-helper.php';
require_once __DIR__ . '/helpers/trait-wp-mcp-ai-rest-test-helper.php';
require_once __DIR__ . '/helpers/class-wp-mcp-ai-test-helper.php';

/**
 * Manually load the plugin being tested.
 */
function wp_mcp_ai_manually_load_plugin() {
	// ── Disable all AI providers by default in tests ────────────
	// When the plugin bootstraps, it instantiates every provider client
	// via the container (even if unused). Pre-populating the settings
	// default means `get_available_providers()` returns nothing and
	// the router won't attempt real API calls from unmocked tests.
	// Individual tests re-enable the providers they need via
	// `update_option( 'wp_mcp_ai_settings', $settings )`.
	add_filter(
		'default_option_wp_mcp_ai_settings',
		function ( $default ) {
			return array_merge(
				$default,
				array(
					'enable_openai'       => false,
					'enable_gemini'       => false,
					'enable_anthropic'    => false,
					'enable_ollama'       => false,
					'enable_lm_studio'    => false,
					'enable_cloudflare'   => false,
					'enable_deepseek'     => false,
					'enable_kimi'         => false,
					'enable_baseten'      => false,
					'enable_openrouter'   => false,
					'enable_digitalocean' => false,
					'enable_huggingface'  => false,
					'enable_nvidia'       => false,
					// enable_embedded intentionally omitted — it is a local/
					// in-browser provider with no API key, always available.
				)
			);
		},
		10,
		1
	);

	require dirname( __DIR__ ) . '/mcp-ai-wpoos.php';

	// Load the SaaS Controller addon if present so its tests can exercise
	// its classes. The addon is a standalone WP plugin (not auto-loaded by
	// the base plugin) and ships its own `nvoos_saas_controller_bootstrap`
	// hook on `plugins_loaded` priority 20 — loading the file here is
	// equivalent to activating the plugin in a real install.
	$saas_controller = dirname( __DIR__ ) . '/addons/saas-controller/nvoos-saas-controller.php';
	if ( file_exists( $saas_controller ) ) {
		require $saas_controller;
	}

	// Load the Pro addon if present so its tests (e.g. messaging-channels-ajax,
	// CPT AI integration, quiz tools) can exercise their classes.
	// Guard against double-loading: CI environments may have Pro activated as
	// a regular plugin (loaded by WordPress before this mu-plugin callback).
	$pro_addon = dirname( __DIR__ ) . '/addons/pro/mcp-ai-wpoos-pro.php';
	if ( file_exists( $pro_addon ) && ! function_exists( 'wp_mcp_ai_pro_activate' ) ) {
		require_once $pro_addon;
	}
}

tests_add_filter( 'muplugins_loaded', 'wp_mcp_ai_manually_load_plugin' );

/**
 * Detect optional test plugins and set flags for integration tests.
 *
 * Do NOT manually `require_once` plugin files here — WordPress loads
 * them during normal activation (plugins_loaded), and double-loading
 * causes "Cannot declare class" fatal errors when running against a
 * live WordPress site where plugins are already activated.
 */
function wp_mcp_ai_load_optional_test_plugins() {
	$wp_core_dir    = getenv( 'WP_CORE_DIR' );
	$wordpress_path = $wp_core_dir ? $wp_core_dir : dirname( __DIR__ ) . '/.codex-wordpress/wordpress';
	$plugins_dir    = $wordpress_path . '/wp-content/plugins';

	// Track which plugins are detected for diagnostic output.
	$loaded_plugins = array();

	// Detect WooCommerce.
	if ( file_exists( $plugins_dir . '/woocommerce/woocommerce.php' ) ) {
		$loaded_plugins[] = 'woocommerce';
		define( 'WP_MCP_AI_TEST_WOOCOMMERCE_ACTIVE', true );
	}

	// Detect Elementor.
	if ( file_exists( $plugins_dir . '/elementor/elementor.php' ) ) {
		$loaded_plugins[] = 'elementor';
		define( 'WP_MCP_AI_TEST_ELEMENTOR_ACTIVE', true );
	}

	// Detect Rank Math.
	if ( file_exists( $plugins_dir . '/seo-by-rank-math/rank-math.php' ) ) {
		$loaded_plugins[] = 'rank-math';
		define( 'WP_MCP_AI_TEST_RANKMATH_ACTIVE', true );
	}

	// Detect WPCode (main plugin file is ihaf.php, not insert-headers-and-footers.php).
	if ( file_exists( $plugins_dir . '/insert-headers-and-footers/ihaf.php' ) ) {
		$loaded_plugins[] = 'wpcode';
		define( 'WP_MCP_AI_TEST_WPCODE_ACTIVE', true );
	}

	// Detect Simple JWT Login.
	if ( file_exists( $plugins_dir . '/simple-jwt-login/simple-jwt-login.php' ) ) {
		$loaded_plugins[] = 'simple-jwt-login';
		define( 'WP_MCP_AI_TEST_SIMPLE_JWT_LOGIN_ACTIVE', true );
	}

	// Detect JetEngine (premium — must be installed manually).
	if ( file_exists( $plugins_dir . '/jet-engine/jet-engine.php' ) ) {
		$loaded_plugins[] = 'jet-engine';
		define( 'WP_MCP_AI_TEST_JETENGINE_ACTIVE', true );
	}

	if ( ! empty( $loaded_plugins ) ) {
		fwrite( STDOUT, "\nDetected optional test plugins: " . implode( ', ', $loaded_plugins ) . "\n\n" );
	}
}

tests_add_filter( 'muplugins_loaded', 'wp_mcp_ai_load_optional_test_plugins', 5 );

/**
 * Set up test environment with admin user and authentication.
 */
function wp_mcp_ai_setup_test_environment() {
	// Create unique admin user for tests to avoid conflicts.
	$unique_id = uniqid();
	$admin_id  = wp_create_user( 'test_admin_' . $unique_id, 'password', 'admin_' . $unique_id . '@example.com' );
	$admin     = new WP_User( $admin_id );
	$admin->set_role( 'administrator' );

	// Set as current user.
	wp_set_current_user( $admin_id );

	// Set up REST authentication.
	$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );

	// Set auth cookie.
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' );

	// Enable all capabilities for admin user in tests.
	add_filter(
		'user_has_cap',
		function ( $allcaps ) {
			$allcaps['manage_options']    = true;
			$allcaps['edit_posts']        = true;
			$allcaps['upload_files']      = true;
			$allcaps['edit_others_posts'] = true;
			$allcaps['delete_posts']      = true;
			return $allcaps;
		}
	);
}

tests_add_filter( 'wp_loaded', 'wp_mcp_ai_setup_test_environment' );

/**
 * Initialize database tables for testing.
 */
function wp_mcp_ai_init_test_database_tables() {
	// Ensure token tracking database table exists for tests.
	if ( class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
		WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();
	}
}

tests_add_filter( 'wp_loaded', 'wp_mcp_ai_init_test_database_tables', 20 );

require $_tests_dir . '/includes/bootstrap.php';

// ---------------------------------------------------------------------------
// Global wp_die exception handler.
//
// Without this, wp_die("Security check failed.") in AJAX handlers calls PHP's
// die(), killing the entire PHPUnit process. The wp_die_ajax_handler filter
// is critical — WordPress uses _ajax_wp_die_handler() (which calls die()
// directly) when DOING_AJAX is defined, bypassing the standard wp_die_handler
// filter.
// ---------------------------------------------------------------------------
$throw_die_handler = function () {
	return function ( $message, $title = '', $args = array() ) {
		throw new WPDieException( $message, $title, $args );
	};
};
add_filter( 'wp_die_handler', $throw_die_handler, PHP_INT_MAX );
add_filter( 'wp_die_ajax_handler', $throw_die_handler, PHP_INT_MAX );

// Helpers that depend on classes provided by the WP test bootstrap (e.g.
// `WP_Ajax_UnitTestCase`) must be loaded after it.
require_once __DIR__ . '/helpers/class-wp-mcp-ai-ajax-testcase.php';
