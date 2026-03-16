<?php
/**
 * Tests for WP_MCP_AI_JetEngine_Vitals_Log_CCT time-aware upsert and
 * WP_MCP_AI_Tool_Import_Vitals JSON format parsing.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for vitals_log CCT upsert and import_vitals JSON parser.
 */
class Test_Vitals_Log_CCT_Upsert extends WP_UnitTestCase {

	/**
	 * Load the vitals log CCT class from the pro addon path.
	 *
	 * @return bool True if the class is available.
	 */
	private function load_vitals_log_cct() {
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
			return true;
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return false;
		}

		$path = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php';
		if ( file_exists( $path ) ) {
			require_once $path;
			return class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' );
		}

		return false;
	}

	/**
	 * Load the import_vitals tool from the pro addon path.
	 *
	 * @return WP_MCP_AI_Tool_Import_Vitals|null
	 */
	private function get_import_vitals_tool() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
			$interface_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
			if ( file_exists( $interface_path ) ) {
				require_once $interface_path;
			}
		}

		if ( ! interface_exists( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) ) {
			$flags_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-capability-flags.php';
			if ( file_exists( $flags_path ) ) {
				require_once $flags_path;
			}
		}

		$this->load_vitals_log_cct();

		$tool_path = WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-vitals.php';
		if ( ! file_exists( $tool_path ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Import_Vitals file not found' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Vitals' ) ) {
			require_once $tool_path;
		}

		return new WP_MCP_AI_Tool_Import_Vitals();
	}

	// =========================================================================
	// Constants
	// =========================================================================

	/**
	 * SAME_SESSION_WINDOW_MINUTES constant must be defined and be a small positive integer.
	 */
	public function test_same_session_window_constant_defined() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$this->assertTrue(
			defined( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT::SAME_SESSION_WINDOW_MINUTES' ),
			'SAME_SESSION_WINDOW_MINUTES constant must be defined.'
		);
		$this->assertGreaterThan( 0, WP_MCP_AI_JetEngine_Vitals_Log_CCT::SAME_SESSION_WINDOW_MINUTES );
		$this->assertLessThan(
			WP_MCP_AI_JetEngine_Vitals_Log_CCT::DEDUP_WINDOW_MINUTES,
			WP_MCP_AI_JetEngine_Vitals_Log_CCT::SAME_SESSION_WINDOW_MINUTES,
			'SAME_SESSION_WINDOW_MINUTES must be smaller than DEDUP_WINDOW_MINUTES.'
		);
	}

	// =========================================================================
	// get_for_date_and_time() — unit tests via reflection
	// =========================================================================

	/**
	 * get_for_date_and_time() must exist as a public static method.
	 */
	public function test_get_for_date_and_time_method_exists() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT', 'get_for_date_and_time' ),
			'get_for_date_and_time() must exist on WP_MCP_AI_JetEngine_Vitals_Log_CCT.'
		);
	}

	/**
	 * get_for_date_and_time() must return null when the table does not exist.
	 */
	public function test_get_for_date_and_time_returns_null_without_table() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		if ( WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			$this->markTestSkipped( 'vitals_log table exists; skipping no-table test.' );
		}

		$result = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_for_date_and_time( 1, '2026-01-01', '10:00' );
		$this->assertNull( $result );
	}

	// =========================================================================
	// is_near_duplicate — edge cases
	// =========================================================================

	/**
	 * is_near_duplicate() must return false when the incoming data contains
	 * a numeric field that the existing row does not have (new data — not dup).
	 */
	public function test_is_near_duplicate_false_when_existing_lacks_incoming_field() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' );
		$method     = $reflection->getMethod( 'is_near_duplicate' );
		$method->setAccessible( true );

		// Existing row has bp_systolic; incoming also has egfr (not in existing).
		$existing              = new stdClass();
		$existing->bp_systolic = 120;
		$existing->measurement_time = '';

		$incoming = array(
			'bp_systolic' => 120,
			'egfr'        => 55.4,
		);

		$result = $method->invoke( null, $existing, $incoming );
		$this->assertFalse( $result, 'is_near_duplicate must return false when incoming has a field not in existing.' );
	}

	/**
	 * is_near_duplicate() must return true when all incoming numeric fields match
	 * and times are within DEDUP_WINDOW.
	 */
	public function test_is_near_duplicate_true_when_values_and_times_match() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' );
		$method     = $reflection->getMethod( 'is_near_duplicate' );
		$method->setAccessible( true );

		$existing              = new stdClass();
		$existing->bp_systolic = 105;
		$existing->bp_diastolic = 56;
		$existing->measurement_time = '18:00';

		$incoming = array(
			'bp_systolic'  => 105,
			'bp_diastolic' => 56,
			'measurement_time' => '18:03', // 3 min apart — within dedup window.
		);

		$result = $method->invoke( null, $existing, $incoming );
		$this->assertTrue( $result, 'is_near_duplicate must return true when all values match and times are within DEDUP_WINDOW.' );
	}

	// =========================================================================
	// import_vitals — JSON format schema
	// =========================================================================

	/**
	 * The import_vitals tool schema must include 'json' as an allowed format.
	 */
	public function test_import_vitals_schema_includes_json_format() {
		$tool   = $this->get_import_vitals_tool();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'format', $schema['properties'] );
		$this->assertContains( 'json', $schema['properties']['format']['enum'] );
	}

	// =========================================================================
	// import_vitals — parse_json_array() via reflection
	// =========================================================================

	/**
	 * parse_json_array() must return a successful result with correct row count
	 * for the sample ED visit payload (8 records).
	 */
	public function test_parse_json_array_handles_sample_ed_payload() {
		$tool = $this->get_import_vitals_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'parse_json_array' );
		$method->setAccessible( true );

		$payload = json_encode(
			array(
				array(
					'member_id'        => 2976,
					'measurement_date' => '2026-02-05',
					'measurement_time' => '',
					'source'           => 'import',
					'notes'            => 'Lab results from PDF.',
					'sodium'           => 137,
					'potassium'        => 5.4,
					'bun'              => 13,
					'creatinine'       => 1.44,
				),
				array(
					'member_id'        => 2976,
					'measurement_date' => '2026-02-09',
					'measurement_time' => '18:00',
					'source'           => 'import',
					'notes'            => 'ED vitals.',
					'bp_systolic'      => 105,
					'bp_diastolic'     => 56,
					'heart_rate'       => 67,
					'oxygen_saturation' => 96,
					'respiratory_rate' => 19,
				),
				array(
					'member_id'        => 2976,
					'measurement_date' => '2026-02-09',
					'measurement_time' => '18:20',
					'source'           => 'import',
					'notes'            => 'ED vitals.',
					'bp_systolic'      => 90,
					'bp_diastolic'     => 61,
					'heart_rate'       => 65,
					'oxygen_saturation' => 96,
					'respiratory_rate' => 19,
				),
			)
		);

		$result = $method->invoke( $tool, $payload );

		$this->assertTrue( $result['success'], 'parse_json_array() must succeed for valid JSON.' );
		$this->assertCount( 3, $result['rows'], 'parse_json_array() must return one row per input record.' );
		$this->assertEmpty( $result['parse_errors'], 'No parse errors expected for valid payload.' );
	}

	/**
	 * parse_json_array() must exclude the member_id field from the output row.
	 */
	public function test_parse_json_array_excludes_member_id_from_row() {
		$tool = $this->get_import_vitals_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'parse_json_array' );
		$method->setAccessible( true );

		$payload = json_encode(
			array(
				array(
					'member_id'        => 2976,
					'measurement_date' => '2026-02-09',
					'measurement_time' => '18:00',
					'bp_systolic'      => 105,
				),
			)
		);

		$result = $method->invoke( $tool, $payload );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertArrayNotHasKey( 'member_id', $result['rows'][0], 'member_id must not appear in the parsed row.' );
		$this->assertArrayHasKey( 'bp_systolic', $result['rows'][0], 'bp_systolic must be present in the parsed row.' );
	}

	/**
	 * parse_json_array() must accept a single JSON object (not wrapped in an array).
	 */
	public function test_parse_json_array_accepts_single_object() {
		$tool = $this->get_import_vitals_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'parse_json_array' );
		$method->setAccessible( true );

		$payload = json_encode(
			array(
				'measurement_date' => '2026-03-01',
				'creatinine'       => 1.38,
				'bun'              => 28,
			)
		);

		$result = $method->invoke( $tool, $payload );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['rows'], 'A single JSON object must produce one row.' );
	}

	/**
	 * parse_json_array() must return an error for non-JSON input.
	 */
	public function test_parse_json_array_returns_error_for_invalid_json() {
		$tool = $this->get_import_vitals_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'parse_json_array' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'this is not json' );

		$this->assertFalse( $result['success'], 'parse_json_array() must fail for non-JSON input.' );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * measurement_time must be empty string (not '00:00') when the parsed row
	 * carries an empty time value, so the upsert can distinguish timed from
	 * untimed records.
	 */
	public function test_execute_does_not_default_measurement_time_to_midnight() {
		$tool = $this->get_import_vitals_tool();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'parse_json_array' );
		$method->setAccessible( true );

		$payload = json_encode(
			array(
				array(
					'measurement_date' => '2026-02-05',
					'measurement_time' => '',
					'creatinine'       => 1.44,
				),
			)
		);

		$result = $method->invoke( $tool, $payload );

		$this->assertTrue( $result['success'] );
		$row = $result['rows'][0];

		// The parsed row must not inject a '00:00' time that would prevent the
		// upsert from correctly identifying this as an untimed record.
		if ( isset( $row['measurement_time'] ) ) {
			$this->assertNotSame(
				'00:00',
				$row['measurement_time'],
				'An empty measurement_time must not be replaced with "00:00" during JSON parsing.'
			);
		}
	}

	// =========================================================================
	// Decimal field handling
	// =========================================================================

	/**
	 * get_decimal_vital_fields() must exist and return an array that includes
	 * the renal indicators affected by the decimal-truncation bug.
	 */
	public function test_get_decimal_vital_fields_includes_renal_indicators() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT', 'get_decimal_vital_fields' ),
			'get_decimal_vital_fields() must exist on WP_MCP_AI_JetEngine_Vitals_Log_CCT.'
		);

		$fields = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_decimal_vital_fields();

		$this->assertIsArray( $fields );

		foreach ( array( 'egfr', 'creatinine', 'potassium', 'bun', 'albumin', 'phosphorus' ) as $expected ) {
			$this->assertContains(
				$expected,
				$fields,
				"get_decimal_vital_fields() must include '{$expected}'."
			);
		}

		// Integer-only fields must NOT be in the decimal list.
		foreach ( array( 'bp_systolic', 'bp_diastolic', 'heart_rate', 'blood_glucose', 'oxygen_saturation', 'respiratory_rate' ) as $int_field ) {
			$this->assertNotContains(
				$int_field,
				$fields,
				"Integer field '{$int_field}' must not appear in get_decimal_vital_fields()."
			);
		}
	}

	/**
	 * build_row_format() must return '%f' for decimal fields, '%d' for integer
	 * fields, and '%s' for text fields.
	 */
	public function test_build_row_format_returns_correct_specifiers() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT', 'build_row_format' ),
			'build_row_format() must exist on WP_MCP_AI_JetEngine_Vitals_Log_CCT.'
		);

		$row = array(
			'member_id'        => 42,
			'measurement_date' => '2026-02-05',
			'measurement_time' => '10:00',
			'bp_systolic'      => 120,
			'bp_diastolic'     => 80,
			'heart_rate'       => 72,
			'temperature'      => 98.6,
			'egfr'             => 55.4,
			'creatinine'       => 1.44,
			'potassium'        => 4.8,
			'notes'            => 'test',
			'source'           => 'import',
		);

		$format = WP_MCP_AI_JetEngine_Vitals_Log_CCT::build_row_format( $row );

		$this->assertIsArray( $format );
		$this->assertCount( count( $row ), $format, 'build_row_format() must return one specifier per row key.' );

		$keys = array_keys( $row );
		$map  = array_combine( $keys, $format );

		// Integer fields → %d.
		$this->assertSame( '%d', $map['member_id'], 'member_id must map to %d.' );
		$this->assertSame( '%d', $map['bp_systolic'], 'bp_systolic must map to %d.' );
		$this->assertSame( '%d', $map['bp_diastolic'], 'bp_diastolic must map to %d.' );
		$this->assertSame( '%d', $map['heart_rate'], 'heart_rate must map to %d.' );

		// Decimal fields → %f.
		$this->assertSame( '%f', $map['temperature'], 'temperature must map to %f.' );
		$this->assertSame( '%f', $map['egfr'], 'egfr must map to %f.' );
		$this->assertSame( '%f', $map['creatinine'], 'creatinine must map to %f.' );
		$this->assertSame( '%f', $map['potassium'], 'potassium must map to %f.' );

		// Text / date fields → %s.
		$this->assertSame( '%s', $map['measurement_date'], 'measurement_date must map to %s.' );
		$this->assertSame( '%s', $map['measurement_time'], 'measurement_time must map to %s.' );
		$this->assertSame( '%s', $map['notes'], 'notes must map to %s.' );
		$this->assertSame( '%s', $map['source'], 'source must map to %s.' );
	}

	/**
	 * maybe_migrate_decimal_columns() must exist and be a no-op when the table
	 * does not exist.
	 */
	public function test_maybe_migrate_decimal_columns_noop_without_table() {
		if ( ! $this->load_vitals_log_cct() ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT not available.' );
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT', 'maybe_migrate_decimal_columns' ),
			'maybe_migrate_decimal_columns() must exist on WP_MCP_AI_JetEngine_Vitals_Log_CCT.'
		);

		if ( WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			$this->markTestSkipped( 'vitals_log table exists; skipping no-table migration test.' );
		}

		// Must not throw or produce an error when the table is absent.
		WP_MCP_AI_JetEngine_Vitals_Log_CCT::maybe_migrate_decimal_columns();
		$this->assertTrue( true, 'maybe_migrate_decimal_columns() must not throw when the table is absent.' );
	}
}
