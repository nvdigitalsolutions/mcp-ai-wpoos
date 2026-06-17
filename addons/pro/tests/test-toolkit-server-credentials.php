<?php
/**
 * Test_Toolkit_Server_Credentials
 *
 * Phase 3d — per-toolkit-server bearer tokens.
 *
 * Covers:
 *   1.  Token service class exists.
 *   2.  generate() returns a raw token in the expected format.
 *   3.  validate() returns true for a freshly generated token.
 *   4.  validate() returns false for a wrong secret.
 *   5.  validate() returns false for an unknown server slug.
 *   6.  validate() returns false for an unknown prefix.
 *   7.  revoke() removes the token and subsequent validate() returns false.
 *   8.  list_tokens() returns metadata without secrets.
 *   9.  generate() enforces MAX_TOKENS limit.
 *  10.  clear_all() removes all tokens for a server.
 *  11.  REST GET /mcp/{slug}/token returns token list.
 *  12.  REST POST /mcp/{slug}/token generates and returns a token.
 *  13.  REST DELETE /mcp/{slug}/token/{prefix} revokes a token.
 *  14.  permission_jsonrpc() accepts a valid server token.
 *  15.  permission_jsonrpc() rejects an invalid server token.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

/** Summary.
 *
 * @group toolkit-mcp-servers
 */
class Test_Toolkit_Server_Credentials extends WP_UnitTestCase {

	/**
	 * A server slug we can safely use in every test.
	 */
	const TEST_SLUG = 'crm';

