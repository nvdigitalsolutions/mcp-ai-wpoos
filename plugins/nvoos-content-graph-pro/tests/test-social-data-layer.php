<?php
/**
 * Characterization tests for the Wave F2 social-media data layer — the
 * social-media optimization helper and the slim standalone init (which the
 * base tree ships but nothing loads — byte-identical dormancy).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   optimization class (classmap-autoloaded); the byte-identical contracts
 *   are asserted.
 * - Standalone matrix (base plugin absent): the ported copy in `src/` is
 *   asserted in full, including the init's enabled gate and the
 *   standalone-only registry module.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Social data-layer tests.
 */
class Test_Social_Data_Layer extends WP_UnitTestCase {

	/**
	 * Enable the social media toolkit for the init gate.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                                = get_option( 'wp_mcp_ai_settings', array() );
		$settings                                = is_array( $settings ) ? $settings : array();
		$settings['enable_social_media_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The ported symbol must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Social_Media_Optimization' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/tools/social-media/class-wp-mcp-ai-social-media-optimization.php', $path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/social-media/class-wp-mcp-ai-social-media-optimization.php', $path );
		}
	}

	/**
	 * The optimization constants and init hooks must be byte-identical.
	 */
	public function test_optimizer_contracts(): void {
		$this->assertSame( 'social_sched_post', WP_MCP_AI_Social_Media_Optimization::SCHEDULED_POST_TYPE );
		$this->assertSame( 'wp_mcp_ai_sm_daily_cleanup', WP_MCP_AI_Social_Media_Optimization::CLEANUP_HOOK );
		$this->assertSame( 'wp_mcp_ai_autorespond_templates', WP_MCP_AI_Social_Media_Optimization::TEMPLATES_OPTION );
		$this->assertSame( 50, WP_MCP_AI_Social_Media_Optimization::MAX_TEMPLATES );
		$this->assertSame( 30, WP_MCP_AI_Social_Media_Optimization::DEFAULT_RETENTION_DAYS );

		WP_MCP_AI_Social_Media_Optimization::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Social_Media_Optimization', 'register_scheduled_post_cpt' ) ) );
		$this->assertNotFalse( has_action( 'wp_mcp_ai_publish_scheduled_post', array( 'WP_MCP_AI_Social_Media_Optimization', 'handle_publish_scheduled_post' ) ) );
		$this->assertNotFalse( has_action( 'wp_mcp_ai_sm_daily_cleanup', array( 'WP_MCP_AI_Social_Media_Optimization', 'run_daily_cleanup' ) ) );
	}

	/**
	 * Standalone only: the slim init's enabled gate must load the optimizer
	 * and expose the standalone-only tool wiring.
	 */
	public function test_init_gate_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base social init is tree-dormant.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/social-media/init.php';

		// The optimizer require ran inside the enabled gate (setting enabled
		// in setUp), so the class resolves from the addon's src tree.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Social_Media_Optimization' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/social-media/class-wp-mcp-ai-social-media-optimization.php', $path );

		// The standalone-only tool wiring functions must exist.
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_social_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_social_ecosystem_tools' ) );

		// The filter must be inert-but-present.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_social_tools', 10 );
		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertIsArray( $tools );
	}

	/**
	 * Standalone only: the registry must define the standalone-only
	 * `toolkit_social_media` module.
	 */
	public function test_registry_module_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry has no social module.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-module-registry.php';

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertArrayHasKey( 'toolkit_social_media', $registry->get_modules() );

		// The module's files guard must be satisfied by the ported init, so
		// boot() loads it standalone.
		$this->assertTrue( $registry->is_loaded( 'toolkit_social_media' ) );
	}
}
