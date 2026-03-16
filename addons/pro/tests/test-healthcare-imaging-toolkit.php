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
	// DICOM metadata
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
	 * extract() should return WP_Error when file does not exist.
	 */
	public function test_dicom_extract_returns_error_for_missing_file() {
		$result = WP_MCP_AI_DICOM_Metadata::extract( '/nonexistent/path/file.dcm' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	// =========================================================================
	// Capabilities
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
	 * current_user_can helper maps action names to capabilities.
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
	// Audit log
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
	// Study CPT
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
	 * get_by_uid should return the correct post.
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
	 * add_series should append a series to the study.
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
					array( 'sop_instance_uid' => '1.2.3.4.5.6.7.inst1', 'file_path' => '/tmp/test.dcm' ),
				),
			)
		);

		$series_json = get_post_meta( $post_id, '_imaging_series', true );
		$series = json_decode( $series_json, true );
		$this->assertIsArray( $series );
		$this->assertCount( 1, $series );
		$this->assertEquals( '1.2.3.4.5.6.series1', $series[0]['series_instance_uid'] );
	}

	// =========================================================================
	// manage_imaging_studies tool
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
		$result = $tool->execute( array( 'action' => 'get', 'study_uid' => 'NONEXISTENT.UID' ) );
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
		$result = $tool->execute( array( 'action' => 'get', 'study_uid' => $uid ) );

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
				'instances'           => array_fill( 0, 60, array( 'sop_instance_uid' => uniqid( '', true ), 'file_path' => '/tmp/fake.dcm' ) ),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Manage_Imaging_Studies();
		$result = $tool->execute( array( 'action' => 'summarize', 'study_uid' => $uid ) );

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
}
