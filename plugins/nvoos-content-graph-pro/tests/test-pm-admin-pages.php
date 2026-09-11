<?php
/**
 * Characterization tests for the Wave F2 PM admin slice B — the ported
 * project/event/task research pages, the project/event settings pages,
 * and the event consolidate page (PM admin complete).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical constants and hook
 *   wiring are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full, including the file-gated init
 *   targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM admin slice B tests.
 */
class Test_Pm_Admin_Pages extends WP_UnitTestCase {

	/**
	 * Enable the PM toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings['enable_project_management'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The six ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Project_Research_Page'  => 'admin/class-wp-mcp-ai-project-research-page.php',
			'WP_MCP_AI_Project_Settings_Page'  => 'admin/class-wp-mcp-ai-project-settings-page.php',
			'WP_MCP_AI_Event_Research_Page'    => 'admin/class-wp-mcp-ai-event-research-page.php',
			'WP_MCP_AI_Event_Settings_Page'    => 'admin/class-wp-mcp-ai-event-settings-page.php',
			'WP_MCP_AI_Event_Consolidate_Page' => 'admin/class-wp-mcp-ai-event-consolidate-page.php',
			'WP_MCP_AI_Task_Research_Page'     => 'admin/class-wp-mcp-ai-task-research-page.php',
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
	 * The page slugs and options must be byte-identical.
	 */
	public function test_page_constants(): void {
		$this->assertSame( 'research-project', WP_MCP_AI_Project_Research_Page::PAGE_SLUG );
		$this->assertSame( 'research-event', WP_MCP_AI_Event_Research_Page::PAGE_SLUG );
		$this->assertSame( 'research-task', WP_MCP_AI_Task_Research_Page::PAGE_SLUG );

		$project_settings = new WP_MCP_AI_Project_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_project_settings', $this->read_prop( $project_settings, 'option_name' ) );
		$this->assertSame( 'project-settings', $this->read_prop( $project_settings, 'page_slug' ) );

		$event_settings = new WP_MCP_AI_Event_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_event_settings', $this->read_prop( $event_settings, 'option_name' ) );
		$this->assertSame( 'event-settings', $this->read_prop( $event_settings, 'page_slug' ) );
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
	 * init() must wire the admin_menu/AJAX hooks for the research pages.
	 */
	public function test_init_hooks(): void {
		WP_MCP_AI_Project_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Project_Research_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Event_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Event_Research_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Task_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Task_Research_Page', 'add_menu_page' ) ) );

		WP_MCP_AI_Event_Consolidate_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Event_Consolidate_Page', 'add_menu_page' ) ) );
	}

	/**
	 * Standalone only: the PM init's file-gated targets for the remaining
	 * research/settings/consolidate pages must now exist (the PM admin
	 * slice is complete).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base PM init wires the pages at boot.' );
		}

		$targets = array(
			'class-wp-mcp-ai-project-research-page.php',
			'class-wp-mcp-ai-project-settings-page.php',
			'class-wp-mcp-ai-event-research-page.php',
			'class-wp-mcp-ai-event-settings-page.php',
			'class-wp-mcp-ai-event-consolidate-page.php',
			'class-wp-mcp-ai-task-research-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/' . $target );
		}
	}
}
