<?php // phpcs:ignoreFile WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile
/**
 * PHPUnit bootstrap file.
 *
 * Sets up the WordPress test environment and loads the plugin.
 *
 * This is not a class file — it contains bootstrap functions and a
 * small PHPUnit 11 compatibility shim.
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

// Marker consumed by production code at sites that must terminate a request
// (SSE stream ends, admin redirects). Under PHPUnit those sites return or
// throw instead of calling exit()/die(), which would silently kill the whole
// phpunit process with exit code 0.
if ( ! defined( 'WP_MCP_AI_TESTS_RUNNING' ) ) {
	define( 'WP_MCP_AI_TESTS_RUNNING', true );
}

// ============================================================
// PHPUnit 11 Compatibility: parseTestMethodAnnotations() was
// removed in PHPUnit 10+. The wp-phpunit abstract-testcase.php
// still calls it. Patch it at bootstrap time.
// ============================================================
if ( ! class_exists( 'WP_MCP_AI_PHPUnit11_Compat' ) ) {

	/**
	 * PHPUnit 11 compatibility shim.
	 *
	 * Provides a stub for the removed parseTestMethodAnnotations()
	 * method, returning empty arrays.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	class WP_MCP_AI_PHPUnit11_Compat {

		/**
		 * Stub for removed PHPUnit 9 parseTestMethodAnnotations().
		 *
		 * @param string $cn Class name.
		 * @param string $mn Optional method name.
		 * @return array<string,array>
		 */
		public static function parseTestMethodAnnotations( $cn, $mn = null ) {
			return array(
				'class'  => array(),
				'method' => array(),
			);
		}
	}

	// phpcs:enable
}

$abstract_testcase = $plugin_root . '/vendor/wp-phpunit/wp-phpunit/includes/abstract-testcase.php';
if ( file_exists( $abstract_testcase ) ) {
	$original = file_get_contents( $abstract_testcase );
	$patched  = str_replace(
		'\PHPUnit\Util\Test::parseTestMethodAnnotations',
		'\WP_MCP_AI_PHPUnit11_Compat::parseTestMethodAnnotations',
		$original
	);
	// PHPUnit 11 removed TestCase::getName(); use name() instead.
	$patched = str_replace(
		'$this->getName( false )',
		'$this->name()',
		$patched
	);

	// Write only when the patch actually changes the file, and atomically
	// (temp file + rename). Parallel sweep workers each run this bootstrap;
	// an unconditional rewrite makes concurrent readers observe truncated
	// content mid-write ("Class WP_UnitTestCase_Base not found").
	if ( $patched !== $original ) {
		$tmp = $abstract_testcase . '.tmp-' . getmypid();
		file_put_contents( $tmp, $patched );
		rename( $tmp, $abstract_testcase );
	}
}
// ============================================================

/*
 * Provide a REQUEST_URI, which the CLI SAPI never populates.
 *
 * WordPress core reads `$_SERVER['REQUEST_URI']` unguarded in a few places —
 * most visibly `wp_cron()`, which does
 * `str_contains( $_SERVER['REQUEST_URI'], '/wp-cron.php' )` — so any test that
 * reaches a cron spawn emits a PHP warning that has nothing to do with the
 * code under test. The web SAPI always sets this key; mirror that.
 *
 * Tests that need a specific URI should save and restore the previous value
 * rather than `unset()`-ing it, so later tests keep this default.
 */
if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
	$_SERVER['REQUEST_URI'] = '/';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Throwing die handler used across every wp_die() branch under tests.
 *
 * WordPress 6.9 routes wp_die() through request-specific handlers
 * (`_ajax_wp_die_handler`, `_json_wp_die_handler`, …) that end in bare
 * `die()`/`exit()` calls. A test that triggers `wp_die()` — directly or via
 * `wp_send_json_*()` — outside of `WP_Ajax_UnitTestCase::_handleAjax()`
 * therefore killed the entire phpunit process with exit code 0 and no
 * summary. Replacing those handlers with a throwing one turns every such
 * termination into a catchable `WPDieException`.
 *
 * @param string|WP_Error $message Optional error message.
 * @param string          $title   Optional error title.
 * @param array           $args    Optional die arguments.
 * @throws WPDieException Always.
 */
