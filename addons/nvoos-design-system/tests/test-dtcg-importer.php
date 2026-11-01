<?php
/**
 * Test: DTCG Importer.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the DTCG_Importer class.
 *
 * @covers NV_oOS_Design_System_DTCG_Importer
 * @covers NV_oOS_Design_System_DTCG_Exporter
 */
class Test_DTCG_Importer extends WP_UnitTestCase {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry
	 */
	private $registry;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new NV_oOS_Design_System_Token_Registry();
	}

	/**
	 * Exporter output should import cleanly into a fresh registry.
	 *
	 * @return void
	 */
	public function test_exporter_output_round_trips() {
		$source = $this->registry;
		$source->set( 'color_accent', '#123456' );
		$source->set( 'space_md', '24px' );
		$source->set( 'font_family', 'Georgia, serif' );
		$source->set( 'transition_normal', '450ms ease' );

		$exporter = new NV_oOS_Design_System_DTCG_Exporter( $source );
		$json     = $exporter->export( false );

		$target   = new NV_oOS_Design_System_Token_Registry();
		$importer = new NV_oOS_Design_System_DTCG_Importer( $target );
		$report   = $importer->import( $json );

		$this->assertEmpty( $report['errors'], 'Round-trip import should produce no errors.' );
		$this->assertEmpty( $report['skipped'], 'Round-trip import should skip nothing.' );
		$this->assertSame( count( $source->get_all() ), count( $report['imported'] ) );
		$this->assertTrue( $report['applied'] );
		$this->assertSame( $source->get_values_map(), $target->get_values_map() );
	}

	/**
	 * An ADS nds_export-shaped document should land values on the right ids.
	 *
	 * @return void
	 */
	public function test_imports_ads_nds_export_document() {
		$json = wp_json_encode(
			array(
				'colors'     => array(
					'color_surface' => array(
						'$type'  => 'color',
						'$value' => '#ffffff',
					),
					'color_accent'  => array(
						'$type'        => 'color',
						'$value'       => '#8b9f48',
						'$description' => 'Accent colour.',
					),
				),
				'sizing'     => array(
					'input_height' => array(
						'$type'  => 'dimension',
						'$value' => '48px',
					),
				),
				'typography' => array(
					'font_family' => array(
						'$type'  => 'fontFamily',
						'$value' => 'Helvetica, Arial, sans-serif',
					),
				),
			)
		);

		$importer = new NV_oOS_Design_System_DTCG_Importer( $this->registry );
		$report   = $importer->import( $json );

		$this->assertEmpty( $report['errors'] );
		$this->assertEmpty( $report['skipped'] );
		$this->assertTrue( $report['applied'] );
		$this->assertContains( 'color_surface', $report['imported'] );
		$this->assertContains( 'color_accent', $report['imported'] );
		$this->assertContains( 'input_height', $report['imported'] );
		$this->assertContains( 'font_family', $report['imported'] );

		$this->assertSame( '#ffffff', $this->registry->get( 'color_surface' )->value );
		$this->assertSame( '#8b9f48', $this->registry->get( 'color_accent' )->value );
		$this->assertSame( '48px', $this->registry->get( 'input_height' )->value );
		$this->assertSame( 'Helvetica, Arial, sans-serif', $this->registry->get( 'font_family' )->value );

		// Imported values persist to the options table.
		$saved = get_option( NV_oOS_Design_System_Plugin::OPTION_KEY, array() );
		$this->assertSame( '#ffffff', $saved['color_surface'] );
		$this->assertSame( '48px', $saved['input_height'] );
	}

	/**
	 * Unknown ids should be skipped, not fatal — valid siblings still apply.
	 *
	 * @return void
	 */
	public function test_unknown_token_is_skipped() {
		$json = wp_json_encode(
			array(
				'colors' => array(
					'color_accent'  => array(
						'$type'  => 'color',
						'$value' => '#8b9f48',
					),
					'color_unknown' => array(
						'$type'  => 'color',
						'$value' => '#ffffff',
					),
				),
			)
		);

		$importer = new NV_oOS_Design_System_DTCG_Importer( $this->registry );
		$report   = $importer->import( $json );

		$this->assertEmpty( $report['errors'] );
		$this->assertSame( array( 'color_accent' ), $report['imported'] );
		$this->assertSame(
			array(
				array(
					'id'     => 'color_unknown',
					'reason' => 'unknown_token',
				),
			),
			$report['skipped']
		);
		$this->assertTrue( $report['applied'] );
		$this->assertSame( '#8b9f48', $this->registry->get( 'color_accent' )->value );
	}

	/**
	 * DTCG reference values should be rejected and nothing applied.
	 *
	 * @return void
	 */
	public function test_reference_value_is_rejected() {
		$baseline = get_option( NV_oOS_Design_System_Plugin::OPTION_KEY, array() );

		$json = wp_json_encode(
			array(
				'colors' => array(
					'color_accent'  => array(
						'$type'  => 'color',
						'$value' => '{core.palette.accent}',
					),
					'color_surface' => array(
						'$type'  => 'color',
						'$value' => '#ffffff',
					),
				),
			)
		);

		$importer = new NV_oOS_Design_System_DTCG_Importer( $this->registry );
		$report   = $importer->import( $json );

		$this->assertNotEmpty( $report['errors'] );
		$this->assertStringContainsString( 'reference', $report['errors'][0] );
		$this->assertFalse( $report['applied'] );

		// The valid sibling is not applied either — all-or-nothing.
		$this->assertSame( '#1a1a1a', $this->registry->get( 'color_surface' )->value );
		$this->assertSame( $baseline, get_option( NV_oOS_Design_System_Plugin::OPTION_KEY, array() ) );
	}

	/**
	 * Payloads over the 256 KB cap should be rejected with an error.
	 *
	 * @return void
	 */
	public function test_size_cap_rejects_oversized_payload() {
		$json = str_repeat( 'a', NV_oOS_Design_System_DTCG_Importer::MAX_PAYLOAD_BYTES + 1 );

		$importer = new NV_oOS_Design_System_DTCG_Importer( $this->registry );
		$report   = $importer->import( $json );

		$this->assertNotEmpty( $report['errors'] );
		$this->assertStringContainsString( '256 KB', $report['errors'][0] );
		$this->assertEmpty( $report['imported'] );
		$this->assertFalse( $report['applied'] );
	}

	/**
	 * Malformed JSON should be reported as a parse error.
	 *
	 * @return void
	 */
	public function test_invalid_json_reports_parse_error() {
		$report = NV_oOS_Design_System_DTCG_Importer::prepare( '{"colors": {', $this->registry );

		$this->assertNotEmpty( $report['errors'] );
		$this->assertStringContainsString( 'Invalid JSON', $report['errors'][0] );
		$this->assertEmpty( $report['imported'] );
	}

	/**
	 * A $type that maps to a different NDS token type should be an error.
	 *
	 * @return void
	 */
	public function test_type_mismatch_is_an_error() {
		$json = wp_json_encode(
			array(
				'colors' => array(
					'color_accent' => array(
						'$type'  => 'dimension',
						'$value' => '16px',
					),
				),
			)
		);

		$importer = new NV_oOS_Design_System_DTCG_Importer( $this->registry );
		$report   = $importer->import( $json );

		$this->assertNotEmpty( $report['errors'] );
		$this->assertStringContainsString( 'does not match', $report['errors'][0] );
		$this->assertEmpty( $report['imported'] );
		$this->assertFalse( $report['applied'] );
	}

	/**
	 * Non-string $value (e.g. an ADS fontFamily array) should be an error.
	 *
	 * @return void
	 */
	public function test_non_string_value_is_an_error() {
		$json = '{"typography":{"font_family":{"$type":"fontFamily","$value":["Arial","sans-serif"]}}}';

		$report = NV_oOS_Design_System_DTCG_Importer::prepare( $json, $this->registry );

		$this->assertNotEmpty( $report['errors'] );
		$this->assertStringContainsString( '$value must be a string', $report['errors'][0] );
	}

	/**
	 * Mixed input — one valid token, one invalid — must apply nothing.
	 *
	 * @return void
	 */
	public function test_mixed_input_applies_nothing() {
		$baseline_value  = $this->registry->get( 'color_surface' )->value;
		$baseline_option = get_option( NV_oOS_Design_System_Plugin::OPTION_KEY, array() );

		$json = wp_json_encode(
			array(
				'colors' => array(
					'color_surface' => array(
						'$type'  => 'color',
						'$value' => '#ffffff',
					),
					'color_accent'  => array(
						'$type' => 'color',
					),
				),
			)
		);

		$importer = new NV_oOS_Design_System_DTCG_Importer( $this->registry );
		$report   = $importer->import( $json );

		$this->assertNotEmpty( $report['errors'] );
		$this->assertContains( 'color_surface', $report['imported'], 'The valid token is listed as importable.' );
		$this->assertFalse( $report['applied'], 'Nothing may be applied when any error exists.' );
		$this->assertSame( $baseline_value, $this->registry->get( 'color_surface' )->value );
		$this->assertSame( $baseline_option, get_option( NV_oOS_Design_System_Plugin::OPTION_KEY, array() ) );
	}

	/**
	 * A top-level JSON array should be rejected.
	 *
	 * @return void
	 */
	public function test_top_level_array_is_an_error() {
		$report = NV_oOS_Design_System_DTCG_Importer::prepare( '[{"a":1}]', $this->registry );

		$this->assertNotEmpty( $report['errors'] );
		$this->assertStringContainsString( 'object of token groups', $report['errors'][0] );
	}
}
