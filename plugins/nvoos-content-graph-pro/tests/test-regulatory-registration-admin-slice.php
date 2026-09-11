<?php
/**
 * Characterization tests for the Wave F2 regulatory-registration admin slice —
 * the nine ported admin pages (regulatory product CPT settings, product
 * research & add, registration settings/dashboard/research, document
 * management/research, country requirements config, and the Excel migration
 * import page).
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
 * Regulatory-registration admin slice tests.
 */
class Test_Regulatory_Registration_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The regulatory-product settings page class name does not map 1:1 to
	 * its file name (`-cpt-settings-page.php`), so neither classmap serves
	 * it; the init requires it explicitly (is_admin-gated). Preload it the
	 * same way (docgen precedent).
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-regulatory-product-cpt-settings-page.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-regulatory-product-cpt-settings-page.php';
		}
	}

	/**
	 * The nine ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Regulatory_Product_Settings_Page' => 'admin/class-wp-mcp-ai-regulatory-product-cpt-settings-page.php',
			'WP_MCP_AI_Reg_Product_Research_Page'        => 'admin/class-wp-mcp-ai-reg-product-research-page.php',
			'WP_MCP_AI_Registration_Settings_Page'       => 'admin/class-wp-mcp-ai-registration-settings-page.php',
			'WP_MCP_AI_Registration_Dashboard_Page'      => 'admin/class-wp-mcp-ai-registration-dashboard-page.php',
			'WP_MCP_AI_Registration_Research_Page'       => 'admin/class-wp-mcp-ai-registration-research-page.php',
			'WP_MCP_AI_Reg_Document_Page'                => 'admin/class-wp-mcp-ai-reg-document-page.php',
			'WP_MCP_AI_Reg_Document_Research_Page'       => 'admin/class-wp-mcp-ai-reg-document-research-page.php',
			'WP_MCP_AI_Reg_Country_Config_Page'          => 'admin/class-wp-mcp-ai-reg-country-config-page.php',
			'WP_MCP_AI_Reg_Migration_Page'               => 'admin/class-wp-mcp-ai-reg-migration-page.php',
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
	 * The research-page slugs and settings-page constructor props must be
	 * byte-identical.
	 */
	public function test_page_slugs(): void {
		$this->assertSame( 'wp-mcp-ai-reg-product-research', WP_MCP_AI_Reg_Product_Research_Page::PAGE_SLUG );
		$this->assertSame( 'wp-mcp-ai-registration-research', WP_MCP_AI_Registration_Research_Page::PAGE_SLUG );
		$this->assertSame( 'wp-mcp-ai-reg-document-research', WP_MCP_AI_Reg_Document_Research_Page::PAGE_SLUG );

		$reg_product = new WP_MCP_AI_Regulatory_Product_Settings_Page();
		$this->assertSame( 'mcp_ai_reg_product', $this->read_prop( $reg_product, 'post_type' ) );
		$this->assertSame( 'regulatory-product-settings', $this->read_prop( $reg_product, 'page_slug' ) );
		$this->assertSame( 'wp_mcp_ai_reg_product_settings', $this->read_prop( $reg_product, 'option_name' ) );

		$registration = new WP_MCP_AI_Registration_Settings_Page();
		$this->assertSame( 'mcp_ai_registration', $this->read_prop( $registration, 'post_type' ) );
		$this->assertSame( 'registration-settings', $this->read_prop( $registration, 'page_slug' ) );
		$this->assertSame( 'wp_mcp_ai_registration_settings', $this->read_prop( $registration, 'option_name' ) );
	}

	/**
	 * init() must wire the byte-identical admin_menu/enqueue/AJAX hooks at
	 * the byte-identical priorities.
	 */
	public function test_init_hooks(): void {
		WP_MCP_AI_Registration_Dashboard_Page::init();
		$this->assertSame( 10, has_action( 'admin_menu', array( 'WP_MCP_AI_Registration_Dashboard_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Reg_Product_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Reg_Product_Research_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( 'WP_MCP_AI_Reg_Product_Research_Page', 'enqueue_assets' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_reg_product_from_research' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_import_reg_product' ) );

		WP_MCP_AI_Reg_Document_Page::init();
		$this->assertSame( 23, has_action( 'admin_menu', array( 'WP_MCP_AI_Reg_Document_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Reg_Country_Config_Page::init();
		$this->assertSame( 24, has_action( 'admin_menu', array( 'WP_MCP_AI_Reg_Country_Config_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Reg_Migration_Page::init();
		$this->assertSame( 25, has_action( 'admin_menu', array( 'WP_MCP_AI_Reg_Migration_Page', 'add_menu_page' ) ) );
	}

	/**
	 * register_settings() must register the byte-identical options/groups.
	 */
	public function test_register_settings(): void {
		$reg_product = new WP_MCP_AI_Regulatory_Product_Settings_Page();
		$reg_product->register_settings();
		$registered = get_registered_settings();
		$this->assertArrayHasKey( 'wp_mcp_ai_reg_product_settings', $registered );
		$this->assertSame( 'wp_mcp_ai_reg_product_settings_group', $registered['wp_mcp_ai_reg_product_settings']['group'] );

		$registration = new WP_MCP_AI_Registration_Settings_Page();
		$registration->register_settings();
		$registered = get_registered_settings();
		$this->assertArrayHasKey( 'wp_mcp_ai_registration_settings', $registered );
		$this->assertSame( 'wp_mcp_ai_registration_settings_group', $registered['wp_mcp_ai_registration_settings']['group'] );
	}

	/**
	 * sanitize_settings() must keep the byte-identical clamp contract
	 * (negative assistant ids clamp to 0 rather than absint-flipping).
	 */
	public function test_sanitize_contract(): void {
		$product   = new WP_MCP_AI_Regulatory_Product_Settings_Page();
		$sanitized = $product->sanitize_settings( array( 'assistant_id' => -5 ) );
		$this->assertSame( 0, $sanitized['assistant_id'] );

		$clean = $product->sanitize_settings( array( 'assistant_id' => 12 ) );
		$this->assertSame( 12, $clean['assistant_id'] );
	}

	/**
	 * Standalone only: the regulatory init's nine file-gated admin-page
	 * targets must now exist (the F2 admin slice has landed), so the gates
	 * activate at admin boot.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base regulatory init wires the pages at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-regulatory-product-cpt-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-reg-product-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-registration-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-registration-dashboard-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-registration-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-reg-document-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-reg-document-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-reg-country-config-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-reg-migration-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		// The research page self-boots at file load (byte-identical with the
		// base file's trailing init() call) — the menu callback must be wired
		// as soon as the file loads. First-loader-gated: if an earlier test
		// already autoloaded the class, its file-load hooks were wiped by the
		// per-test hook backup/restore, so init() re-proves the contract.
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-reg-product-research-page.php';
		} else {
			WP_MCP_AI_Reg_Product_Research_Page::init();
		}
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Reg_Product_Research_Page', 'add_menu_page' ) ) );
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
}
