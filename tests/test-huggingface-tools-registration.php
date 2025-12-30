<?php
/**
 * Tests for HuggingFace tools registration in WP_MCP_AI_Tool_Registry.
 *
 * Verifies that all HuggingFace dataset tools are properly registered
 * and available in the tool registry.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for huggingface tools registration tests.
 *
 * @group tool-registry
 * @group huggingface
 */
class WP_MCP_AI_Huggingface_Tools_Registration_Tests extends WP_UnitTestCase {

	/**
	 * Test that all HuggingFace dataset tools are registered.
	 */
	public function test_all_huggingface_tools_are_registered() {
		// Get the tool registry instance.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// List of all expected HuggingFace tool slugs.
		$expected_tools = array(
			'huggingface_dataset_search',
			'huggingface_dataset_get_info',
			'huggingface_dataset_get_size',
			'huggingface_dataset_get_rows',
			'huggingface_dataset_preview_rows',
			'huggingface_dataset_list_splits',
			'huggingface_dataset_get_statistics',
			'huggingface_dataset_get_parquet',
			'huggingface_dataset_is_valid',
			'huggingface_dataset_filter',
			'huggingface_recommended_datasets',
		);

		// Check that each tool is registered.
		foreach ( $expected_tools as $slug ) {
			$tool = $registry->get_tool( $slug );
			$this->assertNotNull(
				$tool,
				sprintf( 'HuggingFace tool "%s" should be registered in the tool registry', $slug )
			);

			// Verify the tool has required methods.
			$this->assertTrue(
				method_exists( $tool, 'get_slug' ),
				sprintf( 'HuggingFace tool "%s" should have get_slug() method', $slug )
			);
			$this->assertTrue(
				method_exists( $tool, 'get_definition' ),
				sprintf( 'HuggingFace tool "%s" should have get_definition() method', $slug )
			);
			$this->assertTrue(
				method_exists( $tool, 'execute' ),
				sprintf( 'HuggingFace tool "%s" should have execute() method', $slug )
			);

			// Verify the slug matches.
			$this->assertEquals(
				$slug,
				$tool->get_slug(),
				sprintf( 'HuggingFace tool slug should match expected value "%s"', $slug )
			);
		}
	}

	/**
	 * Test that HuggingFace tools are included in the full tools list.
	 */
	public function test_huggingface_tools_in_full_list() {
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools  = $registry->get_tools();
		$tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$all_tools
		);

		// Check that at least some HuggingFace tools are in the list.
		$hf_tools = array_filter(
			$tool_slugs,
			function ( $slug ) {
				return strpos( $slug, 'huggingface' ) !== false;
			}
		);

		$this->assertGreaterThanOrEqual(
			11,
			count( $hf_tools ),
			'At least 11 HuggingFace tools should be registered'
		);
	}

	/**
	 * Test that HuggingFace tools have proper definitions.
	 */
	public function test_huggingface_tools_have_valid_definitions() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Test one sample tool to verify structure.
		$tool = $registry->get_tool( 'huggingface_dataset_search' );
		$this->assertNotNull( $tool, 'HuggingFace dataset search tool should be registered' );

		$definition = $tool->get_definition();
		$this->assertIsArray( $definition, 'Tool definition should be an array' );
		$this->assertArrayHasKey( 'name', $definition, 'Tool definition should have a name' );
		$this->assertArrayHasKey( 'description', $definition, 'Tool definition should have a description' );
		$this->assertArrayHasKey( 'parameters', $definition, 'Tool definition should have parameters' );

		// Verify parameters structure.
		$this->assertIsArray( $definition['parameters'], 'Parameters should be an array' );
		$this->assertNotEmpty( $definition['parameters'], 'Parameters should not be empty' );
	}
}
