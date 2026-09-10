<?php
/**
 * Characterization tests for the Wave F5 chat-channels REST slice A — the
 * inbox controller and the Google Chat webhook controller ported from the
 * base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/rest/`
 *   are asserted in full, including the slim init's now-firing REST gate
 *   targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Chat Channels REST slice A tests.
 */
class Test_Chat_Channels_REST_A extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Chat_Channels_REST_Controller'  => 'rest/class-wp-mcp-ai-chat-channels-rest-controller.php',
			'WP_MCP_AI_Google_Chat_Webhook_Controller' => 'rest/class-wp-mcp-ai-google-chat-webhook-controller.php',
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
	 * The inbox routes must register on rest_api_init under the
	 * `mcp-ai-pro/v1` namespace.
	 */
	public function test_inbox_routes(): void {
		$controller = new WP_MCP_AI_Chat_Channels_REST_Controller();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/conversations', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/reply', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/chat-channels/contacts', $routes );
	}

	/**
	 * The webhook routes must register on rest_api_init under the
	 * `mcp-ai/v1` namespace.
	 */
	public function test_webhook_routes(): void {
		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );

		$has_webhook = false;
		foreach ( array_keys( $routes ) as $key ) {
			if ( false !== strpos( $key, 'webhooks/google-chat' ) ) {
				$has_webhook = true;
			}
		}
		$this->assertTrue( $has_webhook );
	}

	/**
	 * Standalone only: the slim init's file-gated REST requires have their
	 * gate targets present.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base chat-channels init wires the REST slice at boot.' );
		}

		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-chat-channels-rest-controller.php' );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-google-chat-webhook-controller.php' );
	}
}