function wp_mcp_ai_tests_throwing_die_handler( $message = '', $title = '', $args = array() ) {
	unset( $title, $args );

	$text = '';
	if ( is_object( $message ) && is_callable( array( $message, 'get_error_message' ) ) ) {
		$text = (string) $message->get_error_message();
	} elseif ( is_scalar( $message ) ) {
		$text = (string) $message;
	}

	// wp-phpunit declares WPDieException in exceptions.php, which is only
	// required after wp-settings.php loads. Define it lazily so the throwing
	// handler also works during the early bootstrap window.
	if ( ! class_exists( 'WPDieException' ) ) {

		/**
		 * Exception thrown when wp_die() terminates a request under PHPUnit.
		 */
		class WPDieException extends Exception { // phpcs:ignore Generic.Classes.DuplicateClassName
		}
	}

	// Direct AJAX handler calls (tests that invoke wp_ajax_* handlers without
	// the WP_Ajax_UnitTestCase::_handleAjax() harness) are written against the
	// wp-phpunit WPAjaxDieContinueException contract. It extends
	// WPDieException, so every assertion against the base class keeps
	// working while legacy catches keep matching.
	if ( wp_doing_ajax() && class_exists( 'WPAjaxDieContinueException' ) ) {
		throw new WPAjaxDieContinueException( $text );
	}

	throw new WPDieException( $text );
}

/**
 * Swap stock WordPress die handlers for the throwing test handler.
 *
 * Passes through any handler that a test (or the Ajax test case) deliberately
 * installed — e.g. `WP_Ajax_UnitTestCase` replaces the ajax die handler with
 * one that throws `WPAjaxDieContinueException` / `WPAjaxDieStopException`, and
 * that behaviour must be preserved.
 *
 * @param callable $handler Current die handler.
 * @return callable
 */
function wp_mcp_ai_tests_die_handler_filter( $handler ) {
	$stock_handlers = array(
		'_wp_die_handler',
		'_default_wp_die_handler',
		'_ajax_wp_die_handler',
		'_json_wp_die_handler',
		'_jsonp_wp_die_handler',
	);

	if ( ! is_string( $handler ) || ! in_array( $handler, $stock_handlers, true ) ) {
		return $handler;
	}

	return 'wp_mcp_ai_tests_throwing_die_handler';
}

// Priority 11 on wp_die_handler so this runs after wp-phpunit's own
// `_wp_die_handler_filter` (priority 10) and wins. Priority 10 on the ajax
// handler so `WP_Ajax_UnitTestCase`'s priority-1 handler still wins inside
// `_handleAjax()`.
tests_add_filter( 'wp_die_handler', 'wp_mcp_ai_tests_die_handler_filter', 11 );
tests_add_filter( 'wp_die_ajax_handler', 'wp_mcp_ai_tests_die_handler_filter', 10 );
tests_add_filter( 'wp_die_json_handler', 'wp_mcp_ai_tests_die_handler_filter', 10 );
tests_add_filter( 'wp_die_jsonp_handler', 'wp_mcp_ai_tests_die_handler_filter', 10 );

/**
 * Report "doing AJAX" whenever a test simulates an AJAX request.
 *
 * WP 6.9 routes request termination through bare die()/exit() calls when
 * `wp_doing_ajax()` is false: `wp_send_json()` ends with `die;` and
 * `check_ajax_referer()` calls `die( '-1' )` on a missing nonce. Neither can
 * be intercepted through a filter. Flagging the AJAX context routes both
 * paths through `wp_die()`, which the throwing handlers above convert into a
 * catchable `WPDieException`. Tests that do not post an AJAX-shaped payload
 * are unaffected.
 *
 * Two signals count as a simulated AJAX request: an `action` parameter
 * (used by the dispatch harness) or a `nonce` parameter (the plugin's AJAX
 * handlers read their nonce from `$_POST['nonce']` / `$_REQUEST['nonce']`).
 *
 * @param bool $wp_doing_ajax Current value.
 * @return bool
 */
	function wp_mcp_ai_tests_wp_doing_ajax_filter( $wp_doing_ajax ) {
		return (bool) $wp_doing_ajax
			|| ( isset( $_POST['action'] ) && '' !== $_POST['action'] )
			|| isset( $_POST['nonce'] )
			|| isset( $_REQUEST['nonce'] );
	}

tests_add_filter( 'wp_doing_ajax', 'wp_mcp_ai_tests_wp_doing_ajax_filter', 10 );

