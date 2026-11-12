<?php
/**
 * Parity suite: the Higgsfield image tool's workflow map and the capability
 * registry must describe the same models, endpoints and limits.
 *
 * Companion to tests/test-higgsfield-video-catalog-parity.php. Proposal 141
 * derives the image tool's schema enums from
 * includes/data/media-model-catalog.json, which only pays off if the two sources
 * cannot drift. Asserted in BOTH directions plus per-model field parity:
 *
 *   const  → registry : every IMAGE_MODELS workflow is registered
 *   registry → const   : the registry advertises no model this tool cannot serve
 *   fields             : endpoint, resolutions and batch sizes agree per model
 *   derivation         : the derived unions reproduce the ALLOWED_* constants
 *   fallback           : with no registry, the const still governs
 *
 * A failure here means: update IMAGE_MODELS and the registry together.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for Higgsfield image const ↔ registry parity.
 */
class Test_Higgsfield_Image_Catalog_Parity extends WP_UnitTestCase {

	/**
	 * Tool under test.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Higgsfield_Image
	 */
	protected $tool;

	/**
	 * Load dependencies and reset the registry cache.
	 */
	public function set_up() {
		parent::set_up();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-model-catalog.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-higgsfield-image.php';

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$this->tool = new WP_MCP_AI_Tool_Generate_Higgsfield_Image();
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
	 * Read the tool's verified workflow map.
	 *
	 * @return array
	 */
	private function get_declared_models() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Image' );

		return $reflection->getConstant( 'IMAGE_MODELS' );
	}

	/**
	 * Registry entries for this tool, keyed by model id.
	 *
	 * @return array<string,array>
	 */
	private function get_registered_models() {
		return WP_MCP_AI_Media_Model_Catalog::get_models( array( 'tool' => 'generate_higgsfield_image' ) );
	}

	/**
	 * Every workflow the tool can build an endpoint for is in the registry.
	 *
	 * Direction: const → registry.
	 */
	public function test_every_declared_model_is_registered() {
		$declared = array_keys( $this->get_declared_models() );
		$missing  = array_values( array_diff( $declared, array_keys( $this->get_registered_models() ) ) );

		$this->assertSame(
			array(),
			$missing,
			'IMAGE_MODELS declares workflows the capability registry does not list: ' . implode( ', ', $missing )
		);
	}

	/**
	 * The registry never advertises a workflow this tool cannot serve.
	 *
	 * Direction: registry → const.
	 */
	public function test_registry_advertises_no_unservable_model() {
		$declared = array_keys( $this->get_declared_models() );
		$extra    = array_values( array_diff( array_keys( $this->get_registered_models() ), $declared ) );

		$this->assertSame(
			array(),
			$extra,
			'The capability registry lists workflows IMAGE_MODELS cannot serve: ' . implode( ', ', $extra )
		);
	}

	/**
	 * Per-model endpoint, resolution and batch-size values agree.
	 *
	 * The schema only publishes unions; the payload is built per model. A silent
	 * disagreement between the two maps would send a request to the wrong
	 * endpoint or with an unsupported resolution.
	 */
	public function test_per_model_fields_match_the_registry() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Image' );
		$batch      = $reflection->getConstant( 'ALLOWED_BATCH_SIZES' );
		$registered = $this->get_registered_models();

		foreach ( $this->get_declared_models() as $id => $spec ) {
			$this->assertArrayHasKey( $id, $registered, "Registry is missing workflow {$id}" );

			$model = $registered[ $id ];

			$this->assertSame( (string) $spec['endpoint'], (string) $model['endpoint'], "Endpoint drift for {$id}" );

			$this->assertSame(
				array_values( $spec['resolutions'] ),
				WP_MCP_AI_Media_Model_Catalog::get_enum( $id, 'resolutions' ),
				"Resolutions drift for {$id}"
			);

			$this->assertSame(
				array_map( 'intval', $batch ),
				array_map( 'intval', WP_MCP_AI_Media_Model_Catalog::get_enum( $id, 'batch_sizes' ) ),
				"Batch sizes drift for {$id}"
			);
		}
	}

	/**
	 * The schema advertises exactly the const list, in const order.
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
	 * The derived unions reproduce the constants declared by the tool.
	 */
	public function test_derived_unions_match_allowed_constants() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Image' );

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_ASPECT_RATIOS' ),
			$this->tool->get_allowed_aspect_ratios(),
			'Derived aspect-ratio union diverged from ALLOWED_ASPECT_RATIOS'
		);

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_BATCH_SIZES' ),
			$this->tool->get_allowed_batch_sizes(),
			'Derived batch-size union diverged from ALLOWED_BATCH_SIZES'
		);

		// Resolutions are declared per workflow in IMAGE_MODELS, so the expected
		// union is derived from the const rather than restated.
		$expected_resolutions = array();

		foreach ( $this->get_declared_models() as $spec ) {
			foreach ( $spec['resolutions'] as $resolution ) {
				if ( ! in_array( $resolution, $expected_resolutions, true ) ) {
					$expected_resolutions[] = $resolution;
				}
			}
		}

		$this->assertSame( $expected_resolutions, $this->tool->get_allowed_resolutions() );
	}

	/**
	 * The schema enums are the derived values, not separate literals.
	 */
	public function test_schema_enums_are_the_derived_values() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertSame( $this->tool->get_allowed_aspect_ratios(), $schema['properties']['aspect_ratio']['enum'] );
		$this->assertSame( $this->tool->get_allowed_resolutions(), $schema['properties']['resolution']['enum'] );
		$this->assertSame( $this->tool->get_allowed_batch_sizes(), $schema['properties']['batch_size']['enum'] );
	}

	/**
	 * Without a registry the const still governs every derived list.
	 */
	public function test_missing_registry_falls_back_to_the_const() {
		add_filter(
			'wp_mcp_ai_media_model_catalog_path',
			static function () {
				return __DIR__ . '/fixtures/does-not-exist.json';
			}
		);

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Higgsfield_Image' );

		$this->assertSame(
			array_keys( $this->get_declared_models() ),
			$this->tool->get_supported_models()
		);

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_ASPECT_RATIOS' ),
			$this->tool->get_allowed_aspect_ratios()
		);

		$this->assertSame(
			$reflection->getConstant( 'ALLOWED_BATCH_SIZES' ),
			$this->tool->get_allowed_batch_sizes()
		);

		$this->assertSame( array( '720p', '1080p' ), $this->tool->get_allowed_resolutions() );
	}
}
