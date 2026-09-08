<?php
/**
 * Characterization tests for the Wave F2 e-commerce admin pages batch —
 * the ported E-commerce Toolkit Settings / Product Research & Add /
 * Product Consolidate & Add / Product Settings pages plus their shared
 * bases and research-page traits.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical constants, props,
 *   hooks, and registered settings are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full, including the serving sources and
 *   the file-gated init targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * E-commerce admin pages tests.
 */
class Test_Ecommerce_Admin_Pages extends WP_UnitTestCase {

	/**
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Ecommerce_Settings_Page'      => 'admin/class-wp-mcp-ai-ecommerce-settings-page.php',
			'WP_MCP_AI_Product_Research_Page'        => 'admin/class-wp-mcp-ai-product-research-page.php',
			'WP_MCP_AI_Product_Consolidate_Page'     => 'admin/class-wp-mcp-ai-product-consolidate-page.php',
			'WP_MCP_AI_Product_Settings_Page'        => 'admin/class-wp-mcp-ai-product-settings-page.php',
			'WP_MCP_AI_CPT_Settings_Page_Base'       => 'admin/class-wp-mcp-ai-cpt-settings-page-base.php',
			'WP_MCP_AI_Consolidate_Add_Base'         => 'admin/class-wp-mcp-ai-consolidate-add-base.php',
			'WP_MCP_AI_Research_Page_Featured_Image' => 'admin/trait-wp-mcp-ai-research-page-featured-image.php',
			'WP_MCP_AI_Research_Page_Import_Handler' => 'admin/trait-wp-mcp-ai-research-page-enhancements.php',
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
	 * The page slugs and constructor props must be byte-identical.
	 */
	public function test_page_slugs(): void {
		$this->assertSame( 'research-product', WP_MCP_AI_Product_Research_Page::PAGE_SLUG );
		$this->assertSame( 'product-consolidate', WP_MCP_AI_Product_Consolidate_Page::PAGE_SLUG );

		$ecommerce = new WP_MCP_AI_Ecommerce_Settings_Page();
		$this->assertSame( 'ecommerce', $this->read_prop( $ecommerce, 'toolkit_slug' ) );
		$this->assertSame( 'wp_mcp_ai_ecommerce_toolkit_settings', $this->read_prop( $ecommerce, 'option_name' ) );
		$this->assertSame( 'wp-mcp-ai-ecommerce-toolkit-settings', $this->read_prop( $ecommerce, 'page_slug' ) );
		$this->assertSame( 'wp-mcp-ai-ecommerce-toolkit', $this->read_prop( $ecommerce, 'parent_slug' ) );

		$product = new WP_MCP_AI_Product_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_product_settings', $this->read_prop( $product, 'option_name' ) );
		$this->assertSame( 'product', $this->read_prop( $product, 'post_type' ) );
		$this->assertSame( 'product-research-settings', $this->read_prop( $product, 'page_slug' ) );
	}

	/**
	 * Read a protected/private property for byte-identical pinning.
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
	 * init() must wire the admin_menu/enqueue/AJAX hooks.
	 */
	public function test_init_hooks(): void {
		WP_MCP_AI_Product_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Product_Research_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( 'WP_MCP_AI_Product_Research_Page', 'enqueue_assets' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_product_from_research' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_import_product' ) );

		WP_MCP_AI_Product_Consolidate_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Product_Consolidate_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( 'WP_MCP_AI_Product_Consolidate_Page', 'enqueue_assets' ) ) );
	}

	/**
	 * register_settings() must register the byte-identical options/groups.
	 */
	public function test_register_settings(): void {
		$product = new WP_MCP_AI_Product_Settings_Page();
		$product->register_settings();
		$registered = get_registered_settings();
		$this->assertArrayHasKey( 'wp_mcp_ai_product_settings', $registered );
		$this->assertSame( 'wp_mcp_ai_product_settings_group', $registered['wp_mcp_ai_product_settings']['group'] );
		$this->assertArrayHasKey( 'wp_mcp_ai_ecommerce_toolkit_settings', $registered );

		$ecommerce = new WP_MCP_AI_Ecommerce_Settings_Page();
		$ecommerce->register_settings();
		$registered = get_registered_settings();
		$this->assertArrayHasKey( 'wp_mcp_ai_ecommerce_toolkit_settings', $registered );
		$this->assertSame( 'wp_mcp_ai_ecommerce_toolkit_settings_group', $registered['wp_mcp_ai_ecommerce_toolkit_settings']['group'] );
	}

	/**
	 * sanitize_settings() must keep the byte-identical clamp contract
	 * (negative assistant ids clamp to 0 rather than absint-flipping).
	 */
	public function test_sanitize_contract(): void {
		$product   = new WP_MCP_AI_Product_Settings_Page();
		$sanitized = $product->sanitize_settings( array( 'assistant_id' => -5 ) );
		$this->assertSame( 0, $sanitized['assistant_id'] );

		$clean = $product->sanitize_settings( array( 'assistant_id' => 12 ) );
		$this->assertSame( 12, $clean['assistant_id'] );
	}

	/**
	 * Standalone only: the e-commerce init's four file-gated admin-page
	 * targets must now exist (the F2 admin slice has landed), so the gates
	 * activate at admin boot.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base e-commerce init wires the pages at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-ecommerce-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-product-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-product-consolidate-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-product-settings-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		// The research page self-boots at file load (byte-identical with the
		// base file's trailing init() call) — the menu callback must be wired
		// as soon as the file loads. First-loader-gated: if an earlier test
		// already autoloaded the class, its file-load hooks were wiped by the
		// per-test hook backup/restore, so init() re-proves the contract.
		if ( ! class_exists( 'WP_MCP_AI_Product_Research_Page' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-product-research-page.php';
		} else {
			WP_MCP_AI_Product_Research_Page::init();
		}
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Product_Research_Page', 'add_menu_page' ) ) );
	}
}
