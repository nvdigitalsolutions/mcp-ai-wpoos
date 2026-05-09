<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Vault_Access.
 *
 * Read-only access to encrypted vault items.  Tests cover missing action,
 * invalid action, and the list action which returns data even when no items
 * exist in the test database.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the vault_access pro tool.
 */
class Test_Tool_Pro_Vault_Access extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Vault_Access
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

		// Load vault CPT dependency.
		if ( ! class_exists( 'WP_MCP_AI_Vault_Encryption_Service' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/vault/class-wp-mcp-ai-vault-encryption-service.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Vault_Item_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/vault/class-wp-mcp-ai-vault-item-cpt.php';
		}
		WP_MCP_AI_Vault_Item_CPT::get_instance();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Vault_Access' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-vault-access.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_Vault_Access();
	}

	// -----------------------------------------------------------------------
	// get_slug / get_definition
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_vault_access() {
		$this->assertSame( 'vault_access', $this->tool->get_slug() );
	}

	/**
	 * Test that get_definition returns required keys.
	 */
	public function test_get_definition_returns_required_keys() {
		$def = $this->tool->get_definition();

		$this->assertArrayHasKey( 'name', $def );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'input_schema', $def );
		$this->assertSame( 'vault_access', $def['name'] );
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
	// execute – list action (happy path, empty vault)
	// -----------------------------------------------------------------------

	/**
	 * Test that the list action returns a success array even with an empty vault.
	 */
	public function test_list_action_returns_success_with_empty_vault() {
		$result = $this->tool->execute( array( 'action' => 'list' ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
	}

	// -----------------------------------------------------------------------
	// execute – search missing query
	// -----------------------------------------------------------------------

	/**
	 * Test that the search action without a query returns a failure array.
	 */
	public function test_search_missing_query_returns_failure() {
		$result = $this->tool->execute( array( 'action' => 'search' ), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – get missing item_id
	// -----------------------------------------------------------------------

	/**
	 * Test that the get action without item_id returns a failure array.
	 */
	public function test_get_missing_item_id_returns_failure() {
		$result = $this->tool->execute( array( 'action' => 'get' ), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – get non-existent item
	// -----------------------------------------------------------------------

	/**
	 * Test that the get action with a non-existent item_id returns a failure array.
	 */
	public function test_get_nonexistent_item_returns_failure() {
		$result = $this->tool->execute(
			array(
				'action'  => 'get',
				'item_id' => 999999,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}
}
