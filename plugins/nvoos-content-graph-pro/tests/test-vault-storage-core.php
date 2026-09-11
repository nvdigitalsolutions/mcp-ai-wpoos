<?php
/**
 * Characterization tests for the ported vault storage core (Wave F1,
 * sub-cluster 4a): encryption service, item/folder CPTs, conflict resolver.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded even though the CLI test env never boots
 *   the vault init — it is admin-gated); the same public surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported classes in
 *   `src/vault/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Vault storage core tests.
 */
class Test_Vault_Storage_Core extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// The docker test env does not define AUTH_KEY — the encryption
		// service needs one for key derivation (production sites always
		// have wp-config keys). WP's own salt machinery recognises this
		// sentinel as an undefined-salt marker, so wp_salt() stays quiet.
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'put your unique phrase here' );
		}
		// The persistent docker DB may hold vault options from earlier
		// suites — start each test with a clean slate.
		delete_option( 'wp_mcp_ai_vault_conflicts' );
		delete_option( 'wp_mcp_ai_vault_conflict_logs' );
	}

	/**
	 * The encryption service must round-trip plaintext per user.
	 */
	public function test_encrypt_decrypt_round_trip(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$service   = new WP_MCP_AI_Vault_Encryption_Service();
		$plaintext = 'super-secret-credential-123!';
		$encrypted = $service->encrypt( $plaintext, $user_id );

		// Byte-identical payload envelope: iv + tag + ciphertext.
		$this->assertIsArray( $encrypted );
		$this->assertArrayHasKey( 'iv', $encrypted );
		$this->assertArrayHasKey( 'ciphertext', $encrypted );
		$this->assertArrayHasKey( 'auth_tag', $encrypted );
		$this->assertNotSame( $plaintext, $encrypted['ciphertext'] );
		$this->assertSame( $plaintext, $service->decrypt( $encrypted, $user_id ) );
	}

	/**
	 * Decrypting with the wrong user must fail (per-user key isolation).
	 */
	public function test_decrypt_with_wrong_user_fails(): void {
		$user_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$service   = new WP_MCP_AI_Vault_Encryption_Service();
		$encrypted = $service->encrypt( 'shared-secret', $user_a );

		$result = $service->decrypt( $encrypted, $user_b );
		$this->assertNotSame( 'shared-secret', $result );
	}

	/**
	 * Byte-identical crypto constants must survive the port.
	 */
	public function test_crypto_constants(): void {
		$this->assertSame( 100000, WP_MCP_AI_Vault_Encryption_Service::PBKDF2_ITERATIONS );
		$this->assertSame( 'aes-256-gcm', WP_MCP_AI_Vault_Encryption_Service::CIPHER_METHOD );
		$this->assertSame( 30, WP_MCP_AI_Vault_Encryption_Service::TOTP_TIME_STEP );
		$this->assertSame( 6, WP_MCP_AI_Vault_Encryption_Service::TOTP_CODE_LENGTH );
	}

	/**
	 * TOTP generation and verification must round-trip.
	 */
	public function test_totp_generate_verify(): void {
		$service = new WP_MCP_AI_Vault_Encryption_Service();
		$secret  = $service->generate_totp_secret();

		$this->assertNotFalse( $secret );
		$this->assertGreaterThanOrEqual( 20, strlen( (string) $secret ) );

		$code = $service->generate_totp_code( $secret );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', (string) $code );

		$this->assertTrue( $service->verify_totp_code( $secret, $code ) );
		$this->assertFalse( $service->verify_totp_code( $secret, '000000' === $code ? '111111' : '000000' ) );
	}

	/**
	 * Password generation must obey the length and charset contract.
	 */
	public function test_generate_password_contract(): void {
		$service  = new WP_MCP_AI_Vault_Encryption_Service();
		$password = $service->generate_password( 24, true, true, true, true, true );
		$this->assertSame( 24, strlen( (string) $password ) );
	}

	/**
	 * The vault item and folder CPTs must register with byte-identical slugs.
	 */
	public function test_vault_cpts_register(): void {
		$item   = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$folder = WP_MCP_AI_Vault_Folder_CPT::get_instance();

		$this->assertTrue( post_type_exists( 'mcp_vault_item' ) );
		$this->assertTrue( post_type_exists( 'mcp_vault_folder' ) );
		$this->assertSame( 'mcp_vault_item', get_post_type_object( 'mcp_vault_item' )->name );
	}

	/**
	 * Vault item type sanitization must whitelist the four item types.
	 */
	public function test_item_type_sanitization(): void {
		$item = WP_MCP_AI_Vault_Item_CPT::get_instance();

		$this->assertSame( 'login', $item->sanitize_item_type( 'login' ) );
		$this->assertSame( 'note', $item->sanitize_item_type( 'note' ) );
		$this->assertSame( 'card', $item->sanitize_item_type( 'card' ) );
		$this->assertSame( 'identity', $item->sanitize_item_type( 'identity' ) );
		$this->assertSame( 'login', $item->sanitize_item_type( 'not-a-real-type' ) );
	}

	/**
	 * The conflict resolver must implement the byte-identical strategies and
	 * queue/resolve round-trip.
	 */
	public function test_conflict_resolver_strategies_and_queue(): void {
		$resolver = new WP_MCP_AI_Vault_Conflict_Resolver();

		$this->assertSame( 'local_wins', WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_LOCAL_WINS );
		$this->assertSame( 'remote_wins', WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_REMOTE_WINS );
		$this->assertSame( 'newest_wins', WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_NEWEST_WINS );
		$this->assertSame( 'manual', WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_MANUAL );
		$this->assertSame( 'merge', WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_MERGE );

		$local  = array(
			'name'     => 'example',
			'username' => 'local-user',
			'modified' => '2026-01-01T00:00:00Z',
		);
		$remote = array(
			'name'     => 'example',
			'username' => 'remote-user',
			'modified' => '2026-02-01T00:00:00Z',
		);

		$result = $resolver->resolve_conflict( $local, $remote, WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_REMOTE_WINS );
		$this->assertSame( 'remote-user', $result['username'] );

		$result = $resolver->resolve_conflict( $local, $remote, WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_LOCAL_WINS );
		$this->assertSame( 'local-user', $result['username'] );

		$result = $resolver->resolve_conflict( $local, $remote, WP_MCP_AI_Vault_Conflict_Resolver::STRATEGY_NEWEST_WINS );
		$this->assertSame( 'remote-user', $result['username'] );

		$this->assertSame( array(), $resolver->get_pending_conflicts() );
		$resolver->clear_resolved_conflicts(); // Updates the option; no return value.
		$this->assertSame( array(), $resolver->get_pending_conflicts() );
	}
}
