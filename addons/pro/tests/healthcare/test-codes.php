<?php
/**
 * Tests for the unified Healthcare Toolkit clinical codes registry.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
	$engine_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/class-wp-mcp-ai-healthcare-engine.php';
	if ( file_exists( $engine_path ) ) {
		require_once $engine_path;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Healthcare_Codes' ) ) {
	$codes_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/class-wp-mcp-ai-healthcare-codes.php';
	if ( file_exists( $codes_path ) ) {
		require_once $codes_path;
	}
}

/**
 * Test case for WP_MCP_AI_Healthcare_Codes.
 */
class Test_Healthcare_Codes extends WP_UnitTestCase {

	/**
	 * Reset the codes cache between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Healthcare_Codes::reset_cache();
	}

	/**
	 * Tear down: drop filters and cache.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_healthcare_code_packs' );
		remove_all_filters( 'wp_mcp_ai_healthcare_default_code_pack' );
		WP_MCP_AI_Healthcare_Codes::reset_cache();
		parent::tearDown();
	}

	/**
	 * Default packs are registered.
	 */
	public function test_default_packs_present() {
		$packs = WP_MCP_AI_Healthcare_Codes::get_packs();
		$this->assertArrayHasKey( 'icd10-cm-2025', $packs );
		$this->assertArrayHasKey( 'snomed-ct-2025', $packs );
		$this->assertArrayHasKey( 'loinc-2025', $packs );
		$this->assertArrayHasKey( 'rxnorm-2025', $packs );
		$this->assertArrayHasKey( 'cvx-2025', $packs );
		$this->assertArrayHasKey( 'cpt-2025', $packs );
		$this->assertArrayHasKey( 'dicom-modality', $packs );
	}

	/**
	 * Validate / lookup a known seed code.
	 */
	public function test_validate_and_lookup() {
		$this->assertTrue( WP_MCP_AI_Healthcare_Codes::validate_code( 'icd10-cm-2025', 'I10' ) );
		$this->assertFalse( WP_MCP_AI_Healthcare_Codes::validate_code( 'icd10-cm-2025', 'NOSUCH' ) );
		$this->assertSame(
			'Essential (primary) hypertension',
			WP_MCP_AI_Healthcare_Codes::lookup( 'icd10-cm-2025', 'I10' )
		);
		$this->assertNull( WP_MCP_AI_Healthcare_Codes::lookup( 'icd10-cm-2025', 'NOSUCH' ) );
	}

	/**
	 * `display_name()` is an alias for `lookup()`.
	 */
	public function test_display_name_alias() {
		$this->assertSame(
			WP_MCP_AI_Healthcare_Codes::lookup( 'loinc-2025', '8867-4' ),
			WP_MCP_AI_Healthcare_Codes::display_name( 'loinc-2025', '8867-4' )
		);
	}

	/**
	 * System URL is exposed.
	 */
	public function test_system_url() {
		$this->assertSame( 'http://hl7.org/fhir/sid/icd-10-cm', WP_MCP_AI_Healthcare_Codes::system_url( 'icd10-cm-2025' ) );
		$this->assertNull( WP_MCP_AI_Healthcare_Codes::system_url( 'no-such-pack' ) );
	}

	/**
	 * Filter can register a custom pack.
	 */
	public function test_filter_registers_custom_pack() {
		add_filter(
			'wp_mcp_ai_healthcare_code_packs',
			static function ( $packs ) {
				$packs['icd11-2025'] = array(
					'system' => 'icd11',
					'title'  => 'ICD-11 (2025)',
					'url'    => 'http://id.who.int/icd/release/11/mms',
					'codes'  => array(
						'BA00' => 'Essential hypertension',
					),
				);
				return $packs;
			}
		);
		WP_MCP_AI_Healthcare_Codes::reset_cache();
		$this->assertTrue( WP_MCP_AI_Healthcare_Codes::validate_code( 'icd11-2025', 'BA00' ) );
		$this->assertSame( 'Essential hypertension', WP_MCP_AI_Healthcare_Codes::lookup( 'icd11-2025', 'BA00' ) );
	}

	/**
	 * Default pack id can be overridden via filter.
	 */
	public function test_default_pack_id_filter() {
		$this->assertSame( 'icd10-cm-2025', WP_MCP_AI_Healthcare_Codes::default_pack_id() );
		add_filter(
			'wp_mcp_ai_healthcare_default_code_pack',
			static function () {
				return 'icd11-2025';
			}
		);
		$this->assertSame( 'icd11-2025', WP_MCP_AI_Healthcare_Codes::default_pack_id() );
	}
}
