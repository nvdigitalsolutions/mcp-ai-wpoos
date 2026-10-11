<?php
/**
 * Tests for WP_MCP_AI_CORS_Guard.
 *
 * Covers the same-origin CORS enforcement that overrides WordPress core's
 * unconditional Origin reflection on REST responses, the origin allowlist
 * resolution, and the settings plumbing for cors_allowed_origins.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test CORS guard enforcement.
 *
 * @group security
 * @group rest
 */
class Test_CORS_Guard extends WP_UnitTestCase {

	/**
	 * Baseline settings written before every test.
	 *
	 * @var array
	 */
	private $base_settings = array(
		'cors_allow_origin'       => 'site',
		'cors_allowed_origins'    => '',
		'enable_security_headers' => true,
	);

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'wp_mcp_ai_settings', $this->base_settings );
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_ORIGIN'] );
		remove_all_filters( 'wp_mcp_ai_cors_allowed_origins' );
		remove_all_filters( 'wp_mcp_ai_cors_allow_origin' );
		delete_option( 'wp_mcp_ai_settings' );
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		parent::tearDown();
	}

	// ------------------------------------------------------------------ //
	// Settings plumbing                                                    //
	// ------------------------------------------------------------------ //

	/**
	 * The cors_allowed_origins setting has an empty-string default in the base defaults.
	 */
	public function test_cors_allowed_origins_default_is_empty() {
		$defaults = WP_MCP_AI_Admin_Settings_Base::get_default_settings();
		$this->assertArrayHasKey( 'cors_allowed_origins', $defaults );
		$this->assertSame( '', $defaults['cors_allowed_origins'] );
	}

	/**
	 * The security section registers the cors_allowed_origins field.
	 */
	public function test_security_section_registers_cors_allowed_origins_field() {
		$section = new WP_MCP_AI_Section_Security();
		$fields  = $section->get_fields();
		$this->assertArrayHasKey( 'cors_allowed_origins', $fields );
		$this->assertSame( 'textarea', $fields['cors_allowed_origins']['type'] );
	}

	/**
	 * Invalid origin entries are rejected by the section validator.
	 */
	public function test_section_validation_rejects_malformed_origins() {
		$section = new WP_MCP_AI_Section_Security();
		$input   = array(
			'cors_allowed_origins' => "https://chat.nvoos.cloud\nnot-an-origin\nhttps://app.example.com/path\n",
		);

		$result = $section->validate( $input );

		$this->assertWPError( $result, 'Validation should reject malformed origin entries.' );
	}

	/**
	 * Valid origin entries pass the section validator.
	 */
	public function test_section_validation_accepts_valid_origins() {
		$section = new WP_MCP_AI_Section_Security();
		$input   = array(
			'cors_allowed_origins' => "https://chat.nvoos.cloud\nhttp://localhost:5173\n",
		);

		$result = $section->validate( $input );

		$this->assertNotWPError( $result, 'Validation should accept valid origin entries.' );
	}

	// ------------------------------------------------------------------ //
	// Origin resolution                                                    //
	// ------------------------------------------------------------------ //

	/**
	 * The allowlist always contains the site's own origin.
	 */
	public function test_allowlist_always_contains_site_origin() {
		$allowed = WP_MCP_AI_CORS_Guard::get_allowed_origins();
		$this->assertContains( untrailingslashit( get_site_url() ), $allowed );
	}

	/**
	 * Cors_allowed_origins setting lines extend the allowlist.
	 */
	public function test_allowlist_reads_setting_lines() {
		$settings                         = $this->base_settings;
		$settings['cors_allowed_origins'] = "https://chat.nvoos.cloud\n  https://app.example.com  \n";
		update_option( 'wp_mcp_ai_settings', $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$allowed = WP_MCP_AI_CORS_Guard::get_allowed_origins();
		$this->assertContains( 'https://chat.nvoos.cloud', $allowed );
		$this->assertContains( 'https://app.example.com', $allowed );
	}

	/**
	 * The wp_mcp_ai_cors_allowed_origins filter extends the allowlist.
	 */
	public function test_allowlist_reads_filter() {
		add_filter(
			'wp_mcp_ai_cors_allowed_origins',
			static function () {
				return array( 'https://chat.nvoos.cloud/' );
			}
		);

		$allowed = WP_MCP_AI_CORS_Guard::get_allowed_origins();
		$this->assertContains( 'https://chat.nvoos.cloud', $allowed );
	}

	/**
	 * Origins are deduplicated and trailing slashes are stripped.
	 */
	public function test_allowlist_deduplicates_and_strips_slashes() {
		$settings                         = $this->base_settings;
		$settings['cors_allowed_origins'] = "https://chat.nvoos.cloud/\nhttps://chat.nvoos.cloud\n";
		update_option( 'wp_mcp_ai_settings', $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$allowed = WP_MCP_AI_CORS_Guard::get_allowed_origins();
		$this->assertSame( count( $allowed ), count( array_unique( $allowed ) ), 'Allowlist must not contain duplicates.' );
	}

	/**
	 * Star mode resolves to '*'.
	 */
	public function test_resolve_star_mode_returns_star() {
		$settings                      = $this->base_settings;
		$settings['cors_allow_origin'] = 'star';
		update_option( 'wp_mcp_ai_settings', $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$_SERVER['HTTP_ORIGIN'] = 'https://anything.example.com';
		$this->assertSame( '*', WP_MCP_AI_CORS_Guard::resolve_allow_origin() );
	}

	/**
	 * Site mode with no Origin header resolves to the site's own origin.
	 */
	public function test_resolve_site_mode_without_origin_returns_site_url() {
		$this->assertSame( untrailingslashit( get_site_url() ), WP_MCP_AI_CORS_Guard::resolve_allow_origin() );
	}

	/**
	 * The site's own origin is echoed back in site mode.
	 */
	public function test_resolve_site_mode_echoes_site_origin() {
		$_SERVER['HTTP_ORIGIN'] = untrailingslashit( get_site_url() );
		$this->assertSame( untrailingslashit( get_site_url() ), WP_MCP_AI_CORS_Guard::resolve_allow_origin() );
	}

	/**
	 * An allowlisted extra origin is echoed back in site mode.
	 */
	public function test_resolve_site_mode_echoes_allowlisted_origin() {
		$settings                         = $this->base_settings;
		$settings['cors_allowed_origins'] = "https://chat.nvoos.cloud\n";
		update_option( 'wp_mcp_ai_settings', $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$_SERVER['HTTP_ORIGIN'] = 'https://chat.nvoos.cloud';
		$this->assertSame( 'https://chat.nvoos.cloud', WP_MCP_AI_CORS_Guard::resolve_allow_origin() );
	}

	/**
	 * A disallowed origin resolves to the site's own origin in site mode.
	 */
	public function test_resolve_site_mode_blocks_unknown_origin() {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
		$this->assertSame( untrailingslashit( get_site_url() ), WP_MCP_AI_CORS_Guard::resolve_allow_origin() );
	}

	/**
	 * The literal 'null' origin (sandboxed iframes, file://) is blocked.
	 */
	public function test_resolve_site_mode_blocks_null_origin() {
		$_SERVER['HTTP_ORIGIN'] = 'null';
		$this->assertSame( untrailingslashit( get_site_url() ), WP_MCP_AI_CORS_Guard::resolve_allow_origin() );
	}

	/**
	 * The legacy wp_mcp_ai_cors_allow_origin filter remains the final override.
	 */
	public function test_legacy_filter_remains_final_override() {
		add_filter(
			'wp_mcp_ai_cors_allow_origin',
			static function () {
				return 'https://legacy.example.com';
			}
		);

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
		$this->assertSame( 'https://legacy.example.com', WP_MCP_AI_CORS_Guard::resolve_allow_origin() );
	}

	// ------------------------------------------------------------------ //
	// Enforcement hook                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * The guard is registered on rest_pre_serve_request after core's handler.
	 */
	public function test_enforcement_hook_registered_at_priority_20() {
		$this->assertSame(
			20,
			has_filter( 'rest_pre_serve_request', array( 'WP_MCP_AI_CORS_Guard', 'enforce_on_pre_serve' ) ),
			'CORS guard must run after core\'s rest_send_cors_headers (priority 10).'
		);
	}

	/**
	 * Enforce_on_pre_serve passes through the $served flag unchanged.
	 */
	public function test_enforce_passes_through_served_flag() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$result  = new WP_REST_Response( array( 'ok' => true ) );

		$this->assertTrue( WP_MCP_AI_CORS_Guard::enforce_on_pre_serve( true, $result, $request, rest_get_server() ) );
		$this->assertFalse( WP_MCP_AI_CORS_Guard::enforce_on_pre_serve( false, $result, $request, rest_get_server() ) );
	}

	/**
	 * Star mode leaves core's reflection untouched (no header() calls needed).
	 */
	public function test_enforce_skips_star_mode() {
		$settings                      = $this->base_settings;
		$settings['cors_allow_origin'] = 'star';
		update_option( 'wp_mcp_ai_settings', $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$_SERVER['HTTP_ORIGIN'] = 'https://anything.example.com';
		$request                = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$result                 = new WP_REST_Response( array( 'ok' => true ) );

		$this->assertFalse( WP_MCP_AI_CORS_Guard::enforce_on_pre_serve( false, $result, $request, rest_get_server() ) );
	}

	/**
	 * Non-plugin routes are not touched by the guard.
	 */
	public function test_enforce_skips_non_plugin_routes() {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
		$request                = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$result                 = new WP_REST_Response( array( 'ok' => true ) );

		$this->assertFalse( WP_MCP_AI_CORS_Guard::enforce_on_pre_serve( false, $result, $request, rest_get_server() ) );
	}

	/**
	 * Plugin routes are enforced via the wp_mcp_ai_cors_guard_route_prefixes filter.
	 */
	public function test_enforce_honors_route_prefix_filter() {
		add_filter(
			'wp_mcp_ai_cors_guard_route_prefixes',
			static function () {
				return array( '/wp/v2' );
			}
		);

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
		$request                = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$result                 = new WP_REST_Response( array( 'ok' => true ) );

		// The guard should process the route and still return $served unchanged.
		$this->assertFalse( WP_MCP_AI_CORS_Guard::enforce_on_pre_serve( false, $result, $request, rest_get_server() ) );
	}
}
