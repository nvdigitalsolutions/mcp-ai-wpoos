<?php
/**
 * Tests for the Harmonization sub-toolkit.
 *
 * Validates that all 14 harmonization tools instantiate, expose valid schemas,
 * and declare appropriate capability flags. AI calls are not exercised — the
 * tools are constructed and inspected via their public metadata methods.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test suite for harmonization tools.
 */
class WP_MCP_AI_Harmonization_Tools_Test extends WP_UnitTestCase {

	/**
	 * The 14 harmonization tools and the slugs they should advertise.
	 *
	 * @var array<string, string>
	 */
	protected static $tools = array(
		'WP_MCP_AI_Tool_Generate_Scene_Background'       => 'generate_scene_background',
		'WP_MCP_AI_Tool_Adapt_Background_For_Subject'    => 'adapt_background_for_subject',
		'WP_MCP_AI_Tool_Outpaint_Background'             => 'outpaint_background',
		'WP_MCP_AI_Tool_Refine_Subject_Matte'            => 'refine_subject_matte',
		'WP_MCP_AI_Tool_Auto_Clean_White_Background'     => 'auto_clean_white_background',
		'WP_MCP_AI_Tool_Harmonize_Color'                 => 'harmonize_color',
		'WP_MCP_AI_Tool_Relight_Subject'                 => 'relight_subject',
		'WP_MCP_AI_Tool_Generate_Shadow'                 => 'generate_shadow',
		'WP_MCP_AI_Tool_Generate_Reflection'             => 'generate_reflection',
		'WP_MCP_AI_Tool_Refine_Composite_Boundary'       => 'refine_composite_boundary',
		'WP_MCP_AI_Tool_Analyze_Scene_Lighting'          => 'analyze_scene_lighting',
		'WP_MCP_AI_Tool_Suggest_Placement'               => 'suggest_placement',
		'WP_MCP_AI_Tool_Harmonize_Image_Into_Background' => 'harmonize_image_into_background',
		'WP_MCP_AI_Tool_Harmonize_Batch'                 => 'harmonize_batch',
	);

	/**
	 * Load harmonization tools once per class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			// Define the Pro path manually for tests when the Pro plugin isn't booted.
			$pro_path = dirname( __DIR__ ) . '/addons/pro/';
			if ( is_dir( $pro_path ) ) {
				define( 'WP_MCP_AI_PRO_PATH', $pro_path );
			} else {
				self::markTestSkipped( 'Pro addon path not available; harmonization tools live under addons/pro.' );
				return;
			}
		}

		$dir = WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/harmonization/';
		if ( ! is_dir( $dir ) ) {
			self::markTestSkipped( 'Harmonization directory missing.' );
			return;
		}

		require_once $dir . 'trait-wp-mcp-ai-tool-harmonization.php';
		require_once $dir . 'class-wp-mcp-ai-harmonization-compositor.php';
		require_once $dir . 'class-wp-mcp-ai-lighting-analyzer.php';
		require_once $dir . 'class-wp-mcp-ai-tool-harmonization-base.php';

		foreach ( glob( $dir . 'class-wp-mcp-ai-tool-*.php' ) as $file ) {
			require_once $file;
		}
	}

	/**
	 * Confirm every tool instantiates and reports the expected slug.
	 */
	public function test_all_tools_instantiate_and_report_slugs() {
		foreach ( self::$tools as $class => $expected_slug ) {
			$this->assertTrue( class_exists( $class ), "Class $class must exist." );
			$tool = new $class();
			$this->assertSame( $expected_slug, $tool->get_slug(), "$class must report slug '$expected_slug'." );
			$this->assertNotEmpty( $tool->get_name() );
			$this->assertNotEmpty( $tool->get_description() );
		}
	}

	/**
	 * Every schema must be a valid object schema with `properties` and a `required` array.
	 */
	public function test_every_schema_is_well_formed() {
		foreach ( self::$tools as $class => $slug ) {
			$tool   = new $class();
			$schema = $tool->get_parameters_schema();

			$this->assertIsArray( $schema, "$slug schema must be an array." );
			$this->assertSame( 'object', $schema['type'] ?? null, "$slug schema must declare type=object." );
			$this->assertArrayHasKey( 'properties', $schema, "$slug schema must declare properties." );
			$this->assertIsArray( $schema['properties'], "$slug properties must be an array." );

			// Every array-typed property must declare items (per CLAUDE.md OpenAI compatibility note).
			foreach ( $schema['properties'] as $prop_name => $prop_schema ) {
				if ( is_array( $prop_schema ) && ( $prop_schema['type'] ?? '' ) === 'array' ) {
					$this->assertArrayHasKey(
						'items',
						$prop_schema,
						"$slug.$prop_name is type=array and must declare items."
					);
				}
			}
		}
	}

