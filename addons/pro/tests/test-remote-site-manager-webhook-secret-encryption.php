<?php
/**
 * Tests for at-rest encryption of webhook secret fields in the Remote Site
 * Manager connection store.
 *
 * Covers the verify_token (WhatsApp/Messenger verification handshake) and
 * verification_token (Google Chat shared-secret OIDC bypass) fields:
 * encrypted at rest, decrypted via get_connection(), preserved (without
 * double-encryption) when the masked admin form submits an empty value, and
 * listed as credential fields for the export provider.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.x
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Webhook secret field encryption tests.
 *
 * @since 1.9.x
 */
class Test_Remote_Site_Manager_Webhook_Secret_Encryption extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Save a connection fixture.
	 *
	 * @param array $overrides Field overrides.
	 * @return string|WP_Error Connection ID or error.
	 */
	protected function save_connection( array $overrides ) {
		$data = array_merge(
			array(
				'name'            => 'Test Connection',
				'url'             => 'https://example.com',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
			),
			$overrides
		);

		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
	}

	/**
	 * The webhook secret fields are registered as credential fields.
	 */
	public function test_webhook_secret_fields_are_credential_fields() {
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( 'verify_token' ) );
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( 'verification_token' ) );
	}

	/**
	 * The WhatsApp/Messenger verify_token is encrypted at rest and decrypted
	 * transparently by get_connection().
	 */
	public function test_verify_token_encrypted_at_rest_and_decrypted_on_read() {
		$id = $this->save_connection(
			array(
				'verify_token' => 'wa-verify-secret',
			)
		);

		$this->assertNotWPError( $id );

		$all = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$this->assertNotSame( 'wa-verify-secret', $all[ $id ]['verify_token'] );
		$this->assertStringNotContainsString( 'wa-verify-secret', (string) $all[ $id ]['verify_token'] );
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_value_encrypted( $all[ $id ]['verify_token'] ) );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'wa-verify-secret', $connection['verify_token'] );
	}

	/**
	 * The Google Chat verification_token is encrypted at rest and decrypted
	 * transparently by get_connection().
	 */
	public function test_verification_token_encrypted_at_rest_and_decrypted_on_read() {
		$id = $this->save_connection(
			array(
				'connection_type'      => 'google_chat',
				'verification_token'   => 'gc-shared-secret',
				'disable_oidc_verification' => true,
			)
		);

		$this->assertNotWPError( $id );

		$all = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$this->assertNotSame( 'gc-shared-secret', $all[ $id ]['verification_token'] );
		$this->assertStringNotContainsString( 'gc-shared-secret', (string) $all[ $id ]['verification_token'] );
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_value_encrypted( $all[ $id ]['verification_token'] ) );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'gc-shared-secret', $connection['verification_token'] );
	}

	/**
	 * A masked admin-form save (empty secret fields) preserves the stored
	 * values without double-encrypting them.
	 */
	public function test_masked_save_preserves_webhook_secrets_without_double_encryption() {
		$id = $this->save_connection(
			array(
				'verify_token' => 'wa-verify-secret',
			)
		);
		$this->assertNotWPError( $id );

		$before = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$raw    = $before[ $id ]['verify_token'];

		// Re-save with the same ID and a blank (masked) verify token.
		$again = $this->save_connection(
			array(
				'id'           => $id,
				'verify_token' => '',
			)
		);
		$this->assertNotWPError( $again );

		$after = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		// No double encryption: the stored ciphertext is unchanged.
		$this->assertSame( $raw, $after[ $id ]['verify_token'] );

		// The plaintext survives for consumers.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'wa-verify-secret', $connection['verify_token'] );
	}

	/**
	 * A masked Google Chat save preserves the shared-secret token too.
	 */
	public function test_masked_save_preserves_verification_token() {
		$id = $this->save_connection(
			array(
				'connection_type'        => 'google_chat',
				'verification_token'     => 'gc-shared-secret',
				'disable_oidc_verification' => true,
			)
		);
		$this->assertNotWPError( $id );

		$again = $this->save_connection(
			array(
				'id'                  => $id,
				'connection_type'     => 'google_chat',
				'verification_token'  => '',
			)
		);
		$this->assertNotWPError( $again );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'gc-shared-secret', $connection['verification_token'] );
	}
}
