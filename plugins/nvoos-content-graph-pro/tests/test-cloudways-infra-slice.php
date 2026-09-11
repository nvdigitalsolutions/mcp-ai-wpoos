<?php
/**
 * Characterization tests for the Wave F2 cloudways infra slice — the API
 * client, the helpers, the abstract tool base, and the settings page ported
 * from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical contracts are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the slim init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Cloudways infra slice tests.
 */
class Test_Cloudways_Infra_Slice extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Cloudways_Client'        => 'cloudways/class-wp-mcp-ai-cloudways-client.php',
			'WP_MCP_AI_Tool_Cloudways_Base'     => 'tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php',
			'WP_MCP_AI_Cloudways_Settings_Page' => 'admin/class-wp-mcp-ai-cloudways-settings-page.php',
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
	 * The enablement helper and the base capability must be byte-identical.
	 */
	public function test_contracts(): void {
		// Nothing loads the base cloudways init in either matrix — require the
		// helpers explicitly (the global functions live there).
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/cloudways/class-wp-mcp-ai-cloudways-helpers.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/cloudways/class-wp-mcp-ai-cloudways-helpers.php';
		}

		update_option( 'wp_mcp_ai_settings', array( 'enable_cloudways_toolkit' => 1 ) );
		$this->assertTrue( wp_mcp_ai_is_cloudways_toolkit_enabled() );

		update_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( wp_mcp_ai_is_cloudways_toolkit_enabled() );
		$this->assertFalse( wp_mcp_ai_cloudways_has_credentials() );
		delete_option( 'wp_mcp_ai_settings' );

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Cloudways_Base' );
		$this->assertTrue( $reflection->isAbstract() );
	}

	/**
	 * The settings page must expose the byte-identical page slug.
	 */
	public function test_settings_page_contracts(): void {
		$page = new WP_MCP_AI_Cloudways_Settings_Page();
		$this->assertSame( 'cloudways', $this->read_prop( $page, 'toolkit_slug' ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/cloudways/class-wp-mcp-ai-cloudways-client.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/cloudways/class-wp-mcp-ai-cloudways-helpers.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cloudways-settings-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		// The tool filter carries the full sixty-tool batch.
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/cloudways/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_cloudways_tools', 10 );
		$this->assertCount( 60, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
	}

	/**
	 * Read a protected property for byte-identical pinning.
	 *
	 * @param object $instance Object instance.
	 * @param string $prop     Property name.
	 * @return mixed Property value.
	 */
	private function read_prop( object $instance, string $prop ) {
		$reflection = new ReflectionObject( $instance );
		while ( ! $reflection->hasProperty( $prop ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$property = $reflection->getProperty( $prop );
		$property->setAccessible( true );
		return $property->getValue( $instance );
	}
}
