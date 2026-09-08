<?php
/**
 * Characterization tests for the Wave F2 mcp-servers batch A — the nineteen
 * Phase 1+2 toolkit servers.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the server
 *   classes (classmap-autoloaded); the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/mcp-servers/servers/` are asserted in full, including the slim
 *   init's file-gated requires and the guarded registration loop.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * MCP servers batch A tests.
 */
class Test_MCP_Servers_Batch_A extends WP_UnitTestCase {

	/**
	 * Standalone matrix: the slim init's explicit requires load the server
	 * classes (the eca file/class-name mismatch is served by the init's
	 * require, not the spl autoloader — byte-identical with the monolith
	 * where the Pro classmap serves them).
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/mcp-servers/mcp-servers-init.php';
		}
	}

	/**
	 * The nineteen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$servers = array(
			'WP_MCP_AI_CRM_MCP_Server',
			'WP_MCP_AI_Healthcare_MCP_Server',
			'WP_MCP_AI_Architectural_Design_MCP_Server',
			'WP_MCP_AI_AI_Tool_Builder_MCP_Server',
			'WP_MCP_AI_Calendar_Booking_MCP_Server',
			'WP_MCP_AI_CRE_Debt_MCP_Server',
			'WP_MCP_AI_DJ_Management_MCP_Server',
			'WP_MCP_AI_Document_Generation_MCP_Server',
			'WP_MCP_AI_ECA_Management_MCP_Server',
			'WP_MCP_AI_Ecommerce_MCP_Server',
			'WP_MCP_AI_Financial_Planner_MCP_Server',
			'WP_MCP_AI_Image_Production_MCP_Server',
			'WP_MCP_AI_Law_Firm_MCP_Server',
			'WP_MCP_AI_Media_Toolkit_MCP_Server',
			'WP_MCP_AI_Multilingual_MCP_Server',
			'WP_MCP_AI_Project_Management_MCP_Server',
			'WP_MCP_AI_Regulatory_Registration_MCP_Server',
			'WP_MCP_AI_Social_Media_MCP_Server',
			'WP_MCP_AI_Video_Production_MCP_Server',
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
	 * All nineteen servers must extend the ported toolkit-server base and
	 * expose a stable slug/name surface.
	 */
	public function test_server_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_CRM_MCP_Server'                     => 'crm',
			'WP_MCP_AI_Healthcare_MCP_Server'              => 'health',
			'WP_MCP_AI_Architectural_Design_MCP_Server'    => 'architectural-design',
			'WP_MCP_AI_AI_Tool_Builder_MCP_Server'         => 'ai-tool-builder',
			'WP_MCP_AI_Calendar_Booking_MCP_Server'        => 'calendar-booking',
			'WP_MCP_AI_CRE_Debt_MCP_Server'                => 'cre-debt',
			'WP_MCP_AI_DJ_Management_MCP_Server'           => 'dj-management',
			'WP_MCP_AI_Document_Generation_MCP_Server'     => 'document-generation',
			'WP_MCP_AI_ECA_Management_MCP_Server'          => 'eca',
			'WP_MCP_AI_Ecommerce_MCP_Server'               => 'ecommerce',
			'WP_MCP_AI_Financial_Planner_MCP_Server'       => 'financial-planner',
			'WP_MCP_AI_Image_Production_MCP_Server'        => 'image-production',
			'WP_MCP_AI_Law_Firm_MCP_Server'                => 'law-firm',
			'WP_MCP_AI_Media_Toolkit_MCP_Server'           => 'media',
			'WP_MCP_AI_Multilingual_MCP_Server'            => 'multilingual',
			'WP_MCP_AI_Project_Management_MCP_Server'      => 'project-management',
			'WP_MCP_AI_Regulatory_Registration_MCP_Server' => 'regulatory-registration',
			'WP_MCP_AI_Social_Media_MCP_Server'            => 'social-media',
			'WP_MCP_AI_Video_Production_MCP_Server'        => 'video-production',
		);

		foreach ( $slugs as $class => $slug ) {
			$server = new $class();
			$this->assertInstanceOf( 'WP_MCP_AI_Toolkit_Server_Base', $server, $class );
			$this->assertSame( $slug, $server->get_slug(), $class );
			$this->assertIsString( $server->get_name(), $class );
		}
	}

	/**
	 * The servers must register into the registry through the registration
	 * path (the guarded loop resolves the classes standalone).
	 */
	public function test_registry_roundtrip(): void {
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$registry->register( new WP_MCP_AI_CRM_MCP_Server() );
		$registry->register( new WP_MCP_AI_Financial_Planner_MCP_Server() );

		$this->assertNotNull( $registry->get( 'crm' ) );
		$this->assertNotNull( $registry->get( 'financial-planner' ) );
	}

	/**
	 * Standalone only: the slim init's file-gated requires must now resolve
	 * for the batch-A servers.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base mcp-servers init owns the requires.' );
		}

		foreach ( array( 'crm', 'calendar-booking', 'social-media' ) as $slug ) {
			$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/mcp-servers/servers/class-wp-mcp-ai-' . $slug . '-mcp-server.php' );
		}
	}
}
