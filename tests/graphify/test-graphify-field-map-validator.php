<?php
/**
 * Tests for the field-map validator — Field-mapping admin UI.
 *
 * @package NV_oOS_Graphify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Field_Map_Validator
 */
class Test_Graphify_Field_Map_Validator extends WP_UnitTestCase {

	/**
	 * A complete, well-formed map validates and exposes its referenced paths.
	 */
	public function test_valid_full_map() {
		$json = wp_json_encode(
			array(
				'id'         => 'sku',
				'label'      => 'name',
				'url'        => 'links.self',
				'type'       => 'product',
				'properties' => array(
					'price' => 'pricing.amount',
					'desc'  => 'description',
				),
			)
		);
		$out  = NV_oOS_Graphify_Field_Map_Validator::validate( $json );
		$this->assertTrue( $out['valid'] );
		$this->assertEmpty( $out['errors'] );
		$this->assertContains( 'sku', $out['fields'] );
		$this->assertContains( 'name', $out['fields'] );
		$this->assertContains( 'links.self', $out['fields'] );
		$this->assertContains( 'pricing.amount', $out['fields'] );
		$this->assertContains( 'description', $out['fields'] );
	}

	/**
	 * Empty input is rejected with a clear message.
	 */
	public function test_empty_input_rejected() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate( '' );
		$this->assertFalse( $out['valid'] );
		$this->assertNotEmpty( $out['errors'] );
	}

	/**
	 * Whitespace-only input is rejected.
	 */
	public function test_whitespace_only_rejected() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate( "   \n  " );
		$this->assertFalse( $out['valid'] );
	}

	/**
	 * Invalid JSON yields a parse-error message.
	 */
	public function test_invalid_json_reports_parse_error() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate( '{not json}' );
		$this->assertFalse( $out['valid'] );
		$this->assertNotEmpty( $out['errors'] );
	}

	/**
	 * JSON arrays at the top level are rejected (must be object).
	 */
	public function test_top_level_array_rejected() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate( '[1,2,3]' );
		$this->assertFalse( $out['valid'] );
	}

	/**
	 * A map with neither id nor label fails validation.
	 */
	public function test_requires_id_or_label() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate( wp_json_encode( array( 'type' => 'product' ) ) );
		$this->assertFalse( $out['valid'] );
	}

	/**
	 * Non-string scalar paths are rejected.
	 */
	public function test_rejects_non_string_scalar_paths() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate(
			wp_json_encode(
				array(
					'id'    => 42,
					'label' => 'name',
				)
			)
		);
		$this->assertFalse( $out['valid'] );
	}

	/**
	 * Empty string scalar paths are rejected.
	 */
	public function test_rejects_empty_string_paths() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate(
			wp_json_encode(
				array(
					'id'    => '   ',
					'label' => 'name',
				)
			)
		);
		$this->assertFalse( $out['valid'] );
	}

	/**
	 * `properties` must be an object.
	 */
	public function test_properties_must_be_object() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate(
			wp_json_encode(
				array(
					'id'         => 'x',
					'properties' => array( 'a', 'b' ),
				)
			)
		);
		$this->assertFalse( $out['valid'] );
	}

	/**
	 * Property values must be non-empty strings.
	 */
	public function test_property_values_must_be_non_empty_strings() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate(
			wp_json_encode(
				array(
					'id'         => 'x',
					'properties' => array(
						'good'  => 'foo',
						'bad'   => '',
						'wrong' => 123,
					),
				)
			)
		);
		$this->assertFalse( $out['valid'] );
	}

	/**
	 * Unknown top-level keys produce warnings (non-fatal).
	 */
	public function test_unknown_top_level_keys_warn() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate(
			wp_json_encode(
				array(
					'id'      => 'x',
					'label'   => 'y',
					'mystery' => 'z',
				)
			)
		);
		$this->assertTrue( $out['valid'] );
		$this->assertNotEmpty( $out['warnings'] );
	}

	/**
	 * Field paths are deduplicated.
	 */
	public function test_field_paths_deduped() {
		$out = NV_oOS_Graphify_Field_Map_Validator::validate(
			wp_json_encode(
				array(
					'id'         => 'sku',
					'label'      => 'sku',
					'properties' => array(
						'a' => 'sku',
						'b' => 'name',
					),
				)
			)
		);
		$this->assertTrue( $out['valid'] );
		$this->assertSame( count( $out['fields'] ), count( array_unique( $out['fields'] ) ) );
	}

	/**
	 * Validate_against_sample reports unresolved paths.
	 */
	public function test_validate_against_sample_reports_unresolved() {
		$json = wp_json_encode(
			array(
				'id'         => 'sku',
				'label'      => 'name',
				'properties' => array( 'price' => 'pricing.amount' ),
			)
		);
		$out  = NV_oOS_Graphify_Field_Map_Validator::validate_against_sample(
			$json,
			array(
				'sku'  => 'A1',
				'name' => 'Widget',
				// pricing missing.
			)
		);
		$this->assertTrue( $out['valid'] );
		$this->assertContains( 'pricing.amount', $out['unresolved'] );
		$this->assertNotEmpty( $out['warnings'] );
	}

	/**
	 * Validate_against_sample marks all paths resolved when present.
	 */
	public function test_validate_against_sample_all_resolved() {
		$json = wp_json_encode(
			array(
				'id'    => 'sku',
				'label' => 'name',
			)
		);
		$out  = NV_oOS_Graphify_Field_Map_Validator::validate_against_sample(
			$json,
			array(
				'sku'  => 'A1',
				'name' => 'Widget',
			)
		);
		$this->assertTrue( $out['valid'] );
		$this->assertSame( array(), $out['unresolved'] );
	}
}
