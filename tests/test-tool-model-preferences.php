<?php
/**
 * Tests for tool model preferences functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test tool model preferences functionality.
 */
class Test_Tool_Model_Preferences extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any existing model preferences.
		delete_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION );

		parent::tearDown();
	}

	/**
	 * Test getting default model preference.
	 */
	public function test_get_default_model_preference() {
		$preference = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( 'test_tool' );
		$this->assertEquals( 'default', $preference );
	}

	/**
	 * Test setting and getting model preference.
	 */
	public function test_set_and_get_model_preference() {
		$tool_slug = 'run_crawl4ai_job';
		$model     = 'gpt-4o';

		// Set model preference.
		$result = WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $model );
		$this->assertTrue( $result );

		// Verify preference was set.
		$retrieved_preference = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug );
		$this->assertEquals( $model, $retrieved_preference );
	}

	/**
	 * Test setting multiple model preferences.
	 */
	public function test_set_multiple_model_preferences() {
		$preferences = array(
			'run_crawl4ai_job' => 'gpt-4o-mini',
			'search_content'   => 'claude-3-5-sonnet-20241022',
			'web_search'       => 'gemini-1.5-pro',
		);

		// Set multiple preferences.
		foreach ( $preferences as $tool_slug => $model ) {
			$result = WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $model );
			$this->assertTrue( $result );
		}

		// Verify all preferences were set.
		$all_preferences = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preferences();
		foreach ( $preferences as $tool_slug => $model ) {
			$this->assertArrayHasKey( $tool_slug, $all_preferences );
			$this->assertEquals( $model, $all_preferences[ $tool_slug ] );
		}
	}

	/**
	 * Test updating existing model preference.
	 */
	public function test_update_model_preference() {
		$tool_slug    = 'test_tool';
		$first_model  = 'gpt-4o';
		$second_model = 'claude-3-5-haiku-20241022';

		// Set initial preference.
		WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $first_model );
		$this->assertEquals( $first_model, WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug ) );

		// Update preference.
		WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $second_model );
		$this->assertEquals( $second_model, WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug ) );
	}

	/**
	 * Test resetting to default.
	 */
	public function test_reset_to_default() {
		$tool_slug = 'test_tool';
		$model     = 'gpt-4o';

		// Set a model preference.
		WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $model );
		$this->assertEquals( $model, WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug ) );

		// Reset to default.
		WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, 'default' );
		$this->assertEquals( 'default', WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug ) );
	}

	/**
	 * Test invalid tool slug handling.
	 */
	public function test_invalid_tool_slug() {
		// Empty slug should return false.
		$result = WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( '', 'gpt-4o' );
		$this->assertFalse( $result );

		// Getting preference for empty slug should return 'default'.
		$preference = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( '' );
		$this->assertEquals( 'default', $preference );
	}

	/**
	 * Test get_available_models returns expected structure.
	 */
	public function test_get_available_models_structure() {
		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models();

		// Should always have 'default' option.
		$this->assertArrayHasKey( 'default', $models );
		$this->assertNotEmpty( $models['default'] );

		// Check structure of provider groups if they exist.
		foreach ( $models as $key => $value ) {
			if ( 'default' !== $key && is_array( $value ) ) {
				// Provider groups should have 'label' and 'options'.
				$this->assertArrayHasKey( 'label', $value );
				$this->assertArrayHasKey( 'options', $value );
				$this->assertIsArray( $value['options'] );
			}
		}
	}

	/**
	 * Test filter for available models.
	 */
	public function test_available_models_filter() {
		// Add custom models via filter.
		add_filter(
			'wp_mcp_ai_available_tool_models',
			function ( $models ) {
				$models['custom_group'] = array(
					'label'   => 'Custom Models',
					'options' => array(
						'custom-model' => 'Custom Model',
					),
				);
				return $models;
			}
		);

		$models = WP_MCP_AI_Tool_Token_Limits::get_available_models();

		// Verify custom group was added.
		$this->assertArrayHasKey( 'custom_group', $models );
		$this->assertEquals( 'Custom Models', $models['custom_group']['label'] );
		$this->assertArrayHasKey( 'custom-model', $models['custom_group']['options'] );
	}

	/**
	 * Test filter for model preferences.
	 */
	public function test_model_preferences_filter() {
		// Set some preferences.
		WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( 'tool1', 'gpt-4o' );

		// Add filter to modify preferences.
		add_filter(
			'wp_mcp_ai_all_tool_model_preferences',
			function ( $preferences ) {
				$preferences['tool2'] = 'claude-3-5-sonnet-20241022';
				return $preferences;
			}
		);

		$preferences = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preferences();

		// Verify both stored and filtered preferences exist.
		$this->assertArrayHasKey( 'tool1', $preferences );
		$this->assertArrayHasKey( 'tool2', $preferences );
		$this->assertEquals( 'gpt-4o', $preferences['tool1'] );
		$this->assertEquals( 'claude-3-5-sonnet-20241022', $preferences['tool2'] );
	}

	/**
	 * Test sanitization of model values.
	 */
	public function test_model_value_sanitization() {
		$tool_slug = 'test_tool';

		// Test with special characters.
		$model = 'gpt-4o<script>alert("xss")</script>';
		WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $model );

		$retrieved = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug );

		// Should be sanitized.
		$this->assertStringNotContainsString( '<script>', $retrieved );
		$this->assertStringNotContainsString( 'alert', $retrieved );
	}

	/**
	 * Test persistence across multiple get/set operations.
	 */
	public function test_persistence() {
		$tool_slug = 'persistent_tool';
		$model     = 'gpt-4o';

		// Set preference.
		WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $model );

		// Get preference multiple times to ensure it persists.
		for ( $i = 0; $i < 5; $i++ ) {
			$retrieved = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( $tool_slug );
			$this->assertEquals( $model, $retrieved );
		}

		// Verify it's actually stored in the database.
		$stored_preferences = get_option( WP_MCP_AI_Tool_Token_Limits::MODEL_PREFERENCES_OPTION );
		$this->assertArrayHasKey( $tool_slug, $stored_preferences );
		$this->assertEquals( $model, $stored_preferences[ $tool_slug ] );
	}
}
