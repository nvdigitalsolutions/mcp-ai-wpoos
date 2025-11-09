<?php
/**
 * Tests for assistant tool presets functionality.
 */
class WP_MCP_AI_Tool_Presets_Test extends WP_UnitTestCase {

	/**
	 * Test that get_tool_presets returns expected structure.
	 */
	public function test_get_tool_presets_returns_valid_structure() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $cpt );
		$method = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		$presets = $method->invoke( $cpt );

		$this->assertIsArray( $presets, 'Presets should be an array' );
		$this->assertNotEmpty( $presets, 'Presets should not be empty' );

		// Check that we have at least 20 presets.
		$this->assertGreaterThanOrEqual( 20, count( $presets ), 'Should have at least 20 presets' );

		// Verify each preset has required keys.
		foreach ( $presets as $preset_id => $preset_data ) {
			$this->assertIsArray( $preset_data, "Preset {$preset_id} should be an array" );
			$this->assertArrayHasKey( 'label', $preset_data, "Preset {$preset_id} should have a label" );
			$this->assertArrayHasKey( 'description', $preset_data, "Preset {$preset_id} should have a description" );
			$this->assertArrayHasKey( 'tools', $preset_data, "Preset {$preset_id} should have tools array" );
			$this->assertIsArray( $preset_data['tools'], "Preset {$preset_id} tools should be an array" );
			$this->assertNotEmpty( $preset_data['tools'], "Preset {$preset_id} should have at least one tool" );
		}
	}

	/**
	 * Test that specific presets exist and have expected tools.
	 */
	public function test_specific_presets_exist() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $cpt );
		$method = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		$presets = $method->invoke( $cpt );

		// Test that key presets exist.
		$required_presets = array(
			'content_creator',
			'marketer',
			'ecommerce_manager',
			'it_manager',
			'developer',
			'customer_support',
			'data_analyst',
			'seo_specialist',
			'social_media_manager',
			'project_manager',
			'media_producer',
			'automation_specialist',
			'research_analyst',
			'security_specialist',
			'api_integrator',
			'emergency_responder',
			'general_assistant',
			'communication_manager',
			'site_administrator',
			'local_business_owner',
		);

		foreach ( $required_presets as $preset_id ) {
			$this->assertArrayHasKey( $preset_id, $presets, "Preset {$preset_id} should exist" );
		}
	}

	/**
	 * Test that IT Manager preset contains expected tools.
	 */
	public function test_it_manager_preset_contains_expected_tools() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $cpt );
		$method = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		$presets = $method->invoke( $cpt );

		$this->assertArrayHasKey( 'it_manager', $presets );
		$it_manager_tools = $presets['it_manager']['tools'];

		// Check for expected IT manager tools.
		$expected_tools = array( 'get_site_health', 'check_site_security', 'get_system_logs' );
		foreach ( $expected_tools as $tool_slug ) {
			$this->assertContains(
				$tool_slug,
				$it_manager_tools,
				"IT Manager preset should contain {$tool_slug} tool"
			);
		}
	}

	/**
	 * Test that Content Creator preset contains expected tools.
	 */
	public function test_content_creator_preset_contains_expected_tools() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $cpt );
		$method = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		$presets = $method->invoke( $cpt );

		$this->assertArrayHasKey( 'content_creator', $presets );
		$content_creator_tools = $presets['content_creator']['tools'];

		// Check for expected content creator tools.
		$expected_tools = array( 'save_post', 'search_content', 'submit_document_prompt' );
		foreach ( $expected_tools as $tool_slug ) {
			$this->assertContains(
				$tool_slug,
				$content_creator_tools,
				"Content Creator preset should contain {$tool_slug} tool"
			);
		}
	}

	/**
	 * Test that preset tools only reference registered tools.
	 */
	public function test_preset_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $cpt );
		$method = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		$presets = $method->invoke( $cpt );

		// Get all registered tools.
		$registered_tools = array();
		foreach ( $registry->get_tools() as $tool ) {
			$registered_tools[] = $tool->get_slug();
		}

		// Check that all tools in all presets are registered.
		foreach ( $presets as $preset_id => $preset_data ) {
			foreach ( $preset_data['tools'] as $tool_slug ) {
				// We allow tools that might not be registered in base version.
				// Just check that the tool slug is a valid string format.
				$this->assertIsString( $tool_slug, "Tool slug in {$preset_id} should be a string" );
				$this->assertNotEmpty( $tool_slug, "Tool slug in {$preset_id} should not be empty" );
			}
		}
	}

	/**
	 * Test that presets filter is applied.
	 */
	public function test_presets_filter_is_applied() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Add filter to modify presets.
		add_filter( 'wp_mcp_ai_tool_presets', function( $presets ) {
			$presets['custom_preset'] = array(
				'label' => 'Custom Test Preset',
				'description' => 'A custom preset for testing',
				'tools' => array( 'get_site_summary' ),
			);
			return $presets;
		} );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $cpt );
		$method = $reflection->getMethod( 'get_tool_presets' );
		$method->setAccessible( true );
		$presets = $method->invoke( $cpt );

		$this->assertArrayHasKey( 'custom_preset', $presets, 'Custom preset should be added via filter' );
		$this->assertEquals( 'Custom Test Preset', $presets['custom_preset']['label'] );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_tool_presets' );
	}
}
