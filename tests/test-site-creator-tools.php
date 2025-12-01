<?php
/**
 * Tests for the Site Creator and related tools.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test the Site Creator tool and its supporting tools.
 */
class WP_MCP_AI_Site_Creator_Tools_Test extends WP_UnitTestCase {

	/**
	 * Test that the update_option tool is registered.
	 */
	public function test_update_option_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'update_option' );

		$this->assertNotNull( $tool, 'The update_option tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test that the install_and_activate_plugin tool is registered.
	 */
	public function test_install_and_activate_plugin_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'install_and_activate_plugin' );

		$this->assertNotNull( $tool, 'The install_and_activate_plugin tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test that the install_and_activate_theme tool is registered.
	 */
	public function test_install_and_activate_theme_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'install_and_activate_theme' );

		$this->assertNotNull( $tool, 'The install_and_activate_theme tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test that the site_creator tool is registered.
	 */
	public function test_site_creator_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'site_creator' );

		$this->assertNotNull( $tool, 'The site_creator tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test update_option tool execution.
	 */
	public function test_update_option_execution() {
		// Enable site creator features.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator'               => true,
				'site_creator_allow_option_updates' => true,
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'update_option' );

		// Create an admin user.
		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$result = $tool->execute(
			array(
				'option_name'  => 'test_option_name',
				'option_value' => 'test_value',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertTrue( $result['success'], 'Update should be successful.' );
		$this->assertSame( 'test_option_name', $result['option_name'] );
		$this->assertSame( 'test_value', $result['option_value'] );

		// Verify the option was actually updated.
		$this->assertSame( 'test_value', get_option( 'test_option_name' ) );
	}

	/**
	 * Test update_option permission check.
	 */
	public function test_update_option_permission_check() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'update_option' );

		// Create a subscriber user.
		$subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$result = $tool->execute(
			array(
				'option_name'  => 'test_option',
				'option_value' => 'value',
			),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Non-admin should not be able to update options.' );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test update_option with missing option name.
	 */
	public function test_update_option_missing_name() {
		// Enable site creator features.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator'               => true,
				'site_creator_allow_option_updates' => true,
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'update_option' );

		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$result = $tool->execute(
			array(
				'option_value' => 'value',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return error for missing option name.' );
		$this->assertSame( 'wp_mcp_ai_missing_option_name', $result->get_error_code() );
	}

	/**
	 * Test update_option when feature is disabled.
	 */
	public function test_update_option_feature_disabled() {
		// Ensure feature is disabled.
		update_option( 'wp_mcp_ai_settings', array() );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'update_option' );

		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$result = $tool->execute(
			array(
				'option_name'  => 'test_option',
				'option_value' => 'value',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return error when feature is disabled.' );
		$this->assertSame( 'wp_mcp_ai_feature_disabled', $result->get_error_code() );
	}

	/**
	 * Test site_creator permission check.
	 */
	public function test_site_creator_permission_check() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'site_creator' );

		// Create a subscriber user.
		$subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$result = $tool->execute(
			array(
				'plan' => array(
					'options' => array( 'blogname' => 'Test Site' ),
				),
			),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Non-admin should not be able to create sites.' );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test site_creator with invalid plan.
	 */
	public function test_site_creator_invalid_plan() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'site_creator' );

		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$result = $tool->execute(
			array(
				'plan' => 'not an object',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return error for invalid plan.' );
		$this->assertSame( 'wp_mcp_ai_invalid_plan', $result->get_error_code() );
	}

	/**
	 * Test site_creator with options only.
	 */
	public function test_site_creator_with_options() {
		// Enable site creator features.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator'               => true,
				'site_creator_allow_option_updates' => true,
			)
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'site_creator' );

		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$result = $tool->execute(
			array(
				'plan' => array(
					'options' => array(
						'blogname'        => 'My Test Site',
						'blogdescription' => 'A test site',
					),
				),
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertTrue( $result['success'], 'Site creation should be successful.' );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'summary', $result );

		// Verify options were updated.
		$this->assertSame( 'My Test Site', get_option( 'blogname' ) );
		$this->assertSame( 'A test site', get_option( 'blogdescription' ) );
	}

	/**
	 * Test that tools have proper capability flags.
	 */
	public function test_tools_have_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = array(
			'update_option',
			'install_and_activate_plugin',
			'install_and_activate_theme',
			'site_creator',
			'check_wp_cli',
		);

		foreach ( $tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool {$tool_slug} should be registered." );

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = $tool->get_capability_flags();
				$this->assertIsArray( $flags, "Tool {$tool_slug} should return capability flags as array." );
				$this->assertNotEmpty( $flags, "Tool {$tool_slug} should have at least one capability flag." );
			}
		}
	}

	/**
	 * Test that tools have proper parameter schemas.
	 */
	public function test_tools_have_parameter_schemas() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = array(
			'update_option',
			'install_and_activate_plugin',
			'install_and_activate_theme',
			'site_creator',
			'check_wp_cli',
		);

		foreach ( $tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool {$tool_slug} should be registered." );

			$schema = $tool->get_parameters_schema();
			$this->assertIsArray( $schema, "Tool {$tool_slug} should return schema as array." );
			$this->assertArrayHasKey( 'type', $schema, "Tool {$tool_slug} schema should have a type." );
			$this->assertArrayHasKey( 'properties', $schema, "Tool {$tool_slug} schema should have properties." );
		}
	}

	/**
	 * Test check_wp_cli tool is registered.
	 */
	public function test_check_wp_cli_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'check_wp_cli' );

		$this->assertNotNull( $tool, 'The check_wp_cli tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test check_wp_cli requires site_creator_allow_wp_cli_tools setting.
	 */
	public function test_check_wp_cli_requires_wp_cli_tools_setting() {
		// Ensure feature is disabled.
		update_option( 'wp_mcp_ai_settings', array() );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'check_wp_cli' );

		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$result = $tool->execute(
			array(),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return error when WP-CLI tools are disabled.' );
		$this->assertSame( 'wp_mcp_ai_feature_disabled', $result->get_error_code() );
	}
}
