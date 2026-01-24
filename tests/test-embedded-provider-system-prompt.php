<?php
/**
 * Test Embedded Provider System Prompt Configuration
 *
 * Tests that system prompts, professional roles, and base knowledge are correctly
 * passed to the embedded LLM client for client-side execution.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Embedded Provider System Prompt.
 */
class Test_Embedded_Provider_System_Prompt extends WP_UnitTestCase {

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
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
		}

		// Create test profession with knowledge base.
		$this->profession_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Test Software Developer',
				'post_content' => 'Software development expertise',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_role_description', 'You are a professional software developer with expertise in multiple programming languages.' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_knowledge_base', 'Understanding of design patterns, clean code principles, and testing methodologies is essential. Follow SOLID principles and write maintainable code.' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_expertise', array( 'JavaScript', 'PHP', 'Python', 'Design Patterns' ) );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_warnings', array( 'Always validate user input', 'Follow security best practices' ) );

		// Create test assistant with embedded provider and primary roles.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Test Embedded Assistant',
				'post_content' => 'Test assistant for embedded provider',
				'post_status'  => 'publish',
			)
		);

		// Set provider to embedded.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_model', 'Llama-3.2-1B-Instruct-q4f16_1-MLC' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_temperature', 0.7 );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_system_prompt', 'You are a helpful AI assistant. Always provide clear and accurate responses.' );

		// Assign primary role to assistant.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_primary_roles', array( $this->profession_id ) );

		// Assign some tools.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_tools',
			array( 'get_recent_posts', 'search_content' )
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		wp_delete_post( $this->profession_id, true );
		wp_delete_post( $this->assistant_id, true );
		parent::tearDown();
	}

	/**
	 * Test that get_assistant_configuration includes system_prompt with primary roles.
	 */
	public function test_get_assistant_configuration_includes_primary_roles() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'system_prompt', $config );
		$this->assertNotEmpty( $config['system_prompt'] );

		// Should contain profession title.
		$this->assertStringContainsString( 'Test Software Developer', $config['system_prompt'] );

		// Should contain role description.
		$this->assertStringContainsString( 'professional software developer', $config['system_prompt'] );

		// Should contain knowledge base.
		$this->assertStringContainsString( 'design patterns', $config['system_prompt'] );
		$this->assertStringContainsString( 'SOLID principles', $config['system_prompt'] );

		// Should contain expertise areas.
		$this->assertStringContainsString( 'JavaScript', $config['system_prompt'] );
		$this->assertStringContainsString( 'PHP', $config['system_prompt'] );

		// Should contain warnings.
		$this->assertStringContainsString( 'validate user input', $config['system_prompt'] );

		// Should also contain the custom system prompt appended.
		$this->assertStringContainsString( 'helpful AI assistant', $config['system_prompt'] );
	}

	/**
	 * Test that provider and model are included in configuration.
	 */
	public function test_get_assistant_configuration_includes_provider_and_model() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertArrayHasKey( 'provider', $config );
		$this->assertEquals( 'embedded', $config['provider'] );

		$this->assertArrayHasKey( 'model', $config );
		$this->assertEquals( 'Llama-3.2-1B-Instruct-q4f16_1-MLC', $config['model'] );
	}

	/**
	 * Test that temperature is included in configuration.
	 */
	public function test_get_assistant_configuration_includes_temperature() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertArrayHasKey( 'temperature', $config );
		$this->assertEquals( 0.7, $config['temperature'] );
	}

	/**
	 * Test that tools are included in configuration.
	 */
	public function test_get_assistant_configuration_includes_tools() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertArrayHasKey( 'tools', $config );
		$this->assertIsArray( $config['tools'] );
		$this->assertContains( 'get_recent_posts', $config['tools'] );
		$this->assertContains( 'search_content', $config['tools'] );
	}

	/**
	 * Test system prompt structure when primary roles are set.
	 */
	public function test_system_prompt_structure_with_primary_roles() {
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );
		$system_prompt = $config['system_prompt'];

		// Should start with roles section.
		$this->assertStringContainsString( '# Your Roles and Capabilities', $system_prompt );

		// Should have role description section.
		$this->assertStringContainsString( '## Role:', $system_prompt );

		// Should have knowledge base section.
		$this->assertStringContainsString( '### Knowledge Base', $system_prompt );

		// Should have expertise section.
		$this->assertStringContainsString( '### Expertise Areas', $system_prompt );

		// Should have warnings section.
		$this->assertStringContainsString( '### Important Warnings', $system_prompt );

		// Should have additional instructions section with custom prompt.
		$this->assertStringContainsString( '# Additional Instructions', $system_prompt );
	}

	/**
	 * Test assistant without primary roles.
	 */
	public function test_assistant_without_primary_roles() {
		// Create assistant without primary roles.
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Test Assistant No Roles',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', 'Simple system prompt.' );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'system_prompt', $config );
		$this->assertEquals( 'Simple system prompt.', $config['system_prompt'] );

		// Should not contain roles section.
		$this->assertStringNotContainsString( '# Your Roles and Capabilities', $config['system_prompt'] );

		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test assistant with primary roles but no custom system prompt.
	 */
	public function test_assistant_with_roles_no_custom_prompt() {
		// Create assistant with primary roles but no custom system prompt.
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Test Assistant Roles Only',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_primary_roles', array( $this->profession_id ) );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'system_prompt', $config );
		$this->assertNotEmpty( $config['system_prompt'] );

		// Should contain roles section.
		$this->assertStringContainsString( '# Your Roles and Capabilities', $config['system_prompt'] );
		$this->assertStringContainsString( 'Test Software Developer', $config['system_prompt'] );

		// Should NOT contain additional instructions section.
		$this->assertStringNotContainsString( '# Additional Instructions', $config['system_prompt'] );

		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test that empty system prompt is properly handled.
	 */
	public function test_empty_system_prompt() {
		// Create assistant with empty everything.
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Test Assistant Empty',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'embedded' );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertArrayHasKey( 'system_prompt', $config );
		$this->assertEmpty( $config['system_prompt'] );

		wp_delete_post( $assistant_id, true );
	}
}
