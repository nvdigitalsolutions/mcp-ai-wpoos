<?php
/**
 * GTmetrix Tools + Registration Tests — envelopes in fixture mode, preset surfaces.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: tool envelopes and preset registration.
 */
class Test_GTmetrix_Tools_And_Registration extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/gtmetrix';

		foreach ( array( 'settings', 'fixture-reader', 'client', 'score-engine', 'fix-engine', 'tool-base' ) as $service ) {
			$file = $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-' . $service . '.php';
			if ( ! class_exists( $this->class_for( $service ) ) && file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Enable the toolkit toggle for the guard.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_gtmetrix_toolkit' => true )
		);

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Class name for a service file.
	 *
	 * @param string $service Service slug.
	 * @return string
	 */
	private function class_for( $service ) {
		$map = array(
			'settings'       => 'WP_MCP_AI_GTmetrix_Settings',
			'fixture-reader' => 'WP_MCP_AI_GTmetrix_Fixture_Reader',
			'client'         => 'WP_MCP_AI_GTmetrix_Client',
			'score-engine'   => 'WP_MCP_AI_GTmetrix_Score_Engine',
			'fix-engine'     => 'WP_MCP_AI_GTmetrix_Fix_Engine',
			'tool-base'      => 'WP_MCP_AI_GTmetrix_Tool_Base',
		);

		return isset( $map[ $service ] ) ? $map[ $service ] : '';
	}

	/**
	 * Skip when the toolkit files are unavailable.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Tool_Base' ) ) {
			$this->markTestSkipped( 'GTmetrix toolkit not available' );
		}
	}

	/**
	 * Tool file for a slug.
	 *
	 * @param string $slug Tool slug.
	 * @return string
	 */
	private function tool_file( $slug ) {
		$base = str_replace( 'gtmetrix_', '', $slug );
		$base = str_replace( '_', '-', $base );

		return $this->toolkit_dir . '/class-wp-mcp-ai-tool-gtmetrix-' . $base . '.php';
	}

	/**
	 * Test: read tools return the canonical envelope in fixture mode.
	 *
	 * @dataProvider data_read_tools
	 *
	 * @param string $slug      Tool slug.
	 * @param array  $arguments Tool arguments.
	 */
	public function test_read_tool_envelopes( $slug, $arguments ) {
		$this->maybe_skip();

		$file = $this->tool_file( $slug );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( "Tool file missing: {$slug}" );
		}
		require_once $file;

		$classes = get_declared_classes();
		$tool    = null;
		foreach ( $classes as $class ) {
			if ( 0 === strpos( $class, 'WP_MCP_AI_Tool_GTmetrix_' ) && is_subclass_of( $class, 'WP_MCP_AI_GTmetrix_Tool_Base' ) ) {
				$instance = new $class();
				if ( $instance->get_slug() === $slug ) {
					$tool = $instance;
					break;
				}
			}
		}

		$this->assertNotNull( $tool, "Tool not found: {$slug}" );

		$result = $tool->execute( $arguments, array( 'user_id' => get_current_user_id() ) );

		$this->assertNotWPError( $result, $slug . ' returned an error' );
		$this->assertIsArray( $result );
		$this->assertTrue( isset( $result['success'] ) && true === $result['success'], $slug . ' missing success envelope' );
	}

	/**
	 * Read-tool matrix.
	 *
	 * @return array
	 */
	public static function data_read_tools() {
		return array(
			array( 'gtmetrix_account_status', array() ),
			array( 'gtmetrix_list_locations', array() ),
			array( 'gtmetrix_list_browsers', array() ),
			array( 'gtmetrix_list_simulated_devices', array() ),
			array( 'gtmetrix_get_test_status', array( 'test_id' => 'T1Q' ) ),
			array( 'gtmetrix_list_tests', array() ),
			array( 'gtmetrix_get_report', array( 'report_id' => 'RGOOD' ) ),
			array( 'gtmetrix_get_lighthouse_audits', array( 'report_id' => 'RGOOD' ) ),
			array( 'gtmetrix_get_resource_summary', array( 'report_id' => 'RGOOD' ) ),
			array( 'gtmetrix_list_report_resources', array( 'report_id' => 'RGOOD' ) ),
			array( 'gtmetrix_analyze_report', array( 'report_id' => 'RGOOD' ) ),
			array( 'gtmetrix_list_pages', array() ),
			array( 'gtmetrix_get_page', array( 'page_id' => 'P1' ) ),
			array( 'gtmetrix_get_alert_events', array( 'page_id' => 'P1' ) ),
		);
	}

	/**
	 * Test: every registered tool slug appears in the TOOLS_LIST manifest.
	 */
	public function test_tools_list_manifest() {
		$this->maybe_skip();

		$file = $this->toolkit_dir . '/TOOLS_LIST.txt';
		$this->assertFileExists( $file );

		$slugs = array_filter( array_map( 'trim', file( $file ) ) );
		$this->assertCount( 23, $slugs );
		$this->assertContains( 'gtmetrix_start_test', $slugs );
		$this->assertContains( 'gtmetrix_install_wpcode_fix', $slugs );
	}

	/**
	 * Test: schedule and workflow presets expose the gtmetrix toolkit surface.
	 */
	public function test_presets_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) || ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			$this->markTestSkipped( 'Preset classes not available in this test context' );
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Presets::get_presets_by_toolkit( 'gtmetrix' );
		$this->assertArrayHasKey( 'gtmetrix_weekly_sweep', $schedule );
		$this->assertArrayHasKey( 'gtmetrix_monthly_review', $schedule );

		$workflows = WP_MCP_AI_Pro_Workflow_Presets::get_presets_by_category( 'gtmetrix' );
		$this->assertArrayHasKey( 'gtmetrix_audit_fix_pipeline', $workflows );
		$this->assertArrayHasKey( 'gtmetrix_deploy_regression_gate', $workflows );

		// Every workflow preset carries the required keys incl. edges.
		foreach ( $workflows as $id => $preset ) {
			foreach ( array( 'name', 'description', 'category', 'icon', 'tags', 'nodes', 'edges' ) as $key ) {
				$this->assertArrayHasKey( $key, $preset, $id . ' missing ' . $key );
			}
			foreach ( $preset['edges'] as $edge ) {
				$this->assertArrayHasKey( 'id', $edge );
				$this->assertArrayHasKey( 'source', $edge );
				$this->assertArrayHasKey( 'target', $edge );
				$this->assertArrayHasKey( 'sourceHandle', $edge );
			}
		}
	}
}
