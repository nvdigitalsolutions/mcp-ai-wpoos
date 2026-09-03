<?php
/**
 * Tests for subtab cross-contamination prevention.
 *
 * Verifies that saving one subtab doesn't affect fields from other subtabs.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Simulate a form submission for a subtab that doesn't exist in General
		// (data_management belongs to Advanced). The submitted settings array
		// marks this as a real form save rather than an import.
		$_POST['subtab_general']     = 'data_management';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_logging' => '1',
		);
		$submitted = array(
			'enable_logging' => '1',
		);

		$sanitized = $section->sanitize( $submitted );

		// Should return empty array since 'data_management' isn't in General's subtabs.
		$this->assertIsArray( $sanitized );
		$this->assertEmpty( $sanitized );

		// Clean up.
		unset( $_POST['subtab_general'], $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that missing subtab POST param defaults correctly.
	 */
	public function test_missing_subtab_uses_default() {
		// Configure OpenAI so 'openai' is a valid default_provider option (the
		// provider dropdown is dynamically filtered to enabled providers).
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'  => true,
				'openai_api_key' => 'sk-test-config',
			)
		);

		$section = new WP_MCP_AI_Section_General();

		// Don't set any subtab POST field - the import fallback sanitizes
		// every field so exported settings re-import without data loss.
		$submitted = array(
			'default_provider'  => 'openai',
			'default_assistant' => '0',
		);

		$sanitized = $section->sanitize( $submitted );

		// Should sanitize using the default 'core' subtab.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'default_provider', $sanitized );
		$this->assertEquals( 'openai', $sanitized['default_provider'] );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that saving Advanced → performance subtab correctly saves memory_max_file_bytes.
	 *
	 * This is the regression test for the bug where memory_max_file_bytes was reverting
	 * to 5 MB (5242880) on every save due to overly-restrictive max validation.
	 */
	public function test_advanced_performance_saves_memory_max_file_bytes() {
		$section = new WP_MCP_AI_Section_Advanced();

		// Simulate saving performance subtab using the section-specific hidden field.
		// Use 52428799 (one byte under 50 MB) – a representative value from the problem report.
		$_POST['subtab_advanced'] = 'performance';
		$submitted                = array(
			'memory_max_file_bytes' => '52428799',
		);

		$sanitized = $section->sanitize( $submitted );

		// The performance subtab should include memory_max_file_bytes.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'memory_max_file_bytes', $sanitized );
		$this->assertEquals( 52428799, $sanitized['memory_max_file_bytes'] );

		// Validate should pass for this value (no upper-bound restriction).
		$validated = $section->validate( $sanitized );
		$this->assertNotInstanceOf( 'WP_Error', $validated );
		$this->assertEquals( 52428799, $validated['memory_max_file_bytes'] );

		// Clean up.
		unset( $_POST['subtab_advanced'] );
	}

	/**
	 * Test that memory_max_file_bytes accepts any positive integer value.
	 */
	public function test_memory_max_file_bytes_accepts_any_positive_integer() {
		$section = new WP_MCP_AI_Section_Advanced();

		$test_values = array(
			1,           // Minimum.
			5242880,     // 5 MB (default).
			10485760,    // 10 MB.
			52428800,    // 50 MB.
			104857600,   // 100 MB.
			209715200,   // 200 MB (previously rejected by old max=104857600 check).
			1073741824,  // 1 GB.
		);

		foreach ( $test_values as $bytes ) {
			$sanitized = array( 'memory_max_file_bytes' => $bytes );
			$validated = $section->validate( $sanitized );

			$this->assertNotInstanceOf(
				'WP_Error',
				$validated,
				sprintf( 'Validation should pass for %d bytes', $bytes )
			);
		}
	}
}
