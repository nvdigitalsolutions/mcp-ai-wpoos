<?php
/**
 * Test Profession Integration with Assistants
 *
 * Tests that professions are properly integrated when testing via the Test Profession page.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test profession integration with assistants.
 */
class Test_Profession_Integration extends WP_UnitTestCase {

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Test profession ID.
	 *
	 * @var int
	 */
	protected $profession_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

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

		// Create test profession.
		$this->profession_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Test Tax Advisor',
				'post_content' => 'A tax advisory professional',
				'post_status'  => 'publish',
			)
		);

		// Set profession meta data.
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_role_description', 'You are a professional tax advisor specializing in tax law and compliance.' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_knowledge_base', 'Tax laws vary by jurisdiction. Always recommend consulting with a licensed professional.' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_default_tools', array( 'search', 'calculator' ) );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_default_provider', 'openai' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_default_model', 'gpt-4' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_default_temperature', 0.7 );

		// Create test assistant (default assistant).
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Default Assistant',
				'post_content' => 'Default assistant for testing',
				'post_status'  => 'publish',
			)
		);

		// Set as default assistant in settings.
		update_option( 'wp_mcp_ai_default_assistant', $this->assistant_id );

		// Get REST controller instance using reflection to access protected methods.
		$this->rest_controller = new WP_MCP_AI_REST();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up.
		wp_delete_post( $this->profession_id, true );
		wp_delete_post( $this->assistant_id, true );
		delete_option( 'wp_mcp_ai_default_assistant' );

		parent::tearDown();
	}

	/**
	 * Test that extract_profession_id correctly extracts profession ID from string.
	 */
	public function test_extract_profession_id() {
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'extract_profession_id' );
		$method->setAccessible( true );

		// Test with valid profession_ prefix.
		$result = $method->invoke( $this->rest_controller, 'profession_' . $this->profession_id );
		$this->assertEquals( $this->profession_id, $result, 'Should extract profession ID from string' );

		// Test with invalid format.
		$result = $method->invoke( $this->rest_controller, 'invalid_format' );
		$this->assertFalse( $result, 'Should return false for invalid format' );

		// Test with numeric ID.
		$result = $method->invoke( $this->rest_controller, 123 );
		$this->assertFalse( $result, 'Should return false for numeric ID' );

		// Test with non-existent profession ID.
		$result = $method->invoke( $this->rest_controller, 'profession_99999' );
		$this->assertFalse( $result, 'Should return false for non-existent profession' );
	}

	/**
	 * Test that resolve_assistant_id handles profession_ prefix correctly.
	 */
	public function test_resolve_assistant_id_with_profession() {
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'resolve_assistant_id' );
		$method->setAccessible( true );

		// Test with profession_ prefix returns default assistant.
		$result = $method->invoke( $this->rest_controller, 'profession_' . $this->profession_id );
		$this->assertEquals( $this->assistant_id, $result, 'Should return default assistant ID when profession_ prefix is used' );

		// Test with regular numeric ID.
		$result = $method->invoke( $this->rest_controller, $this->assistant_id );
		$this->assertEquals( $this->assistant_id, $result, 'Should return same assistant ID for numeric input' );
	}

	/**
	 * Test that load_profession_configuration merges profession data correctly.
	 */
	public function test_load_profession_configuration() {
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'load_profession_configuration' );
		$method->setAccessible( true );

		// Base assistant configuration (empty).
		$assistant_config = array(
			'tools'         => array(),
			'system_prompt' => '',
			'provider'      => 'gemini',
			'model'         => 'gemini-pro',
			'temperature'   => 0.5,
		);

		// Load profession configuration.
		$result = $method->invoke( $this->rest_controller, $this->profession_id, $assistant_config );

		// Verify system prompt was built from profession data.
		$this->assertStringContainsString( 'professional tax advisor', $result['system_prompt'], 'System prompt should contain role description' );
		$this->assertStringContainsString( 'Knowledge Base:', $result['system_prompt'], 'System prompt should contain knowledge base label' );
		$this->assertStringContainsString( 'Tax laws vary by jurisdiction', $result['system_prompt'], 'System prompt should contain knowledge base content' );

		// Verify tools were set from profession.
		$this->assertIsArray( $result['tools'], 'Tools should be an array' );
		$this->assertContains( 'search', $result['tools'], 'Tools should contain search' );
		$this->assertContains( 'calculator', $result['tools'], 'Tools should contain calculator' );

		// Verify provider, model, and temperature were set from profession.
		$this->assertEquals( 'openai', $result['provider'], 'Provider should be set from profession' );
		$this->assertEquals( 'gpt-4', $result['model'], 'Model should be set from profession' );
		$this->assertEquals( 0.7, $result['temperature'], 'Temperature should be set from profession' );
	}

	/**
	 * Test that load_profession_configuration doesn't override when profession data is empty.
	 */
	public function test_load_profession_configuration_preserves_assistant_config() {
		// Create a profession with no custom data.
		$empty_profession_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Empty Profession',
				'post_content' => '',
				'post_status'  => 'publish',
			)
		);

		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'load_profession_configuration' );
		$method->setAccessible( true );

		// Base assistant configuration.
		$assistant_config = array(
			'tools'         => array( 'existing_tool' ),
			'system_prompt' => 'Existing system prompt',
			'provider'      => 'gemini',
			'model'         => 'gemini-pro',
			'temperature'   => 0.5,
		);

		// Load profession configuration.
		$result = $method->invoke( $this->rest_controller, $empty_profession_id, $assistant_config );

		// Verify original config is preserved when profession has no data.
		$this->assertEquals( 'Existing system prompt', $result['system_prompt'], 'System prompt should be preserved' );
		$this->assertEquals( array( 'existing_tool' ), $result['tools'], 'Tools should be preserved' );
		$this->assertEquals( 'gemini', $result['provider'], 'Provider should be preserved' );

		// Clean up.
		wp_delete_post( $empty_profession_id, true );
	}

	/**
	 * Test that profession configuration takes priority over assistant defaults.
	 */
	public function test_profession_configuration_priority() {
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'load_profession_configuration' );
		$method->setAccessible( true );

		// Base assistant configuration with defaults.
		$assistant_config = array(
			'tools'         => array( 'assistant_tool' ),
			'system_prompt' => 'Assistant system prompt',
			'provider'      => 'gemini',
			'model'         => 'gemini-pro',
			'temperature'   => 0.5,
		);

		// Load profession configuration.
		$result = $method->invoke( $this->rest_controller, $this->profession_id, $assistant_config );

		// Verify profession data overrides assistant defaults.
		$this->assertNotEquals( 'Assistant system prompt', $result['system_prompt'], 'Profession system prompt should override assistant' );
		$this->assertNotEquals( array( 'assistant_tool' ), $result['tools'], 'Profession tools should override assistant tools' );
		$this->assertEquals( 'openai', $result['provider'], 'Profession provider should override assistant provider' );
		$this->assertEquals( 'gpt-4', $result['model'], 'Profession model should override assistant model' );
		$this->assertEquals( 0.7, $result['temperature'], 'Profession temperature should override assistant temperature' );
	}
}
