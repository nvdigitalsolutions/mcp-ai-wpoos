<?php
/**
 * Tests for Regulatory Registration Toolkit checkbox in settings.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that the Regulatory Registration Toolkit checkbox is properly registered.
 */
class Test_Regulatory_Toolkit_Checkbox extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that the enable_regulatory_registration_toolkit field exists in Tools section.
	 */
	public function test_regulatory_toolkit_field_exists() {
		// Load the Tools section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-tools.php';
		
		$this->assertTrue( class_exists( 'WP_MCP_AI_Section_Tools' ), 'Tools section class should exist' );

		// Create an instance using reflection to access protected methods.
		$section = new WP_MCP_AI_Section_Tools();
		$reflection = new ReflectionClass( $section );
		
		// Get the get_fields method.
		$get_fields_method = $reflection->getMethod( 'get_fields' );
		$get_fields_method->setAccessible( true );
		
		// Call get_fields to get all field definitions.
		$fields = $get_fields_method->invoke( $section );
		
		// Check that the regulatory registration toolkit field exists.
		$this->assertArrayHasKey( 'enable_regulatory_registration_toolkit', $fields, 'Regulatory registration toolkit field should be defined' );
		
		// Verify field properties.
		$field = $fields['enable_regulatory_registration_toolkit'];
		$this->assertEquals( 'checkbox', $field['type'], 'Field should be a checkbox' );
		$this->assertArrayHasKey( 'label', $field, 'Field should have a label' );
		$this->assertArrayHasKey( 'checkbox_label', $field, 'Field should have a checkbox label' );
		$this->assertArrayHasKey( 'description', $field, 'Field should have a description' );
		$this->assertFalse( $field['default'], 'Field should default to false' );
	}

	/**
	 * Test that the regulatory toolkit field is included in the Features subtab.
	 */
	public function test_regulatory_toolkit_in_features_subtab() {
		// Load the Tools section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-tools.php';
		
		$this->assertTrue( class_exists( 'WP_MCP_AI_Section_Tools' ), 'Tools section class should exist' );

		// Create an instance using reflection to access protected methods.
		$section = new WP_MCP_AI_Section_Tools();
		$reflection = new ReflectionClass( $section );
		
		// Get the get_subtab_groups method.
		$get_subtab_groups_method = $reflection->getMethod( 'get_subtab_groups' );
		$get_subtab_groups_method->setAccessible( true );
		
		// Call get_subtab_groups to get all subtab configurations.
		$subtab_groups = $get_subtab_groups_method->invoke( $section );
		
		// Check that the features subtab exists.
		$this->assertArrayHasKey( 'features', $subtab_groups, 'Features subtab should exist' );
		
		// Check that the regulatory toolkit field is in the features subtab fields.
		$features_fields = $subtab_groups['features']['fields'];
		$this->assertContains( 'enable_regulatory_registration_toolkit', $features_fields, 'Regulatory toolkit field should be in Features subtab' );
	}

	/**
	 * Test that the regulatory toolkit has a memory requirement defined.
	 */
	public function test_regulatory_toolkit_memory_requirement() {
		// Load the Tools section class.
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-tools.php';
		
		$this->assertTrue( class_exists( 'WP_MCP_AI_Section_Tools' ), 'Tools section class should exist' );

		// Create an instance using reflection to access private methods.
		$section = new WP_MCP_AI_Section_Tools();
		$reflection = new ReflectionClass( $section );
		
		// Get the get_toolkit_memory_requirements method.
		$get_memory_method = $reflection->getMethod( 'get_toolkit_memory_requirements' );
		$get_memory_method->setAccessible( true );
		
		// Call get_toolkit_memory_requirements to get memory allocations.
		$memory_requirements = $get_memory_method->invoke( $section );
		
		// Check that the regulatory toolkit has a memory requirement.
		$this->assertArrayHasKey( 'enable_regulatory_registration_toolkit', $memory_requirements, 'Regulatory toolkit should have memory requirement' );
		
		// Verify it's a reasonable number (in MB).
		$memory = $memory_requirements['enable_regulatory_registration_toolkit'];
		$this->assertIsInt( $memory, 'Memory requirement should be an integer' );
		$this->assertGreaterThan( 0, $memory, 'Memory requirement should be greater than 0' );
		$this->assertEquals( 80, $memory, 'Memory requirement should be 80 MB' );
	}

	/**
	 * Test that the regulatory toolkit setting can be saved.
	 */
	public function test_regulatory_toolkit_setting_can_be_saved() {
		// Set the regulatory toolkit to enabled.
		$settings = array(
			'enable_regulatory_registration_toolkit' => true,
		);
		update_option( 'wp_mcp_ai_settings', $settings );
		
		// Retrieve the settings.
		$retrieved_settings = get_option( 'wp_mcp_ai_settings' );
		
		// Verify the setting was saved correctly.
		$this->assertArrayHasKey( 'enable_regulatory_registration_toolkit', $retrieved_settings );
		$this->assertTrue( $retrieved_settings['enable_regulatory_registration_toolkit'] );
		
		// Test disabling the toolkit.
		$settings['enable_regulatory_registration_toolkit'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );
		
		$retrieved_settings = get_option( 'wp_mcp_ai_settings' );
		$this->assertFalse( $retrieved_settings['enable_regulatory_registration_toolkit'] );
	}
}