/**
 * Test-safe override of the pluggable check_ajax_referer().
 *
 * Bridges two test-environment gaps:
 *
 * 1. CLI superglobals: in the CLI SAPI, writes to $_POST / $_GET never
 *    appear in $_REQUEST (the web SAPI builds $_REQUEST once at request
 *    start). wp-phpunit's own WP_Ajax_UnitTestCase::_handleAjax() therefore
 *    sets $_REQUEST['action'] by hand. Handler tests that post a valid
 *    nonce to $_POST would otherwise fail verification for the wrong
 *    reason, so the request superglobals are synced first.
 *
 * 2. The failure branch: core dies with a bare die( '-1' ) when
 *    wp_doing_ajax() is false, which kills the phpunit process. Under tests
 *    the failure always routes through wp_die(), which the throwing die
 *    handlers convert into a catchable exception.
 *
 * Mirrors WP 6.9 core logic; review on core upgrades.
 *
 * @param int|string   $action    The nonce action.
 * @param false|string $query_arg Optional key under which the nonce is stored in $_REQUEST.
 * @param bool         $stop      Whether to stop the request on failure.
 * @return false|int
 */
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		if ( -1 === $action ) {
			_doing_it_wrong( __FUNCTION__, __( 'You should specify an action to be verified by using the first parameter.' ), '4.7.0' );
		}

		// Emulate the web SAPI: $_REQUEST merges $_GET, $_POST and $_COOKIE.
		foreach ( array( '_GET', '_POST', '_COOKIE' ) as $src ) {
			foreach ( (array) $GLOBALS[ $src ] as $key => $value ) {
				if ( ! isset( $_REQUEST[ $key ] ) ) {
					$_REQUEST[ $key ] = $value;
				}
			}
		}

		$nonce = '';

		if ( $query_arg && isset( $_REQUEST[ $query_arg ] ) ) {
			$nonce = $_REQUEST[ $query_arg ];
		} elseif ( isset( $_REQUEST['_ajax_nonce'] ) ) {
			$nonce = $_REQUEST['_ajax_nonce'];
		} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = $_REQUEST['_wpnonce'];
		}

		$result = wp_verify_nonce( $nonce, $action );

		/**
		 * Fires once the Ajax request has been validated or not.
		 *
		 * @param string    $action The Ajax nonce action.
		 * @param false|int $result False if the nonce is invalid, 1 if the nonce is valid and generated between
		 *                          0-12 hours ago, 2 if the nonce is valid and generated between 12-24 hours ago.
		 */
		do_action( 'check_ajax_referer', $action, $result );

		if ( $stop && false === $result ) {
			// Core dies with a bare die( '-1' ) outside AJAX context; route
			// through wp_die() instead so the throwing test handlers convert
			// the termination into a catchable exception.
			wp_die( -1, 403 );
		}

		return $result;
	}
}

/**
 * Neutralise Elementor's init replay when tests re-fire `init`.
 *
 * Several tests call do_action( 'init' ) in setUp to bootstrap plugin
 * subsystems or register REST routes. Elementor hooks Plugin::init() to
 * `init` at priority 0, and every run calls init_components(), which
 * constructs a fresh Elements_Manager whose constructor plain-requires
 * includes/elements/column.php (already declared) — a fatal error that
 * kills the process. After the first real `init`, detach the callback so
 * later `init` firings skip Elementor's re-initialisation.
 */
function wp_mcp_ai_tests_neutralise_elementor_init_replay() {
	if ( class_exists( 'Elementor\\Plugin' ) ) {
		remove_action( 'init', array( \Elementor\Plugin::instance(), 'init' ), 0 );
	}
}

tests_add_filter( 'init', 'wp_mcp_ai_tests_neutralise_elementor_init_replay', PHP_INT_MAX );

/**
 * Provide wc_get_page_screen_id() for tests that fire the `current_screen`
 * hook — WooCommerce's OrderAttributionController calls it from there.
 *
 * WooCommerce declares the function without a function_exists guard and only
 * includes wc-admin-functions.php from WC_Install::create_pages() during a
 * fresh install. Declaring a stub unconditionally at bootstrap parse time
 * fatals with "Cannot redeclare wc_get_page_screen_id()" the first time
 * WooCommerce installs its pages (every fresh MySQL / CI run). Therefore:
 *
 *   1. Prefer WooCommerce's real file when it is installed, loading it here
 *      before WooCommerce itself boots (priority 1; WooCommerce is loaded at
 *      priority 5 by wp_mcp_ai_load_optional_test_plugins()).
 *   2. Fall back to a stub only when WooCommerce is absent (Base builds), so
 *      set_current_screen() does not fatal inside WooCommerce's own hook.
 *
 * Deferred to muplugins_loaded because the WooCommerce file exits when
 * ABSPATH is undefined, and ABSPATH only exists once the WP test bootstrap
 * runs.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			return;
		}

		$wp_core_dir    = getenv( 'WP_CORE_DIR' );
		$wordpress_path = $wp_core_dir ? $wp_core_dir : dirname( __DIR__ ) . '/.codex-wordpress/wordpress';
		$wc_admin_functions = $wordpress_path . '/wp-content/plugins/woocommerce/includes/admin/wc-admin-functions.php';

		if ( file_exists( $wc_admin_functions ) ) {
			require_once $wc_admin_functions;
		}

		if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
			/**
			 * Stub for WooCommerce's admin-only helper.
			 *
			 * Mirrors the production result (WC orders screen under HPOS)
			 * so tests that call set_current_screen() keep working on Base
			 * builds where WooCommerce is not installed.
			 *
			 * @param string $for Page slug, e.g. "shop-order".
			 * @return string Admin screen id.
			 */
			function wc_get_page_screen_id( $for ) {
				$for = str_replace( '-', '_', (string) $for );

				if ( 'shop_order' === $for ) {
					return 'woocommerce_page_wc-orders';
				}

				return 'admin_page_wc-orders--' . $for;
			}
		}
	},
	1
);