	/**
	 * Tear down — clear all tokens and reset registry singleton.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Pro_Toolkit_Server_Token::clear_all( self::TEST_SLUG );
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// 1. Service class exists.
	// -----------------------------------------------------------------------

	/** Test token class exists.
	 */
	public function test_token_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Pro_Toolkit_Server_Token' ) );
	}

	// -----------------------------------------------------------------------
	// 2. generate() format.
	// -----------------------------------------------------------------------

	/** Test generate returns expected keys.
	 */
	public function test_generate_returns_expected_keys() {
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG, 'test-label' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'token', $result );
		$this->assertArrayHasKey( 'prefix', $result );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'created_at', $result );
	}

	/** Test generated token starts with prefix.
	 */
	public function test_generated_token_starts_with_prefix() {
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		$this->assertStringStartsWith( WP_MCP_AI_Pro_Toolkit_Server_Token::TOKEN_PREFIX, $result['token'] );
	}

	// -----------------------------------------------------------------------
	// 3. validate() — valid token.
	// -----------------------------------------------------------------------

	/** Test validate accepts freshly generated token.
	 */
	public function test_validate_accepts_freshly_generated_token() {
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		$valid  = WP_MCP_AI_Pro_Toolkit_Server_Token::validate( self::TEST_SLUG, $result['token'] );
		$this->assertTrue( $valid );
	}

	// -----------------------------------------------------------------------
	// 4. validate() — wrong secret.
	// -----------------------------------------------------------------------

	/** Test validate rejects tampered secret.
	 */
	public function test_validate_rejects_tampered_secret() {
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );

		// Replace secret portion with garbage.
		$token_parts = explode( '.', $result['token'], 2 );
		$bad_token   = $token_parts[0] . '.badbadbadbadbad';

		$valid = WP_MCP_AI_Pro_Toolkit_Server_Token::validate( self::TEST_SLUG, $bad_token );
		$this->assertFalse( $valid );
	}

	// -----------------------------------------------------------------------
	// 5. validate() — unknown slug.
	// -----------------------------------------------------------------------

	/** Test validate rejects wrong slug.
	 */
	public function test_validate_rejects_wrong_slug() {
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		$valid  = WP_MCP_AI_Pro_Toolkit_Server_Token::validate( 'nonexistent', $result['token'] );
		$this->assertFalse( $valid );
	}

	// -----------------------------------------------------------------------
	// 6. validate() — unknown prefix.
	// -----------------------------------------------------------------------

	/** Test validate rejects unknown prefix.
	 */
	public function test_validate_rejects_unknown_prefix() {
		WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		$fake = WP_MCP_AI_Pro_Toolkit_Server_Token::TOKEN_PREFIX . 'deadbeef.fakesecretfakesecretfake00000000';
		$this->assertFalse( WP_MCP_AI_Pro_Toolkit_Server_Token::validate( self::TEST_SLUG, $fake ) );
	}

	// -----------------------------------------------------------------------
	// 7. revoke()
	// -----------------------------------------------------------------------

	/** Test revoke removes token.
	 */
	public function test_revoke_removes_token() {
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		$this->assertTrue( WP_MCP_AI_Pro_Toolkit_Server_Token::validate( self::TEST_SLUG, $result['token'] ) );

		WP_MCP_AI_Pro_Toolkit_Server_Token::revoke( self::TEST_SLUG, $result['prefix'] );

		$this->assertFalse( WP_MCP_AI_Pro_Toolkit_Server_Token::validate( self::TEST_SLUG, $result['token'] ) );
	}

	// -----------------------------------------------------------------------
	// 8. list_tokens()
	// -----------------------------------------------------------------------

	/** Test list tokens omits secrets.
	 */
	public function test_list_tokens_omits_secrets() {
		WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG, 'my-label' );
		$tokens = WP_MCP_AI_Pro_Toolkit_Server_Token::list_tokens( self::TEST_SLUG );

		$this->assertCount( 1, $tokens );
		$this->assertArrayHasKey( 'prefix', $tokens[0] );
		$this->assertArrayHasKey( 'label', $tokens[0] );
		$this->assertArrayHasKey( 'created_at', $tokens[0] );
		$this->assertArrayHasKey( 'last_used_at', $tokens[0] );
		$this->assertArrayNotHasKey( 'hash', $tokens[0] );
		$this->assertSame( 'my-label', $tokens[0]['label'] );
	}

	// -----------------------------------------------------------------------
	// 9. MAX_TOKENS limit.
	// -----------------------------------------------------------------------

	/** Test generate enforces max tokens limit.
	 */
	public function test_generate_enforces_max_tokens_limit() {
		for ( $i = 0; $i < WP_MCP_AI_Pro_Toolkit_Server_Token::MAX_TOKENS; $i++ ) {
			$this->assertIsArray( WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG ) );
		}
		$extra = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		$this->assertWPError( $extra );
		$this->assertSame( 'token_limit', $extra->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// 10. clear_all()
	// -----------------------------------------------------------------------

	/** Test clear all removes every token.
	 */
	public function test_clear_all_removes_every_token() {
		WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		WP_MCP_AI_Pro_Toolkit_Server_Token::clear_all( self::TEST_SLUG );

		$this->assertCount( 0, WP_MCP_AI_Pro_Toolkit_Server_Token::list_tokens( self::TEST_SLUG ) );
	}

	// -----------------------------------------------------------------------
	// 11–13. REST endpoints.
	// -----------------------------------------------------------------------

	/**
	 * Bootstrap the WP REST API server and create an admin user.
	 *
	 * @return WP_User Admin user.
	 */
	private function make_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return get_user_by( 'id', $user_id );
	}

	/** Create rest server.
	 */
	private function make_rest_server() {
		/**
		 * REST server instance.
		 *
		 * @var WP_REST_Server
		 */
		$server = rest_get_server();
		do_action( 'rest_api_init' );
		return $server;
	}

	/** Test rest token list returns empty array when no tokens.
	 */
	public function test_rest_token_list_returns_empty_array_when_no_tokens() {
		$this->make_admin();
		$server   = $this->make_rest_server();
		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/mcp/crm/token' );
		$response = $server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'tokens', $data );
		$this->assertCount( 0, $data['tokens'] );
	}

	/** Test rest token generate creates token.
	 */
	public function test_rest_token_generate_creates_token() {
		$this->make_admin();
		$server  = $this->make_rest_server();
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/crm/token' );
		$request->set_param( 'label', 'rest-test' );
		$response = $server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'token', $data );
		$this->assertStringStartsWith( WP_MCP_AI_Pro_Toolkit_Server_Token::TOKEN_PREFIX, $data['token'] );
	}

	/** Test rest token revoke removes token.
	 */
	public function test_rest_token_revoke_removes_token() {
		$this->make_admin();
		$server = $this->make_rest_server();

		// Generate.
		$gen_req = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/crm/token' );
		$gen_req->set_param( 'label', 'to-revoke' );
		$gen_resp = $server->dispatch( $gen_req );
		$prefix   = $gen_resp->get_data()['prefix'];

		// Revoke.
		$del_req  = new WP_REST_Request( 'DELETE', '/mcp-ai-pro/v1/mcp/crm/token/' . $prefix );
		$del_resp = $server->dispatch( $del_req );

		$this->assertSame( 200, $del_resp->get_status() );
		$this->assertTrue( $del_resp->get_data()['revoked'] );

		// Token is gone.
		$this->assertCount( 0, WP_MCP_AI_Pro_Toolkit_Server_Token::list_tokens( self::TEST_SLUG ) );
	}

	// -----------------------------------------------------------------------
	// 14–15. permission_jsonrpc()
	// -----------------------------------------------------------------------

	/** Test permission jsonrpc accepts valid server token.
	 */
	public function test_permission_jsonrpc_accepts_valid_server_token() {
		wp_set_current_user( 0 ); // No user session.
		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( self::TEST_SLUG );
		$server = $this->make_rest_server();

		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/crm' );
		$request->add_header( 'Authorization', 'Bearer ' . $result['token'] );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'ping',
				)
			)
		);
		$response = $server->dispatch( $request );

		// Should not be a 401/403.
		$this->assertLessThan( 400, $response->get_status() );
	}

	/** Test permission jsonrpc rejects invalid server token.
	 */
	public function test_permission_jsonrpc_rejects_invalid_server_token() {
		wp_set_current_user( 0 ); // No user session.
		$server = $this->make_rest_server();

		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/crm' );
		$request->add_header( 'Authorization', 'Bearer ' . WP_MCP_AI_Pro_Toolkit_Server_Token::TOKEN_PREFIX . 'ffffffff.badsecretbadsecretbadsecretbaad' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'ping',
				)
			)
		);
		$response = $server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}
}
