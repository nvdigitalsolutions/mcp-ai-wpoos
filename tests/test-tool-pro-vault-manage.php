<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Vault_Manage.
 *
 * Vault management is among the highest-risk capabilities: it controls
 * creation, update, and deletion of encrypted credentials.  All paths
 * short of a real vault-item write (which requires WP_MCP_AI_Vault_Encryption_Service
 * to encrypt the payload) are exercised here.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the vault_manage pro tool.
 */
class Test_Tool_Pro_Vault_Manage extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Vault_Manage
	 */
	private $tool;

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Load vault CPT dependency (registers mcp_vault_item post type).
		if ( ! class_exists( 'WP_MCP_AI_Vault_Encryption_Service' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/vault/class-wp-mcp-ai-vault-encryption-service.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Vault_Item_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/vault/class-wp-mcp-ai-vault-item-cpt.php';
		}
		// Instantiating registers the post type.
		WP_MCP_AI_Vault_Item_CPT::get_instance();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Vault_Manage' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-vault-manage.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_Vault_Manage();
	}

	// -----------------------------------------------------------------------
	// get_slug / get_definition
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_vault_manage() {
		$this->assertSame( 'vault_manage', $this->tool->get_slug() );
	}

	/**
	 * Test that get_definition returns required keys.
	 */
	public function test_get_definition_returns_required_keys() {
		$def = $this->tool->get_definition();

		$this->assertArrayHasKey( 'name', $def );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'input_schema', $def );
		$this->assertSame( 'vault_manage', $def['name'] );
	}

	// -----------------------------------------------------------------------
	// execute – missing action
	// -----------------------------------------------------------------------

	/**
	 * Test that omitting 'action' returns a failure array.
	 */
	public function test_missing_action_returns_failure() {
		$result = $this->tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'action', $result['error'] );
	}

	// -----------------------------------------------------------------------
	// execute – invalid action
	// -----------------------------------------------------------------------

	/**
	 * Test that an unknown action value returns a failure array.
	 */
	public function test_invalid_action_returns_failure() {
		$result = $this->tool->execute( array( 'action' => 'frobnicate' ), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – create missing required fields
	// -----------------------------------------------------------------------

	/**
	 * Test that creating without 'name' and 'item_type' returns a failure array.
	 */
	public function test_create_missing_name_and_item_type_returns_failure() {
		$result = $this->tool->execute( array( 'action' => 'create' ), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'name', $result['error'] );
	}

	// -----------------------------------------------------------------------
	// execute – delete missing item_id
	// -----------------------------------------------------------------------

	/**
	 * Test that deleting without 'item_id' returns a failure array.
	 */
	public function test_delete_missing_item_id_returns_failure() {
		$result = $this->tool->execute( array( 'action' => 'delete' ), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – delete non-existent item_id
	// -----------------------------------------------------------------------

	/**
	 * Test that deleting a non-existent item returns a failure array.
	 */
	public function test_delete_nonexistent_item_returns_failure() {
		$result = $this->tool->execute(
			array(
				'action'  => 'delete',
				'item_id' => 999999,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – update missing item_id
	// -----------------------------------------------------------------------

	/**
	 * Test that updating without 'item_id' returns a failure array.
	 */
	public function test_update_missing_item_id_returns_failure() {
		$result = $this->tool->execute( array( 'action' => 'update' ), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}
}