require_once __DIR__ . '/helpers/trait-wp-mcp-ai-docx-test-helper.php';
require_once __DIR__ . '/helpers/trait-wp-mcp-ai-rest-test-helper.php';
require_once __DIR__ . '/helpers/trait-wp-mcp-ai-http-test-helper.php';
require_once __DIR__ . '/helpers/trait-wp-mcp-ai-request-context-test-helper.php';
require_once __DIR__ . '/helpers/class-wp-mcp-ai-test-helper.php';

// NOTE: paper-store trait loaded after WP bootstrap below
// (it has an ABSPATH guard that requires WordPress to be loaded first).

/**
 * Manually load the plugin being tested.
 *
 * @return void
 */
function wp_mcp_ai_manually_load_plugin() {
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

	// Ensure security/ISO classes are loaded (loader.php may stop short
	// if intermediate class instantiations fail in CLI context).
	$security_files = array(
		'includes/class-wp-mcp-ai-security-audit.php',
		'includes/class-wp-mcp-ai-security-training.php',
		'includes/class-wp-mcp-ai-supplier-security.php',
		'includes/class-wp-mcp-ai-asset-inventory.php',
		'includes/class-wp-mcp-ai-information-labelling.php',
		'includes/class-wp-mcp-ai-incident-learning.php',
	);
	foreach ( $security_files as $file ) {
		$path = dirname( __DIR__ ) . '/' . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

tests_add_filter( 'muplugins_loaded', 'wp_mcp_ai_manually_load_plugin' );

/**
 * Load optional test plugins if available.
 * This allows integration tests to run when plugins are installed.
 */
function wp_mcp_ai_load_optional_test_plugins() {
	$wp_core_dir    = getenv( 'WP_CORE_DIR' );
	$wordpress_path = $wp_core_dir ? $wp_core_dir : dirname( __DIR__ ) . '/.codex-wordpress/wordpress';
	$plugins_dir    = $wordpress_path . '/wp-content/plugins';

	// Track which plugins are loaded for test skipping.
	$loaded_plugins = array();

	// Load WooCommerce if available.
	if ( file_exists( $plugins_dir . '/woocommerce/woocommerce.php' ) ) {
		require_once $plugins_dir . '/woocommerce/woocommerce.php';
		$loaded_plugins[] = 'woocommerce';
		define( 'WP_MCP_AI_TEST_WOOCOMMERCE_ACTIVE', true );
	}

	// Load Elementor if available.
	if ( file_exists( $plugins_dir . '/elementor/elementor.php' ) ) {
		require_once $plugins_dir . '/elementor/elementor.php';
		$loaded_plugins[] = 'elementor';
		define( 'WP_MCP_AI_TEST_ELEMENTOR_ACTIVE', true );
	}

	// Load Rank Math if available.
	if ( file_exists( $plugins_dir . '/seo-by-rank-math/rank-math.php' ) ) {
		require_once $plugins_dir . '/seo-by-rank-math/rank-math.php';
		$loaded_plugins[] = 'rank-math';
		define( 'WP_MCP_AI_TEST_RANKMATH_ACTIVE', true );
	}

	// Load WPCode if available. The wp.org slug is `insert-headers-and-footers`
	// but the bootstrap file is `ihaf.php`; accept the slug-shaped name too in
	// case a future release renames it.
	foreach ( array( 'ihaf.php', 'wpcode.php', 'insert-headers-and-footers.php' ) as $wpcode_file ) {
		if ( file_exists( $plugins_dir . '/insert-headers-and-footers/' . $wpcode_file ) ) {
			require_once $plugins_dir . '/insert-headers-and-footers/' . $wpcode_file;
			$loaded_plugins[] = 'wpcode';
			define( 'WP_MCP_AI_TEST_WPCODE_ACTIVE', true );
			break;
		}
	}

	// Load Simple JWT Login if available.
	if ( file_exists( $plugins_dir . '/simple-jwt-login/simple-jwt-login.php' ) ) {
		require_once $plugins_dir . '/simple-jwt-login/simple-jwt-login.php';
		$loaded_plugins[] = 'simple-jwt-login';
		define( 'WP_MCP_AI_TEST_SIMPLE_JWT_LOGIN_ACTIVE', true );
	}

	// Load JetFormBuilder if available.
	if ( file_exists( $plugins_dir . '/jetformbuilder/jet-form-builder.php' ) ) {
		require_once $plugins_dir . '/jetformbuilder/jet-form-builder.php';
		$loaded_plugins[] = 'jetformbuilder';
		define( 'WP_MCP_AI_TEST_JETFORMBUILDER_ACTIVE', true );
	}

	// Load Newsletter if available.
	if ( file_exists( $plugins_dir . '/newsletter/plugin.php' ) ) {
		require_once $plugins_dir . '/newsletter/plugin.php';
		$loaded_plugins[] = 'newsletter';
		define( 'WP_MCP_AI_TEST_NEWSLETTER_ACTIVE', true );
	}

	// Load WP All Import (lite) if available.
	if ( file_exists( $plugins_dir . '/wp-all-import/plugin.php' ) ) {
		require_once $plugins_dir . '/wp-all-import/plugin.php';
		$loaded_plugins[] = 'wp-all-import';
		define( 'WP_MCP_AI_TEST_WPALLIMPORT_ACTIVE', true );
	}

	if ( ! empty( $loaded_plugins ) ) {
		fwrite( STDOUT, "\nLoaded optional test plugins: " . implode( ', ', $loaded_plugins ) . "\n\n" );
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

	// Store admin ID for the capability filter.
	$GLOBALS['wp_mcp_ai_test_admin_id'] = $admin_id;

	// Grant full capabilities to the test admin user only.
	add_filter(
		'user_has_cap',
		function ( $allcaps, $caps, $args, $user ) {
			$admin_id = isset( $GLOBALS['wp_mcp_ai_test_admin_id'] ) ? $GLOBALS['wp_mcp_ai_test_admin_id'] : 0;
			if ( $user instanceof WP_User && (int) $user->ID === (int) $admin_id ) {
				$allcaps['manage_options']    = true;
				$allcaps['edit_posts']        = true;
				$allcaps['upload_files']      = true;
				$allcaps['edit_others_posts'] = true;
				$allcaps['delete_posts']      = true;
			}
			return $allcaps;
		},
		10,
		4
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

	// Ensure the async job queue table exists for tests. The load guard
	// queries it on every REST dispatch (`rest_pre_dispatch`); without the
	// table every dispatched request spams SQL errors and slows standalone
	// REST test runs to a crawl.
	if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) && method_exists( 'WP_MCP_AI_Async_Job_Queue', 'create_table' ) ) {
		WP_MCP_AI_Async_Job_Queue::create_table();
	}

	// Ensure the content embedding table exists for tests. In production it
	// is created on plugin activation, which never runs under PHPUnit, but
	// the delete_post hook fires during the WP PHPUnit bootstrap cleanup
	// (_delete_all_posts(), which runs right after wp-settings.php loads).
	// Without the table, every deleted post spams "no such table" database
	// errors on SQLite test installs.
	if ( function_exists( 'wp_mcp_ai_content_embedding_ensure_loaded' )
		&& wp_mcp_ai_content_embedding_ensure_loaded()
		&& class_exists( 'WP_MCP_AI_Content_Embedding_Store' ) ) {
		WP_MCP_AI_Content_Embedding_Store::install();
	}
}

tests_add_filter( 'wp_loaded', 'wp_mcp_ai_init_test_database_tables', 20 );

// Wrap WP PHPUnit bootstrap in output buffering to prevent output
// from the WP test framework (WP_PHPUnit_Util_Getopt echoes) from
// interfering with PHPUnit 11's test runner.
ob_start();
require $_tests_dir . '/includes/bootstrap.php';
ob_end_clean();

// Helpers that depend on classes provided by the WP test bootstrap (e.g.
// `WP_Ajax_UnitTestCase`) must be loaded after it.
require_once __DIR__ . '/helpers/class-wp-mcp-ai-ajax-testcase.php';
require_once __DIR__ . '/helpers/class-wp-mcp-ai-mock-http-client.php';
require_once __DIR__ . '/paper-store/trait-paper-store-test-helpers.php';
