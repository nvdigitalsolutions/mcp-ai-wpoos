<?php
/**
 * Tests for WP_MCP_AI_Credentials — token expiry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for credential token expiry.
 *
 * @group security
 * @group credentials
 * @group token-expiry
 */
class WP_MCP_AI_Credentials_Expiry_Tests extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * @var int
	 */
	protected $user_id;

	public function setUp(): void {
		parent::setUp();

		$this->assistant_id = $this->factory->post->create( array(
			'post_type'   => 'mcp_ai_assistant',
			'post_title'  => 'Expiry Test Assistant',
			'post_status' => 'publish',
		) );

		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		delete_post_meta( $this->assistant_id, WP_MCP_AI_Credentials::META_KEY );
		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );
	}

	public function tearDown(): void {
		wp_delete_post( $this->assistant_id, true );
		if ( $this->user_id ) {
			wp_delete_user( $this->user_id );
		}
		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );
		parent::tearDown();
	}

	/**
	 * Test that newly issued credentials include an expires_at field.
	 */
	public function test_issued_credential_has_expires_at() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'credential', $result );
		$this->assertArrayHasKey( 'expires_at', $result['credential'] );
		$this->assertNotEmpty( $result['credential']['expires_at'], 'expires_at should be set.' );
	}

	/**
	 * Test that expires_at is in the future.
	 */
	public function test_expires_at_is_in_future() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		$expires_at = strtotime( $result['credential']['expires_at'] );
		$this->assertNotFalse( $expires_at, 'expires_at should be parseable.' );
		$this->assertGreaterThan( time(), $expires_at, 'expires_at should be in the future.' );
	}

	/**
	 * Test that validate_token succeeds for a non-expired credential.
	 */
	public function test_validate_token_non_expired() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$token  = $result['token'];

		$validated = WP_MCP_AI_Credentials::validate_token( $token, $this->assistant_id );
		$this->assertIsArray( $validated, 'Non-expired token should validate.' );
		$this->assertArrayHasKey( 'assistant_id', $validated );
	}

	/**
	 * Test that validate_token fails for an expired credential.
	 */
	public function test_validate_token_expired() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		// Manually set expires_at to the past by updating post meta directly.
		$credentials   = get_post_meta( $this->assistant_id, WP_MCP_AI_Credentials::META_KEY, true );
		$credentials[0]['expires_at'] = '2020-01-01 00:00:00';
		update_post_meta( $this->assistant_id, WP_MCP_AI_Credentials::META_KEY, $credentials );

		$token     = $result['token'];
		$validated = WP_MCP_AI_Credentials::validate_token( $token, $this->assistant_id );

		$this->assertWPError( $validated, 'Expired token should return error.' );
		$this->assertSame( 'wp_mcp_ai_expired_token', $validated->get_error_code() );
	}

	/**
	 * Test that pre-existing credentials without expires_at still validate.
	 * (Backward compatibility with credentials issued before 1.2.0.)
	 */
	public function test_validate_token_without_expires_at() {
		$result = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );

		// Remove expires_at from the stored credential.
		$credentials = get_post_meta( $this->assistant_id, WP_MCP_AI_Credentials::META_KEY, true );
		unset( $credentials[0]['expires_at'] );
		update_post_meta( $this->assistant_id, WP_MCP_AI_Credentials::META_KEY, $credentials );

		$token     = $result['token'];
		$validated = WP_MCP_AI_Credentials::validate_token( $token, $this->assistant_id );

		$this->assertIsArray( $validated, 'Credential without expires_at should still validate (backward compat).' );
	}

	/**
	 * Test that revoked credentials are rejected regardless of expiry.
	 */
	public function test_validate_token_revoked() {
		$result       = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->user_id );
		$credential_id = $result['credential']['id'];

		WP_MCP_AI_Credentials::revoke_credential( $this->assistant_id, $credential_id, $this->user_id );

		$token     = $result['token'];
		$validated = WP_MCP_AI_Credentials::validate_token( $token, $this->assistant_id );

		$this->assertWPError( $validated, 'Revoked token should fail.' );
		$this->assertSame( 'wp_mcp_ai_revoked_token', $validated->get_error_code() );
	}
}
