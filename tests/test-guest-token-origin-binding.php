<?php
/**
 * Tests for guest-token origin binding (audit F-AUTHZ-04 / R-S-09).
 *
 * @package WP_MCP_AI\Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Verifies that guest tokens issued by `WP_MCP_AI_Shortcode::generate_guest_token`
 * are bound to the issuance origin and that
 * `WP_MCP_AI_Shortcode::validate_guest_token` rejects requests whose `Origin`
 * (or `Referer`) does not match the bound host, while remaining backward
 * compatible with legacy tokens that pre-date this binding.
 */
class WP_MCP_AI_Guest_Token_Origin_Binding_Test extends WP_UnitTestCase {

	/**
	 * Assistant post ID used by all tests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Bootstrap the plugin and create a published assistant.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		wp_set_current_user( 0 );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Origin Binding Test Assistant',
			)
		);
	}

	/**
	 * Build a REST request with the requested headers populated.
	 *
	 * @param array $headers Header name => value map.
	 * @return WP_REST_Request
	 */
	protected function build_request( array $headers ) {
		$request = new WP_REST_Request( 'POST', '/wp-mcp-ai/v1/chat' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	/**
	 * Origin matching the bound host must validate.
	 */
	public function test_matching_origin_passes_validation() {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$token     = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token );

		$request = $this->build_request( array( 'origin' => 'https://' . $home_host ) );

		$this->assertSame(
			(int) $this->assistant_id,
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request )
		);
	}

	/**
	 * Origin pointing at a different host must be rejected.
	 */
	public function test_mismatched_origin_fails_validation() {
		$token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token );

		$request = $this->build_request( array( 'origin' => 'https://attacker.example' ) );

		$this->assertFalse(
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request )
		);
	}

	/**
	 * When `Origin` is missing, `Referer` is used as a fallback.
	 */
	public function test_referer_fallback_when_origin_header_absent() {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$token     = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token );

		$request_ok = $this->build_request( array( 'referer' => 'https://' . $home_host . '/some/page' ) );
		$this->assertSame(
			(int) $this->assistant_id,
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request_ok )
		);

		// Re-issue: referer pointing elsewhere is rejected.
		$token_bad = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token_bad );
		$request_bad = $this->build_request( array( 'referer' => 'https://attacker.example/page' ) );
		$this->assertFalse(
			WP_MCP_AI_Shortcode::validate_guest_token( $token_bad, $this->assistant_id, $request_bad )
		);
	}

	/**
	 * No request supplied (e.g. CLI / cron call sites) must skip the origin
	 * check and continue to validate the token on assistant scope alone.
	 */
	public function test_validation_without_request_skips_origin_check() {
		$token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token );

		$this->assertSame(
			(int) $this->assistant_id,
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id )
		);
	}

	/**
	 * Legacy records persisted before origin binding (no `origin` field)
	 * must continue to validate so active sessions are not invalidated by
	 * the upgrade.
	 */
	public function test_legacy_record_without_origin_field_still_validates() {
		// Manually craft a transient that mimics a token issued by the
		// pre-R-S-09 code path: no `origin` field.
		$token  = wp_generate_password( 32, false, false );
		$key    = 'wp_mcp_ai_guest_access_' . md5( $token );
		$record = array(
			'assistant_id' => $this->assistant_id,
			'created'      => time(),
		);
		set_transient( $key, $record, HOUR_IN_SECONDS );

		// Even a clearly foreign Origin must not invalidate a legacy token.
		$request = $this->build_request( array( 'origin' => 'https://attacker.example' ) );

		$this->assertSame(
			(int) $this->assistant_id,
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request )
		);
	}

	/**
	 * The `wp_mcp_ai_guest_token_allowed_origins` filter expands the bound
	 * origin to an explicit allowlist, e.g. for a chat embedded on a
	 * partner domain.
	 */
	public function test_allowlist_filter_extends_bound_origin() {
		$token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token );

		$filter = static function ( $origins ) {
			$origins[] = 'partner.example';
			return $origins;
		};
		add_filter( 'wp_mcp_ai_guest_token_allowed_origins', $filter );

		$request = $this->build_request( array( 'origin' => 'https://partner.example' ) );
		$this->assertSame(
			(int) $this->assistant_id,
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request )
		);

		// An origin still outside the (bound + allowlisted) set is rejected.
		$request_bad = $this->build_request( array( 'origin' => 'https://attacker.example' ) );
		$this->assertFalse(
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request_bad )
		);

		remove_filter( 'wp_mcp_ai_guest_token_allowed_origins', $filter );
	}

	/**
	 * The `wp_mcp_ai_guest_token_issuance_origin` filter lets the issuer
	 * bind to an arbitrary origin (or disable binding entirely by returning
	 * an empty string).
	 */
	public function test_issuance_filter_can_disable_binding() {
		$disable = static function () {
			return '';
		};
		add_filter( 'wp_mcp_ai_guest_token_issuance_origin', $disable );
		$token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		remove_filter( 'wp_mcp_ai_guest_token_issuance_origin', $disable );

		$this->assertNotEmpty( $token );

		// With binding disabled, even an attacker origin validates.
		$request = $this->build_request( array( 'origin' => 'https://attacker.example' ) );
		$this->assertSame(
			(int) $this->assistant_id,
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request )
		);
	}

	/**
	 * A request supplying neither `Origin` nor `Referer` against an
	 * origin-bound token must be rejected — there is no way to verify the
	 * request comes from the issuance origin.
	 */
	public function test_request_with_no_origin_or_referer_is_rejected() {
		$token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token );

		$request = $this->build_request( array() );

		$this->assertFalse(
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request )
		);
	}

	/**
	 * `Origin: null` (sandboxed iframes / file://) must be treated as
	 * "no origin" and rejected against an origin-bound token.
	 */
	public function test_null_origin_string_is_rejected() {
		$token = WP_MCP_AI_Shortcode::generate_guest_token( $this->assistant_id );
		$this->assertNotEmpty( $token );

		$request = $this->build_request( array( 'origin' => 'null' ) );

		$this->assertFalse(
			WP_MCP_AI_Shortcode::validate_guest_token( $token, $this->assistant_id, $request )
		);
	}
}
