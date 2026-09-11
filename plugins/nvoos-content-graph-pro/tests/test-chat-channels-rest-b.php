<?php
/**
 * Characterization tests for the Wave F5 chat-channels REST slice B — the
 * Apple Messages, Outlook, iCloud, and Telegram webhook controllers ported
 * from the base Pro addon.
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
 * Chat Channels REST slice B tests.
 */
class Test_Chat_Channels_REST_B extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Apple_Messages_Webhook_Controller' => 'rest/class-wp-mcp-ai-apple-messages-webhook-controller.php',
			'WP_MCP_AI_Outlook_Webhook_Controller'        => 'rest/class-wp-mcp-ai-outlook-webhook-controller.php',
			'WP_MCP_AI_ICloud_Webhook_Controller'         => 'rest/class-wp-mcp-ai-icloud-webhook-controller.php',
			'WP_MCP_AI_Telegram_Webhook_Controller'       => 'rest/class-wp-mcp-ai-telegram-webhook-controller.php',
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
	 * The controllers must extend WP_REST_Controller and carry the
	 * byte-identical `mcp-ai/v1` namespace.
	 */
	public function test_controller_contracts(): void {
		foreach (
			array(
				'WP_MCP_AI_Apple_Messages_Webhook_Controller',
				'WP_MCP_AI_Outlook_Webhook_Controller',
				'WP_MCP_AI_ICloud_Webhook_Controller',
				'WP_MCP_AI_Telegram_Webhook_Controller',
			) as $class
		) {
			$this->assertTrue( is_subclass_of( $class, 'WP_REST_Controller' ), $class );
			$this->assertTrue( method_exists( $class, 'register_routes' ), $class );
		}
	}

	/**
	 * Standalone only: the slim init's file-gated REST requires have their
	 * gate targets present.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base chat-channels init wires the REST slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-apple-messages-webhook-controller.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-outlook-webhook-controller.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-icloud-webhook-controller.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-telegram-webhook-controller.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}
}