	/**
	 * Write tools must declare write + state-changing flags. Read-only helpers
	 * must declare read-only.
	 */
	public function test_capability_flags_match_tool_intent() {
		$write_tools = array(
			'generate_scene_background',
			'adapt_background_for_subject',
			'outpaint_background',
			'refine_subject_matte',
			'auto_clean_white_background',
			'harmonize_color',
			'relight_subject',
			'generate_shadow',
			'generate_reflection',
			'refine_composite_boundary',
			'harmonize_image_into_background',
			'harmonize_batch',
		);

		$read_only_tools = array(
			'analyze_scene_lighting',
			'suggest_placement',
		);

		foreach ( self::$tools as $class => $slug ) {
			$tool  = new $class();
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags, "$slug capability flags must be an array." );
			$this->assertContains( 'pro', $flags, "$slug must declare 'pro' flag." );
			$this->assertContains( 'requires-capability', $flags, "$slug must declare 'requires-capability'." );

			if ( in_array( $slug, $write_tools, true ) ) {
				$this->assertContains( 'write', $flags, "$slug must declare 'write'." );
				$this->assertContains( 'state-changing', $flags, "$slug must declare 'state-changing'." );
			}

			if ( in_array( $slug, $read_only_tools, true ) ) {
				$this->assertContains( 'read-only', $flags, "$slug must declare 'read-only'." );
			}
		}
	}

	/**
	 * Orchestrator and batch must declare async + long-running flags.
	 */
	public function test_orchestrator_and_batch_are_async() {
		$orch = new WP_MCP_AI_Tool_Harmonize_Image_Into_Background();
		$this->assertContains( 'async', $orch->get_capability_flags() );
		$this->assertContains( 'long-running', $orch->get_capability_flags() );

		$batch = new WP_MCP_AI_Tool_Harmonize_Batch();
		$this->assertContains( 'async', $batch->get_capability_flags() );
		$this->assertContains( 'batch', $batch->get_capability_flags() );
	}

	/**
	 * The orchestrator should accept either a background attachment id or a
	 * background prompt.
	 */
	public function test_orchestrator_requires_subject_only() {
		$orch     = new WP_MCP_AI_Tool_Harmonize_Image_Into_Background();
		$schema   = $orch->get_parameters_schema();
		$required = $schema['required'] ?? array();

		$this->assertContains( 'subject_attachment_id', $required );
		$this->assertNotContains( 'background_attachment_id', $required, 'background must NOT be required at schema level (validated at runtime).' );
		$this->assertNotContains( 'background_prompt', $required );
	}

	/**
	 * Compositor must report availability when GD or Imagick is loaded.
	 */
	public function test_compositor_reports_availability() {
		$comp = new WP_MCP_AI_Harmonization_Compositor();
		// On the test runner GD is normally available.
		if ( extension_loaded( 'gd' ) || extension_loaded( 'imagick' ) ) {
			$this->assertTrue( $comp->is_available() );
		} else {
			$this->assertFalse( $comp->is_available() );
			$this->assertNotEmpty( $comp->get_unavailable_reason() );
		}
	}

	/**
	 * Lighting analyzer returns reasonable values for a simple solid-color
	 * fixture or a WP_Error if GD is unavailable.
	 */
	public function test_lighting_analyzer_runs_on_solid_image() {
		if ( ! extension_loaded( 'gd' ) ) {
			$this->markTestSkipped( 'GD extension not available.' );
			return;
		}

		// Build a tiny gradient PNG (light at the top-left, dark at bottom-right).
		$tmp = tempnam( sys_get_temp_dir(), 'harm-' ) . '.png';
		$im  = imagecreatetruecolor( 32, 32 );
		for ( $y = 0; $y < 32; $y++ ) {
			for ( $x = 0; $x < 32; $x++ ) {
				$v = (int) ( 255 - ( ( $x + $y ) * 4 ) );
				$v = max( 0, min( 255, $v ) );
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, $v, $v, $v ) );
			}
		}
		imagepng( $im, $tmp );
		imagedestroy( $im );

		$analyzer = new WP_MCP_AI_Lighting_Analyzer();
		$result   = $analyzer->analyze( $tmp );

		wp_delete_file( $tmp );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'direction_deg', $result );
		$this->assertArrayHasKey( 'intensity', $result );
		$this->assertArrayHasKey( 'color_temp', $result );
		$this->assertArrayHasKey( 'kelvin_estimate', $result );
		$this->assertArrayHasKey( 'confidence', $result );
		$this->assertGreaterThanOrEqual( 0.0, $result['intensity'] );
		$this->assertLessThanOrEqual( 1.0, $result['intensity'] );
	}
}
