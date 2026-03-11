<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_JetEngine CCT CRUD operations.
 *
 * Validates the tool schema, capability flags, get_definition(), and the new
 * delete_item action dispatch.  Requires the pro addon path to be defined.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for WP_MCP_AI_Pro_Tool_JetEngine.
 */
class Test_JetEngine_Pro_Tool_CCT_CRUD extends WP_UnitTestCase {

	/**
	 * Load the tool class from the pro addon path.
	 *
	 * @return WP_MCP_AI_Pro_Tool_JetEngine
	 */
	private function get_tool() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
			$interface_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
			if ( file_exists( $interface_path ) ) {
				require_once $interface_path;
			}
		}

		if ( ! interface_exists( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) ) {
			$flags_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-capability-flags.php';
			if ( file_exists( $flags_path ) ) {
				require_once $flags_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_JetEngine' ) ) {
			$tool_path = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/class-wp-mcp-ai-pro-tool-jetengine.php';
			if ( ! file_exists( $tool_path ) ) {
				$this->markTestSkipped( 'WP_MCP_AI_Pro_Tool_JetEngine file not found' );
			}
			require_once $tool_path;
		}

		return new WP_MCP_AI_Pro_Tool_JetEngine();
	}

	// =========================================================================
	// Slug / name / description
	// =========================================================================

	/**
	 * Slug must be 'jetengine' for backwards compatibility.
	 */
	public function test_slug_is_jetengine() {
		$tool = $this->get_tool();
		$this->assertSame( 'jetengine', $tool->get_slug() );
	}

	// =========================================================================
	// Parameters schema
	// =========================================================================

	/**
	 * The 'delete_item' action must be present in the enum list.
	 */
	public function test_schema_includes_delete_item_action() {
		$tool   = $this->get_tool();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertContains( 'delete_item', $schema['properties']['action']['enum'] );
	}

	/**
	 * All six CRUD actions must be declared.
	 */
	public function test_schema_declares_all_crud_actions() {
		$tool     = $this->get_tool();
		$schema   = $tool->get_parameters_schema();
		$expected = array( 'list_types', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item' );

		foreach ( $expected as $action ) {
			$this->assertContains(
				$action,
				$schema['properties']['action']['enum'],
				"Action '{$action}' must be in the schema enum."
			);
		}
	}

	// =========================================================================
	// Capability flags
	// =========================================================================

	/**
	 * Capability flags must include 'write' (covers create/update/delete).
	 */
	public function test_capability_flags_include_write() {
		$tool = $this->get_tool();
		$this->assertContains( 'write', $tool->get_capability_flags() );
	}

	// =========================================================================
	// get_definition()
	// =========================================================================

	/**
	 * get_definition() must return an array with the required orchestration keys.
	 */
	public function test_get_definition_returns_expected_structure() {
		$tool       = $this->get_tool();
		$definition = $tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'toolkit', $definition );
		$this->assertArrayHasKey( 'pattern_compatibility', $definition );
		$this->assertArrayHasKey( 'risk_level', $definition );
	}

	/**
	 * The toolkit identifier must be 'jetengine_cct'.
	 */
	public function test_get_definition_toolkit_is_jetengine_cct() {
		$tool       = $this->get_tool();
		$definition = $tool->get_definition();
		$this->assertSame( 'jetengine_cct', $definition['toolkit'] );
	}

	// =========================================================================
	// delete_item — missing JetEngine
	// =========================================================================

	/**
	 * delete_item must return WP_Error when JetEngine is not active.
	 */
	public function test_delete_item_returns_error_without_jetengine() {
		if ( class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' ) ) {
			$this->markTestSkipped( 'JetEngine is present; skipping unavailability test.' );
		}

		$tool = $this->get_tool();

		$result = $tool->execute(
			array(
				'action'   => 'delete_item',
				'cct_slug' => 'vital_signs',
				'item_id'  => 1,
			),
			array( 'user_id' => 1 )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'jetengine_not_active', $result->get_error_code() );
	}

	// =========================================================================
	// delete_item — missing parameters
	// =========================================================================

	/**
	 * delete_item must return WP_Error when cct_slug or item_id is absent.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_delete_item_requires_cct_slug_and_item_id() {
		$tool = $this->get_tool();

		// Use reflection to call the protected delete_item method directly.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'delete_item' );
		$method->setAccessible( true );

		// Missing item_id.
		$result = $method->invokeArgs(
			$tool,
			array(
				array( 'cct_slug' => 'vital_signs' ),
				array( 'user_id' => 1 ),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );

		// Missing cct_slug.
		$result = $method->invokeArgs(
			$tool,
			array(
				array( 'item_id' => 1 ),
				array( 'user_id' => 1 ),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );
	}

	// =========================================================================
	// delete_item — permission check
	// =========================================================================

	/**
	 * delete_item must return WP_Error when the user lacks edit_posts capability.
	 * Tested via reflection to bypass the JetEngine availability check.
	 */
	public function test_delete_item_requires_edit_posts_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool = $this->get_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'delete_item' );
		$method->setAccessible( true );

		$result = $method->invokeArgs(
			$tool,
			array(
				array(
					'cct_slug' => 'vital_signs',
					'item_id'  => 1,
				),
				array( 'user_id' => $subscriber_id ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	// =========================================================================
	// Description — mentions CCT and create_post
	// =========================================================================

	/**
	 * The description must explicitly mention vital_signs and instruct the AI
	 * NOT to use create_post for CCT items, so the correct tool is always chosen.
	 */
	public function test_description_directs_ai_away_from_create_post() {
		$tool        = $this->get_tool();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'vital_signs', $description );

		// Description must contain explicit negative guidance (NOT / not) alongside create_post.
		$this->assertMatchesRegularExpression(
			'/NOT create_post|not.*create_post|create_post.*NOT/i',
			$description,
			'Description must explicitly tell the AI not to use create_post for CCT items.'
		);
	}
}
