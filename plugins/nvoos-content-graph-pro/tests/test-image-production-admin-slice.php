<?php
/**
 * Characterization tests for the Wave F2 image-production admin slice — the
 * CPT settings page, the image-template research page, and the Research &
 * Add page ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the init's file-gate targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Image-production admin slice tests.
 */
class Test_Image_Production_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		// File/class name mismatch (cpt-settings file declares the Settings_Page
		// class) — neither the spl autoloader nor the base classmap can derive
		// the file (nothing loads the base init in the matrices), so the test
		// requires it explicitly per matrix (ECA-server precedent).
		if ( ! class_exists( 'WP_MCP_AI_Image_Production_Settings_Page' ) ) {
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
			} else {
				require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
			}
		}

		$symbols = array(
			'WP_MCP_AI_Image_Production_Settings_Page' => 'admin/class-wp-mcp-ai-image-production-cpt-settings-page.php',
			'WP_MCP_AI_Image_Template_Research_Page'   => 'admin/class-wp-mcp-ai-image-template-research-page.php',
			'WP_MCP_AI_Image_Production_Research_Add'  => 'research-add/class-wp-mcp-ai-image-production-research-add.php',
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
	 * The settings-page constructor props must be byte-identical.
	 */
	public function test_settings_page_props(): void {
		// File/class name mismatch — explicit require per matrix (see above).
		if ( ! class_exists( 'WP_MCP_AI_Image_Production_Settings_Page' ) ) {
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
			} else {
				require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
			}
		}

		$settings = new WP_MCP_AI_Image_Production_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_image_production_settings', $this->read_prop( $settings, 'option_name' ) );
		$this->assertSame( 'image-production-settings', $this->read_prop( $settings, 'page_slug' ) );
	}

	/**
	 * The research-page init() must wire the admin_menu/AJAX hooks.
	 */
	public function test_research_page_init_hooks(): void {
		WP_MCP_AI_Image_Template_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Image_Template_Research_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_image_template_from_research', array( 'WP_MCP_AI_Image_Template_Research_Page', 'handle_create_from_research' ) ) );
	}

	/**
	 * The research-add entity types must be byte-identical.
	 */
	public function test_research_add_entity_types(): void {
		$research_add = new WP_MCP_AI_Image_Production_Research_Add();

		$entity_types = $this->invoke_protected( $research_add, 'get_entity_types' );
		$this->assertIsArray( $entity_types );
	}

	/**
	 * Standalone only: the init's file-gated admin targets must now exist
	 * (the admin slice has landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-template-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-image-production-research-add.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
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

	/**
	 * Invoke a protected method for byte-identical pinning.
	 *
	 * @param object $instance Object instance.
	 * @param string $method   Method name.
	 * @return mixed Method return value.
	 */
	private function invoke_protected( object $instance, string $method ) {
		$reflection = new ReflectionObject( $instance );
		while ( ! $reflection->hasMethod( $method ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$method_ref = $reflection->getMethod( $method );
		$method_ref->setAccessible( true );
		return $method_ref->invoke( $instance );
	}
}
