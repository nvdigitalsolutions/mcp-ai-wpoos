<?php
/**
 * Security Tests for NV oOS: CORS Allowed Origin Setting
 *
 * Verifies that:
 * - Empty setting returns wildcard '*' (backward-compatible default).
 * - Configured URL is returned by the filter.
 * - Invalid URL falls back to '*'.
 * - WP_DEBUG localhost bypass works for local development.
 * - WP_DEBUG localhost bypass does NOT override a configured production origin.
 *
 * @package WP_MCP_AI
 * @group security
 * @group cors
 */

/**
 * CORS allowlist setting test suite.
 */
class WP_MCP_AI_CORS_Allowlist_Test extends WP_UnitTestCase {

	/**
	 * Security manager instance under test.
	 *
	 * @var WP_MCP_AI_Security_Manager
	 */
	private $manager;

	/**
	 * Original settings snapshot for restoration.
	 *
	 * @var array
	 */
	private $original_settings = array();

	/**
	 * Original HTTP_ORIGIN server variable for restoration.
	 *
	 * @var string|null
	 */
	private $original_http_origin;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_settings    = get_option( 'wp_mcp_ai_settings', array() );
		$this->original_http_origin = isset( $_SERVER['HTTP_ORIGIN'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) )
			: null;

		// Remove any pre-existing filter callbacks to avoid interference.
		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );

		// Fresh manager instance registers the filter.
		$this->manager = new WP_MCP_AI_Security_Manager();
	}

	/**
	 * Tear down: restore settings and filters.
	 */
	public function tearDown(): void {
		update_option( 'wp_mcp_ai_settings', $this->original_settings );
		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );

		// Restore HTTP_ORIGIN to its original state.
		if ( null === $this->original_http_origin ) {
			unset( $_SERVER['HTTP_ORIGIN'] );
		} else {
			$_SERVER['HTTP_ORIGIN'] = $this->original_http_origin;
		}

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Default behaviour (empty setting)
	// -----------------------------------------------------------------------

	/**
	 * When cors_allowed_origin is empty the filter returns '*'.
	 */
	public function test_empty_setting_returns_wildcard() {
		update_option( 'wp_mcp_ai_settings', array( 'cors_allowed_origin' => '' ) );

		// Re-instantiate to pick up fresh settings.
		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		new WP_MCP_AI_Security_Manager();

		$result = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$this->assertSame( '*', $result );
	}

	/**
	 * When cors_allowed_origin is absent from settings the filter returns '*'.
	 */
	public function test_missing_setting_key_returns_wildcard() {
		$settings = $this->original_settings;
		unset( $settings['cors_allowed_origin'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		new WP_MCP_AI_Security_Manager();

		$result = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$this->assertSame( '*', $result );
	}

	// -----------------------------------------------------------------------
	// Configured origin
	// -----------------------------------------------------------------------

	/**
	 * When cors_allowed_origin is set to a valid URL that URL is returned.
	 */
	public function test_configured_url_is_returned() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'cors_allowed_origin' => 'https://app.example.com' )
		);

		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		new WP_MCP_AI_Security_Manager();

		$result = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$this->assertSame( 'https://app.example.com', $result );
	}

	/**
	 * Trailing slash in stored value is stripped before returning.
	 */
	public function test_trailing_slash_stripped() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'cors_allowed_origin' => 'https://app.example.com/' )
		);

		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		new WP_MCP_AI_Security_Manager();

		$result = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$this->assertSame( 'https://app.example.com', $result );
	}

	/**
	 * Configured URL overrides the wildcard default even when WP_DEBUG is on
	 * and a localhost origin is present in the request.
	 */
	public function test_configured_url_overrides_localhost_in_debug_mode() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'cors_allowed_origin' => 'https://app.example.com' )
		);
		$_SERVER['HTTP_ORIGIN'] = 'http://localhost:3000';

		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		new WP_MCP_AI_Security_Manager();

		$result = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$this->assertSame(
			'https://app.example.com',
			$result,
			'Configured URL must win over localhost debug bypass.'
		);
	}

	// -----------------------------------------------------------------------
	// WP_DEBUG localhost bypass (only when no origin configured)
	// -----------------------------------------------------------------------

	/**
	 * When empty setting and WP_DEBUG=true, a localhost origin is echoed back.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_localhost_allowed_when_debug_on_and_no_origin_configured() {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}
		if ( ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG must be true to run this test.' );
		}

		update_option( 'wp_mcp_ai_settings', array( 'cors_allowed_origin' => '' ) );
		$_SERVER['HTTP_ORIGIN'] = 'http://localhost:3000';

		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		new WP_MCP_AI_Security_Manager();

		$result = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$this->assertSame( 'http://localhost:3000', $result );
	}

	/**
	 * Non-localhost origins are NOT special-cased even when WP_DEBUG is on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_non_localhost_not_bypassed_in_debug_mode() {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}
		if ( ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG must be true to run this test.' );
		}

		update_option( 'wp_mcp_ai_settings', array( 'cors_allowed_origin' => '' ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';

		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		new WP_MCP_AI_Security_Manager();

		$result = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$this->assertSame(
			'*',
			$result,
			'Non-localhost origin must fall through to wildcard default.'
		);
	}

	// -----------------------------------------------------------------------
	// Section-level sanitization (testing the setting is saved correctly)
	// -----------------------------------------------------------------------

	/**
	 * WP_MCP_AI_Section_Security sanitize() strips an invalid URL to empty string.
	 */
	public function test_section_sanitize_invalid_url_becomes_empty() {
		if ( ! class_exists( 'WP_MCP_AI_Section_Security' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Section_Security not loaded.' );
		}

		$section = new WP_MCP_AI_Section_Security();
		$input   = array( 'cors_allowed_origin' => 'not-a-url!!!' );
		$result  = $section->sanitize( $input );

		$this->assertArrayHasKey( 'cors_allowed_origin', $result );
		$this->assertSame( '', $result['cors_allowed_origin'] );
	}

	/**
	 * WP_MCP_AI_Section_Security sanitize() preserves a valid HTTPS URL.
	 */
	public function test_section_sanitize_valid_url_preserved() {
		if ( ! class_exists( 'WP_MCP_AI_Section_Security' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Section_Security not loaded.' );
		}

		$section = new WP_MCP_AI_Section_Security();
		$input   = array( 'cors_allowed_origin' => 'https://app.example.com' );
		$result  = $section->sanitize( $input );

		$this->assertSame( 'https://app.example.com', $result['cors_allowed_origin'] );
	}
}
