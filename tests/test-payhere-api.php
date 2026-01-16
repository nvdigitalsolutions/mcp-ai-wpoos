<?php
/**
 * Tests for PayHere API integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Class WP_MCP_AI_PayHere_API_Test
 *
 * Tests that PayHere API integration is properly configured.
 */
class WP_MCP_AI_PayHere_API_Test extends WP_UnitTestCase {

	/**
	 * Test that PayHere settings are in default settings.
	 */
	public function test_payhere_settings_in_defaults() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'payhere_app_id', $defaults, 'payhere_app_id should be in default settings' );
		$this->assertSame( '', $defaults['payhere_app_id'], 'payhere_app_id should default to empty string' );

		$this->assertArrayHasKey( 'payhere_app_secret', $defaults, 'payhere_app_secret should be in default settings' );
		$this->assertSame( '', $defaults['payhere_app_secret'], 'payhere_app_secret should default to empty string' );

		$this->assertArrayHasKey( 'payhere_sandbox_mode', $defaults, 'payhere_sandbox_mode should be in default settings' );
		$this->assertFalse( $defaults['payhere_sandbox_mode'], 'payhere_sandbox_mode should default to false' );
	}

	/**
	 * Test that PayHere client can retrieve credentials from settings.
	 */
	public function test_payhere_client_retrieves_credentials() {
		$test_app_id     = 'app-test-123';
		$test_app_secret = 'secret-test-456';

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array_merge(
				WP_MCP_AI_Admin_Settings::get_default_settings(),
				array(
					'payhere_app_id'     => $test_app_id,
					'payhere_app_secret' => $test_app_secret,
					'payhere_sandbox_mode' => true,
				)
			)
		);

		$client = new WP_MCP_AI_PayHere_Client();

		$this->assertSame( $test_app_id, $client->get_app_id(), 'PayHere client should retrieve App ID from settings' );
		$this->assertSame( $test_app_secret, $client->get_app_secret(), 'PayHere client should retrieve App Secret from settings' );
		$this->assertTrue( $client->is_sandbox_mode(), 'PayHere client should detect sandbox mode' );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that PayHere tool is registered.
	 */
	public function test_payhere_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'payhere_get_payment' );

		$this->assertNotNull( $tool, 'PayHere tool should be registered in the tool registry' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_PayHere_Get_Payment', $tool, 'Tool should be instance of PayHere tool class' );
	}

	/**
	 * Test that PayHere tool has correct metadata.
	 */
	public function test_payhere_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_PayHere_Get_Payment();

		$this->assertSame( 'payhere_get_payment', $tool->get_slug(), 'Tool slug should be payhere_get_payment' );
		$this->assertStringContainsString( 'PayHere', $tool->get_name(), 'Tool name should mention PayHere' );
		$this->assertStringContainsString( 'payment', $tool->get_description(), 'Tool description should mention payment' );
	}

	/**
	 * Test that PayHere tool has correct parameter schema.
	 */
	public function test_payhere_tool_parameters() {
		$tool   = new WP_MCP_AI_Tool_PayHere_Get_Payment();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'order_id', $schema['properties'], 'Schema should include order_id parameter' );
		$this->assertSame( 'string', $schema['properties']['order_id']['type'], 'order_id should be string type' );
		$this->assertContains( 'order_id', $schema['required'], 'order_id should be required' );
	}

	/**
	 * Test that PayHere tool has correct capability flags.
	 */
	public function test_payhere_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_PayHere_Get_Payment();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags, 'Capability flags should be an array' );
		$this->assertContains( 'pro', $flags, 'Tool should have pro flag' );
		$this->assertContains( 'external-api', $flags, 'Tool should have external-api flag' );
		$this->assertContains( 'requires-credentials', $flags, 'Tool should have requires-credentials flag' );
		$this->assertContains( 'requires-capability', $flags, 'Tool should have requires-capability flag' );
		$this->assertContains( 'read-only', $flags, 'Tool should have read-only flag' );
		$this->assertContains( 'pii-data', $flags, 'Tool should have pii-data flag' );
	}

	/**
	 * Test that PayHere tool returns error when credentials are missing.
	 */
	public function test_payhere_tool_error_missing_credentials() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array_merge(
				WP_MCP_AI_Admin_Settings::get_default_settings(),
				array(
					'payhere_app_id'     => '',
					'payhere_app_secret' => '',
				)
			)
		);

		$tool   = new WP_MCP_AI_Tool_PayHere_Get_Payment();
		$result = $tool->execute(
			array( 'order_id' => 'TEST123' ),
			array(
				'user_id'             => 1,
				'token_authenticated' => false,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Tool should return WP_Error when credentials are missing' );
		$this->assertSame( 'wp_mcp_ai_missing_payhere_credentials', $result->get_error_code(), 'Error code should indicate missing credentials' );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that PayHere tool requires authentication.
	 */
	public function test_payhere_tool_requires_authentication() {
		$tool   = new WP_MCP_AI_Tool_PayHere_Get_Payment();
		$result = $tool->execute(
			array( 'order_id' => 'TEST123' ),
			array(
				'user_id'             => 0,
				'token_authenticated' => false,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Tool should return WP_Error when not authenticated' );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code(), 'Error code should indicate forbidden access' );
	}

	/**
	 * Test that PayHere tool requires proper capability.
	 */
	public function test_payhere_tool_requires_capability() {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_PayHere_Get_Payment();
		$result = $tool->execute(
			array( 'order_id' => 'TEST123' ),
			array(
				'user_id'             => $user_id,
				'token_authenticated' => false,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Tool should return WP_Error when user lacks capability' );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code(), 'Error code should indicate forbidden access' );
	}

	/**
	 * Test that PayHere tool requires order_id parameter.
	 */
	public function test_payhere_tool_requires_order_id() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_PayHere_Get_Payment();
		$result = $tool->execute(
			array(),
			array(
				'user_id'             => $user_id,
				'token_authenticated' => false,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Tool should return WP_Error when order_id is missing' );
		$this->assertSame( 'wp_mcp_ai_missing_order_id', $result->get_error_code(), 'Error code should indicate missing order_id' );
	}

	/**
	 * Test that providers section includes PayHere fields.
	 */
	public function test_providers_section_includes_payhere_fields() {
		$providers_section = new WP_MCP_AI_Section_Providers();
		$fields            = $providers_section->get_fields();

		$this->assertArrayHasKey( 'payhere_app_id', $fields, 'Providers section should include payhere_app_id field' );
		$this->assertSame( 'text', $fields['payhere_app_id']['type'], 'payhere_app_id should be text type' );

		$this->assertArrayHasKey( 'payhere_app_secret', $fields, 'Providers section should include payhere_app_secret field' );
		$this->assertSame( 'password', $fields['payhere_app_secret']['type'], 'payhere_app_secret should be password type' );

		$this->assertArrayHasKey( 'payhere_sandbox_mode', $fields, 'Providers section should include payhere_sandbox_mode field' );
		$this->assertSame( 'checkbox', $fields['payhere_sandbox_mode']['type'], 'payhere_sandbox_mode should be checkbox type' );
	}

	/**
	 * Test that PayHere subtab exists in providers section.
	 */
	public function test_providers_section_has_payhere_subtab() {
		$providers_section = new WP_MCP_AI_Section_Providers();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $providers_section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtabs = $method->invoke( $providers_section );

		$this->assertArrayHasKey( 'payhere', $subtabs, 'Providers section should have PayHere subtab' );
		$this->assertSame( 'payhere', $subtabs['payhere']['id'], 'PayHere subtab should have correct id' );
		$this->assertContains( 'payhere_app_id', $subtabs['payhere']['fields'], 'PayHere subtab should include app_id field' );
		$this->assertContains( 'payhere_app_secret', $subtabs['payhere']['fields'], 'PayHere subtab should include app_secret field' );
		$this->assertContains( 'payhere_sandbox_mode', $subtabs['payhere']['fields'], 'PayHere subtab should include sandbox_mode field' );
	}

	/**
	 * Test that PayHere tool is in external-tools group.
	 */
	public function test_payhere_tool_in_external_tools_group() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'payhere_get_payment', $group_map, 'PayHere tool should be in group map' );
		$this->assertSame( 'external-tools', $group_map['payhere_get_payment'], 'PayHere tool should be in external-tools group' );
	}
}
