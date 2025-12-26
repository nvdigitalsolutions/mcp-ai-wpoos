<?php
/**
 * Tests for HuggingFace tools recommendations.
 *
 * Verifies that all HuggingFace tools have proper preset values
 * and recommendations in the tool recommendations system.
 *
 * @package WP_MCP_AI
 */

/**
 * @group tool-recommendations
 * @group huggingface
 */
class Test_Huggingface_Tools_Recommendations extends WP_UnitTestCase {

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
	 * Test that all HuggingFace tools have recommendations.
	 */
	public function test_all_huggingface_tools_have_recommendations() {
		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$recommendation = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( $slug );

			$this->assertNotNull(
				$recommendation,
				sprintf( 'HuggingFace tool "%s" should have a recommendation', $slug )
			);

			$this->assertIsArray(
				$recommendation,
				sprintf( 'HuggingFace tool "%s" recommendation should be an array', $slug )
			);
		}
	}

	/**
	 * Test that HuggingFace tools are in the dataset_operations category.
	 */
	public function test_huggingface_tools_in_dataset_operations_category() {
		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$recommendation = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( $slug );

			$this->assertArrayHasKey(
				'category',
				$recommendation,
				sprintf( 'HuggingFace tool "%s" recommendation should have a category', $slug )
			);

			$this->assertEquals(
				'dataset_operations',
				$recommendation['category'],
				sprintf( 'HuggingFace tool "%s" should be in dataset_operations category', $slug )
			);
		}
	}

	/**
	 * Test that HuggingFace tools have appropriate multiplier.
	 */
	public function test_huggingface_tools_have_appropriate_multiplier() {
		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$recommendation = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( $slug );

			$this->assertArrayHasKey(
				'multiplier',
				$recommendation,
				sprintf( 'HuggingFace tool "%s" recommendation should have a multiplier', $slug )
			);

			$multiplier = $recommendation['multiplier'];

			$this->assertIsFloat(
				$multiplier,
				sprintf( 'HuggingFace tool "%s" multiplier should be a float', $slug )
			);

			$this->assertEquals(
				1.3,
				$multiplier,
				sprintf( 'HuggingFace tool "%s" should have 1.3× multiplier', $slug )
			);
		}
	}

	/**
	 * Test that HuggingFace tools have preferred model.
	 */
	public function test_huggingface_tools_have_preferred_model() {
		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$recommendation = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( $slug );

			$this->assertArrayHasKey(
				'preferred_model',
				$recommendation,
				sprintf( 'HuggingFace tool "%s" recommendation should have a preferred_model', $slug )
			);

			$preferred_model = $recommendation['preferred_model'];

			$this->assertNotEmpty(
				$preferred_model,
				sprintf( 'HuggingFace tool "%s" preferred_model should not be empty', $slug )
			);

			$this->assertEquals(
				'gpt-4o-mini',
				$preferred_model,
				sprintf( 'HuggingFace tool "%s" should prefer gpt-4o-mini model', $slug )
			);
		}
	}

	/**
	 * Test that dataset_operations category exists and has proper structure.
	 */
	public function test_dataset_operations_category_exists() {
		$categories = WP_MCP_AI_Tool_Recommendations::get_tool_categories();

		$this->assertArrayHasKey(
			'dataset_operations',
			$categories,
			'dataset_operations category should exist'
		);

		$dataset_ops = $categories['dataset_operations'];

		$this->assertArrayHasKey( 'multiplier', $dataset_ops );
		$this->assertArrayHasKey( 'preferred_model', $dataset_ops );
		$this->assertArrayHasKey( 'description', $dataset_ops );
		$this->assertArrayHasKey( 'tools', $dataset_ops );

		$this->assertEquals( 1.3, $dataset_ops['multiplier'] );
		$this->assertEquals( 'gpt-4o-mini', $dataset_ops['preferred_model'] );

		$this->assertIsArray( $dataset_ops['tools'] );
		$this->assertGreaterThanOrEqual(
			11,
			count( $dataset_ops['tools'] ),
			'dataset_operations should have at least 11 HuggingFace tools'
		);
	}

	/**
	 * Test that all HuggingFace tools are in dataset_operations tools list.
	 */
	public function test_all_huggingface_tools_in_category_tools_list() {
		$categories        = WP_MCP_AI_Tool_Recommendations::get_tool_categories();
		$dataset_ops_tools = $categories['dataset_operations']['tools'];

		foreach ( $this->huggingface_tool_slugs as $slug ) {
			$this->assertContains(
				$slug,
				$dataset_ops_tools,
				sprintf( 'HuggingFace tool "%s" should be in dataset_operations tools list', $slug )
			);
		}
	}

	/**
	 * Test recommendation match status for HuggingFace tools.
	 */
	public function test_huggingface_tools_recommendation_match() {
		foreach ( $this->huggingface_tool_slugs as $slug ) {
			// Test with recommended multiplier.
			$match_status = WP_MCP_AI_Tool_Recommendations::check_recommendation_match( $slug, 1.3, 'gpt-4o-mini' );

			$this->assertIsArray( $match_status );
			$this->assertArrayHasKey( 'matches', $match_status );
			$this->assertTrue(
				$match_status['matches'],
				sprintf( 'HuggingFace tool "%s" should match with recommended values', $slug )
			);

			// Test with non-recommended multiplier.
			$mismatch_status = WP_MCP_AI_Tool_Recommendations::check_recommendation_match( $slug, 1.0, 'gpt-4o-mini' );

			$this->assertIsArray( $mismatch_status );
			$this->assertFalse(
				$mismatch_status['matches'],
				sprintf( 'HuggingFace tool "%s" should not match with 1.0× multiplier', $slug )
			);
		}
	}
}
