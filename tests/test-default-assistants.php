<?php
/**
 * Test default assistants installation.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Default_Assistants
 */
class Test_Default_Assistants extends WP_UnitTestCase {

	/**
	 * Test that default assistants class exists.
	 */
	public function test_default_assistants_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Default_Assistants' ) );
	}

	/**
	 * Test that default assistants configuration is valid.
	 */
	public function test_get_default_assistants_returns_array() {
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();

		$this->assertIsArray( $assistants );
		$this->assertNotEmpty( $assistants );
		$this->assertCount( 6, $assistants, 'Should have 6 default assistants' );
	}

	/**
	 * Test that each assistant has required fields.
	 */
	public function test_assistant_configurations_have_required_fields() {
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();

		foreach ( $assistants as $assistant ) {
			$this->assertArrayHasKey( 'slug', $assistant );
			$this->assertArrayHasKey( 'title', $assistant );
			$this->assertArrayHasKey( 'description', $assistant );
			$this->assertArrayHasKey( 'system_prompt', $assistant );
			$this->assertArrayHasKey( 'tools', $assistant );
			$this->assertArrayHasKey( 'provider', $assistant );
			$this->assertArrayHasKey( 'model', $assistant );
			$this->assertArrayHasKey( 'temperature', $assistant );
			$this->assertArrayHasKey( 'primary_roles', $assistant );
		}
	}

	/**
	 * Test that Orchestrator has correct slug and configuration.
	 */
	public function test_orchestrator_configuration() {
		$assistants   = WP_MCP_AI_Default_Assistants::get_default_assistants();
		$orchestrator = $assistants[0];

		$this->assertEquals( 'orchestrator-supervisor', $orchestrator['slug'] );
		$this->assertEquals( 'openai', $orchestrator['provider'] );
		$this->assertEquals( 'gpt-4o', $orchestrator['model'] );
		$this->assertEquals( 0.3, $orchestrator['temperature'] );
		$this->assertContains( 'supervisor', $orchestrator['primary_roles'] );
		$this->assertIsArray( $orchestrator['tools'] );
		$this->assertNotEmpty( $orchestrator['tools'] );
	}

	/**
	 * Test that all 6 assistants have unique slugs.
	 */
	public function test_all_assistants_have_unique_slugs() {
		$assistants   = WP_MCP_AI_Default_Assistants::get_default_assistants();
		$slugs        = array_column( $assistants, 'slug' );
		$unique_slugs = array_unique( $slugs );

		$this->assertCount( 6, $unique_slugs, 'All assistants should have unique slugs' );
	}

	/**
	 * Test expected assistant slugs.
	 */
	public function test_expected_assistant_slugs() {
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();
		$slugs      = array_column( $assistants, 'slug' );

		$expected_slugs = array(
			'orchestrator-supervisor',
			'research-operative',
			'unstructured-parser',
			'content-drafter',
			'seo-compliance-auditor',
			'publisher-terminal',
		);

		foreach ( $expected_slugs as $expected_slug ) {
			$this->assertContains( $expected_slug, $slugs, "Missing expected assistant: {$expected_slug}" );
		}
	}

	/**
	 * Test that tools are arrays and not empty.
	 */
	public function test_assistants_have_tools() {
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();

		foreach ( $assistants as $assistant ) {
			$this->assertIsArray( $assistant['tools'], "Tools for {$assistant['slug']} should be an array" );
			$this->assertNotEmpty( $assistant['tools'], "Tools for {$assistant['slug']} should not be empty" );
		}
	}

	/**
	 * Test that system prompts are not empty.
	 */
	public function test_assistants_have_system_prompts() {
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();

		foreach ( $assistants as $assistant ) {
			$this->assertNotEmpty( $assistant['system_prompt'], "System prompt for {$assistant['slug']} should not be empty" );
			$this->assertGreaterThan( 100, strlen( $assistant['system_prompt'] ), "System prompt for {$assistant['slug']} should be substantial" );
		}
	}

	/**
	 * Test assistant installation.
	 */
	public function test_install_default_assistants() {
		// Clean up any existing assistants first.
		WP_MCP_AI_Default_Assistants::uninstall();

		// Install default assistants.
		$result = WP_MCP_AI_Default_Assistants::install();

		$this->assertTrue( ! is_wp_error( $result ) || true === $result, 'Installation should succeed or return true' );

		// Verify installation was marked as complete.
		$this->assertTrue( WP_MCP_AI_Default_Assistants::is_installed() );

		// Get installation info.
		$info = WP_MCP_AI_Default_Assistants::get_installation_info();
		$this->assertIsArray( $info );
		$this->assertArrayHasKey( 'installed_at', $info );
		$this->assertArrayHasKey( 'assistant_ids', $info );
	}

	/**
	 * Test that installed assistants exist in database.
	 */
	public function test_installed_assistants_exist_in_database() {
		// Install assistants.
		WP_MCP_AI_Default_Assistants::install();

		// Get installation info.
		$info = WP_MCP_AI_Default_Assistants::get_installation_info();

		if ( ! empty( $info['assistant_ids'] ) ) {
			foreach ( $info['assistant_ids'] as $post_id ) {
				$post = get_post( $post_id );
				$this->assertNotNull( $post, "Post {$post_id} should exist" );
				$this->assertEquals( 'mcp_ai_assistant', $post->post_type );
				$this->assertEquals( 'publish', $post->post_status );
			}
		}
	}

	/**
	 * Test that installed assistants have correct metadata.
	 */
	public function test_installed_assistants_have_metadata() {
		// Install assistants.
		WP_MCP_AI_Default_Assistants::install();

		// Get installation info.
		$info = WP_MCP_AI_Default_Assistants::get_installation_info();

		if ( ! empty( $info['assistant_ids'] ) ) {
			$post_id = $info['assistant_ids'][0]; // Test first assistant.

			// Check for required meta.
			$provider      = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, true );
			$model         = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_MODEL, true );
			$tools         = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
			$system_prompt = get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, true );

			$this->assertNotEmpty( $provider, 'Provider should be set' );
			$this->assertNotEmpty( $model, 'Model should be set' );
			$this->assertIsArray( $tools, 'Tools should be an array' );
			$this->assertNotEmpty( $tools, 'Tools should not be empty' );
			$this->assertNotEmpty( $system_prompt, 'System prompt should be set' );
		}
	}

	/**
	 * Test that reinstall works correctly.
	 */
	public function test_reinstall_default_assistants() {
		// Initial install.
		WP_MCP_AI_Default_Assistants::install();
		$first_install_info = WP_MCP_AI_Default_Assistants::get_installation_info();

		// Reinstall.
		$result = WP_MCP_AI_Default_Assistants::reinstall();

		$this->assertTrue( ! is_wp_error( $result ) || true === $result );
		$this->assertTrue( WP_MCP_AI_Default_Assistants::is_installed() );

		// Verify new installation.
		$second_install_info = WP_MCP_AI_Default_Assistants::get_installation_info();
		$this->assertNotEquals( $first_install_info['installed_at'], $second_install_info['installed_at'] );
	}

	/**
	 * Test uninstall cleans up assistants.
	 */
	public function test_uninstall_removes_assistants() {
		// Install assistants.
		WP_MCP_AI_Default_Assistants::install();
		$info           = WP_MCP_AI_Default_Assistants::get_installation_info();
		$assistant_ids  = $info['assistant_ids'];

		// Uninstall.
		WP_MCP_AI_Default_Assistants::uninstall();

		// Verify option is deleted.
		$this->assertFalse( WP_MCP_AI_Default_Assistants::is_installed() );

		// Verify posts are deleted.
		foreach ( $assistant_ids as $post_id ) {
			$post = get_post( $post_id );
			$this->assertNull( $post, "Post {$post_id} should be deleted" );
		}
	}

	/**
	 * Test that Pro tools can be conditionally added.
	 *
	 * This test verifies the logic exists but cannot fully test
	 * Pro-specific behavior without the Pro addon active.
	 */
	public function test_pro_tool_conditional_logic_exists() {
		// Verify that the assistant configuration method accepts Pro state.
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();

		// Verify base tools are always present.
		$orchestrator = $assistants[0];
		$this->assertIsArray( $orchestrator['tools'] );

		// The tool count will differ between base and pro.
		// In base mode, we expect base tools only.
		// This test just ensures the configuration is dynamic.
		$this->assertGreaterThan( 10, count( $orchestrator['tools'] ), 'Orchestrator should have at least 10 tools' );
	}

	/**
	 * Test that base tools are always included.
	 */
	public function test_base_tools_always_included() {
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();

		// Check Orchestrator for base tools.
		$orchestrator = $assistants[0];
		$orchestrator_tools = $orchestrator['tools'];

		// These are base plugin tools that should always be present.
		$expected_base_tools = array(
			'get_session_status',
			'get_site_health',
			'web_search',
			'create_post',
		);

		foreach ( $expected_base_tools as $tool ) {
			$this->assertContains( $tool, $orchestrator_tools, "Orchestrator should have base tool: {$tool}" );
		}
	}

	/**
	 * Test temperature ranges are appropriate for agent roles.
	 */
	public function test_temperature_ranges_for_roles() {
		$assistants = WP_MCP_AI_Default_Assistants::get_default_assistants();

		foreach ( $assistants as $assistant ) {
			$temp = $assistant['temperature'];

			// All temperatures should be between 0 and 1.
			$this->assertGreaterThanOrEqual( 0, $temp, "{$assistant['slug']} temperature should be >= 0" );
			$this->assertLessThanOrEqual( 1, $temp, "{$assistant['slug']} temperature should be <= 1" );

			// Specific role checks.
			if ( in_array( 'parser', $assistant['primary_roles'], true ) ||
					in_array( 'auditor', $assistant['primary_roles'], true ) ||
					in_array( 'publisher', $assistant['primary_roles'], true ) ) {
				$this->assertLessThanOrEqual( 0.3, $temp, "{$assistant['slug']} should have low temperature for precision" );
			}

			if ( in_array( 'writer', $assistant['primary_roles'], true ) ||
					in_array( 'creative', $assistant['primary_roles'], true ) ) {
				$this->assertGreaterThanOrEqual( 0.5, $temp, "{$assistant['slug']} should have higher temperature for creativity" );
			}
		}
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up installed assistants.
		WP_MCP_AI_Default_Assistants::uninstall();
		parent::tearDown();
	}
}
