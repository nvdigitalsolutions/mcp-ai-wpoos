<?php
/**
 * Test Profession Integration with Assistants
 *
 * Tests that professions are properly integrated when testing via the Test Profession page.
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

		// Test with profession_ prefix without associated assistant - should return 0 now (changed behavior).
		$result = $method->invoke( $this->rest_controller, 'profession_' . $this->profession_id );
		$this->assertEquals( 0, $result, 'Should return 0 when profession has no associated assistant' );

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
	 * Now tests append behavior when assistant has base knowledge.
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

		// Verify profession knowledge is APPENDED to assistant's base knowledge.
		$this->assertStringContainsString( 'Assistant system prompt', $result['system_prompt'], 'Should retain assistant base knowledge' );
		$this->assertStringContainsString( 'professional tax advisor', $result['system_prompt'], 'Should append profession knowledge' );
		$this->assertStringContainsString( 'Professional Role & Expertise:', $result['system_prompt'], 'Should include profession section header' );

		// Verify tools are MERGED (both assistant and profession tools).
		$this->assertContains( 'assistant_tool', $result['tools'], 'Should keep assistant tools' );
		$this->assertContains( 'search', $result['tools'], 'Should add profession tools' );
		$this->assertContains( 'calculator', $result['tools'], 'Should add profession tools' );

		// Verify profession settings take priority for model/provider/temperature.
		$this->assertEquals( 'openai', $result['provider'], 'Profession provider should override assistant provider' );
		$this->assertEquals( 'gpt-4', $result['model'], 'Profession model should override assistant model' );
		$this->assertEquals( 0.7, $result['temperature'], 'Profession temperature should override assistant temperature' );
	}

	/**
	 * Test profession with associated assistant uses that assistant and appends profession data.
	 */
	public function test_profession_with_associated_assistant() {
		// Create a specific assistant to associate with the profession.
		$associated_assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Tax Law Assistant',
				'post_content' => 'Specialized tax law assistant',
				'post_status'  => 'publish',
			)
		);

		// Set assistant's base configuration.
		update_post_meta( $associated_assistant_id, '_wp_mcp_ai_assistant_system_prompt', 'You are a helpful AI assistant with general knowledge.' );
		update_post_meta( $associated_assistant_id, '_wp_mcp_ai_assistant_tools', array( 'general_tool' ) );

		// Associate the assistant with the profession.
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_associated_assistant', $associated_assistant_id );

		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'resolve_assistant_id' );
		$method->setAccessible( true );

		// Test that resolve_assistant_id returns the associated assistant.
		$result = $method->invoke( $this->rest_controller, 'profession_' . $this->profession_id );
		$this->assertEquals( $associated_assistant_id, $result, 'Should return associated assistant ID' );

		// Test that profession data is appended to assistant's base knowledge.
		$load_method = $reflection->getMethod( 'load_profession_configuration' );
		$load_method->setAccessible( true );

		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $associated_assistant_id );
		$merged_config    = $load_method->invoke( $this->rest_controller, $this->profession_id, $assistant_config );

		// Verify assistant base knowledge is preserved and profession knowledge is appended.
		$this->assertStringContainsString( 'helpful AI assistant', $merged_config['system_prompt'], 'Should keep assistant base knowledge' );
		$this->assertStringContainsString( 'professional tax advisor', $merged_config['system_prompt'], 'Should append profession knowledge' );

		// Verify tools are merged.
		$this->assertContains( 'general_tool', $merged_config['tools'], 'Should keep assistant tools' );
		$this->assertContains( 'search', $merged_config['tools'], 'Should add profession tools' );

		// Clean up.
		wp_delete_post( $associated_assistant_id, true );
	}

	/**
	 * Test profession without associated assistant uses default assistant when available.
	 */
	public function test_profession_without_assistant_uses_default() {
		// Ensure default assistant is set.
		update_option( 'wp_mcp_ai_default_assistant', $this->assistant_id );

		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'resolve_assistant_id' );
		$method->setAccessible( true );

		// Test that resolve_assistant_id returns default assistant.
		$result = $method->invoke( $this->rest_controller, 'profession_' . $this->profession_id );
		$this->assertEquals( $this->assistant_id, $result, 'Should return default assistant when no associated assistant' );

		// Test that profession configuration is merged with default assistant config.
		$load_method = $reflection->getMethod( 'load_profession_configuration' );
		$load_method->setAccessible( true );

		// Get default assistant config.
		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );
		$merged_config    = $load_method->invoke( $this->rest_controller, $this->profession_id, $assistant_config );

		// Verify profession knowledge is appended to assistant knowledge.
		$this->assertStringContainsString( 'Professional Role & Expertise:', $merged_config['system_prompt'], 'Should have append header when using default assistant' );
		$this->assertStringContainsString( 'professional tax advisor', $merged_config['system_prompt'], 'Should append profession knowledge' );
	}

	/**
	 * Test profession without associated assistant and no default assistant returns 0.
	 */
	public function test_profession_without_assistant_no_default() {
		// Remove default assistant setting.
		delete_option( 'wp_mcp_ai_default_assistant' );

		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'resolve_assistant_id' );
		$method->setAccessible( true );

		// Test that resolve_assistant_id returns 0 (no assistant).
		$result = $method->invoke( $this->rest_controller, 'profession_' . $this->profession_id );
		$this->assertEquals( 0, $result, 'Should return 0 when no associated assistant and no default' );

		// Test that profession configuration works with empty assistant config.
		$load_method = $reflection->getMethod( 'load_profession_configuration' );
		$load_method->setAccessible( true );

		$empty_config  = array(); // Empty assistant config.
		$merged_config = $load_method->invoke( $this->rest_controller, $this->profession_id, $empty_config );

		// Verify profession knowledge is used as primary system prompt.
		$this->assertStringContainsString( 'professional tax advisor', $merged_config['system_prompt'], 'Should use profession knowledge as primary' );
		$this->assertNotStringContainsString( 'Professional Role & Expertise:', $merged_config['system_prompt'], 'Should NOT have append header when no base assistant' );

		// Verify profession tools are used.
		$this->assertContains( 'search', $merged_config['tools'], 'Should use profession tools' );
		$this->assertContains( 'calculator', $merged_config['tools'], 'Should use profession tools' );

		// Restore default assistant for other tests.
		update_option( 'wp_mcp_ai_default_assistant', $this->assistant_id );
	}
}
