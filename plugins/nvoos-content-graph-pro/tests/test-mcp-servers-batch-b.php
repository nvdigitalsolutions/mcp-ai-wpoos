<?php
/**
 * Characterization tests for the Wave F2 mcp-servers batch B — the fourteen
 * Phase 6+8 + DietPi toolkit servers.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the server
 *   classes (classmap-autoloaded); the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/mcp-servers/servers/` are asserted in full, including the slim
 *   init's file-gated requires.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * MCP servers batch B tests.
 */
class Test_MCP_Servers_Batch_B extends WP_UnitTestCase {

	/**
	 * Standalone matrix: the slim init's explicit requires load the server
	 * classes (byte-identical with the monolith classmap serving).
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/mcp-servers/mcp-servers-init.php';
		}
	}

	/**
	 * The fourteen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$servers = array(
			'WP_MCP_AI_Analytics_MCP_Server',
			'WP_MCP_AI_Architect_Agent_MCP_Server',
			'WP_MCP_AI_Chat_Channels_MCP_Server',
			'WP_MCP_AI_Cloudways_MCP_Server',
			'WP_MCP_AI_Comic_Creation_MCP_Server',
			'WP_MCP_AI_Extended_Cognition_MCP_Server',
			'WP_MCP_AI_Healthcare_Imaging_MCP_Server',
			'WP_MCP_AI_Healthcare_Wellness_MCP_Server',
			'WP_MCP_AI_Site_Creator_MCP_Server',
			'WP_MCP_AI_DietPi_MCP_Server',
			'WP_MCP_AI_Pro_Scheduler_MCP_Server',
			'WP_MCP_AI_FlowHub_MCP_Server',
			'WP_MCP_AI_Shopify_Sync_MCP_Server',
			'WP_MCP_AI_EZuite_MCP_Server',
		);

		foreach ( $servers as $class ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/mcp-servers/servers/', $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/mcp-servers/servers/', $path, $class );
			}
		}
	}

	/**
	 * All fourteen servers must extend the ported toolkit-server base and
	 * expose a stable slug/name surface.
	 */
	public function test_server_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Analytics_MCP_Server'           => 'analytics',
			'WP_MCP_AI_Architect_Agent_MCP_Server'     => 'architect-agent',
			'WP_MCP_AI_Chat_Channels_MCP_Server'       => 'chat-channels',
			'WP_MCP_AI_Cloudways_MCP_Server'           => 'cloudways',
			'WP_MCP_AI_Comic_Creation_MCP_Server'      => 'comic-creation',
			'WP_MCP_AI_Extended_Cognition_MCP_Server'  => 'extended-cognition',
			'WP_MCP_AI_Healthcare_Imaging_MCP_Server'  => 'healthcare-imaging',
			'WP_MCP_AI_Healthcare_Wellness_MCP_Server' => 'healthcare-wellness',
			'WP_MCP_AI_Site_Creator_MCP_Server'        => 'site-creator',
			'WP_MCP_AI_DietPi_MCP_Server'              => 'dietpi',
			'WP_MCP_AI_Pro_Scheduler_MCP_Server'       => 'pro-scheduler',
			'WP_MCP_AI_FlowHub_MCP_Server'             => 'flowhub',
			'WP_MCP_AI_Shopify_Sync_MCP_Server'        => 'shopify-sync',
			'WP_MCP_AI_EZuite_MCP_Server'              => 'ezuite',
		);

		foreach ( $slugs as $class => $slug ) {
			$server = new $class();
			$this->assertInstanceOf( 'WP_MCP_AI_Toolkit_Server_Base', $server, $class );
			$this->assertSame( $slug, $server->get_slug(), $class );
			$this->assertIsString( $server->get_name(), $class );
		}
	}

	/**
	 * Standalone only: the slim init's file-gated requires must now resolve
	 * for the batch-B servers (all 33 servers in the tree).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base mcp-servers init owns the requires.' );
		}

		foreach ( array( 'analytics', 'pro-scheduler', 'ezuite' ) as $slug ) {
			$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/mcp-servers/servers/class-wp-mcp-ai-' . $slug . '-mcp-server.php' );
		}
	}
}
