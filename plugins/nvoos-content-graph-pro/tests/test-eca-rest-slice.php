<?php
/**
 * Characterization tests for the Wave F5 eca-management REST slice — the
 * ECA REST controller ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   class; the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copy in `src/rest/`
 *   is asserted in full, including the slim init's now-firing REST gate
 *   target.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * ECA REST slice tests.
 */
class Test_ECA_REST_Slice extends WP_UnitTestCase {

	/**
	 * The ported symbol must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_ECA_REST_Controller' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/rest/class-wp-mcp-ai-eca-rest-controller.php', $path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/rest/class-wp-mcp-ai-eca-rest-controller.php', $path );
		}
	}

	/**
	 * The four routes must register on rest_api_init with the
	 * `manage_options` gate.
	 */
	public function test_route_registration_and_gate(): void {
		$controller = new WP_MCP_AI_ECA_REST_Controller();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );
		$this->assertArrayHasKey( '/mcp-ai/v1/ecas', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/ecas/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/students', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/students/(?P<id>[\d]+)', $routes );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/ecas' );
		$result  = rest_get_server()->dispatch( $request );
		$this->assertSame( rest_authorization_required_code(), $result->get_status() );
	}

	/**
	 * Standalone only: the slim init's file-gated REST require has its gate
	 * target present, and the global REST-route helper exists.
	 */
	public function test_init_gate_target_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base ECA init wires the REST slice at boot.' );
		}

		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-eca-rest-controller.php' );

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/init.php';
		$this->assertTrue( function_exists( 'wp_mcp_ai_register_eca_rest_routes' ) );
	}
}
