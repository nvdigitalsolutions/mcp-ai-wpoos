<?php
/**
 * Parity suite: the Higgsfield video tool's endpoint map and the capability
 * registry must describe the same model set.
 *
 * Proposal 141 derives the tool's schema enums from
 * includes/data/media-model-catalog.json. That only pays off if the two sources
 * cannot drift, so this suite asserts the relationship in BOTH directions plus
 * the derived values the schema now publishes:
 *
 *   const  → registry : every VIDEO_MODELS entry is registered
 *   registry → const   : the registry advertises no model this tool cannot serve
 *   derivation         : the derived unions/bounds reproduce the ALLOWED_*
 *                        constants and the const's duration span exactly
 *   fallback           : with no registry, the const still governs
 *
 * A failure here means: update VIDEO_MODELS and the registry together.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for Higgsfield video const ↔ registry parity.
 */
class Test_Higgsfield_Video_Catalog_Parity extends WP_UnitTestCase {

	/**
	 * Tool under test.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Higgsfield_Video
	 */
	protected $tool;

	/**
	 * Load dependencies and reset the registry cache.
	 */
	public function set_up() {
		parent::set_up();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-model-catalog.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-video.php';

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$this->tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Video();
	}

	/**
	 * Restore filters and cache.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_media_model_catalog_path' );
		WP_MCP_AI_Media_Model_Catalog::reset_cache();
		parent::tear_down();
	}

	/**
	 * Read the tool's verified endpoint map.
	 *
	 * @return array
	 */
	private function get_declared_models() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Video' );

		return $reflection->getConstant( 'VIDEO_MODELS' );
	}

	/**
	 * Every model the tool can build an endpoint for is in the registry.
	 *
	 * Direction: const → registry.
	 */
	public function test_every_declared_model_is_registered() {
		$declared   = array_keys( $this->get_declared_models() );
		$registered = array_keys(
			WP_MCP_AI_Media_Model_Catalog::get_models( array( 'tool' => 'generate_higgsfield_video' ) )
		);

		$missing = array_values( array_diff( $declared, $registered ) );

		$this->assertSame(
			array(),
			$missing,
			'VIDEO_MODELS declares models the capability registry does not list: ' . implode( ', ', $missing )
		);
	}

	/**
	 * The registry never advertises a model this tool cannot serve.
	 *
	 * Direction: registry → const. This is the direction that would otherwise
	 * let the schema offer a model whose endpoint lookup silently falls back to
	 * the default model.
	 */
	public function test_registry_advertises_no_unservable_model() {
		$declared   = array_keys( $this->get_declared_models() );
		$registered = array_keys(
			WP_MCP_AI_Media_Model_Catalog::get_models( array( 'tool' => 'generate_higgsfield_video' ) )
		);

		$extra = array_values( array_diff( $registered, $declared ) );

		$this->assertSame(
			array(),
			$extra,
			'The capability registry lists models VIDEO_MODELS cannot serve: ' . implode( ', ', $extra )
		);
	}

	/**
	 * The schema advertises exactly the const list, in const order.
	 *
	 * Order matters: it is what makes the documented default model the first
	 * entry a reader sees, and it is what the pre-141 schema published.
	 */
	public function test_schema_model_enum_matches_const_exactly() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertSame(
			array_keys( $this->get_declared_models() ),
			$schema['properties']['model']['enum']
		);
	}

	/**
	 * The supported-model list can only ever narrow, never invent.
	 */
	public function test_supported_models_are_a_subset_of_the_const() {
		$declared = array_keys( $this->get_declared_models() );

		foreach ( $this->tool->get_supported_models() as $model ) {
			$this->assertContains( $model, $declared, "get_supported_models() returned unservable model {$model}" );
		}
	}

	/**
	 * The derived aspect-ratio and resolution unions reproduce the constants.
	 *
	 * This is the regression guard for "deriving changed the schema": if a
	 * future model adds a new value, this test fails and the ALLOWED_* constant
	 * (plus this expectation) must be updated deliberately.
	 */
	public function test_derived_unions_match_allowed_constants() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Video' );

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_ASPECT_RATIOS' ),
			$this->tool->get_allowed_aspect_ratios(),
			'Derived aspect-ratio union diverged from ALLOWED_ASPECT_RATIOS'
		);

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_RESOLUTIONS' ),
			$this->tool->get_allowed_resolutions(),
			'Derived resolution union diverged from ALLOWED_RESOLUTIONS'
		);
	}

	/**
	 * The derived duration bounds span every declared model.
	 */
	public function test_derived_duration_bounds_match_const_span() {
		$models = $this->get_declared_models();

		$min = PHP_INT_MAX;
		$max = 0;

		foreach ( $models as $spec ) {
			$min = min( $min, (int) $spec['duration'][0] );
			$max = max( $max, (int) $spec['duration'][1] );
		}

		$bounds = $this->tool->get_duration_bounds();

		$this->assertSame( $min, $bounds['min'] );
		$this->assertSame( $max, $bounds['max'] );

		$schema = $this->tool->get_parameters_schema();

		$this->assertSame( $min, $schema['properties']['duration']['minimum'] );
		$this->assertSame( $max, $schema['properties']['duration']['maximum'] );
	}

	/**
	 * The schema enums are the derived values, not separate literals.
	 */
	public function test_schema_enums_are_the_derived_values() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertSame( $this->tool->get_allowed_aspect_ratios(), $schema['properties']['aspect_ratio']['enum'] );
		$this->assertSame( $this->tool->get_allowed_resolutions(), $schema['properties']['resolution']['enum'] );
	}

	/**
	 * Without a registry the const still governs, and the legacy duration span
	 * is preserved rather than collapsing to the default.
	 */
	public function test_missing_registry_falls_back_to_the_const() {
		add_filter(
			'wp_mcp_ai_media_model_catalog_path',
			static function () {
				return __DIR__ . '/fixtures/does-not-exist.json';
			}
		);

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Video' );

		$this->assertSame(
			array_keys( $this->get_declared_models() ),
			$this->tool->get_supported_models()
		);

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_ASPECT_RATIOS' ),
			$this->tool->get_allowed_aspect_ratios()
		);

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_RESOLUTIONS' ),
			$this->tool->get_allowed_resolutions()
		);

		$this->assertSame(
			array(
				'min' => 2,
				'max' => 30,
			),
			$this->tool->get_duration_bounds()
		);
	}
}
