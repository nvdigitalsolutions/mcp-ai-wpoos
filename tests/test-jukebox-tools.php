<?php
/**
 * Tests for OpenAI Jukebox integration tools.
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-jukebox-music.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-jukebox-status.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-jukebox-service.php';

/**
 * Tests for Jukebox tools.
 */
class WP_MCP_AI_Jukebox_Tools_Test extends WP_UnitTestCase {

	/**
	 * Clean up global state after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_jukebox_python_path' );
		delete_option( 'wp_mcp_ai_jukebox_install_path' );
		parent::tearDown();
	}

	/**
	 * Test that the generate Jukebox music tool is registered.
	 */
	public function test_generate_jukebox_music_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'generate_jukebox_music' );

		$this->assertInstanceOf( WP_MCP_AI_Tool_Generate_Jukebox_Music::class, $tool );
	}

	/**
	 * Test that the check Jukebox status tool is registered.
	 */
	public function test_check_jukebox_status_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'check_jukebox_status' );

		$this->assertInstanceOf( WP_MCP_AI_Tool_Check_Jukebox_Status::class, $tool );
	}

	/**
	 * Test generate Jukebox music tool requires authentication.
	 */
	public function test_generate_jukebox_music_requires_authentication() {
		$tool   = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$result = $tool->execute( array( 'prompt' => 'rock ballad' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test generate Jukebox music tool requires prompt.
	 */
	public function test_generate_jukebox_music_requires_prompt() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test generate Jukebox music tool requires upload_files capability.
	 */
	public function test_generate_jukebox_music_requires_upload_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$result = $tool->execute(
			array( 'prompt' => 'jazz piano' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test check Jukebox status tool requires authentication.
	 */
	public function test_check_jukebox_status_requires_authentication() {
		$tool   = new WP_MCP_AI_Tool_Check_Jukebox_Status();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test check Jukebox status tool requires manage_options capability.
	 */
	public function test_check_jukebox_status_requires_manage_options() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_Check_Jukebox_Status();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test check Jukebox status returns not installed when not configured.
	 */
	public function test_check_jukebox_status_not_configured() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Check_Jukebox_Status();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['installed'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'setup_instructions', $result );
	}

	/**
	 * Test Jukebox service check_installation returns correct structure.
	 */
	public function test_jukebox_service_check_installation_structure() {
		$service = new WP_MCP_AI_Jukebox_Service();
		$status  = $service->check_installation();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'installed', $status );
		$this->assertArrayHasKey( 'message', $status );
		$this->assertIsBool( $status['installed'] );
	}

	/**
	 * Test generate Jukebox music tool parameters schema.
	 */
	public function test_generate_jukebox_music_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'sample_length', $schema['properties'] );
		$this->assertArrayHasKey( 'artist', $schema['properties'] );
		$this->assertArrayHasKey( 'genre', $schema['properties'] );
		$this->assertArrayHasKey( 'lyrics', $schema['properties'] );
		$this->assertArrayHasKey( 'temperature', $schema['properties'] );
	}

	/**
	 * Test generate Jukebox music tool capability flags.
	 */
	public function test_generate_jukebox_music_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'local-execution', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test check Jukebox status tool capability flags.
	 */
	public function test_check_jukebox_status_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Check_Jukebox_Status();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-execution', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test tools are in the correct group.
	 */
	public function test_jukebox_tools_are_in_external_tools_group() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'generate_jukebox_music', $group_map );
		$this->assertSame( 'external-tools', $group_map['generate_jukebox_music'] );

		$this->assertArrayHasKey( 'check_jukebox_status', $group_map );
		$this->assertSame( 'external-tools', $group_map['check_jukebox_status'] );
	}

	/**
	 * Test generate Jukebox music tool slug.
	 */
	public function test_generate_jukebox_music_slug() {
		$tool = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$this->assertSame( 'generate_jukebox_music', $tool->get_slug() );
	}

	/**
	 * Test check Jukebox status tool slug.
	 */
	public function test_check_jukebox_status_slug() {
		$tool = new WP_MCP_AI_Tool_Check_Jukebox_Status();
		$this->assertSame( 'check_jukebox_status', $tool->get_slug() );
	}

	/**
	 * Test generate Jukebox music tool returns error when Jukebox not installed.
	 */
	public function test_generate_jukebox_music_not_installed_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$result = $tool->execute(
			array( 'prompt' => 'rock music with vocals' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_jukebox_not_installed', $result->get_error_code() );
	}

	/**
	 * Test check Jukebox status includes configuration details.
	 */
	public function test_check_jukebox_status_includes_configuration() {
		update_option( 'wp_mcp_ai_jukebox_python_path', '/usr/bin/python3' );
		update_option( 'wp_mcp_ai_jukebox_install_path', '/opt/jukebox' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool   = new WP_MCP_AI_Tool_Check_Jukebox_Status();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'configuration', $result );
		$this->assertSame( '/usr/bin/python3', $result['configuration']['python_path_setting'] );
		$this->assertSame( '/opt/jukebox', $result['configuration']['install_path_setting'] );
	}

	/**
	 * Test check Jukebox status includes available models when installed.
	 */
	public function test_check_jukebox_status_includes_models_when_installed() {
		// We can't fully test this without actually installing Jukebox,
		// but we can verify the structure when it's installed.
		$service = new WP_MCP_AI_Jukebox_Service();
		$status  = $service->check_installation();

		// If installed (unlikely in test environment), should have models.
		if ( $status['installed'] ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$tool    = new WP_MCP_AI_Tool_Check_Jukebox_Status();
			$result  = $tool->execute( array(), array( 'user_id' => $user_id ) );

			$this->assertArrayHasKey( 'available_models', $result );
			$this->assertIsArray( $result['available_models'] );
		} else {
			// Not installed, should have setup instructions.
			$this->assertArrayHasKey( 'message', $status );
		}
	}
}
