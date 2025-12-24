<?php
/**
 * Tests for WP All Export and WP All Import tools.
 */
class WP_MCP_AI_All_Import_Export_Tool_Availability_Test extends WP_UnitTestCase {
	/**
	 * Ensure the List Export Templates tool reports missing dependencies.
	 */
	public function test_list_export_templates_requires_plugin() {
		if ( class_exists( 'PMXE_Plugin' ) || defined( 'PMXE_VERSION' ) ) {
			$this->markTestSkipped( 'WP All Export is already loaded.' );
		}

		$tool   = new WP_MCP_AI_Tool_List_All_Export_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_all_export_missing', $result->get_error_code() );
	}

	/**
	 * Ensure the Trigger Export tool reports missing dependencies.
	 */
	public function test_trigger_export_requires_plugin() {
		if ( class_exists( 'PMXE_Plugin' ) || defined( 'PMXE_VERSION' ) ) {
			$this->markTestSkipped( 'WP All Export is already loaded.' );
		}

		$tool   = new WP_MCP_AI_Tool_Trigger_All_Export();
		$result = $tool->execute( array( 'export_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_all_export_missing', $result->get_error_code() );
	}

	/**
	 * Ensure the List Import Templates tool reports missing dependencies.
	 */
	public function test_list_import_templates_requires_plugin() {
		if ( class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ) ) {
			$this->markTestSkipped( 'WP All Import is already loaded.' );
		}

		$tool   = new WP_MCP_AI_Tool_List_All_Import_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_all_import_missing', $result->get_error_code() );
	}

	/**
	 * Ensure the Trigger Import tool reports missing dependencies.
	 */
	public function test_trigger_import_requires_plugin() {
		if ( class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ) ) {
			$this->markTestSkipped( 'WP All Import is already loaded.' );
		}

		$tool   = new WP_MCP_AI_Tool_Trigger_All_Import();
		$result = $tool->execute( array( 'import_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_all_import_missing', $result->get_error_code() );
	}

	/**
	 * Ensure the Get Import Status tool reports missing dependencies.
	 */
	public function test_get_import_status_requires_plugin() {
		if ( class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ) ) {
			$this->markTestSkipped( 'WP All Import is already loaded.' );
		}

		$tool   = new WP_MCP_AI_Tool_Get_All_Import_Status();
		$result = $tool->execute( array( 'import_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_all_import_missing', $result->get_error_code() );
	}
}

/**
 * Permission tests for All Import/Export tools.
 */
class WP_MCP_AI_All_Import_Export_Tool_Permission_Test extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );

		// Define constants to simulate plugins being active.
		if ( ! defined( 'PMXE_VERSION' ) ) {
			define( 'PMXE_VERSION', '1.0.0' );
		}
		if ( ! defined( 'PMXI_VERSION' ) ) {
			define( 'PMXI_VERSION', '1.0.0' );
		}
	}

	/**
	 * Ensure unauthenticated users cannot list export templates.
	 */
	public function test_list_export_templates_requires_login() {
		$tool   = new WP_MCP_AI_Tool_List_All_Export_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure unauthenticated users cannot trigger exports.
	 */
	public function test_trigger_export_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Trigger_All_Export();
		$result = $tool->execute( array( 'export_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure unauthenticated users cannot list import templates.
	 */
	public function test_list_import_templates_requires_login() {
		$tool   = new WP_MCP_AI_Tool_List_All_Import_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure unauthenticated users cannot trigger imports.
	 */
	public function test_trigger_import_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Trigger_All_Import();
		$result = $tool->execute( array( 'import_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure unauthenticated users cannot get import status.
	 */
	public function test_get_import_status_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Get_All_Import_Status();
		$result = $tool->execute( array( 'import_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure non-admin users cannot list export templates.
	 */
	public function test_list_export_templates_requires_permissions() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_List_All_Export_Templates();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure non-admin users cannot trigger exports.
	 */
	public function test_trigger_export_requires_permissions() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Trigger_All_Export();
		$result = $tool->execute( array( 'export_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure tool slug uniqueness.
	 */
	public function test_tool_slugs_are_unique() {
		$tools = array(
			new WP_MCP_AI_Tool_List_All_Export_Templates(),
			new WP_MCP_AI_Tool_Trigger_All_Export(),
			new WP_MCP_AI_Tool_List_All_Import_Templates(),
			new WP_MCP_AI_Tool_Trigger_All_Import(),
			new WP_MCP_AI_Tool_Get_All_Import_Status(),
		);

		$slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools
		);

		$this->assertSame( count( $slugs ), count( array_unique( $slugs ) ), 'Tool slugs must be unique' );
	}

	/**
	 * Ensure tools implement required interfaces.
	 */
	public function test_tools_implement_interfaces() {
		$tools = array(
			new WP_MCP_AI_Tool_List_All_Export_Templates(),
			new WP_MCP_AI_Tool_Trigger_All_Export(),
			new WP_MCP_AI_Tool_List_All_Import_Templates(),
			new WP_MCP_AI_Tool_Trigger_All_Import(),
			new WP_MCP_AI_Tool_Get_All_Import_Status(),
		);

		foreach ( $tools as $tool ) {
			$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
			$this->assertInstanceOf( WP_MCP_AI_Tool_Capability_Flags_Interface::class, $tool );
		}
	}

	/**
	 * Ensure tools declare capability flags.
	 */
	public function test_tools_declare_capability_flags() {
		$tools = array(
			new WP_MCP_AI_Tool_List_All_Export_Templates(),
			new WP_MCP_AI_Tool_Trigger_All_Export(),
			new WP_MCP_AI_Tool_List_All_Import_Templates(),
			new WP_MCP_AI_Tool_Trigger_All_Import(),
			new WP_MCP_AI_Tool_Get_All_Import_Status(),
		);

		foreach ( $tools as $tool ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertNotEmpty( $flags );
			$this->assertContains( 'requires-plugin', $flags );
		}
	}
}
