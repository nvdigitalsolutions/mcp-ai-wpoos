<?php
/**
 * Tests for Phase D — Healthcare Imaging depth.
 *
 * @package WP_MCP_AI_Pro
 */

$base = dirname( __DIR__, 4 );
require_once $base . '/includes/interfaces/interface-wp-mcp-ai-tool.php';

$imaging_dir = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/imaging';
require_once $imaging_dir . '/class-wp-mcp-ai-dicomweb-client.php';
foreach ( array(
	'class-wp-mcp-ai-tool-connect-dicomweb.php',
	'class-wp-mcp-ai-tool-import-dicom-study.php',
	'class-wp-mcp-ai-tool-export-dicom-study.php',
	'class-wp-mcp-ai-tool-attach-radiology-report.php',
	'class-wp-mcp-ai-tool-compare-imaging-studies.php',
	'class-wp-mcp-ai-tool-get-imaging-hanging-protocol.php',
) as $file ) {
	$file_path = $imaging_dir . '/' . $file;
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

/**
 * Phase D imaging tools test case.
 */
class Test_Healthcare_Imaging extends WP_UnitTestCase {

	/**
	 * Ensure imaging study CPT exists for tests.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( 'mcp_ai_imaging_study' ) ) {
			register_post_type(
				'mcp_ai_imaging_study',
				array(
					'public' => false,
					'label'  => 'imaging',
				)
			);
		}
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
	}

	/**
	 * Create study.
	 *
	 * @param array $args Study arguments.
	 */
	private function make_study( $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'study_uid'   => '1.2.3.4.5.' . wp_generate_uuid4(),
				'modality'    => 'CT',
				'study_date'  => '20260101',
				'description' => 'Chest CT',
				'patient_id'  => 'PAT-1',
				'series'      => array(
					array(
						'series_instance_uid' => '1.2.3.4.5.6',
						'modality'            => 'CT',
						'description'         => 'axial',
						'instance_count'      => 50,
					),
				),
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_imaging_study',
				'post_title' => $args['description'],
			)
		);
		update_post_meta( $post_id, '_imaging_study_instance_uid', $args['study_uid'] );
		update_post_meta( $post_id, '_imaging_modality', $args['modality'] );
		update_post_meta( $post_id, '_imaging_study_date', $args['study_date'] );
		update_post_meta( $post_id, '_imaging_study_description', $args['description'] );
		update_post_meta( $post_id, '_imaging_patient_id', $args['patient_id'] );
		update_post_meta( $post_id, '_imaging_series', wp_json_encode( $args['series'] ) );
		return $post_id;
	}

	/** Test dicomweb client save and redact.
	 */
	public function test_dicomweb_client_save_and_redact() {
		WP_MCP_AI_DICOMweb_Client::save_connection(
			array(
				'base_url'     => 'https://pacs.example.org/dicom-web',
				'auth_type'    => 'bearer',
				'bearer_token' => 'sekret',
				'timeout'      => 45,
			)
		);
		$conn = WP_MCP_AI_DICOMweb_Client::get_connection();
		$this->assertSame( 'bearer', $conn['auth_type'] );
		$this->assertSame( 45, (int) $conn['timeout'] );

		$tool   = new WP_MCP_AI_Tool_Connect_DICOMweb();
		$result = $tool->execute( array( 'action' => 'get' ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( '[redacted]', $result['config']['bearer_token'] );
	}

	/** Test dicomweb client invalid auth type falls back to none.
	 */
	public function test_dicomweb_client_invalid_auth_type_falls_back_to_none() {
		WP_MCP_AI_DICOMweb_Client::save_connection(
			array(
				'base_url'  => 'https://pacs.example.org/dicom-web',
				'auth_type' => 'evil',
			)
		);
		$conn = WP_MCP_AI_DICOMweb_Client::get_connection();
		$this->assertSame( 'none', $conn['auth_type'] );
	}

	/** Test connect dicomweb requires admin.
	 */
	public function test_connect_dicomweb_requires_admin() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$tool = new WP_MCP_AI_Tool_Connect_DICOMweb();
		$out  = $tool->execute( array( 'action' => 'get' ) );
		$this->assertWPError( $out );
	}

	/** Test compare imaging studies diff.
	 */
	public function test_compare_imaging_studies_diff() {
		$a = $this->make_study(
			array(
				'modality'   => 'CT',
				'study_date' => '20260101',
			)
		);
		$b = $this->make_study(
			array(
				'modality'   => 'CT',
				'study_date' => '20260201',
				'series'     => array(
					array(
						'series_instance_uid' => 'a',
						'instance_count'      => 50,
					),
					array(
						'series_instance_uid' => 'b',
						'instance_count'      => 30,
					),
				),
			)
		);

		$tool = new WP_MCP_AI_Tool_Compare_Imaging_Studies();
		$out  = $tool->execute(
			array(
				'prior_study_id'   => $a,
				'current_study_id' => $b,
			)
		);
		$this->assertTrue( $out['success'] );
		$this->assertFalse( $out['diff']['modality_changed'] );
		$this->assertTrue( $out['diff']['study_date_changed'] );
		$this->assertSame( 1, $out['diff']['series_count_delta'] );
		$this->assertSame( 30, $out['diff']['instance_count_delta'] );
		$this->assertSame( 31, $out['days_between'] );
	}

	/** Test compare imaging studies missing.
	 */
	public function test_compare_imaging_studies_missing() {
		$tool = new WP_MCP_AI_Tool_Compare_Imaging_Studies();
		$out  = $tool->execute(
			array(
				'prior_study_id'   => 9999,
				'current_study_id' => 9998,
			)
		);
		$this->assertWPError( $out );
	}

	/** Test attach radiology report.
	 */
	public function test_attach_radiology_report() {
		$study_id = $this->make_study();
		$tool     = new WP_MCP_AI_Tool_Attach_Radiology_Report();
		$out      = $tool->execute(
			array(
				'study_id'         => $study_id,
				'findings'         => 'No acute findings.',
				'impression'       => 'Negative study.',
				'generate_sr'      => true,
				'reporting_doctor' => 'Dr. Test',
			)
		);
		$this->assertTrue( $out['success'] );
		$this->assertGreaterThan( 0, $out['report_id'] );
		$this->assertNotEmpty( $out['sr'] );
		$this->assertSame( array( 'SR' ), $out['sr']['00080060']['Value'] );

		$linked = (array) get_post_meta( $study_id, '_imaging_report_ids', true );
		$this->assertContains( (int) $out['report_id'], $linked );
	}

	/** Test attach radiology report missing body.
	 */
	public function test_attach_radiology_report_missing_body() {
		$study_id = $this->make_study();
		$tool     = new WP_MCP_AI_Tool_Attach_Radiology_Report();
		$out      = $tool->execute(
			array(
				'study_id'   => $study_id,
				'findings'   => '',
				'impression' => '',
			)
		);
		$this->assertWPError( $out );
	}

	/** Test hanging protocol lookup by modality.
	 */
	public function test_hanging_protocol_lookup_by_modality() {
		$tool = new WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol();
		$out  = $tool->execute( array( 'modality' => 'pt' ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'PT', $out['modality'] );
		$this->assertSame( '2x2', $out['protocol']['layout'] );
	}

	/** Test hanging protocol resolves modality from study.
	 */
	public function test_hanging_protocol_resolves_modality_from_study() {
		$study_id = $this->make_study( array( 'modality' => 'MR' ) );
		$tool     = new WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol();
		$out      = $tool->execute( array( 'study_id' => $study_id ) );
		$this->assertSame( 'MR', $out['modality'] );
	}

	/** Test hanging protocol filter.
	 */
	public function test_hanging_protocol_filter() {
		add_filter(
			'wp_mcp_ai_healthcare_hanging_protocols',
			static function ( $p ) {
				$p['CT'] = array(
					'name'   => 'site override',
					'layout' => '4x1',
					'stages' => array(),
				);
				return $p;
			}
		);
		$tool = new WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol();
		$out  = $tool->execute( array( 'modality' => 'CT' ) );
		$this->assertSame( '4x1', $out['protocol']['layout'] );
	}

	/** Test hanging protocol unknown modality returns generic.
	 */
	public function test_hanging_protocol_unknown_modality_returns_generic() {
		$tool = new WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol();
		$out  = $tool->execute( array( 'modality' => 'XX' ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( '1x1', $out['protocol']['layout'] );
	}

	/** Test export filter runs.
	 */
	public function test_export_filter_runs() {
		$study_id = $this->make_study();
		$called   = false;
		add_filter(
			'wp_mcp_ai_healthcare_before_imaging_export',
			static function ( $instances, $sid, $deid ) use ( &$called ) {
				$called = true;
				// Strip patient id.
				foreach ( $instances as &$inst ) {
					if ( isset( $inst['00100020'] ) ) {
						$inst['00100020']['Value'] = array( 'ANON' );
					}
				}
				return $instances;
			},
			10,
			3
		);
		// Stub HTTP so STOW request never leaves the test runner.
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, '/studies' ) && isset( $args['method'] ) && 'POST' === $args['method'] ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"00081190":{"vr":"UR","Value":["ok"]}}',
						'headers'  => array(),
					);
				}
				return $pre;
			},
			10,
			3
		);
		WP_MCP_AI_DICOMweb_Client::save_connection( array( 'base_url' => 'https://pacs.example.org/dicom-web' ) );

		$tool = new WP_MCP_AI_Tool_Export_DICOM_Study();
		$out  = $tool->execute( array( 'study_id' => $study_id ) );
		$this->assertTrue( $out['success'] );
		$this->assertTrue( $called, 'Export filter should be invoked.' );
		$this->assertGreaterThan( 0, $out['instances'] );
	}
}
