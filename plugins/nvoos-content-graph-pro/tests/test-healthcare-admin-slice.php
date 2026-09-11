<?php
/**
 * Characterization tests for the Wave F4 healthcare admin slice — the eight
 * ported admin pages (imaging viewer, member settings/research, policy
 * settings/research, health-records consolidate, health-wellness dashboard,
 * medical-vitals dashboard).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical constants, props, and hooks are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full, including the file-gated init
 *   targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare admin slice tests.
 */
class Test_Healthcare_Admin_Slice extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eight gated base files deterministically
	 * (the settings pages' class names map 1:1, but the gated map does not
	 * guarantee them loaded at registry-init time). Standalone: the entry
	 * autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'admin/class-wp-mcp-ai-imaging-admin-page.php',
				'admin/class-wp-mcp-ai-member-settings-page.php',
				'admin/class-wp-mcp-ai-member-research-page.php',
				'admin/class-wp-mcp-ai-policy-research-page.php',
				'admin/class-wp-mcp-ai-policy-settings-page.php',
				'admin/class-wp-mcp-ai-health-records-consolidate-page.php',
				'admin/class-wp-mcp-ai-health-wellness-dashboard-page.php',
				'admin/class-wp-mcp-ai-medical-vitals-dashboard-page.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Imaging_Admin_Page'              => 'admin/class-wp-mcp-ai-imaging-admin-page.php',
			'WP_MCP_AI_Member_Settings_Page'            => 'admin/class-wp-mcp-ai-member-settings-page.php',
			'WP_MCP_AI_Member_Research_Page'            => 'admin/class-wp-mcp-ai-member-research-page.php',
			'WP_MCP_AI_Policy_Research_Page'            => 'admin/class-wp-mcp-ai-policy-research-page.php',
			'WP_MCP_AI_Policy_Settings_Page'            => 'admin/class-wp-mcp-ai-policy-settings-page.php',
			'WP_MCP_AI_Health_Records_Consolidate_Page' => 'admin/class-wp-mcp-ai-health-records-consolidate-page.php',
			'WP_MCP_AI_Health_Wellness_Dashboard_Page'  => 'admin/class-wp-mcp-ai-health-wellness-dashboard-page.php',
			'WP_MCP_AI_Medical_Vitals_Dashboard_Page'   => 'admin/class-wp-mcp-ai-medical-vitals-dashboard-page.php',
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
	 * The page slugs and settings constructor props must be byte-identical.
	 */
	public function test_page_contracts(): void {
		$this->assertSame( 'healthcare-imaging-viewer', WP_MCP_AI_Imaging_Admin_Page::PAGE_SLUG );
		$this->assertSame( 'assets/vendor/cornerstone', WP_MCP_AI_Imaging_Admin_Page::VENDOR_CORNERSTONE_DIR );
		$this->assertSame( 'research-policy', WP_MCP_AI_Policy_Research_Page::PAGE_SLUG );
		$this->assertSame( 'health-wellness-dashboard', WP_MCP_AI_Health_Wellness_Dashboard_Page::PAGE_SLUG );
		$this->assertSame( 'medical-vitals-dashboard', WP_MCP_AI_Medical_Vitals_Dashboard_Page::PAGE_SLUG );

		$member = new WP_MCP_AI_Member_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_member_settings', $this->read_prop( $member, 'option_name' ) );
		$this->assertSame( 'mcp_ai_member', $this->read_prop( $member, 'post_type' ) );
		$this->assertSame( 'member-settings', $this->read_prop( $member, 'page_slug' ) );

		$policy = new WP_MCP_AI_Policy_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_policy_settings', $this->read_prop( $policy, 'option_name' ) );
		$this->assertSame( 'mcp_ai_policy', $this->read_prop( $policy, 'post_type' ) );
		$this->assertSame( 'policy-settings', $this->read_prop( $policy, 'page_slug' ) );
	}

	/**
	 * init() must wire the byte-identical admin_menu hooks at the
	 * byte-identical priorities.
	 */
	public function test_init_hooks(): void {
		WP_MCP_AI_Health_Wellness_Dashboard_Page::init();
		$this->assertSame( 24, has_action( 'admin_menu', array( 'WP_MCP_AI_Health_Wellness_Dashboard_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Medical_Vitals_Dashboard_Page::init();
		$this->assertSame( 25, has_action( 'admin_menu', array( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Imaging_Admin_Page::init();
		$this->assertSame( 30, has_action( 'admin_menu', array( 'WP_MCP_AI_Imaging_Admin_Page', 'add_menu_page' ) ) );
	}

	/**
	 * Standalone only: the wellness init's seven file-gated admin-page
	 * targets and the imaging-init's admin-page target must now exist (the
	 * healthcare admin slice has landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base inits wire the pages at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-member-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-member-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-policy-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-policy-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-health-records-consolidate-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-health-wellness-dashboard-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-medical-vitals-dashboard-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-imaging-admin-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
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
