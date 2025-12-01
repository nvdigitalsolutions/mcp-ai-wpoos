<?php
/**
 * Tests for preset application fixes.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test preset application batch updates and new tool handling.
 */
class WP_MCP_AI_Preset_Application_Fix_Test extends WP_UnitTestCase {

	/**
	 * Test that preset application uses batch updates.
	 */
	public function test_preset_application_uses_batch_updates() {
		// Clear any existing settings.
		delete_option( 'wp_mcp_ai_tool_multipliers' );
		delete_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION );

		// Apply balanced preset.
		$results = WP_MCP_AI_Tool_Recommendations::apply_preset( 'balanced' );

		// Should have success results.
		$this->assertIsArray( $results );
		$this->assertArrayHasKey( 'success', $results );
		$this->assertArrayHasKey( 'failed', $results );
		$this->assertArrayHasKey( 'skipped', $results );

		// Should have successful applications.
		$this->assertGreaterThan( 0, $results['success'], 'At least some tools should be configured successfully' );

		// Verify settings were actually saved.
		$multipliers = get_option( 'wp_mcp_ai_tool_multipliers', array() );
		$preferences = get_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION, array() );

		$this->assertIsArray( $multipliers );
		$this->assertIsArray( $preferences );
		$this->assertNotEmpty( $multipliers, 'Multipliers should be saved' );
		$this->assertNotEmpty( $preferences, 'Preferences should be saved' );

		// Check that both arrays have the same number of items (all tools should have both settings).
		$this->assertCount(
			count( $multipliers ),
			$preferences,
			'Should have same number of multipliers and preferences'
		);
	}

	/**
	 * Test that all valid presets can be applied.
	 */
	public function test_all_presets_can_be_applied() {
		$presets = array( 'conservative', 'balanced', 'performance', 'aggressive' );

		foreach ( $presets as $preset ) {
			// Clear settings before each test.
			delete_option( 'wp_mcp_ai_tool_multipliers' );
			delete_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION );

			// Apply preset.
			$results = WP_MCP_AI_Tool_Recommendations::apply_preset( $preset );

			$this->assertIsArray( $results, "Results should be array for preset: {$preset}" );
			$this->assertGreaterThan(
				0,
				$results['success'],
				"Should have successful applications for preset: {$preset}"
			);

			// Verify different presets produce different multipliers.
			$multipliers = get_option( 'wp_mcp_ai_tool_multipliers', array() );
			$this->assertNotEmpty( $multipliers, "Multipliers should be saved for preset: {$preset}" );

			// Check that a known tool has a multiplier.
			$this->assertArrayHasKey(
				'search_content',
				$multipliers,
				"search_content should have multiplier for preset: {$preset}"
			);
		}
	}

	/**
	 * Test detecting uncategorized tools.
	 */
	public function test_detect_uncategorized_tools() {
		$uncategorized = WP_MCP_AI_Tool_Recommendations::get_uncategorized_tools();

		$this->assertIsArray( $uncategorized );
		// Can be empty if all tools are categorized, but should be an array.
	}

	/**
	 * Test suggesting category for a tool.
	 */
	public function test_suggest_tool_category() {
		// Test with a tool that should match a pattern.
		$suggestion = WP_MCP_AI_Tool_Recommendations::suggest_tool_category( 'test_search_content' );

		$this->assertIsArray( $suggestion );
		$this->assertArrayHasKey( 'category', $suggestion );
		$this->assertArrayHasKey( 'multiplier', $suggestion );
		$this->assertArrayHasKey( 'model', $suggestion );
		$this->assertArrayHasKey( 'confidence', $suggestion );
		$this->assertArrayHasKey( 'reasoning', $suggestion );

		// Search tools should be categorized as high_resource.
		$this->assertEquals( 'high_resource', $suggestion['category'] );

		// Test with a cache tool.
		$cache_suggestion = WP_MCP_AI_Tool_Recommendations::suggest_tool_category( 'test_purge_cache' );
		$this->assertEquals( 'cache_performance', $cache_suggestion['category'] );

		// Test with a message tool.
		$message_suggestion = WP_MCP_AI_Tool_Recommendations::suggest_tool_category( 'send_test_message' );
		$this->assertEquals( 'messaging', $message_suggestion['category'] );
	}

	/**
	 * Test that new tools get default recommendations.
	 */
	public function test_new_tools_get_recommendations() {
		// Test with a fake new tool.
		$recommendation = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( 'brand_new_test_tool' );

		$this->assertIsArray( $recommendation );
		$this->assertArrayHasKey( 'category', $recommendation );
		$this->assertArrayHasKey( 'multiplier', $recommendation );
		$this->assertArrayHasKey( 'preferred_model', $recommendation );

		// Should have a default multiplier.
		$this->assertGreaterThan( 0, $recommendation['multiplier'] );
	}

	/**
	 * Test that preset application doesn't fail completely on invalid tools.
	 */
	public function test_preset_handles_invalid_tools_gracefully() {
		// Apply preset - should work even if some tools are invalid.
		$results = WP_MCP_AI_Tool_Recommendations::apply_preset( 'balanced' );

		// Should complete without errors.
		$this->assertIsArray( $results );
		$this->assertArrayNotHasKey( 'error', $results );
	}

	/**
	 * Test that invalid preset returns error.
	 */
	public function test_invalid_preset_returns_error() {
		$results = WP_MCP_AI_Tool_Recommendations::apply_preset( 'nonexistent_preset' );

		$this->assertArrayHasKey( 'error', $results );
		$this->assertEquals( 0, $results['success'] );
	}

	/**
	 * Test different presets produce different multiplier values.
	 */
	public function test_presets_produce_different_multipliers() {
		$presets         = array( 'conservative', 'balanced', 'aggressive' );
		$multiplier_sets = array();

		foreach ( $presets as $preset ) {
			delete_option( 'wp_mcp_ai_tool_multipliers' );

			WP_MCP_AI_Tool_Recommendations::apply_preset( $preset );
			$multipliers = get_option( 'wp_mcp_ai_tool_multipliers', array() );

			// Get a sample multiplier for comparison.
			if ( isset( $multipliers['search_content'] ) ) {
				$multiplier_sets[ $preset ] = $multipliers['search_content'];
			}
		}

		// Conservative should have lower multiplier than balanced.
		if ( isset( $multiplier_sets['conservative'] ) && isset( $multiplier_sets['balanced'] ) ) {
			$this->assertLessThan(
				$multiplier_sets['balanced'],
				$multiplier_sets['conservative'],
				'Conservative preset should have lower multiplier than balanced'
			);
		}

		// Aggressive should have higher multiplier than balanced.
		if ( isset( $multiplier_sets['aggressive'] ) && isset( $multiplier_sets['balanced'] ) ) {
			$this->assertGreaterThan(
				$multiplier_sets['balanced'],
				$multiplier_sets['aggressive'],
				'Aggressive preset should have higher multiplier than balanced'
			);
		}
	}

	/**
	 * Test that get_tool_categories returns filtered categories.
	 */
	public function test_get_tool_categories_supports_filters() {
		// Add a filter to add a test tool to a category.
		add_filter(
			'wp_mcp_ai_tool_categories',
			function ( $categories ) {
				if ( isset( $categories['low_resource'] ) ) {
					$categories['low_resource']['tools'][] = 'test_filtered_tool';
				}
				return $categories;
			}
		);

		$categories = WP_MCP_AI_Tool_Recommendations::get_tool_categories();

		$this->assertIsArray( $categories );
		$this->assertArrayHasKey( 'low_resource', $categories );
		$this->assertContains( 'test_filtered_tool', $categories['low_resource']['tools'] );

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_tool_categories' );
	}
}
