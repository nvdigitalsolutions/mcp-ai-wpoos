<?php
/**
 * Test Vault Custom Post Type Registration
 *
 * Verifies that the vault item and folder custom post types
 * are properly registered and accessible.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Vault CPT registration
 */
class Test_Vault_CPT_Registration extends WP_UnitTestCase {

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Load vault CPT classes if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Vault_Item_CPT' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-item-cpt.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Vault_Folder_CPT' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-folder-cpt.php';
		}
	}

	/**
	 * Test that mcp_vault_item post type is registered
	 */
	public function test_vault_item_cpt_registered() {
		$post_type = get_post_type_object( 'mcp_vault_item' );
		$this->assertNotNull( $post_type, 'mcp_vault_item post type should be registered' );
		$this->assertEquals( 'mcp_vault_item', $post_type->name, 'Post type name should match' );
	}

	/**
	 * Test that mcp_vault_folder post type is registered
	 */
	public function test_vault_folder_cpt_registered() {
		$post_type = get_post_type_object( 'mcp_vault_folder' );
		$this->assertNotNull( $post_type, 'mcp_vault_folder post type should be registered' );
		$this->assertEquals( 'mcp_vault_folder', $post_type->name, 'Post type name should match' );
	}

	/**
	 * Test that vault item CPT has expected configuration
	 */
	public function test_vault_item_cpt_configuration() {
		$post_type = get_post_type_object( 'mcp_vault_item' );

		$this->assertFalse( $post_type->public, 'Vault item should not be public' );
		$this->assertFalse( $post_type->publicly_queryable, 'Vault item should not be publicly queryable' );
		$this->assertTrue( $post_type->show_in_rest, 'Vault item should be available in REST API' );
		$this->assertEquals( 'vault-items', $post_type->rest_base, 'REST base should be vault-items' );
	}

	/**
	 * Test that administrator role has vault capabilities
	 */
	public function test_administrator_has_vault_capabilities() {
		$admin_role = get_role( 'administrator' );

		$this->assertNotNull( $admin_role, 'Administrator role should exist' );
		$this->assertTrue( $admin_role->has_cap( 'edit_own_vault_items' ), 'Admin should have edit_own_vault_items capability' );
		$this->assertTrue( $admin_role->has_cap( 'read_own_vault_items' ), 'Admin should have read_own_vault_items capability' );
		$this->assertTrue( $admin_role->has_cap( 'delete_own_vault_items' ), 'Admin should have delete_own_vault_items capability' );
		$this->assertTrue( $admin_role->has_cap( 'edit_others_vault_items' ), 'Admin should have edit_others_vault_items capability' );
		$this->assertTrue( $admin_role->has_cap( 'publish_vault_items' ), 'Admin should have publish_vault_items capability' );
		$this->assertTrue( $admin_role->has_cap( 'read_private_vault_items' ), 'Admin should have read_private_vault_items capability' );
	}

	/**
	 * Test that vault item CPT supports title and author
	 */
	public function test_vault_item_cpt_supports() {
		$post_type = get_post_type_object( 'mcp_vault_item' );

		$this->assertTrue( post_type_supports( 'mcp_vault_item', 'title' ), 'Vault item should support title' );
		$this->assertTrue( post_type_supports( 'mcp_vault_item', 'author' ), 'Vault item should support author' );
	}

	/**
	 * Test that vault folder CPT has expected configuration
	 */
	public function test_vault_folder_cpt_configuration() {
		$post_type = get_post_type_object( 'mcp_vault_folder' );

		$this->assertFalse( $post_type->public, 'Vault folder should not be public' );
		$this->assertFalse( $post_type->publicly_queryable, 'Vault folder should not be publicly queryable' );
		$this->assertTrue( $post_type->show_in_rest, 'Vault folder should be available in REST API' );
	}
}
