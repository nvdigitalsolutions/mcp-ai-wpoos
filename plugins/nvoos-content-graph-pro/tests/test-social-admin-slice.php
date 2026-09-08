<?php
/**
 * Characterization tests for the Wave F2 social admin slice — the
 * social-media settings page ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the page
 *   class (classmap-autoloaded); the byte-identical surface is asserted.
 * - Standalone matrix (base plugin absent): the ported copy in `src/admin/`
 *   is asserted in full, including the init's file-gate target.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Social admin slice tests.
 */
class Test_Social_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The ported symbol must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Social_Media_Settings_Page' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/admin/class-wp-mcp-ai-social-media-settings-page.php', $path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/admin/class-wp-mcp-ai-social-media-settings-page.php', $path );
		}
	}

	/**
	 * The settings page constants must be byte-identical.
	 */
	public function test_page_constants(): void {
		$settings = new WP_MCP_AI_Social_Media_Settings_Page();
		$this->assertSame( 'social_media', $this->read_prop( $settings, 'toolkit_slug' ) );
		$this->assertSame( 'wp_mcp_ai_social_media_toolkit_settings', $this->read_prop( $settings, 'option_name' ) );
		$this->assertSame( 'wp-mcp-ai-social-media-toolkit-settings', $this->read_prop( $settings, 'page_slug' ) );
	}

	/**
	 * Standalone only: the social init's file-gated admin target must now
	 * exist (the admin slice has landed).
	 */
	public function test_init_gate_target_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base social init is tree-dormant.' );
		}

		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-social-media-settings-page.php' );
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
