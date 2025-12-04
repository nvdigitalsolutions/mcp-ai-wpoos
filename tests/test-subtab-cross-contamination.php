<?php
/**
 * Tests for subtab cross-contamination prevention.
 *
 * Verifies that saving one subtab doesn't affect fields from other subtabs.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that subtab sanitization prevents cross-contamination.
 */
class WP_MCP_AI_Subtab_Cross_Contamination_Test extends WP_UnitTestCase {

	/**
	 * Test that saving General → custom_filters doesn't affect other subtabs.
	 */
	public function test_general_custom_filters_doesnt_affect_logs_subtab() {
		$section = new WP_MCP_AI_Section_General();

		// Simulate saving custom_filters subtab with filter values.
		$_POST['subtab'] = 'custom_filters';
		$submitted       = array(
			'filter_default_light_model'    => 'gpt-4o-mini',
			'filter_default_advanced_model' => 'gpt-4o',
			'filter_max_agentic_iterations' => '10',
			'filter_max_retries'            => '5',
		);

		$sanitized = $section->sanitize( $submitted );

		// Verify filter fields are sanitized.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'filter_default_light_model', $sanitized );
		$this->assertEquals( 'gpt-4o-mini', $sanitized['filter_default_light_model'] );

		// Verify logs subtab fields (checkboxes) are NOT included.
		// If they were, they'd all be false, which would clear logging settings.
		$this->assertArrayNotHasKey( 'enable_logging', $sanitized );
		$this->assertArrayNotHasKey( 'enable_extended_logging', $sanitized );
		$this->assertArrayNotHasKey( 'delete_on_uninstall', $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that saving General → logs doesn't affect custom_filters subtab.
	 */
	public function test_general_logs_doesnt_affect_custom_filters_subtab() {
		$section = new WP_MCP_AI_Section_General();

		// Simulate saving logs subtab with logging checkboxes.
		$_POST['subtab'] = 'logs';
		$submitted       = array(
			'enable_logging'                  => '1',
			'enable_extended_logging'         => '0',
			'enable_agentic_loop_logging'     => '1',
			'enable_api_logging'              => '0',
			'enable_tool_execution_logging'   => '1',
			'enable_chat_interaction_logging' => '0',
			'delete_on_uninstall'             => '0',
		);

		$sanitized = $section->sanitize( $submitted );

		// Verify logging fields are sanitized.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'enable_logging', $sanitized );
		$this->assertTrue( $sanitized['enable_logging'] );
		$this->assertFalse( $sanitized['enable_extended_logging'] );

		// Verify custom_filters fields are NOT included.
		// If they were, empty number fields would become 0 instead of staying empty.
		$this->assertArrayNotHasKey( 'filter_default_light_model', $sanitized );
		$this->assertArrayNotHasKey( 'filter_max_agentic_iterations', $sanitized );
		$this->assertArrayNotHasKey( 'filter_max_retries', $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that empty number fields preserve empty string (not converted to 0).
	 */
	public function test_empty_number_fields_preserved_as_empty_string() {
		$section = new WP_MCP_AI_Section_General();

		// Simulate saving custom_filters with some empty number fields.
		$_POST['subtab'] = 'custom_filters';
		$submitted       = array(
			'filter_max_agentic_iterations' => '',  // Empty means "use default".
			'filter_max_retries'            => '5', // Explicit value.
			'filter_resource_max_tokens'    => '',  // Empty means "use default".
		);

		$sanitized = $section->sanitize( $submitted );

		// Verify empty strings are preserved, not converted to 0.
		$this->assertSame( '', $sanitized['filter_max_agentic_iterations'] );
		$this->assertSame( 5, $sanitized['filter_max_retries'] );
		$this->assertSame( '', $sanitized['filter_resource_max_tokens'] );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that empty URL fields preserve empty string.
	 */
	public function test_empty_url_fields_preserved_as_empty_string() {
		$section = new WP_MCP_AI_Section_General();

		// Simulate saving custom_filters with empty URL fields.
		$_POST['subtab'] = 'custom_filters';
		$submitted       = array(
			'filter_default_ollama_endpoint_url'    => '',
			'filter_default_lm_studio_endpoint_url' => 'http://localhost:1234',
		);

		$sanitized = $section->sanitize( $submitted );

		// Verify empty URLs are preserved as empty strings.
		$this->assertSame( '', $sanitized['filter_default_ollama_endpoint_url'] );
		$this->assertSame( 'http://localhost:1234', $sanitized['filter_default_lm_studio_endpoint_url'] );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that saving Advanced → data_management doesn't affect other subtabs.
	 */
	public function test_advanced_data_management_doesnt_affect_performance() {
		$section = new WP_MCP_AI_Section_Advanced();

		// Simulate saving data_management subtab (no form fields, custom content only).
		$_POST['subtab'] = 'data_management';
		$submitted       = array();

		$sanitized = $section->sanitize( $submitted );

		// Should return empty array since data_management has no fields.
		$this->assertIsArray( $sanitized );
		$this->assertEmpty( $sanitized );

		// Verify performance subtab fields are NOT included.
		$this->assertArrayNotHasKey( 'memory_max_file_bytes', $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that wrong subtab name returns empty array.
	 */
	public function test_wrong_subtab_returns_empty() {
		$section = new WP_MCP_AI_Section_General();

		// Simulate a subtab that doesn't exist in General.
		$_POST['subtab'] = 'data_management'; // This is from Advanced, not General.
		$submitted       = array(
			'enable_logging' => '1',
		);

		$sanitized = $section->sanitize( $submitted );

		// Should return empty array since 'data_management' isn't in General's subtabs.
		$this->assertIsArray( $sanitized );
		$this->assertEmpty( $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that missing subtab POST param defaults correctly.
	 */
	public function test_missing_subtab_uses_default() {
		$section = new WP_MCP_AI_Section_General();

		// Don't set $_POST['subtab'] - it should default to 'core'.
		$submitted = array(
			'default_provider'  => 'openai',
			'default_assistant' => '0',
		);

		$sanitized = $section->sanitize( $submitted );

		// Should sanitize using the default 'core' subtab.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'default_provider', $sanitized );
		$this->assertEquals( 'openai', $sanitized['default_provider'] );
	}
}
