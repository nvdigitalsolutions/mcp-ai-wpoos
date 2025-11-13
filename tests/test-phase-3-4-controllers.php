<?php
/**
 * Tests for Phase 3.4 Controllers
 *
 * @package WP_MCP_AI
 */

/**
 * Test Phase 3.4 Controller classes.
 */
class Test_Phase_3_4_Controllers extends WP_UnitTestCase {
	/**
	 * Test that Tools Controller can be instantiated.
	 */
	public function test_tools_controller_instantiation() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_REST_Tools_Controller' ), 'Tools Controller class should exist' );
		
		$controller = new WP_MCP_AI_REST_Tools_Controller();
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Tools_Controller', $controller );
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Controller_Base', $controller );
	}

	/**
	 * Test that Admin Controller can be instantiated.
	 */
	public function test_admin_controller_instantiation() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_REST_Admin_Controller' ), 'Admin Controller class should exist' );
		
		$controller = new WP_MCP_AI_REST_Admin_Controller();
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Admin_Controller', $controller );
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Controller_Base', $controller );
	}

	/**
	 * Test that Files Controller can be instantiated.
	 */
	public function test_files_controller_instantiation() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_REST_Files_Controller' ), 'Files Controller class should exist' );
		
		$controller = new WP_MCP_AI_REST_Files_Controller();
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Files_Controller', $controller );
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Controller_Base', $controller );
	}

	/**
	 * Test that Tools Controller has required methods.
	 */
	public function test_tools_controller_has_required_methods() {
		$controller = new WP_MCP_AI_REST_Tools_Controller();
		
		$this->assertTrue( method_exists( $controller, 'register_routes' ), 'Should have register_routes method' );
		$this->assertTrue( method_exists( $controller, 'handle_tools_list' ), 'Should have handle_tools_list method' );
		$this->assertTrue( method_exists( $controller, 'handle_tool_request' ), 'Should have handle_tool_request method' );
	}

	/**
	 * Test that Admin Controller has required methods.
	 */
	public function test_admin_controller_has_required_methods() {
		$controller = new WP_MCP_AI_REST_Admin_Controller();
		
		$this->assertTrue( method_exists( $controller, 'register_routes' ), 'Should have register_routes method' );
		$this->assertTrue( method_exists( $controller, 'handle_cron_status_request' ), 'Should have handle_cron_status_request method' );
	}

	/**
	 * Test that Files Controller has required methods.
	 */
	public function test_files_controller_has_required_methods() {
		$controller = new WP_MCP_AI_REST_Files_Controller();
		
		$this->assertTrue( method_exists( $controller, 'register_routes' ), 'Should have register_routes method' );
		$this->assertTrue( method_exists( $controller, 'handle_file_download' ), 'Should have handle_file_download method' );
		$this->assertTrue( method_exists( $controller, 'download_file_permissions_check' ), 'Should have download_file_permissions_check method' );
	}

	/**
	 * Test that main REST controller helper methods are public.
	 */
	public function test_main_rest_controller_helper_methods_public() {
		$rest = new WP_MCP_AI_REST();
		
		$reflection = new ReflectionClass( $rest );
		
		$methods_to_check = array(
			'resolve_assistant_id',
			'apply_token_assistant_scope',
			'validate_assistant_access',
			'generate_tool_slug_candidates',
			'candidates_include_slug',
			'resolve_tool_slug_from_candidates',
			'ensure_tool_in_config',
			'tool_arguments_include_document_payload',
			'resolve_local_attachment_for_openai_file',
			'get_openai_client',
			'get_cron_status_service',
		);

		foreach ( $methods_to_check as $method_name ) {
			$this->assertTrue( $reflection->hasMethod( $method_name ), "Should have method: {$method_name}" );
			$method = $reflection->getMethod( $method_name );
			$this->assertTrue( $method->isPublic(), "Method {$method_name} should be public" );
		}
	}

	/**
	 * Test that routes are registered correctly.
	 */
	public function test_routes_are_registered() {
		// Clear routes
		rest_get_server()->override_by_default = true;
		
		// Force route re-registration
		do_action( 'rest_api_init' );
		
		$routes = rest_get_server()->get_routes();
		
		// Check that Phase 3.4 routes exist
		$this->assertArrayHasKey( '/mcp-ai/v1/tools', $routes, '/tools route should be registered' );
		$this->assertArrayHasKey( '/mcp-ai/v1/cron-status', $routes, '/cron-status route should be registered' );
		$this->assertArrayHasKey( '/mcp-ai/v1/files/(?P<file_id>[^/]+)/download', $routes, '/files download route should be registered' );
	}

	/**
	 * Test that Tools Controller constant is defined.
	 */
	public function test_tools_controller_constant() {
		$this->assertTrue( defined( 'WP_MCP_AI_REST_Tools_Controller::DOCUMENT_PROMPT_TOOL_SLUG' ), 'DOCUMENT_PROMPT_TOOL_SLUG constant should be defined' );
		$this->assertEquals( 'document_prompt_helper', WP_MCP_AI_REST_Tools_Controller::DOCUMENT_PROMPT_TOOL_SLUG );
	}
}
