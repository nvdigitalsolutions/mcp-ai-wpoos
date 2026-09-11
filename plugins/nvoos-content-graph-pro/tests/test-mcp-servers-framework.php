<?php
/**
 * Characterization tests for the Wave F2 mcp-servers framework — the
 * observability card, the well-known endpoint, the scheduled-server trait,
 * and the slim standalone init.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   framework classes (classmap-autoloaded); the byte-identical surfaces
 *   are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/mcp-servers/` are asserted in full, including the init wiring and
 *   the registry module.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * MCP servers framework tests.
 */
class Test_MCP_Servers_Framework extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card' => 'mcp-servers/class-wp-mcp-ai-pro-toolkit-mcp-observability-card.php',
			'WP_MCP_AI_Pro_Well_Known_MCP'                 => 'mcp-servers/class-wp-mcp-ai-pro-well-known-mcp.php',
			'WP_MCP_AI_Scheduled_Toolkit_Server_Trait'     => 'mcp-servers/trait-wp-mcp-ai-scheduled-toolkit-server.php',
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
	 * The framework surfaces must be byte-identical.
	 */
	public function test_framework_surfaces(): void {
		$this->assertSame( 'wp_mcp_ai_well_known_mcp', WP_MCP_AI_Pro_Well_Known_MCP::QUERY_VAR );

		$well_known = new WP_MCP_AI_Pro_Well_Known_MCP();
		$this->assertNotFalse( has_action( 'init', array( $well_known, 'add_rewrite_rules' ) ) );
		$this->assertNotFalse( has_filter( 'query_vars', array( $well_known, 'add_query_vars' ) ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( $well_known, 'handle_request' ) ) );

		$card = new WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card();
		$this->assertInstanceOf( 'WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card', $card );
	}

	/**
	 * Standalone only: the slim init must exist and carry the byte-identical
	 * wiring surface (the WP test framework restores hooks between tests, so
	 * the registry-module state is asserted instead of has_action).
	 */
	public function test_init_wiring_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base mcp-servers init boots via the base registry.' );
		}

		$init_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/mcp-servers/mcp-servers-init.php';
		$this->assertFileExists( $init_file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture read.
		$init_source = file_get_contents( $init_file );
		$this->assertStringContainsString( "'init'", $init_source );
		$this->assertStringContainsString( 'wp_mcp_ai_register_toolkit_servers', $init_source );
		$this->assertStringContainsString( 'admin_post_wp_mcp_ai_save_toolkit_mcp_server', $init_source );
		$this->assertStringContainsString( 'WP_MCP_AI_Toolkit_MCP_REST_Controller::get_instance()->init()', $init_source );
		$this->assertStringContainsString( 'WP_MCP_AI_OAuth_REST::get_instance()->init()', $init_source );
		$this->assertStringContainsString( 'class_exists( $nvoos_content_graph_pro_server_class )', $init_source );

		// The registry must remain usable without the not-yet-ported server
		// classes (the guarded registration loop degrades gracefully).
		require_once $init_file;
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$this->assertIsArray( $registry->all() );
	}

	/**
	 * Standalone only: the registry must define the `mcp_servers_framework`
	 * module and boot it (its files guard is satisfied).
	 */
	public function test_registry_module_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry owns the mcp-servers module.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-module-registry.php';

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertArrayHasKey( 'mcp_servers_framework', $registry->get_modules() );
		$this->assertTrue( $registry->is_loaded( 'mcp_servers_framework' ) );
	}
}
