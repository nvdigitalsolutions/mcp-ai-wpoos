<?php
/**
 * Tests for WP_MCP_AI_Credentials class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for credentials tests.
 *
 * @group credentials
 * @group security
 */
class WP_MCP_AI_Credentials_Tests extends WP_UnitTestCase {

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test assistant post.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Create a test user.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Clear any existing credentials.
		delete_post_meta( $this->assistant_id, WP_MCP_AI_Credentials::META_KEY );
		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up.
		wp_delete_post( $this->assistant_id, true );
		if ( $this->user_id ) {
			wp_delete_user( $this->user_id );
		}
		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );

		parent::tearDown();
	}

	/**
	 * Test token format validation with valid token.
	 */
	public function test_is_token_format_with_valid_token() {
		$token = 'cred_abc123.secret456';
		$this->assertTrue( WP_MCP_AI_Credentials::is_token_format( $token ) );
	}

	/**
	 * Test token format validation with invalid token.
	 */
	public function test_is_token_format_with_invalid_token() {
		$this->assertFalse( WP_MCP_AI_Credentials::is_token_format( 'invalid' ) );
		$this->assertFalse( WP_MCP_AI_Credentials::is_token_format( 'no.prefix.secret' ) );
		$this->assertFalse( WP_MCP_AI_Credentials::is_token_format( '' ) );
		$this->assertFalse( WP_MCP_AI_Credentials::is_token_format( 123 ) );
	}

	/**
	 * Test parsing a valid token.
	 */
	public function test_parse_token_with_valid_token() {
		$token  = 'cred_abc123.secret456';
		$parsed = WP_MCP_AI_Credentials::parse_token( $token );

		$this->assertIsArray( $parsed );
		$this->assertCount( 2, $parsed );
		$this->assertEquals( 'cred_abc123', $parsed[0] );
		$this->assertEquals( 'secret456', $parsed[1] );
	}

	/**
	 * Test parsing an invalid token.
	 */
	public function test_parse_token_with_invalid_token() {
		$this->assertNull( WP_MCP_AI_Credentials::parse_token( 'invalid' ) );
		$this->assertNull( WP_MCP_AI_Credentials::parse_token( '' ) );
		$this->assertNull( WP_MCP_AI_Credentials::parse_token( 'no_prefix.secret' ) );
		$this->assertNull( WP_MCP_AI_Credentials::parse_token( 'cred_abc.' ) );
		$this->assertNull( WP_MCP_AI_Credentials::parse_token( '.secret' ) );
	}

	/**
	 * Test retrieving credentials for an assistant with no credentials.
	 */
	public function test_get_credentials_empty() {
		$credentials = WP_MCP_AI_Credentials::get_credentials( $this->assistant_id );
		$this->assertIsArray( $credentials );
		$this->assertEmpty( $credentials );
	}

	/**
	 * Test issuing a new credential.
	 */
	public function test_issue_credential() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'token', $result );
		$this->assertArrayHasKey( 'credential', $result );

		// Verify token format.
		$this->assertTrue( WP_MCP_AI_Credentials::is_token_format( $result['token'] ) );

		// Verify credential structure.
		$credential = $result['credential'];
		$this->assertArrayHasKey( 'id', $credential );
		$this->assertArrayHasKey( 'hash', $credential );
		$this->assertArrayHasKey( 'created_at', $credential );
		$this->assertArrayHasKey( 'created_by', $credential );
		$this->assertEquals( $this->user_id, $credential['created_by'] );
		$this->assertEmpty( $credential['revoked_at'] );
	}

	/**
	 * Test issuing credential for invalid assistant.
	 */
	public function test_issue_credential_for_invalid_assistant() {
		$result = WP_MCP_AI_Credentials::issue_credential( 999999, $this->user_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_assistant', $result->get_error_code() );
	}

	/**
	 * Test validating a valid credential token.
	 */
	public function test_validate_token_success() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$token  = $result['token'];

		$validation = WP_MCP_AI_Credentials::validate_token( $token, $this->assistant_id );

		$this->assertIsArray( $validation );
		$this->assertEquals( $this->assistant_id, $validation['assistant_id'] );
		$this->assertArrayHasKey( 'credential_id', $validation );
		$this->assertArrayHasKey( 'created_at', $validation );
		$this->assertArrayHasKey( 'created_by', $validation );
	}

	/**
	 * Test validating an invalid token.
	 */
	public function test_validate_token_invalid() {
		$validation = WP_MCP_AI_Credentials::validate_token( 'invalid_token' );

		$this->assertWPError( $validation );
		$this->assertEquals( 'wp_mcp_ai_invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test validating a token with wrong secret.
	 */
	public function test_validate_token_wrong_secret() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$parsed = WP_MCP_AI_Credentials::parse_token( $result['token'] );

		// Create a token with correct ID but wrong secret.
		$wrong_token = $parsed[0] . '.wrong_secret';
		$validation  = WP_MCP_AI_Credentials::validate_token( $wrong_token, $this->assistant_id );

		$this->assertWPError( $validation );
		$this->assertEquals( 'wp_mcp_ai_invalid_token', $validation->get_error_code() );
	}

	/**
	 * Test revoking a credential.
	 */
	public function test_revoke_credential() {
		$result        = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$credential_id = $result['credential']['id'];

		$revoked = WP_MCP_AI_Credentials::revoke_credential( $this->assistant_id, $credential_id, $this->user_id );

		$this->assertIsArray( $revoked );
		$this->assertNotEmpty( $revoked['revoked_at'] );
		$this->assertEquals( $this->user_id, $revoked['revoked_by'] );
	}

	/**
	 * Test validating a revoked token.
	 */
	public function test_validate_revoked_token() {
		$result        = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$token         = $result['token'];
		$credential_id = $result['credential']['id'];

		WP_MCP_AI_Credentials::revoke_credential( $this->assistant_id, $credential_id, $this->user_id );

		$validation = WP_MCP_AI_Credentials::validate_token( $token, $this->assistant_id );

		$this->assertWPError( $validation );
		$this->assertEquals( 'wp_mcp_ai_revoked_token', $validation->get_error_code() );
	}

	/**
	 * Test attempting to revoke an already revoked credential.
	 */
	public function test_revoke_already_revoked_credential() {
		$result        = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$credential_id = $result['credential']['id'];

		WP_MCP_AI_Credentials::revoke_credential( $this->assistant_id, $credential_id, $this->user_id );
		$second_revoke = WP_MCP_AI_Credentials::revoke_credential( $this->assistant_id, $credential_id, $this->user_id );

		$this->assertWPError( $second_revoke );
		$this->assertEquals( 'wp_mcp_ai_credential_already_revoked', $second_revoke->get_error_code() );
	}

	/**
	 * Test deleting a credential.
	 */
	public function test_delete_credential() {
		$result        = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$credential_id = $result['credential']['id'];

		$deleted = WP_MCP_AI_Credentials::delete_credential( $this->assistant_id, $credential_id, $this->user_id );

		$this->assertIsArray( $deleted );
		$this->assertEquals( $credential_id, $deleted['id'] );

		// Verify it's removed from the stored credentials.
		$credentials = WP_MCP_AI_Credentials::get_credentials( $this->assistant_id );
		$this->assertEmpty( $credentials );
	}

	/**
	 * Test deleting a non-existent credential.
	 */
	public function test_delete_nonexistent_credential() {
		$result = WP_MCP_AI_Credentials::delete_credential( $this->assistant_id, 'cred_nonexistent', $this->user_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_unknown_credential', $result->get_error_code() );
	}

	/**
	 * Test issuing multiple credentials for the same assistant.
	 */
	public function test_multiple_credentials() {
		$result1 = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$result2 = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		$credentials = WP_MCP_AI_Credentials::get_credentials( $this->assistant_id );

		$this->assertCount( 2, $credentials );
		$this->assertNotEquals( $result1['credential']['id'], $result2['credential']['id'] );

		// Both should be valid.
		$this->assertIsArray( WP_MCP_AI_Credentials::validate_token( $result1['token'], $this->assistant_id ) );
		$this->assertIsArray( WP_MCP_AI_Credentials::validate_token( $result2['token'], $this->assistant_id ) );
	}

	/**
	 * Test purging all credentials for an assistant.
	 */
	public function test_purge_assistant_credentials() {
		// Issue multiple credentials.
		WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		// Verify they exist.
		$credentials = WP_MCP_AI_Credentials::get_credentials( $this->assistant_id );
		$this->assertCount( 2, $credentials );

		// Purge credentials.
		WP_MCP_AI_Credentials::purge_assistant_credentials( $this->assistant_id );

		// Index should be updated.
		$index = get_option( WP_MCP_AI_Credentials::INDEX_OPTION, array() );
		foreach ( $credentials as $credential ) {
			$this->assertArrayNotHasKey( $credential['id'], $index );
		}
	}

	/**
	 * Test credential validation using index lookup.
	 */
	public function test_validate_token_with_index_lookup() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$token  = $result['token'];

		// Validate without providing assistant hint - should use index.
		$validation = WP_MCP_AI_Credentials::validate_token( $token );

		$this->assertIsArray( $validation );
		$this->assertEquals( $this->assistant_id, $validation['assistant_id'] );
	}

	/**
	 * Test that credential IDs are unique.
	 */
	public function test_credential_ids_are_unique() {
		$result1 = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$result2 = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$result3 = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		$id1 = $result1['credential']['id'];
		$id2 = $result2['credential']['id'];
		$id3 = $result3['credential']['id'];

		$this->assertNotEquals( $id1, $id2 );
		$this->assertNotEquals( $id2, $id3 );
		$this->assertNotEquals( $id1, $id3 );

		// All IDs should start with 'cred_'.
		$this->assertStringStartsWith( 'cred_', $id1 );
		$this->assertStringStartsWith( 'cred_', $id2 );
		$this->assertStringStartsWith( 'cred_', $id3 );
	}
}
