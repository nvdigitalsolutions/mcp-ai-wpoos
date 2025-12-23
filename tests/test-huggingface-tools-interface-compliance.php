<?php
/**
 * Tests for HuggingFace tools interface compliance.
 *
 * Verifies that all HuggingFace tools properly implement the required
 * WP_MCP_AI_Tool_Interface methods after the fix.
 *
 * @package WP_MCP_AI
 */

/**
 * @group tool-registry
 * @group huggingface
 * @group interface-compliance
 */
class Test_Huggingface_Tools_Interface_Compliance extends WP_UnitTestCase {

	/**
	 * List of HuggingFace tool slugs.
	 *
	 * @var array
	 */
	private $huggingface_tool_slugs = array(
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

	/**
	 * Test that all HuggingFace tools have get_name() method.
	 */
	public function test_huggingface_tools_have_get_name_method() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$tool = $registry->get_tool( $slug );

			$this->assertNotNull(
				$tool,
				sprintf( 'HuggingFace tool "%s" should be registered', $slug )
			);

			$this->assertTrue(
				method_exists( $tool, 'get_name' ),
				sprintf( 'HuggingFace tool "%s" should have get_name() method', $slug )
			);

			$name = $tool->get_name();
			$this->assertNotEmpty(
				$name,
				sprintf( 'HuggingFace tool "%s" get_name() should return a non-empty string', $slug )
			);
			$this->assertIsString(
				$name,
				sprintf( 'HuggingFace tool "%s" get_name() should return a string', $slug )
			);
		}
	}

	/**
	 * Test that all HuggingFace tools have get_description() method.
	 */
	public function test_huggingface_tools_have_get_description_method() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$tool = $registry->get_tool( $slug );

			$this->assertNotNull(
				$tool,
				sprintf( 'HuggingFace tool "%s" should be registered', $slug )
			);

			$this->assertTrue(
				method_exists( $tool, 'get_description' ),
				sprintf( 'HuggingFace tool "%s" should have get_description() method', $slug )
			);

			$description = $tool->get_description();
			$this->assertNotEmpty(
				$description,
				sprintf( 'HuggingFace tool "%s" get_description() should return a non-empty string', $slug )
			);
			$this->assertIsString(
				$description,
				sprintf( 'HuggingFace tool "%s" get_description() should return a string', $slug )
			);
		}
	}

	/**
	 * Test that all HuggingFace tools have get_parameters_schema() method.
	 */
	public function test_huggingface_tools_have_get_parameters_schema_method() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$tool = $registry->get_tool( $slug );

			$this->assertNotNull(
				$tool,
				sprintf( 'HuggingFace tool "%s" should be registered', $slug )
			);

			$this->assertTrue(
				method_exists( $tool, 'get_parameters_schema' ),
				sprintf( 'HuggingFace tool "%s" should have get_parameters_schema() method', $slug )
			);

			$schema = $tool->get_parameters_schema();
			$this->assertIsArray(
				$schema,
				sprintf( 'HuggingFace tool "%s" get_parameters_schema() should return an array', $slug )
			);
		}
	}

	/**
	 * Test that HuggingFace tools appear in the available tools list.
	 */
	public function test_huggingface_tools_appear_in_available_tools_list() {
		$all_tools = WP_MCP_AI_Token_Usage_Service::get_all_available_tools();

		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$this->assertArrayHasKey(
				$slug,
				$all_tools,
				sprintf( 'HuggingFace tool "%s" should appear in the available tools list', $slug )
			);

			$tool_name = $all_tools[ $slug ];
			$this->assertNotEmpty(
				$tool_name,
				sprintf( 'HuggingFace tool "%s" should have a non-empty name in the tools list', $slug )
			);
			$this->assertIsString(
				$tool_name,
				sprintf( 'HuggingFace tool "%s" name should be a string', $slug )
			);
		}
	}

	/**
	 * Test that all HuggingFace tools implement the WP_MCP_AI_Tool_Interface.
	 */
	public function test_huggingface_tools_implement_interface() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$tool = $registry->get_tool( $slug );

			$this->assertNotNull(
				$tool,
				sprintf( 'HuggingFace tool "%s" should be registered', $slug )
			);

			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Interface',
				$tool,
				sprintf( 'HuggingFace tool "%s" should implement WP_MCP_AI_Tool_Interface', $slug )
			);
		}
	}

	/**
	 * Test that HuggingFace tools have consistent naming.
	 */
	public function test_huggingface_tools_have_consistent_naming() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$tool = $registry->get_tool( $slug );

			$this->assertNotNull(
				$tool,
				sprintf( 'HuggingFace tool "%s" should be registered', $slug )
			);

			$name = $tool->get_name();
			
			// All HuggingFace tools should have "HuggingFace" in their name.
			$this->assertStringContainsString(
				'HuggingFace',
				$name,
				sprintf( 'HuggingFace tool "%s" name should contain "HuggingFace"', $slug )
			);
		}
	}
}
