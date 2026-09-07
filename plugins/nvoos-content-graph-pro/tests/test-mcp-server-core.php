<?php
/**
 * Characterization tests for the Wave F2 MCP toolkit-server core slice —
 * the ported `WP_MCP_AI_Toolkit_Server_Interface` + `_Registry` +
 * `_Base` + `_MCP_Audit_Log` + `_MCP_REST_Controller` +
 * `_OAuth_Server`/`_OAuth_REST` + `_Pro_Toolkit_Server_Token`.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/mcp-servers/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * MCP toolkit-server core slice tests.
 */
class Test_Mcp_Server_Core extends WP_UnitTestCase {

	/**
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Toolkit_Server_Interface'    => 'mcp-servers/interface-wp-mcp-ai-toolkit-server.php',
			'WP_MCP_AI_Toolkit_Server_Registry'     => 'mcp-servers/class-wp-mcp-ai-toolkit-server-registry.php',
			'WP_MCP_AI_Toolkit_MCP_Audit_Log'       => 'mcp-servers/class-wp-mcp-ai-toolkit-mcp-audit-log.php',
			'WP_MCP_AI_Toolkit_Server_Base'         => 'mcp-servers/class-wp-mcp-ai-toolkit-server-base.php',
			'WP_MCP_AI_Toolkit_MCP_REST_Controller' => 'mcp-servers/class-wp-mcp-ai-toolkit-mcp-rest-controller.php',
			'WP_MCP_AI_OAuth_Server'                => 'mcp-servers/class-wp-mcp-ai-oauth-server.php',
			'WP_MCP_AI_OAuth_REST'                  => 'mcp-servers/class-wp-mcp-ai-oauth-rest.php',
			'WP_MCP_AI_Pro_Toolkit_Server_Token'    => 'mcp-servers/class-wp-mcp-ai-pro-toolkit-server-token.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The registry must register and retrieve toolkit servers.
	 */
	public function test_registry_register_and_get(): void {
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();

		$stub = $this->createMock( 'WP_MCP_AI_Toolkit_Server_Base' );
		$stub->method( 'get_slug' )->willReturn( 'test_toolkit_mcp' );
		$stub->method( 'get_name' )->willReturn( 'Test Toolkit' );

		$this->assertTrue( $registry->register( $stub ) );
		$this->assertSame( $stub, $registry->get( 'test_toolkit_mcp' ) );
	}

	/**
	 * The audit log must record entries and cap at MAX_ENTRIES.
	 */
	public function test_audit_log_record(): void {
		$log = WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance();
		$this->assertSame( 'wp_mcp_ai_toolkit_mcp_audit_log', $log::OPTION_KEY );
		$this->assertSame( 200, $log::MAX_ENTRIES );

		$log->record(
			array(
				'consumer' => 'unit-test',
				'source'   => 'test_toolkit_mcp',
				'entity'   => 'lead:42',
				'method'   => 'list_tools',
				'uri'      => '/mcp',
			)
		);

		$entries = $log->get_entries();
		$this->assertNotEmpty( $entries );
		$this->assertSame( 'test_toolkit_mcp', $entries[0]['source'] );

		delete_option( 'wp_mcp_ai_toolkit_mcp_audit_log' );
	}

	/**
	 * The toolkit-server token constants must be byte-identical.
	 */
	public function test_server_token_constants(): void {
		$this->assertSame( 'mcptk_', WP_MCP_AI_Pro_Toolkit_Server_Token::TOKEN_PREFIX );
		$this->assertSame( 'wp_mcp_ai_tk_mcp_token_', WP_MCP_AI_Pro_Toolkit_Server_Token::OPTION_PREFIX );
		$this->assertSame( 10, WP_MCP_AI_Pro_Toolkit_Server_Token::MAX_TOKENS );
	}

	/**
	 * Token generate/validate round trip (option-backed, no user seeding).
	 */
	public function test_server_token_round_trip(): void {
		$generated = WP_MCP_AI_Pro_Toolkit_Server_Token::generate( 'test_toolkit_mcp', 'unit' );
		$this->assertIsArray( $generated );
		$this->assertStringStartsWith( 'mcptk_', $generated['token'] );

		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::validate( 'test_toolkit_mcp', $generated['token'] );
		$this->assertTrue( $result );

		$result = WP_MCP_AI_Pro_Toolkit_Server_Token::validate( 'test_toolkit_mcp', 'mcptk_invalid' );
		$this->assertFalse( $result );

		// Cleanup.
		$option = WP_MCP_AI_Pro_Toolkit_Server_Token::OPTION_PREFIX . 'test_toolkit_mcp';
		delete_option( $option );
	}

	/**
	 * The REST controller must wire its routes on rest_api_init.
	 */
	public function test_rest_controller_routes(): void {
		$controller = WP_MCP_AI_Toolkit_MCP_REST_Controller::get_instance();
		$controller->init();

		$this->assertNotFalse( has_action( 'rest_api_init', array( $controller, 'register_routes' ) ) );

		// The routes register on rest_api_init — fire the action instead of
		// calling register_routes() directly (which trips _doing_it_wrong).
		do_action( 'rest_api_init' );

		$rest_server = rest_get_server();
		$routes      = $rest_server->get_routes();
		$this->assertNotEmpty( $routes );

		$found = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( false !== strpos( $route, 'mcp-ai-pro/v1/mcp' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected mcp-ai-pro/v1/mcp routes to register.' );
	}
}
