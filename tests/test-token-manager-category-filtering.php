<?php
/**
 * Tests for Token Manager category filtering functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test token manager's ability to filter tools by category.
 *
 * @group token-manager
 * @group filtering
 */
class Test_Token_Manager_Category_Filtering extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
	}

	/**
	 * Test that category filtering works correctly.
	 */
	public function test_category_filtering_works() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		// Get all available tools.
		$all_tools_slugs = array_keys( WP_MCP_AI_Token_Usage_Service::get_all_available_tools() );

		// Test filtering by external-tools category.
		$external_tools = array_filter(
			$all_tools_slugs,
			function ( $tool_slug ) use ( $group_map ) {
				$tool_group = isset( $group_map[ $tool_slug ] ) ? $group_map[ $tool_slug ] : 'other';
				return 'external-tools' === $tool_group;
			}
		);

		// Verify we have external tools.
		$this->assertNotEmpty( $external_tools, 'Should have at least one external tool' );

		// Verify HuggingFace tools are in the external-tools category.
		$hf_tools = array_filter(
			$external_tools,
			function ( $slug ) {
				return strpos( $slug, 'huggingface' ) !== false;
			}
		);

		$this->assertGreaterThanOrEqual(
			11,
			count( $hf_tools ),
			'Should have at least 11 HuggingFace tools in external-tools category'
		);
	}

	/**
	 * Test that HuggingFace tools are in the correct category.
	 */
	public function test_huggingface_tools_in_external_category() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$expected_hf_tools = array(
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

		foreach ( $expected_hf_tools as $tool_slug ) {
			$this->assertArrayHasKey(
				$tool_slug,
				$group_map,
				sprintf( 'Tool "%s" should be in the group map', $tool_slug )
			);

			$tool_group = $group_map[ $tool_slug ];
			$this->assertEquals(
				'external-tools',
				$tool_group,
				sprintf( 'HuggingFace tool "%s" should be in external-tools category, got "%s"', $tool_slug, $tool_group )
			);
		}
	}

	/**
	 * Test filtering by each category.
	 */
	public function test_filtering_by_each_category() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();
		$all_tools = WP_MCP_AI_Token_Usage_Service::get_all_available_tools();

		$categories = array(
			'wordpress-core',
			'wordpress-plugins',
			'external-tools',
		);

		foreach ( $categories as $category ) {
			$filtered_tools = array_filter(
				$all_tools,
				function ( $tool_name, $tool_slug ) use ( $group_map, $category ) {
					$tool_group = isset( $group_map[ $tool_slug ] ) ? $group_map[ $tool_slug ] : 'other';
					return $tool_group === $category;
				},
				ARRAY_FILTER_USE_BOTH
			);

			$this->assertNotEmpty(
				$filtered_tools,
				sprintf( 'Category "%s" should have at least one tool', $category )
			);
		}
	}

	/**
	 * Test that tools without a category default to 'other'.
	 */
	public function test_uncategorized_tools_default_to_other() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		// Create a mock tool slug that doesn't exist.
		$mock_tool_slug = 'nonexistent_tool_slug_12345';

		// Verify it doesn't exist in the group map.
		$this->assertArrayNotHasKey( $mock_tool_slug, $group_map );

		// Simulate the filtering logic.
		$tool_group = isset( $group_map[ $mock_tool_slug ] ) ? $group_map[ $mock_tool_slug ] : 'other';

		$this->assertEquals(
			'other',
			$tool_group,
			'Tools not in the group map should default to "other" category'
		);
	}
}
