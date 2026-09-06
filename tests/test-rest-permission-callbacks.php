<?php
/**
 * Tests that every REST route registered in includes/rest/ has an explicit,
 * non-trivial permission_callback.
 *
 * Background (R-T-12): the WordPress.org reviewer flagged a route in
 * addons/embedded (now excluded from the WP.org submission) that used
 * `__return_true` without a justifying comment. As a defence-in-depth gate
 * this test walks every register_rest_route() call across the 22 base REST
 * controllers and asserts that each handler either:
 *   (a) does NOT use __return_true as its permission_callback, or
 *   (b) appears in the ALLOWLIST below with a documented reason.
 *
 * When adding a new intentionally-public route, add it to ALLOWLIST with a
 * comment explaining why public access is safe.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test file; PHPUnit naming convention.

/**
 * Walker test for REST permission_callbacks.
 */
class Test_Rest_Permission_Callbacks extends WP_UnitTestCase {

	/**
	 * Routes whose __return_true permission_callback is intentional and documented.
	 *
	 * Key   = REST route pattern (may be a substring match).
	 * Value = human-readable justification for the reviewer.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWLIST = array(
		// Health check — designed for load balancers, Cloudways monitoring and
		// Kubernetes liveness probes. Returns only the aggregate status of
		// critical subsystems; no sensitive data is exposed.
		// File: includes/rest/class-wp-mcp-ai-rest-health.php.
		'/health' => 'Public liveness probe for load balancers and uptime monitors; returns aggregate health only.',

		// Public service status endpoints (monitoring dashboards / uptime
		// widgets). Private component details are gated inside the handlers:
		// the include_private flag only takes effect for users with
		// manage_options, otherwise only get_public_status() data is returned.
		// File: includes/rest/class-wp-mcp-ai-status-rest-controller.php.
		'/status'            => 'Public service status overview; private details gated behind include_private + manage_options in the handler.',
		'/status/components' => 'Public service component status; private details gated behind include_private + manage_options in the handler.',
		'/status/history'    => 'Public uptime history; no sensitive data is returned.',
	);

	/**
	 * Directory containing the REST controller files to audit.
	 *
	 * @var string
	 */
	private $rest_dir;

	/**
	 * Set up the test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->rest_dir = dirname( __DIR__ ) . '/includes/rest';
	}

	/**
	 * Scan every PHP file in includes/rest/ for register_rest_route() calls
	 * that assign `__return_true` as the permission_callback, then assert
	 * that each one appears in the allowlist.
	 *
	 * This is a static-analysis test: it works on the raw PHP source files
	 * without bootstrapping WordPress classes, so it runs fast even without
	 * a full WP environment.
	 */
	public function test_all_return_true_routes_are_allowlisted(): void {
		$php_files = glob( $this->rest_dir . '/*.php' );
		$this->assertNotEmpty( $php_files, 'No PHP files found in includes/rest/ — check the path.' );

		$unlisted_routes = array();

		foreach ( $php_files as $file ) {
			$source = file_get_contents( $file );
			if ( false === $source ) {
				continue;
			}

			// Skip files that have no __return_true at all.
			if ( false === strpos( $source, '__return_true' ) ) {
				continue;
			}

			// Extract every register_rest_route() block and check if any
			// permission_callback in that block is __return_true.
			//
			// Strategy: find every occurrence of __return_true in the file,
			// then look backwards for the enclosing register_rest_route() call
			// and extract the route pattern from its second argument.
			$offset = 0;
			while ( false !== ( $pos = strpos( $source, "'permission_callback' => '__return_true'", $offset ) ) ) {
				// Walk backwards to find the nearest register_rest_route( call.
				$before     = substr( $source, 0, $pos );
				$route_call = strrpos( $before, 'register_rest_route(' );
				if ( false === $route_call ) {
					$offset = $pos + 1;
					continue;
				}

				// Extract a small window after register_rest_route( to find
				// the route-pattern string (second argument).
				$window = substr( $source, $route_call, 400 );

				// Match the second string argument (the route pattern).
				// register_rest_route( 'namespace', 'route-pattern', ...
				if ( preg_match( "/register_rest_route\s*\(\s*['\"][^'\"]+['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $window, $m ) ) {
					$pattern        = $m[1];
					$is_allowlisted = false;
					foreach ( array_keys( self::ALLOWLIST ) as $allowed_pattern ) {
						if ( false !== strpos( $pattern, $allowed_pattern ) || false !== strpos( $allowed_pattern, $pattern ) ) {
								$is_allowlisted = true;
								break;
						}
					}
					if ( ! $is_allowlisted ) {
						$unlisted_routes[] = sprintf(
							'%s — route "%s" uses __return_true permission_callback without an ALLOWLIST entry.',
							basename( $file ),
							$pattern
						);
					}
				}

				$offset = $pos + 1;
			}
		}

		$this->assertEmpty(
			$unlisted_routes,
			"The following REST routes use '__return_true' as permission_callback without being in the allowlist.\n"
			. "Either fix the permission_callback or add the route to the ALLOWLIST constant with a justification:\n\n"
			. implode( "\n", $unlisted_routes )
		);
	}

	/**
	 * Verify that all allowlist entries still correspond to actual __return_true
	 * usages in the codebase (i.e., the allowlist doesn't contain stale entries).
	 */
	public function test_allowlist_entries_are_not_stale(): void {
		$php_files = glob( $this->rest_dir . '/*.php' );
		$this->assertNotEmpty( $php_files, 'No PHP files found in includes/rest/ — check the path.' );

		$all_source = '';
		foreach ( $php_files as $file ) {
			$source = file_get_contents( $file );
			if ( false !== $source ) {
				$all_source .= $source;
			}
		}

		$stale = array();
		foreach ( array_keys( self::ALLOWLIST ) as $allowed_pattern ) {
			// A stale entry either has no matching __return_true nearby or the
			// pattern no longer appears at all. We do a loose check: if the
			// pattern text doesn't appear in any source file, flag it.
			if ( false === strpos( $all_source, $allowed_pattern ) ) {
				$stale[] = $allowed_pattern;
			}
		}

		$this->assertEmpty(
			$stale,
			"The following ALLOWLIST entries no longer match any route in includes/rest/.\n"
			. "Remove them from the allowlist:\n\n"
			. implode( "\n", $stale )
		);
	}
}
