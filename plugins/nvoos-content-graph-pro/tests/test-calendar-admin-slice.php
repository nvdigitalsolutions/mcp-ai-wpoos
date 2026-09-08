<?php
/**
 * Characterization tests for the Wave F2 calendar admin slice — the
 * calendar-booking research page and the toolkit settings page ported from
 * the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the page
 *   classes (classmap-autoloaded); the byte-identical surfaces and hooks
 *   are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full, including the init's file-gate
 *   targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Calendar admin slice tests.
 */
class Test_Calendar_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Calendar_Booking_Research_Page' => 'admin/class-wp-mcp-ai-calendar-booking-research-page.php',
			'WP_MCP_AI_Calendar_Booking_Settings_Page' => 'admin/class-wp-mcp-ai-calendar-booking-settings-page.php',
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
		$this->assertSame( 'research-appointment', WP_MCP_AI_Calendar_Booking_Research_Page::PAGE_SLUG );

		$settings = new WP_MCP_AI_Calendar_Booking_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_calendar_booking_toolkit_settings', $this->read_prop( $settings, 'option_name' ) );
		$this->assertSame( 'wp-mcp-ai-calendar-booking-toolkit-settings', $this->read_prop( $settings, 'page_slug' ) );
		$this->assertSame( 'calendar_booking', $this->read_prop( $settings, 'toolkit_slug' ) );
	}

	/**
	 * init() must wire the admin_menu/AJAX hooks for the research page.
	 */
	public function test_init_hooks(): void {
		WP_MCP_AI_Calendar_Booking_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Calendar_Booking_Research_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_appointment_from_research', array( 'WP_MCP_AI_Calendar_Booking_Research_Page', 'handle_create_from_research' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_import_appointment', array( 'WP_MCP_AI_Calendar_Booking_Research_Page', 'ajax_handle_import' ) ) );
	}

	/**
	 * Standalone only: the calendar init's file-gated admin targets must now
	 * exist (the admin slice has landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base calendar init wires the admin slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-calendar-booking-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-calendar-booking-settings-page.php',
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
}
