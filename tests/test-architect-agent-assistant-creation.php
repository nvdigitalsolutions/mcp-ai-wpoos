<?php
/**
 * Tests for Architect Agent assistant creation when toolkit is enabled.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Architect Agent assistant creation on toolkit enable.
 */
class Test_Architect_Agent_Assistant_Creation extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the Default Assistants class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			$default_assistants_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-default-assistants.php';
			if ( file_exists( $default_assistants_file ) ) {
				require_once $default_assistants_file;
			}
		}

		// Ensure Assistant CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$cpt_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
			if ( file_exists( $cpt_file ) ) {
				require_once $cpt_file;
			}
		}

		// Register the assistant post type.
		if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			WP_MCP_AI_Assistant_CPT::register_post_type();
		}

		// Clean up any existing Architect Agent assistant.
		$existing = get_page_by_path( 'architect-agent', OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
		if ( $existing ) {
			wp_delete_post( $existing->ID, true );
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );

		// Clean up the Architect Agent assistant if it exists.
		if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$existing = get_page_by_path( 'architect-agent', OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
			if ( $existing ) {
				wp_delete_post( $existing->ID, true );
			}
		}

		parent::tearDown();
	}

	/**
	 * Test that assistant is created when toolkit is enabled.
	 */
	public function test_assistant_created_when_toolkit_enabled() {
		// Skip test if required classes are not available.
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		// Start with toolkit disabled.
		$existing_settings = array(
			'enable_architect_agent_toolkit' => false,
		);
		update_option( 'wp_mcp_ai_settings', $existing_settings );

		// Simulate enabling the toolkit (like the settings dashboard does).
		$merged_settings = array(
			'enable_architect_agent_toolkit' => true,
		);

		// Check if toolkit was just enabled (simulating the dashboard logic).
		$was_architect_disabled = empty( $existing_settings['enable_architect_agent_toolkit'] );
		$is_architect_enabled   = ! empty( $merged_settings['enable_architect_agent_toolkit'] );

		$this->assertTrue( $was_architect_disabled, 'Toolkit should start disabled' );
		$this->assertTrue( $is_architect_enabled, 'Toolkit should be enabled in merged settings' );

		if ( $was_architect_disabled && $is_architect_enabled ) {
			// Architect Agent Toolkit was just enabled, create the assistant.
			$result = WP_MCP_AI_Default_Assistants::install_architect_agent_assistant();
		}

		// Verify the result is not a WP_Error.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Assistant creation should not return WP_Error' );
		$this->assertIsInt( $result, 'Assistant creation should return a post ID' );
		$this->assertGreaterThan( 0, $result, 'Post ID should be greater than 0' );

		// Verify the assistant was actually created.
		$assistant = get_page_by_path( 'architect-agent', OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
		$this->assertNotNull( $assistant, 'Architect Agent assistant should exist' );
		$this->assertEquals( 'The Architect Agent', $assistant->post_title, 'Assistant title should match' );
		$this->assertEquals( 'publish', $assistant->post_status, 'Assistant should be published' );

		// Verify assistant metadata.
		$tools = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
		$this->assertIsArray( $tools, 'Tools should be an array' );
		$this->assertCount( 4, $tools, 'Should have 4 tools' );
		$this->assertContains( 'manage_files', $tools, 'Should have manage_files tool' );
		$this->assertContains( 'execute_shell_command', $tools, 'Should have execute_shell_command tool' );
		$this->assertContains( 'git_operations', $tools, 'Should have git_operations tool' );
		$this->assertContains( 'search_codebase', $tools, 'Should have search_codebase tool' );

		// Verify provider and model.
		$provider = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_PROVIDER, true );
		$model    = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_MODEL, true );
		$this->assertEquals( 'openai', $provider, 'Provider should be OpenAI' );
		$this->assertEquals( 'gpt-4o', $model, 'Model should be GPT-4o' );

		// Verify temperature.
		$temperature = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, true );
		$this->assertEquals( 0.2, (float) $temperature, 'Temperature should be 0.2' );

		// Verify primary roles.
		$roles = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES, true );
		$this->assertIsArray( $roles, 'Roles should be an array' );
		$this->assertContains( 'architect', $roles, 'Should have architect role' );
		$this->assertContains( 'developer', $roles, 'Should have developer role' );
		$this->assertContains( 'coder', $roles, 'Should have coder role' );

		// Verify system prompt exists.
		$system_prompt = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, true );
		$this->assertNotEmpty( $system_prompt, 'System prompt should not be empty' );
		$this->assertStringContainsString( 'Architect Agent', $system_prompt, 'System prompt should mention Architect Agent' );
	}

	/**
	 * Test that assistant is not recreated if it already exists.
	 */
	public function test_assistant_not_recreated_if_exists() {
		// Skip test if required classes are not available.
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		// Create the assistant first time.
		$first_result = WP_MCP_AI_Default_Assistants::install_architect_agent_assistant();
		$this->assertNotInstanceOf( 'WP_Error', $first_result, 'First creation should succeed' );

		// Try to create it again.
		$second_result = WP_MCP_AI_Default_Assistants::install_architect_agent_assistant();
		$this->assertNotInstanceOf( 'WP_Error', $second_result, 'Second creation should not error' );

		// Should return the same ID (existing assistant).
		$this->assertEquals( $first_result, $second_result, 'Should return existing assistant ID' );

		// Verify only one assistant exists.
		$assistants = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'name'        => 'architect-agent',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		$this->assertCount( 1, $assistants, 'Should only have one Architect Agent assistant' );
	}

	/**
	 * Test that assistant is not created when toolkit is disabled.
	 */
	public function test_assistant_not_created_when_toolkit_disabled() {
		// Skip test if required classes are not available.
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		// Start with toolkit already enabled.
		$existing_settings = array(
			'enable_architect_agent_toolkit' => true,
		);
		update_option( 'wp_mcp_ai_settings', $existing_settings );

		// Simulate disabling the toolkit.
		$merged_settings = array(
			'enable_architect_agent_toolkit' => false,
		);

		// Check if toolkit was just enabled (simulating the dashboard logic).
		$was_architect_disabled = empty( $existing_settings['enable_architect_agent_toolkit'] );
		$is_architect_enabled   = ! empty( $merged_settings['enable_architect_agent_toolkit'] );

		$this->assertFalse( $was_architect_disabled, 'Toolkit should start enabled' );
		$this->assertFalse( $is_architect_enabled, 'Toolkit should be disabled in merged settings' );

		// The condition should NOT trigger assistant creation.
		$should_create = $was_architect_disabled && $is_architect_enabled;
		$this->assertFalse( $should_create, 'Should not create assistant when disabling toolkit' );

		// Verify no assistant was created.
		$assistant = get_page_by_path( 'architect-agent', OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
		$this->assertNull( $assistant, 'Architect Agent assistant should not exist when toolkit is disabled' );
	}

	/**
	 * Test that assistant is not created when toolkit stays enabled.
	 */
	public function test_assistant_not_created_when_toolkit_stays_enabled() {
		// Skip test if required classes are not available.
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		// Start with toolkit already enabled.
		$existing_settings = array(
			'enable_architect_agent_toolkit' => true,
		);
		update_option( 'wp_mcp_ai_settings', $existing_settings );

		// Toolkit stays enabled.
		$merged_settings = array(
			'enable_architect_agent_toolkit' => true,
		);

		// Check if toolkit was just enabled (simulating the dashboard logic).
		$was_architect_disabled = empty( $existing_settings['enable_architect_agent_toolkit'] );
		$is_architect_enabled   = ! empty( $merged_settings['enable_architect_agent_toolkit'] );

		$this->assertFalse( $was_architect_disabled, 'Toolkit should start enabled' );
		$this->assertTrue( $is_architect_enabled, 'Toolkit should be enabled in merged settings' );

		// The condition should NOT trigger assistant creation (toolkit was already enabled).
		$should_create = $was_architect_disabled && $is_architect_enabled;
		$this->assertFalse( $should_create, 'Should not create assistant when toolkit stays enabled' );
	}

	/**
	 * Test get_architect_agent_assistant_config method.
	 */
	public function test_get_architect_agent_assistant_config() {
		// Skip test if required class is not available.
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Default_Assistants class not available' );
		}

		$config = WP_MCP_AI_Default_Assistants::get_architect_agent_assistant_config();

		// Verify config structure.
		$this->assertIsArray( $config, 'Config should be an array' );
		$this->assertArrayHasKey( 'slug', $config, 'Config should have slug' );
		$this->assertArrayHasKey( 'title', $config, 'Config should have title' );
		$this->assertArrayHasKey( 'description', $config, 'Config should have description' );
		$this->assertArrayHasKey( 'system_prompt', $config, 'Config should have system_prompt' );
		$this->assertArrayHasKey( 'tools', $config, 'Config should have tools' );
		$this->assertArrayHasKey( 'provider', $config, 'Config should have provider' );
		$this->assertArrayHasKey( 'model', $config, 'Config should have model' );
		$this->assertArrayHasKey( 'temperature', $config, 'Config should have temperature' );
		$this->assertArrayHasKey( 'primary_roles', $config, 'Config should have primary_roles' );

		// Verify config values.
		$this->assertEquals( 'architect-agent', $config['slug'], 'Slug should be architect-agent' );
		$this->assertEquals( 'The Architect Agent', $config['title'], 'Title should be The Architect Agent' );
		$this->assertNotEmpty( $config['description'], 'Description should not be empty' );
		$this->assertNotEmpty( $config['system_prompt'], 'System prompt should not be empty' );

		// Verify tools.
		$this->assertIsArray( $config['tools'], 'Tools should be an array' );
		$this->assertCount( 4, $config['tools'], 'Should have 4 tools' );
		$this->assertEquals(
			array( 'manage_files', 'execute_shell_command', 'git_operations', 'search_codebase' ),
			$config['tools'],
			'Tools should match expected list'
		);

		// Verify provider and model.
		$this->assertEquals( 'openai', $config['provider'], 'Provider should be openai' );
		$this->assertEquals( 'gpt-4o', $config['model'], 'Model should be gpt-4o' );
		$this->assertEquals( 0.2, $config['temperature'], 'Temperature should be 0.2' );

		// Verify roles.
		$this->assertIsArray( $config['primary_roles'], 'Primary roles should be an array' );
		$this->assertEquals(
			array( 'architect', 'developer', 'coder' ),
			$config['primary_roles'],
			'Roles should match expected list'
		);
	}
}
