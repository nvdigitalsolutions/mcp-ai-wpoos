<?php
/**
 * Tests for Healthcare Imaging Toolkit.
 *
 * Tests cover:
 *  - DICOM metadata extractor (magic byte detection)
 *  - Imaging capabilities registration
 *  - Imaging audit log (write + read)
 *  - Imaging Study CPT (create + get_by_uid + add_series)
 *  - manage_imaging_studies AI tool (list, get, summarize, audit actions)
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test Healthcare Imaging Toolkit components.
 */
class Test_Healthcare_Imaging_Toolkit extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		// Enable imaging toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_healthcare_imaging' => true )
		);

		// Load classes under test.
		$base = dirname( __DIR__ ) . '/includes/';

		if ( ! class_exists( 'WP_MCP_AI_Imaging_Capabilities' ) ) {
			require_once $base . 'class-wp-mcp-ai-imaging-capabilities.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Imaging_Audit_Log' ) ) {
			require_once $base . 'class-wp-mcp-ai-imaging-audit-log.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_DICOM_Metadata' ) ) {
			require_once $base . 'class-wp-mcp-ai-dicom-metadata.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			require_once $base . 'class-wp-mcp-ai-imaging-study-cpt.php';
			WP_MCP_AI_Imaging_Study_CPT::init();
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Imaging_Studies' ) ) {
			require_once $base . 'tools/class-wp-mcp-ai-tool-manage-imaging-studies.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Interpret_Imaging_Study' ) ) {
			require_once $base . 'tools/class-wp-mcp-ai-tool-interpret-imaging-study.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Imaging_REST_Controller' ) ) {
			require_once $base . 'class-wp-mcp-ai-imaging-rest-controller.php';
		}

		// Register CPT.
		do_action( 'init' );

		// Add capabilities to admin.
		WP_MCP_AI_Imaging_Capabilities::add_caps();

		// Clear audit log.
		delete_option( WP_MCP_AI_Imaging_Audit_Log::OPTION_KEY );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( WP_MCP_AI_Imaging_Audit_Log::OPTION_KEY );
		parent::tearDown();
	}

	// =========================================================================
	// DICOM metadata.
	// =========================================================================

	/**
	 * A file without the DICM magic should not be detected as DICOM.
	 */
	public function test_dicom_is_dicom_rejects_non_dicom() {
		$tmp = wp_tempnam( 'test.dcm' );
		file_put_contents( $tmp, str_repeat( "\x00", 132 ) ); // all zeros – no DICM.
		$this->assertFalse( WP_MCP_AI_DICOM_Metadata::is_dicom( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * A file shorter than 132 bytes cannot be DICOM.
	 */
	public function test_dicom_is_dicom_rejects_short_file() {
		$tmp = wp_tempnam( 'test.dcm' );
		file_put_contents( $tmp, 'short' );
		$this->assertFalse( WP_MCP_AI_DICOM_Metadata::is_dicom( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * A file with the DICM magic at byte 128 is detected as DICOM.
	 */
	public function test_dicom_is_dicom_accepts_valid_magic() {
		$tmp = wp_tempnam( 'test.dcm' );
		// 128 zero preamble bytes + 'DICM'.
		file_put_contents( $tmp, str_repeat( "\x00", 128 ) . 'DICM' );
		$this->assertTrue( WP_MCP_AI_DICOM_Metadata::is_dicom( $tmp ) );
		unlink( $tmp );
	}

	/**
	 * Extract() should return WP_Error when file does not exist.
	 */
	public function test_dicom_extract_returns_error_for_missing_file() {
		$result = WP_MCP_AI_DICOM_Metadata::extract( '/nonexistent/path/file.dcm' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Extract() should correctly read UIDs that appear after an undefined-length
	 * SQ sequence element in the DICOM dataset.
	 *
	 * This is a regression test for the bug where the parser issued `break` on
	 * length=0xFFFFFFFF, stopping before finding StudyInstanceUID /
	 * SOPInstanceUID when an SQ sequence preceded them in tag order.
	 */
	public function test_dicom_extract_reads_uids_after_undefined_length_sequence() {
		// Build a minimal synthetic DICOM file (Explicit Little Endian).
		//
		// Layout:
		// 132-byte preamble+magic.
		// (0002,0010) TransferSyntaxUID = "1.2.840.10008.1.2.1" (Explicit LE)
		// (0008,0018) SOPInstanceUID    = "1.2.3.4.5.sop.test"
		// (0008,1110) SQ undefined length — the undefined-length element under test
		// FFFE,E0DD (empty sequence body, immediately terminated).
		// (0020,000D) StudyInstanceUID  = "1.2.3.4.5.study.test".

		// Preamble: 128 zero bytes + 'DICM'.
		$dcm = str_repeat( "\x00", 128 ) . 'DICM';

		// (0002,0010) TransferSyntaxUID – UI VR, 2-byte length.
		// "1.2.840.10008.1.2.1" is 19 chars; pad to 20 (even) with \x00.
		$ts_uid = "1.2.840.10008.1.2.1\x00";
		$dcm   .= pack( 'vv', 0x0002, 0x0010 ) . 'UI' . pack( 'v', strlen( $ts_uid ) ) . $ts_uid;

		// (0008,0018) SOPInstanceUID – UI VR, 2-byte length.
		// "1.2.3.4.5.sop.test" is 18 chars (even).
		$sop_uid = '1.2.3.4.5.sop.test';
		$dcm    .= pack( 'vv', 0x0008, 0x0018 ) . 'UI' . pack( 'v', strlen( $sop_uid ) ) . $sop_uid;

		// (0008,1110) ReferencedStudySequence – SQ VR, undefined length (FFFFFFFF).
		// Tag | 'SQ' | 2 reserved bytes | length = 0xFFFFFFFF.
		$dcm .= pack( 'vv', 0x0008, 0x1110 ) . 'SQ' . "\x00\x00" . pack( 'V', 0xFFFFFFFF );
		// Empty sequence body terminated immediately by Sequence Delimitation Item.
		// FFFE,E0DD | length = 0x00000000.
		$dcm .= pack( 'vv', 0xFFFE, 0xE0DD ) . pack( 'V', 0x00000000 );

		// (0020,000D) StudyInstanceUID – UI VR, 2-byte length.
		// "1.2.3.4.5.study.test" is 20 chars (even).
		$study_uid = '1.2.3.4.5.study.test';
		$dcm      .= pack( 'vv', 0x0020, 0x000D ) . 'UI' . pack( 'v', strlen( $study_uid ) ) . $study_uid;

		$tmp = wp_tempnam( 'test.dcm' );
		file_put_contents( $tmp, $dcm );

		$meta = WP_MCP_AI_DICOM_Metadata::extract( $tmp );
		unlink( $tmp );

		$this->assertIsArray( $meta, 'extract() should return an array for a valid DICOM file.' );
		$this->assertArrayHasKey( 'study_instance_uid', $meta );
		$this->assertArrayHasKey( 'sop_instance_uid', $meta );
		$this->assertEquals( '1.2.3.4.5.study.test', $meta['study_instance_uid'] );
		$this->assertEquals( '1.2.3.4.5.sop.test', $meta['sop_instance_uid'] );
	}

	/**
	 * Extract() should read UIDs even when the undefined-length SQ contains
	 * a defined-length item before the sequence delimiter.
	 */
	public function test_dicom_extract_reads_uids_after_sequence_with_items() {
		// Same layout as above, but the SQ has one defined-length item inside.
		$dcm = str_repeat( "\x00", 128 ) . 'DICM';

		$ts_uid = "1.2.840.10008.1.2.1\x00";
		$dcm   .= pack( 'vv', 0x0002, 0x0010 ) . 'UI' . pack( 'v', strlen( $ts_uid ) ) . $ts_uid;

		$sop_uid = '1.2.3.4.5.sop.test';
		$dcm    .= pack( 'vv', 0x0008, 0x0018 ) . 'UI' . pack( 'v', strlen( $sop_uid ) ) . $sop_uid;

		// (0008,1115) ReferencedSeriesSequence – SQ undefined length.
		$dcm .= pack( 'vv', 0x0008, 0x1115 ) . 'SQ' . "\x00\x00" . pack( 'V', 0xFFFFFFFF );
		// Item: FFFE,E000 with a defined length of 10 bytes of dummy data.
		$item_data = str_repeat( "\x00", 10 );
		$dcm      .= pack( 'vv', 0xFFFE, 0xE000 ) . pack( 'V', strlen( $item_data ) ) . $item_data;
		// Sequence Delimitation Item.
		$dcm .= pack( 'vv', 0xFFFE, 0xE0DD ) . pack( 'V', 0x00000000 );

		// (0020,000D) StudyInstanceUID.
		$study_uid = '1.2.3.4.5.study.test';
		$dcm      .= pack( 'vv', 0x0020, 0x000D ) . 'UI' . pack( 'v', strlen( $study_uid ) ) . $study_uid;

		$tmp = wp_tempnam( 'test.dcm' );
		file_put_contents( $tmp, $dcm );

		$meta = WP_MCP_AI_DICOM_Metadata::extract( $tmp );
		unlink( $tmp );

		$this->assertIsArray( $meta );
		$this->assertEquals( '1.2.3.4.5.study.test', $meta['study_instance_uid'] );
		$this->assertEquals( '1.2.3.4.5.sop.test', $meta['sop_instance_uid'] );
	}

	// =========================================================================
	// Capabilities.
	// =========================================================================

	/**
	 * Capabilities should be registered for the administrator role.
	 */
	public function test_capabilities_added_to_administrator() {
		WP_MCP_AI_Imaging_Capabilities::add_caps();
		$this->assertTrue( current_user_can( 'view_medical_imaging' ) );
		$this->assertTrue( current_user_can( 'upload_medical_imaging' ) );
		$this->assertTrue( current_user_can( 'delete_medical_imaging' ) );
		$this->assertTrue( current_user_can( 'manage_medical_imaging' ) );
	}

	/**
	 * Current_user_can helper maps action names to capabilities.
	 */
	public function test_capabilities_helper_view_action() {
		WP_MCP_AI_Imaging_Capabilities::add_caps();
		$this->assertTrue( WP_MCP_AI_Imaging_Capabilities::current_user_can( 'view' ) );
	}

	/**
	 * Unknown action returns false.
	 */
	public function test_capabilities_helper_unknown_action_returns_false() {
		$this->assertFalse( WP_MCP_AI_Imaging_Capabilities::current_user_can( 'nonexistent_action' ) );
	}

	// =========================================================================
	// Audit log.
	// =========================================================================

	/**
	 * Logging an event should persist it and be retrievable.
	 */
	public function test_audit_log_write_and_read() {
		WP_MCP_AI_Imaging_Audit_Log::log( 'study_viewed', array( 'study_id' => 'TEST-UID-1' ) );

		$entries = WP_MCP_AI_Imaging_Audit_Log::get_recent( 10 );
		$this->assertNotEmpty( $entries );
		$this->assertEquals( 'study_viewed', $entries[0]['event'] );
	}

	/**
	 * Filtering by study_id should return only matching entries.
	 */
	public function test_audit_log_filter_by_study_id() {
		WP_MCP_AI_Imaging_Audit_Log::log( 'study_viewed', array( 'study_id' => 'STUDY-A' ) );
		WP_MCP_AI_Imaging_Audit_Log::log( 'study_viewed', array( 'study_id' => 'STUDY-B' ) );

		$entries = WP_MCP_AI_Imaging_Audit_Log::get_recent( 50, 'STUDY-A' );
		$this->assertCount( 1, $entries );
		$this->assertEquals( 'STUDY-A', $entries[0]['meta']['study_id'] );
	}

	// =========================================================================
	// Study CPT.
	// =========================================================================

	/**
	 * Creating a study without a UID should fail.
	 */
	public function test_study_cpt_create_fails_without_uid() {
		$result = WP_MCP_AI_Imaging_Study_CPT::create( array() );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Creating a study with a UID should return a post ID.
	 */
	public function test_study_cpt_create_returns_post_id() {
		$result = WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => '1.2.3.4.5.test',
				'modality'           => 'PT',
				'study_date'         => '20240101',
			)
		);
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * Get_by_uid should return the correct post.
	 */
	public function test_study_cpt_get_by_uid() {
		$uid = '1.2.3.4.5.findme';
		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => $uid,
				'modality'           => 'CT',
			)
		);

		$post = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $uid );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertEquals( $uid, get_post_meta( $post->ID, '_imaging_study_instance_uid', true ) );
	}

	/**
	 * Add_series should append a series to the study.
	 */
	public function test_study_cpt_add_series() {
		$post_id = WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => '1.2.3.4.5.series_test',
				'modality'           => 'PT',
			)
		);

		WP_MCP_AI_Imaging_Study_CPT::add_series(
			$post_id,
			array(
				'series_instance_uid' => '1.2.3.4.5.6.series1',
				'modality'            => 'PT',
				'instances'           => array(
					array(
						'sop_instance_uid' => '1.2.3.4.5.6.7.inst1',
						'file_path'        => '/tmp/test.dcm',
					),
				),
			)
		);

		$series_json = get_post_meta( $post_id, '_imaging_series', true );
		$series      = json_decode( $series_json, true );
		$this->assertIsArray( $series );
		$this->assertCount( 1, $series );
		$this->assertEquals( '1.2.3.4.5.6.series1', $series[0]['series_instance_uid'] );
	}

	// =========================================================================
	// manage_imaging_studies tool.
	// =========================================================================

	/**
	 * Tool slug should be 'manage_imaging_studies'.
	 */
	public function test_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$this->assertEquals( 'manage_imaging_studies', $tool->get_slug() );
	}

	/**
	 * Tool returns an error without view_medical_imaging capability.
	 */
	public function test_tool_requires_capability() {
		// Create subscriber who lacks the custom cap.
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute( array( 'action' => 'list' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'imaging_forbidden', $result->get_error_code() );

		// Restore admin.
		wp_set_current_user( $this->admin_user );
	}

	/**
	 * List action with no studies should return an empty array.
	 */
	public function test_tool_list_returns_empty() {
		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute( array( 'action' => 'list' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'studies', $result );
		$this->assertEmpty( $result['studies'] );
	}

	/**
	 * List action should return studies after creating one.
	 */
	public function test_tool_list_returns_studies() {
		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => '1.2.3.list.test',
				'modality'           => 'PT',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute( array( 'action' => 'list' ) );

		$this->assertGreaterThan( 0, $result['total'] );
		$this->assertNotEmpty( $result['studies'] );
	}

	/**
	 * Get action requires study_uid parameter.
	 */
	public function test_tool_get_requires_study_uid() {
		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute( array( 'action' => 'get' ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'imaging_missing_uid', $result->get_error_code() );
	}

	/**
	 * Get action returns WP_Error for unknown study.
	 */
	public function test_tool_get_returns_error_for_unknown_study() {
		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute(
			array(
				'action'    => 'get',
				'study_uid' => 'NONEXISTENT.UID',
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'imaging_not_found', $result->get_error_code() );
	}

	/**
	 * Get action returns study data for existing study.
	 */
	public function test_tool_get_returns_study_data() {
		$uid = '1.2.3.get.test';
		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => $uid,
				'modality'           => 'CT',
				'study_date'         => '20240601',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute(
			array(
				'action'    => 'get',
				'study_uid' => $uid,
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $uid, $result['study_uid'] );
		$this->assertEquals( 'CT', $result['modality'] );
	}

	/**
	 * Summarize action returns a plain-English string.
	 */
	public function test_tool_summarize_returns_string() {
		$uid = '1.2.3.summarize.test';
		$pid = WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => $uid,
				'modality'           => 'PT',
				'study_date'         => '20240801',
			)
		);

		// Add a PET series.
		WP_MCP_AI_Imaging_Study_CPT::add_series(
			$pid,
			array(
				'series_instance_uid' => '1.2.3.summarize.series1',
				'modality'            => 'PT',
				'instances'           => array_fill(
					0,
					60,
					array(
						'sop_instance_uid' => uniqid( '', true ),
						'file_path'        => '/tmp/fake.dcm',
					)
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute(
			array(
				'action'    => 'summarize',
				'study_uid' => $uid,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertIsString( $result['summary'] );
		$this->assertNotEmpty( $result['summary'] );
	}

	/**
	 * Audit action returns audit entries.
	 */
	public function test_tool_audit_returns_entries() {
		WP_MCP_AI_Imaging_Audit_Log::log( 'study_viewed', array( 'study_id' => 'AUDIT.TEST' ) );

		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute( array( 'action' => 'audit' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'entries', $result );
		$this->assertNotEmpty( $result['entries'] );
	}

	/**
	 * Capability flags should include 'pro' and 'pii-data'.
	 */
	public function test_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$flags = $tool->get_capability_flags();
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'pii-data', $flags );
	}

	// =========================================================================
	// interpret_imaging_study tool.
	// =========================================================================

	/**
	 * Interpret_imaging_study tool slug should be 'interpret_imaging_study'.
	 */
	public function test_interpret_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$this->assertEquals( 'interpret_imaging_study', $tool->get_slug() );
	}

	/**
	 * Interpret_imaging_study requires view_medical_imaging capability.
	 */
	public function test_interpret_tool_requires_capability() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute( array( 'study_uid' => '1.2.3' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'imaging_forbidden', $result->get_error_code() );

		wp_set_current_user( $this->admin_user );
	}

	/**
	 * Interpret_imaging_study returns error when study_uid is omitted.
	 */
	public function test_interpret_tool_requires_study_uid() {
		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute( array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'imaging_missing_uid', $result->get_error_code() );
	}

	/**
	 * Interpret_imaging_study returns not-found for an unknown study UID.
	 */
	public function test_interpret_tool_returns_error_for_unknown_study() {
		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute( array( 'study_uid' => 'NONEXISTENT.UID.9999' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'imaging_not_found', $result->get_error_code() );
	}

	/**
	 * Interpret_imaging_study returns no-provider error when no AI keys are configured.
	 */
	public function test_interpret_tool_returns_error_without_ai_provider() {
		$uid = '1.2.3.interpret.test';
		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => $uid,
				'modality'           => 'CT',
				'study_date'         => '20240601',
			)
		);

		// Ensure no AI keys are configured.
		$settings       = get_option( 'wp_mcp_ai_settings', array() );
		$orig_openai    = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
		$orig_gemini    = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
		$orig_anthropic = isset( $settings['anthropic_api_key'] ) ? $settings['anthropic_api_key'] : '';

		$settings['openai_api_key']    = '';
		$settings['gemini_api_key']    = '';
		$settings['anthropic_api_key'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute( array( 'study_uid' => $uid ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'imaging_no_provider', $result->get_error_code() );

		// Restore settings.
		$settings['openai_api_key']    = $orig_openai;
		$settings['gemini_api_key']    = $orig_gemini;
		$settings['anthropic_api_key'] = $orig_anthropic;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Get_all() should return all studies ordered by date DESC.
	 *
	 * This validates that the study browser shows every uploaded study, not just
	 * the most recent one.
	 */
	public function test_study_cpt_get_all_returns_multiple_studies() {
		// Create two studies with different UIDs (simulates two separate uploads).
		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => '1.2.3.4.5.study_alpha',
				'modality'           => 'CT',
				'study_date'         => '20240101',
			)
		);

		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => '1.2.3.4.5.study_beta',
				'modality'           => 'MR',
				'study_date'         => '20240201',
			)
		);

		$result = WP_MCP_AI_Imaging_Study_CPT::get_all( 50, 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );

		// Confirm both UIDs are present in the returned posts.
		$uids = array_map(
			function ( $p ) {
				return get_post_meta( $p->ID, '_imaging_study_instance_uid', true );
			},
			$result['posts']
		);

		$this->assertContains( '1.2.3.4.5.study_alpha', $uids );
		$this->assertContains( '1.2.3.4.5.study_beta', $uids );
	}

	/**
	 * Get_by_uid should retrieve the correct study when two studies exist.
	 *
	 * Verifies study lookup works even when multiple studies share the same
	 * post type (guards against the upload batch routing bug).
	 */
	public function test_study_cpt_get_by_uid_with_multiple_studies() {
		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => '2.16.840.1.study_x',
				'modality'           => 'CT',
			)
		);

		WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => '2.16.840.1.study_y',
				'modality'           => 'PT',
			)
		);

		$post_x = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( '2.16.840.1.study_x' );
		$post_y = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( '2.16.840.1.study_y' );

		$this->assertInstanceOf( 'WP_Post', $post_x );
		$this->assertInstanceOf( 'WP_Post', $post_y );
		$this->assertNotEquals( $post_x->ID, $post_y->ID );
		$this->assertEquals( 'CT', get_post_meta( $post_x->ID, '_imaging_modality', true ) );
		$this->assertEquals( 'PT', get_post_meta( $post_y->ID, '_imaging_modality', true ) );
	}

	/**
	 * Interpret_imaging_study parameter schema has the expected required fields.
	 */
	public function test_interpret_tool_parameter_schema() {
		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'study_uid', $schema['properties'] );
		$this->assertArrayHasKey( 'focus', $schema['properties'] );
		$this->assertArrayHasKey( 'include_pixel_preview', $schema['properties'] );
		$this->assertContains( 'study_uid', $schema['required'] );
	}

	/**
	 * Interpret_imaging_study capability flags include expected values.
	 */
	public function test_interpret_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$flags = $tool->get_capability_flags();
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'pii-data', $flags );
		$this->assertContains( 'requires-credentials', $flags );
	}

	// =========================================================================
	// sanitize_uid_for_path — dot-preservation regression tests.
	// =========================================================================

	/**
	 * Sanitize_uid_for_path must preserve every dot in a standard DICOM UID.
	 *
	 * This is the core regression guard for the "only 1 study showing" bug:
	 * if dots were stripped by sanitize_file_name() (via a third-party filter),
	 * two UIDs that differ only in dot position — e.g. "1.2.3.4.56" and
	 * "1.2.3.4.5.6" — would map to the same directory name, collapsing distinct
	 * studies into a single folder and preventing the extra CPT posts from being
	 * created or found.
	 */
	public function test_sanitize_uid_for_path_preserves_dots() {
		$controller = new WP_MCP_AI_Imaging_REST_Controller();
		$reflect    = new ReflectionMethod( $controller, 'sanitize_uid_for_path' );
		$reflect->setAccessible( true );

		$uid      = '1.2.840.10008.5.1.4.1.1.128';
		$expected = '1.2.840.10008.5.1.4.1.1.128';

		$this->assertSame( $expected, $reflect->invoke( $controller, $uid ) );
	}

	/**
	 * Sanitize_uid_for_path must produce distinct output for UIDs that differ
	 * only in where the dots appear ("dot-position collision" scenario).
	 *
	 * Without dot-preservation, "1.2.3.4.56" and "1.2.3.4.5.6" both reduce to
	 * "1234_56" → same directory → second study never gets its own CPT post.
	 */
	public function test_sanitize_uid_for_path_distinct_for_dot_position_variants() {
		$controller = new WP_MCP_AI_Imaging_REST_Controller();
		$reflect    = new ReflectionMethod( $controller, 'sanitize_uid_for_path' );
		$reflect->setAccessible( true );

		$uid_a = '1.2.3.4.56';
		$uid_b = '1.2.3.4.5.6';

		$this->assertNotSame(
			$reflect->invoke( $controller, $uid_a ),
			$reflect->invoke( $controller, $uid_b ),
			'UIDs that differ in dot position must map to distinct path segments.'
		);
	}

	/**
	 * Sanitize_uid_for_path replaces characters outside [0-9.] with underscores
	 * rather than silently dropping them.
	 *
	 * A malformed or non-standard UID should still produce a safe path component;
	 * replacing with underscore preserves uniqueness better than stripping.
	 */
	public function test_sanitize_uid_for_path_replaces_non_uid_chars_with_underscore() {
		$controller = new WP_MCP_AI_Imaging_REST_Controller();
		$reflect    = new ReflectionMethod( $controller, 'sanitize_uid_for_path' );
		$reflect->setAccessible( true );

		// Characters that a rogue sanitize_file_name filter might try to strip.
		$raw      = '1.2.3/bad\\uid?foo';
		$expected = '1.2.3_bad_uid_foo';

		$this->assertSame( $expected, $reflect->invoke( $controller, $raw ) );
	}

	/**
	 * Three studies with UIDs that only differ in dot placement must each produce
	 * a distinct on-disk path segment (no folder collision).
	 *
	 * This directly exercises the reported scenario: "3 study folders in the
	 * upload folder, only 1 study showing in the viewer."
	 */
	public function test_sanitize_uid_for_path_no_collision_for_three_studies() {
		$controller = new WP_MCP_AI_Imaging_REST_Controller();
		$reflect    = new ReflectionMethod( $controller, 'sanitize_uid_for_path' );
		$reflect->setAccessible( true );

		$study_uids = array(
			'1.2.840.10008.5.1.4.1.1.2.100',
			'1.2.840.10008.5.1.4.1.1.2.200',
			'1.2.840.10008.5.1.4.1.1.3.100',
		);

		$sanitized = array_map(
			function ( $uid ) use ( $reflect, $controller ) {
				return $reflect->invoke( $controller, $uid );
			},
			$study_uids
		);

		// All three must be unique.
		$this->assertCount(
			3,
			array_unique( $sanitized ),
			'Three distinct study UIDs must produce three distinct path segments.'
		);
	}
}
