<?php
/**
 * Tests for the Assistant Builder default assistant.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the Assistant Builder default assistant config and installation.
 */
class Test_Assistant_Builder_Assistant extends WP_UnitTestCase {

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

		// Clean up any existing Assistant Builder assistant.
		$existing = get_page_by_path( 'assistant-builder', OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
		if ( $existing ) {
			wp_delete_post( $existing->ID, true );
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) && class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			// Delete any Assistant Builder post created during the test.
			$existing = get_page_by_path( 'assistant-builder', OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
			if ( $existing ) {
				wp_delete_post( $existing->ID, true );

				// Remove the created ID from the install-tracking option so this
				// test does not leak stale IDs into other suites' uninstall/reinstall.
				$info = get_option( WP_MCP_AI_Default_Assistants::INSTALLED_OPTION, array() );
				if ( is_array( $info ) && isset( $info['assistant_ids'] ) && is_array( $info['assistant_ids'] ) ) {
					$info['assistant_ids'] = array_values(
						array_filter(
							$info['assistant_ids'],
							function ( $id ) use ( $existing ) {
								return (int) $id !== (int) $existing->ID;
							}
						)
					);
					update_option( WP_MCP_AI_Default_Assistants::INSTALLED_OPTION, $info );
				}
			}
		}

		delete_option( 'wp_mcp_ai_assistant_builder_backfilled' );

		parent::tearDown();
	}

	/**
	 * Test the assistant builder config structure and values.
	 */
	public function test_get_assistant_builder_assistant_config() {
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Default_Assistants class not available' );
		}

		$config = WP_MCP_AI_Default_Assistants::get_assistant_builder_assistant_config();

		// Verify config structure.
		$this->assertIsArray( $config, 'Config should be an array' );
		foreach ( array( 'slug', 'title', 'description', 'system_prompt', 'tools', 'provider', 'model', 'temperature', 'primary_roles' ) as $key ) {
			$this->assertArrayHasKey( $key, $config, 'Config should have ' . $key );
		}

		// Verify core values.
		$this->assertEquals( 'assistant-builder', $config['slug'], 'Slug should be assistant-builder' );
		$this->assertEquals( 'The Assistant Builder', $config['title'], 'Title should be The Assistant Builder' );
		$this->assertNotEmpty( $config['description'], 'Description should not be empty' );
		$this->assertNotEmpty( $config['system_prompt'], 'System prompt should not be empty' );
		$this->assertEquals( 'openai', $config['provider'], 'Provider should be openai' );
		$this->assertEquals( 'gpt-4.1', $config['model'], 'Model should be gpt-4.1' );
		$this->assertEquals( 0.3, $config['temperature'], 'Temperature should be 0.3' );

		// Verify tools.
		$this->assertIsArray( $config['tools'], 'Tools should be an array' );
		foreach ( array( 'list_mcp_tools', 'create_assistant', 'duplicate_assistant', 'export_assistant', 'import_assistant', 'probe_chat', 'suggest_best_model' ) as $slug ) {
			$this->assertContains( $slug, $config['tools'], 'Tools should include ' . $slug );
		}

		// Verify roles.
		$this->assertEquals(
			array( 'assistant-builder', 'prompt-engineer', 'meta-agent' ),
			$config['primary_roles'],
			'Roles should match expected list'
		);
	}

	/**
	 * Test that the assistant builder is part of the default roster.
	 */
	public function test_assistant_builder_included_in_default_roster() {
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Default_Assistants class not available' );
		}

		$roster  = WP_MCP_AI_Default_Assistants::get_default_assistants();
		$slugs   = wp_list_pluck( $roster, 'slug' );
		$builder = wp_list_filter( $roster, array( 'slug' => 'assistant-builder' ) );

		$this->assertContains( 'assistant-builder', $slugs, 'Roster should include the Assistant Builder' );
		$this->assertCount( 1, $builder, 'Roster should include exactly one Assistant Builder config' );
	}

	/**
	 * Test the prompt encodes the core design standards.
	 */
	public function test_prompt_contains_industry_standards() {
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Default_Assistants class not available' );
		}

		$config = WP_MCP_AI_Default_Assistants::get_assistant_builder_assistant_config();
		$prompt = $config['system_prompt'];

		$required_phrases = array(
			'The Assistant Builder',
			'PHASE 1',
			'PHASE 7',
			'TOOL SELECTION STANDARDS',
			'SYSTEM PROMPT AUTHORING FRAMEWORK',
			'GUARDRAILS',
			'list_mcp_tools',
			'create_assistant',
			'probe_chat',
			'least privilege',
		);

		foreach ( $required_phrases as $phrase ) {
			$this->assertStringContainsStringIgnoringCase( $phrase, $prompt, 'Prompt should mention: ' . $phrase );
		}
	}

	/**
	 * Test that the assistant is created with correct metadata.
	 */
	public function test_install_assistant_builder_assistant() {
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		$result = WP_MCP_AI_Default_Assistants::install_assistant_builder_assistant();

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Installation should not return WP_Error' );
		$this->assertIsInt( $result, 'Installation should return a post ID' );
		$this->assertGreaterThan( 0, $result, 'Post ID should be greater than 0' );

		$assistant = get_page_by_path( 'assistant-builder', OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
		$this->assertNotNull( $assistant, 'Assistant Builder assistant should exist' );
		$this->assertEquals( 'The Assistant Builder', $assistant->post_title, 'Assistant title should match' );
		$this->assertEquals( 'publish', $assistant->post_status, 'Assistant should be published' );

		// Verify metadata.
		$provider = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_PROVIDER, true );
		$model    = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_MODEL, true );
		$this->assertEquals( 'openai', $provider, 'Provider should be OpenAI' );
		$this->assertEquals( 'gpt-4.1', $model, 'Model should be gpt-4.1' );

		$temperature = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, true );
		$this->assertEquals( 0.3, (float) $temperature, 'Temperature should be 0.3' );

		$tools = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
		$this->assertIsArray( $tools, 'Tools should be an array' );
		$this->assertContains( 'create_assistant', $tools, 'Tools should include create_assistant' );
		$this->assertContains( 'list_mcp_tools', $tools, 'Tools should include list_mcp_tools' );

		$system_prompt = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, true );
		$this->assertNotEmpty( $system_prompt, 'System prompt should not be empty' );

		// The ID should be tracked in the install option.
		$info = get_option( WP_MCP_AI_Default_Assistants::INSTALLED_OPTION, array() );
		$this->assertIsArray( $info, 'Install option should be an array' );
		$this->assertContains( $result, $info['assistant_ids'], 'Install option should track the assistant ID' );
	}

	/**
	 * Test that installation is idempotent.
	 */
	public function test_install_assistant_builder_assistant_is_idempotent() {
		if ( ! class_exists( 'WP_MCP_AI_Default_Assistants' ) || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		$first_result = WP_MCP_AI_Default_Assistants::install_assistant_builder_assistant();
		$this->assertNotInstanceOf( 'WP_Error', $first_result, 'First installation should succeed' );

		$second_result = WP_MCP_AI_Default_Assistants::install_assistant_builder_assistant();
		$this->assertNotInstanceOf( 'WP_Error', $second_result, 'Second installation should not error' );
		$this->assertEquals( $first_result, $second_result, 'Second installation should return the existing ID' );

		$assistants = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'name'        => 'assistant-builder',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		$this->assertCount( 1, $assistants, 'Only one Assistant Builder post should exist' );
	}
}
