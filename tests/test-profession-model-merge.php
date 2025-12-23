<?php
/**
 * Test Profession Model Settings Merge with Associated Assistant
 *
 * Tests that profession model settings (provider, model, temperature) properly
 * override associated assistant settings in the test profession modal.
 *
 * @package WP_MCP_AI
 */

/**
 * Test profession model merge functionality.
 */
class Test_Profession_Model_Merge extends WP_UnitTestCase {

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
		}

		$this->rest_controller = new WP_MCP_AI_REST();
	}

	/**
	 * Test profession temperature of 0 overrides assistant temperature.
	 */
	public function test_profession_temperature_zero_overrides_assistant() {
		// Create profession with temperature = 0.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Profession - Temp Zero',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', 'openai' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', 'gpt-4' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_temperature', 0 );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', 'Test role' );

		// Create assistant with different settings.
		$assistant_config = array(
			'provider'      => 'gemini',
			'model'         => 'gemini-pro',
			'temperature'   => 0.9,
			'system_prompt' => 'Assistant base prompt',
			'tools'         => array( 'search' ),
		);

		// Load profession configuration.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'load_profession_configuration' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $profession_id, $assistant_config );

		// Verify profession settings override assistant settings.
		$this->assertEquals( 'openai', $result['provider'], 'Provider should be overridden by profession' );
		$this->assertEquals( 'gpt-4', $result['model'], 'Model should be overridden by profession' );
		$this->assertEquals( 0.0, $result['temperature'], 'Temperature of 0 should override assistant temperature' );

		// Verify system prompt was merged.
		$this->assertStringContainsString( 'Assistant base prompt', $result['system_prompt'], 'Assistant prompt should be preserved' );
		$this->assertStringContainsString( 'Professional Role & Expertise:', $result['system_prompt'], 'Profession prompt should be appended' );

		// Clean up.
		wp_delete_post( $profession_id, true );
	}

	/**
	 * Test profession with all model settings overrides assistant.
	 */
	public function test_profession_all_settings_override_assistant() {
		// Create profession with full model settings.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Profession - Full Settings',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', 'anthropic' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', 'claude-3-opus' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_temperature', 0.5 );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', 'Test role' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_tools', array( 'calculator' ) );

		// Create assistant with different settings.
		$assistant_config = array(
			'provider'      => 'openai',
			'model'         => 'gpt-4',
			'temperature'   => 0.7,
			'system_prompt' => 'Base instructions',
			'tools'         => array( 'search' ),
		);

		// Load profession configuration.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'load_profession_configuration' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $profession_id, $assistant_config );

		// Verify profession settings override assistant settings.
		$this->assertEquals( 'anthropic', $result['provider'], 'Provider should be overridden' );
		$this->assertEquals( 'claude-3-opus', $result['model'], 'Model should be overridden' );
		$this->assertEquals( 0.5, $result['temperature'], 'Temperature should be overridden' );

		// Verify tools were merged.
		$this->assertContains( 'search', $result['tools'], 'Assistant tools should be preserved' );
		$this->assertContains( 'calculator', $result['tools'], 'Profession tools should be added' );

		// Clean up.
		wp_delete_post( $profession_id, true );
	}

	/**
	 * Test profession without model settings preserves assistant settings.
	 */
	public function test_profession_no_settings_preserves_assistant() {
		// Create profession without model settings (empty strings).
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Profession - No Settings',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', '' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', '' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', 'Test role' );
		// Don't set temperature at all.

		// Create assistant with settings.
		$assistant_config = array(
			'provider'      => 'openai',
			'model'         => 'gpt-4',
			'temperature'   => 0.7,
			'system_prompt' => 'Base instructions',
		);

		// Load profession configuration.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'load_profession_configuration' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rest_controller, $profession_id, $assistant_config );

		// Verify assistant settings are preserved when profession settings are empty.
		$this->assertEquals( 'openai', $result['provider'], 'Provider should be preserved from assistant' );
		$this->assertEquals( 'gpt-4', $result['model'], 'Model should be preserved from assistant' );
		$this->assertEquals( 0.7, $result['temperature'], 'Temperature should be preserved from assistant' );

		// Clean up.
		wp_delete_post( $profession_id, true );
	}
}
