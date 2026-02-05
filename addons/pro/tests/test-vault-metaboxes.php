<?php
/**
 * Test Vault Item Metaboxes
 *
 * Verifies that the vault item metaboxes are properly registered
 * and that save functionality works with encryption.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Vault Item Metaboxes
 */
class Test_Vault_Item_Metaboxes extends WP_UnitTestCase {

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Load vault CPT class if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Vault_Item_CPT' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-item-cpt.php';
		}

		// Load encryption service.
		if ( ! class_exists( 'WP_MCP_AI_Vault_Encryption_Service' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-encryption-service.php';
		}

		// Create a test user with vault capabilities.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->test_user_id );
	}

	/**
	 * Test that metaboxes are registered
	 */
	public function test_metaboxes_registered() {
		global $wp_meta_boxes;

		// Create a test vault item post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Trigger the add_meta_boxes action.
		do_action( 'add_meta_boxes', 'mcp_vault_item', get_post( $post_id ) );

		// Check that metaboxes are registered.
		$this->assertArrayHasKey( 'mcp_vault_item', $wp_meta_boxes, 'Metaboxes should be registered for mcp_vault_item' );
		$this->assertArrayHasKey( 'normal', $wp_meta_boxes['mcp_vault_item'], 'Normal metaboxes should be registered' );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['mcp_vault_item'], 'Side metaboxes should be registered' );

		// Check for specific metaboxes.
		$this->assertArrayHasKey( 'mcp_vault_login_details', $wp_meta_boxes['mcp_vault_item']['normal']['high'], 'Login details metabox should be registered' );
		$this->assertArrayHasKey( 'mcp_vault_notes', $wp_meta_boxes['mcp_vault_item']['normal']['default'], 'Notes metabox should be registered' );
		$this->assertArrayHasKey( 'mcp_vault_item_settings', $wp_meta_boxes['mcp_vault_item']['side']['default'], 'Item settings metabox should be registered' );
	}

	/**
	 * Test that vault item type can be saved
	 */
	public function test_save_vault_item_type() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data.
		$_POST['mcp_vault_item_meta_nonce'] = wp_create_nonce( 'mcp_vault_item_meta' );
		$_POST['vault_item_type']           = 'note';

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify item type was saved.
		$item_type = get_post_meta( $post_id, '_vault_item_type', true );
		$this->assertEquals( 'note', $item_type, 'Item type should be saved as note' );
	}

	/**
	 * Test that folder ID can be saved
	 */
	public function test_save_folder_id() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data.
		$_POST['mcp_vault_item_meta_nonce'] = wp_create_nonce( 'mcp_vault_item_meta' );
		$_POST['vault_folder_id']           = 42;

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify folder ID was saved.
		$folder_id = get_post_meta( $post_id, '_vault_folder_id', true );
		$this->assertEquals( 42, $folder_id, 'Folder ID should be saved as 42' );
	}

	/**
	 * Test that favorite status can be saved
	 */
	public function test_save_favorite_status() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data.
		$_POST['mcp_vault_item_meta_nonce'] = wp_create_nonce( 'mcp_vault_item_meta' );
		$_POST['vault_favorite']            = '1';

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify favorite status was saved.
		$is_favorite = get_post_meta( $post_id, '_vault_favorite', true );
		$this->assertEquals( '1', $is_favorite, 'Favorite status should be saved as 1' );
	}

	/**
	 * Test that username is encrypted when saved
	 */
	public function test_save_username_encrypted() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data.
		$_POST['mcp_vault_item_meta_nonce'] = wp_create_nonce( 'mcp_vault_item_meta' );
		$_POST['vault_username']            = 'testuser@example.com';

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify username was encrypted and saved.
		$username_encrypted = get_post_meta( $post_id, '_vault_username_encrypted', true );
		$this->assertNotEmpty( $username_encrypted, 'Encrypted username should be saved' );
		$this->assertStringContainsString( 'iv', $username_encrypted, 'Encrypted username should contain IV' );
		$this->assertStringContainsString( 'ciphertext', $username_encrypted, 'Encrypted username should contain ciphertext' );
		$this->assertStringContainsString( 'auth_tag', $username_encrypted, 'Encrypted username should contain auth tag' );
	}

	/**
	 * Test that password is encrypted when saved
	 */
	public function test_save_password_encrypted() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data.
		$_POST['mcp_vault_item_meta_nonce'] = wp_create_nonce( 'mcp_vault_item_meta' );
		$_POST['vault_password']            = 'SecureP@ssw0rd!';

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify password was encrypted and saved.
		$password_encrypted = get_post_meta( $post_id, '_vault_password_encrypted', true );
		$this->assertNotEmpty( $password_encrypted, 'Encrypted password should be saved' );
		$this->assertStringContainsString( 'iv', $password_encrypted, 'Encrypted password should contain IV' );
		$this->assertStringContainsString( 'ciphertext', $password_encrypted, 'Encrypted password should contain ciphertext' );
		$this->assertStringContainsString( 'auth_tag', $password_encrypted, 'Encrypted password should contain auth tag' );

		// Verify the plaintext password is not stored.
		$this->assertStringNotContainsString( 'SecureP@ssw0rd!', $password_encrypted, 'Plaintext password should not be in encrypted data' );
	}

	/**
	 * Test that URI is saved correctly
	 */
	public function test_save_uri() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data.
		$_POST['mcp_vault_item_meta_nonce'] = wp_create_nonce( 'mcp_vault_item_meta' );
		$_POST['vault_uri']                 = 'https://example.com';

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify URI was saved.
		$uris = get_post_meta( $post_id, '_vault_uris', true );
		$this->assertIsArray( $uris, 'URIs should be an array' );
		$this->assertCount( 1, $uris, 'URIs array should have one entry' );
		$this->assertEquals( 'https://example.com', $uris[0], 'URI should be saved correctly' );
	}

	/**
	 * Test that notes are encrypted when saved
	 */
	public function test_save_notes_encrypted() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data.
		$_POST['mcp_vault_item_meta_nonce'] = wp_create_nonce( 'mcp_vault_item_meta' );
		$_POST['vault_notes']               = 'This is a secure note with sensitive information.';

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify notes were encrypted and saved.
		$notes_encrypted = get_post_meta( $post_id, '_vault_notes_encrypted', true );
		$this->assertNotEmpty( $notes_encrypted, 'Encrypted notes should be saved' );
		$this->assertStringContainsString( 'iv', $notes_encrypted, 'Encrypted notes should contain IV' );
		$this->assertStringContainsString( 'ciphertext', $notes_encrypted, 'Encrypted notes should contain ciphertext' );
		$this->assertStringContainsString( 'auth_tag', $notes_encrypted, 'Encrypted notes should contain auth tag' );
	}

	/**
	 * Test that save fails without nonce
	 */
	public function test_save_fails_without_nonce() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => 'Test Vault Item',
				'post_author' => $this->test_user_id,
			)
		);

		// Simulate POST data without nonce.
		$_POST['vault_item_type'] = 'note';

		// Trigger save.
		$vault_cpt = WP_MCP_AI_Vault_Item_CPT::get_instance();
		$vault_cpt->save_vault_item_meta( $post_id, get_post( $post_id ) );

		// Verify item type was NOT saved.
		$item_type = get_post_meta( $post_id, '_vault_item_type', true );
		$this->assertEmpty( $item_type, 'Item type should not be saved without nonce' );
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		// Clear $_POST superglobal.
		$_POST = array();

		parent::tearDown();
	}
}
