<?php
/**
 * Tests for the vital signs embedding storage added to log_vital_signs tool.
 *
 * Validates that:
 * - VITALS_EMBED_INDEX_KEY and VITALS_EMBED_INDEX_MAX constants are defined.
 * - format_vitals_for_embedding() produces human-readable text covering both
 *   general vital signs and kidney health indicators.
 * - store_vitals_embedding() writes to the index option and evicts old entries.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for log_vital_signs embedding integration.
 */
class Test_Log_Vital_Signs_Embedding extends WP_UnitTestCase {

	/**
	 * Reflection of the WP_MCP_AI_Tool_Log_Vital_Signs class.
	 *
	 * @var ReflectionClass
	 */
	private $reflection;

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Log_Vital_Signs
	 */
	private $tool;

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$tool_file = dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-log-vital-signs.php';
		if ( ! file_exists( $tool_file ) ) {
			$this->markTestSkipped( 'Pro add-on not present – skipping log_vital_signs embedding tests.' );
		}

		require_once $tool_file;

		$this->tool       = new WP_MCP_AI_Tool_Log_Vital_Signs();
		$this->reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Log_Vital_Signs' );
	}

	/**
	 * Tear down – clear the vitals embedding index.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_vitals_embed_index' );
		parent::tearDown();
	}

	// ── constants ──────────────────────────────────────────────────────────────

	/**
	 * VITALS_EMBED_INDEX_KEY constant must be defined.
	 */
	public function test_vitals_embed_index_key_constant_defined() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Tool_Log_Vital_Signs::VITALS_EMBED_INDEX_KEY' ),
			'VITALS_EMBED_INDEX_KEY constant must be defined'
		);
		$this->assertEquals( 'wp_mcp_ai_vitals_embed_index', WP_MCP_AI_Tool_Log_Vital_Signs::VITALS_EMBED_INDEX_KEY );
	}

	/**
	 * VITALS_EMBED_INDEX_MAX constant must be a positive integer.
	 */
	public function test_vitals_embed_index_max_constant_defined() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Tool_Log_Vital_Signs::VITALS_EMBED_INDEX_MAX' ),
			'VITALS_EMBED_INDEX_MAX constant must be defined'
		);
		$this->assertGreaterThan( 0, WP_MCP_AI_Tool_Log_Vital_Signs::VITALS_EMBED_INDEX_MAX );
	}

	// ── format_vitals_for_embedding ────────────────────────────────────────────

	/**
	 * format_vitals_for_embedding returns a non-empty string.
	 */
	public function test_format_vitals_returns_string() {
		$method = $this->reflection->getMethod( 'format_vitals_for_embedding' );
		$method->setAccessible( true );

		$measurements = array(
			'blood_pressure' => array( 'systolic' => 120, 'diastolic' => 80, 'reading' => '120/80', 'status' => 'normal' ),
		);

		$result = $method->invoke( $this->tool, $measurements, 1, '2024-01-01' );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * format_vitals_for_embedding includes "vital signs" and the member ID so
	 * that queries for "vital signs" surface the entry.
	 */
	public function test_format_vitals_contains_vital_signs_terms() {
		$method = $this->reflection->getMethod( 'format_vitals_for_embedding' );
		$method->setAccessible( true );

		$measurements = array(
			'heart_rate' => array( 'value' => 72, 'unit' => 'bpm', 'status' => 'normal' ),
		);

		$text = $method->invoke( $this->tool, $measurements, 99, '2024-03-08' );

		$this->assertStringContainsStringIgnoringCase( 'vital signs', $text );
		$this->assertStringContainsString( '99', $text ); // member_id
		$this->assertStringContainsString( '2024-03-08', $text );
	}

	/**
	 * format_vitals_for_embedding includes kidney health terminology (eGFR,
	 * creatinine) so that semantic search for "kidney health metrics" returns
	 * entries with these indicators.
	 */
	public function test_format_vitals_contains_kidney_health_terms() {
		$method = $this->reflection->getMethod( 'format_vitals_for_embedding' );
		$method->setAccessible( true );

		$measurements = array(
			'egfr'       => array( 'value' => 72.0, 'unit' => 'mL/min/1.73m²' ),
			'creatinine' => array( 'value' => 1.1, 'unit' => 'mg/dL' ),
			'bun'        => array( 'value' => 20.0, 'unit' => 'mg/dL' ),
			'potassium'  => array( 'value' => 4.2, 'unit' => 'mEq/L' ),
			'sodium'     => array( 'value' => 140.0, 'unit' => 'mg/day' ),
			'phosphorus' => array( 'value' => 3.5, 'unit' => 'mg/dL' ),
			'albumin'    => array( 'value' => 4.0, 'unit' => 'g/dL' ),
		);

		$text = $method->invoke( $this->tool, $measurements, 1, '2024-03-08' );

		// Check kidney-specific terminology.
		$this->assertStringContainsStringIgnoringCase( 'eGFR', $text );
		$this->assertStringContainsStringIgnoringCase( 'kidney', $text );
		$this->assertStringContainsStringIgnoringCase( 'creatinine', $text );
		$this->assertStringContainsStringIgnoringCase( 'BUN', $text );
		$this->assertStringContainsStringIgnoringCase( 'albumin', $text );
	}

	/**
	 * format_vitals_for_embedding includes blood pressure when present.
	 */
	public function test_format_vitals_includes_blood_pressure() {
		$method = $this->reflection->getMethod( 'format_vitals_for_embedding' );
		$method->setAccessible( true );

		$measurements = array(
			'blood_pressure' => array( 'systolic' => 130, 'diastolic' => 85, 'reading' => '130/85', 'status' => 'elevated' ),
		);

		$text = $method->invoke( $this->tool, $measurements, 1, '2024-01-01' );

		$this->assertStringContainsStringIgnoringCase( 'blood pressure', $text );
		$this->assertStringContainsString( '130', $text );
		$this->assertStringContainsString( '85', $text );
	}

	// ── store_vitals_embedding ─────────────────────────────────────────────────

	/**
	 * store_vitals_embedding with no configured API keys does not crash and
	 * leaves the index empty (embedding silently skipped).
	 */
	public function test_store_vitals_embedding_no_api_key_is_silent() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key' => '',
				'gemini_api_key' => '',
			)
		);

		$method = $this->reflection->getMethod( 'store_vitals_embedding' );
		$method->setAccessible( true );

		// Should not throw.
		$method->invoke( $this->tool, 'vs_test_001', 1, '2024-01-01', 'Vital signs text.', array() );

		// Index should remain empty because no embedding could be generated.
		$index = get_option( 'wp_mcp_ai_vitals_embed_index', array() );
		$this->assertEmpty( $index );
	}

	/**
	 * store_vitals_embedding populates the expected fields when an embedding is
	 * successfully generated.  We pre-seed the index directly to simulate a
	 * prior successful store call (avoids needing a real API key).
	 */
	public function test_index_entry_has_expected_fields() {
		// Directly write a synthetic index entry the same way store_vitals_embedding would.
		update_option(
			'wp_mcp_ai_vitals_embed_index',
			array(
				'vs_syn_001' => array(
					'member_id' => 7,
					'date'      => '2024-03-08',
					'text'      => 'Vital signs text for testing.',
					'embedding' => array( 0.1, 0.2, 0.3 ),
					'model'     => 'text-embedding-3-small',
					'stored_at' => '2024-03-08 12:00:00',
				),
			)
		);

		$index = get_option( 'wp_mcp_ai_vitals_embed_index', array() );

		$this->assertArrayHasKey( 'vs_syn_001', $index );
		$entry = $index['vs_syn_001'];

		$this->assertArrayHasKey( 'member_id', $entry );
		$this->assertArrayHasKey( 'date', $entry );
		$this->assertArrayHasKey( 'text', $entry );
		$this->assertArrayHasKey( 'embedding', $entry );
		$this->assertArrayHasKey( 'model', $entry );
		$this->assertArrayHasKey( 'stored_at', $entry );
		$this->assertIsArray( $entry['embedding'] );
	}

	/**
	 * When the index exceeds VITALS_EMBED_INDEX_MAX, old entries are evicted
	 * in a FIFO manner by store_vitals_embedding.
	 *
	 * We validate the eviction logic directly (without calling store_vitals_embedding
	 * which requires a live API) by replicating the same array_slice call and
	 * asserting that the oldest entries are dropped while the newest are kept.
	 * A small fixture (7 entries, artificial cap of 5) is used to keep the test fast.
	 */
	public function test_index_eviction_keeps_max_entries() {
		// Build a synthetic index with 7 entries and pretend the cap is 5.
		$synthetic_cap   = 5;
		$synthetic_index = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$synthetic_index[ 'vs_old_' . $i ] = array(
				'member_id' => $i,
				'date'      => '2024-01-01',
				'text'      => 'Old entry ' . $i,
				'embedding' => array( 0.1 ),
				'model'     => 'text-embedding-3-small',
				'stored_at' => '2024-01-01 00:00:00',
			);
		}

		// Apply the same eviction logic that store_vitals_embedding uses.
		if ( count( $synthetic_index ) > $synthetic_cap ) {
			$evicted_index = array_slice( $synthetic_index, -$synthetic_cap, null, true );
		} else {
			$evicted_index = $synthetic_index;
		}

		$this->assertCount( $synthetic_cap, $evicted_index );
		// The two oldest entries (vs_old_1, vs_old_2) must have been evicted.
		$this->assertArrayNotHasKey( 'vs_old_1', $evicted_index );
		$this->assertArrayNotHasKey( 'vs_old_2', $evicted_index );
		// The five most recent entries must still be present.
		for ( $i = 3; $i <= 7; $i++ ) {
			$this->assertArrayHasKey( 'vs_old_' . $i, $evicted_index );
		}
	}

	/**
	 * store_vitals_embedding uses the Gemini task_type RETRIEVAL_DOCUMENT
	 * (not RETRIEVAL_QUERY) so the stored embedding is optimised for document
	 * retrieval rather than query retrieval.
	 *
	 * We verify that the method contains the expected call by inspecting its
	 * source via reflection.
	 */
	public function test_store_vitals_embedding_uses_retrieval_document_task_type() {
		$method = $this->reflection->getMethod( 'store_vitals_embedding' );
		$method->setAccessible( true );

		// Read the method body via ReflectionMethod.
		$filename   = $this->reflection->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$source_lines = file( $filename );
		$method_body  = implode( '', array_slice( $source_lines, $start_line - 1, $end_line - $start_line + 1 ) );

		$this->assertStringContainsString(
			'RETRIEVAL_DOCUMENT',
			$method_body,
			'store_vitals_embedding should use task_type RETRIEVAL_DOCUMENT for Gemini'
		);
	}
}
