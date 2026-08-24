<?php
/**
 * AJAX tests for healthcare / medical vitals handlers (Pro addon).
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_hw_dashboard_get_health_metrics (Health-Wellness dashboard)
 *   - wp_mcp_ai_mv_dashboard_get_vital_signs    (Medical-Vitals dashboard)
 *   - wp_mcp_ai_get_member_vitals_preview       (Health Records Consolidate page)
 *   - wp_mcp_ai_bulk_import_health_info         (Health Records Consolidate page)
 *   - wp_mcp_ai_upload_health_document          (Health Records Consolidate page)
 *   - wp_mcp_ai_import_vitals_to_cct            (Health Records Consolidate page)
 *
 * All handlers live in the Pro addon. Tests are skipped when the relevant
 * class is absent from the environment.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Healthcare / Medical Vitals (Pro).
 */
// Load the Pro admin class under test; the pro addon loads it only in admin
// context, so require it here to keep the suite runnable standalone (mirrors
// CI, where earlier admin-context tests load it).
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	$wp_mcp_ai_health_page = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-wellness-dashboard-page.php';
	if ( file_exists( $wp_mcp_ai_health_page ) ) {
		require_once $wp_mcp_ai_health_page;
	}
	unset( $wp_mcp_ai_health_page );
}

class Test_Healthcare_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/** Nonce for hw_dashboard handler. */
	const NONCE_HW = 'wp_mcp_ai_hw_dashboard';

	/** Nonce for mv_dashboard handler. */
	const NONCE_MV = 'wp_mcp_ai_mv_dashboard';

	/** Nonce for health consolidate handlers. */
	const NONCE_HC = 'wp_mcp_ai_health_consolidate';

	/** Required capability for hw/mv dashboard handlers. */
	const CAP_DASHBOARD = 'edit_posts';

	// ---
	// wp_mcp_ai_hw_dashboard_get_health_metrics
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_hw_get_health_metrics_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Wellness_Dashboard_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_hw_dashboard_get_health_metrics',
			array( 'member_id' => 1 )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_hw_get_health_metrics_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Wellness_Dashboard_Page (Pro) not available.' );
		}

		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_hw_dashboard_get_health_metrics',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HW ),
				'member_id' => 1,
			)
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Validates the missing member id parameter. */
	public function test_hw_get_health_metrics_validates_missing_member_id() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Wellness_Dashboard_Page (Pro) not available.' );
		}

		$this->as_editor();

		$response = $this->dispatch(
			'wp_mcp_ai_hw_dashboard_get_health_metrics',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HW ),
				'member_id' => '',
			)
		);

		$this->assertAjaxError( $response, 'Member ID is required' );
	}

	/** Verifies the response returns structured response. */
	public function test_hw_get_health_metrics_returns_structured_response() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Wellness_Dashboard_Page (Pro) not available.' );
		}

		$this->as_editor();

		$response = $this->dispatch(
			'wp_mcp_ai_hw_dashboard_get_health_metrics',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HW ),
				'member_id' => 99999,
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_mv_dashboard_get_vital_signs
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_mv_get_vital_signs_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_mv_dashboard_get_vital_signs',
			array( 'member_id' => 1 )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_mv_get_vital_signs_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page (Pro) not available.' );
		}

		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_mv_dashboard_get_vital_signs',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_MV ),
				'member_id' => 1,
			)
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Validates the missing member id parameter. */
	public function test_mv_get_vital_signs_validates_missing_member_id() {
		if ( ! class_exists( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page (Pro) not available.' );
		}

		$this->as_editor();

		$response = $this->dispatch(
			'wp_mcp_ai_mv_dashboard_get_vital_signs',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_MV ),
				'member_id' => '',
			)
		);

		$this->assertAjaxError( $response, 'Member ID is required' );
	}

	/** Verifies the response returns structured response. */
	public function test_mv_get_vital_signs_returns_structured_response() {
		if ( ! class_exists( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page (Pro) not available.' );
		}

		$this->as_editor();

		$response = $this->dispatch(
			'wp_mcp_ai_mv_dashboard_get_vital_signs',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_MV ),
				'member_id' => 99999,
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_get_member_vitals_preview
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_member_vitals_preview_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_get_member_vitals_preview',
			array( 'member_id' => 1 )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Get member vitals preview rejects no cap user. */
	public function test_get_member_vitals_preview_rejects_no_cap_user() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		// The handler requires `read` cap only; however a subscriber without
		// `read` override should fail the cap check.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Force remove the `read` cap to simulate a user without any capability.
		$user = get_user_by( 'id', $user_id );
		$user->remove_cap( 'read' );

		$response = $this->dispatch(
			'wp_mcp_ai_get_member_vitals_preview',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HC ),
				'member_id' => 1,
			)
		);

		$this->assertAjaxError( $response );
	}

	/** Validates the invalid member id parameter. */
	public function test_get_member_vitals_preview_validates_invalid_member_id() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_get_member_vitals_preview',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HC ),
				'member_id' => 0,
			)
		);

		$this->assertAjaxError( $response, 'Invalid member ID' );
	}

	/** Verifies the response returns structured response. */
	public function test_get_member_vitals_preview_returns_structured_response() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_get_member_vitals_preview',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HC ),
				'member_id' => 99999,
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_bulk_import_health_info
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_bulk_import_health_info_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_bulk_import_health_info',
			array( 'member_id' => 1 )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_bulk_import_health_info_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_bulk_import_health_info',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HC ),
				'member_id' => 1,
			)
		);

		$this->assertAjaxError( $response, 'You do not have permission' );
	}

	/** Validates the missing member id parameter. */
	public function test_bulk_import_health_info_validates_missing_member_id() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_editor();

		$response = $this->dispatch(
			'wp_mcp_ai_bulk_import_health_info',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HC ),
				'member_id' => '',
			)
		);

		$this->assertAjaxError( $response, 'Please select a member first' );
	}

	/** Validates the missing content parameter. */
	public function test_bulk_import_health_info_validates_missing_content() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_editor();

		$response = $this->dispatch(
			'wp_mcp_ai_bulk_import_health_info',
			array(
				'nonce'       => wp_create_nonce( self::NONCE_HC ),
				'member_id'   => 1,
				'health_info' => '',
			)
		);

		$this->assertAjaxError( $response, 'Please provide health information' );
	}

	// ---
	// wp_mcp_ai_upload_health_document
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_upload_health_document_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_upload_health_document' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_upload_health_document_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_upload_health_document',
			array( 'nonce' => wp_create_nonce( self::NONCE_HC ) )
		);

		$this->assertAjaxError( $response );
	}

	/** Validates the no file parameter. */
	public function test_upload_health_document_validates_no_file() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_admin();
		$_FILES = array();

		$response = $this->dispatch(
			'wp_mcp_ai_upload_health_document',
			array( 'nonce' => wp_create_nonce( self::NONCE_HC ) )
		);

		$this->assertAjaxError( $response );
	}

	// ---
	// wp_mcp_ai_import_vitals_to_cct
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_import_vitals_to_cct_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_import_vitals_to_cct',
			array( 'member_id' => 1 )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_import_vitals_to_cct_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_import_vitals_to_cct',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HC ),
				'member_id' => 1,
			)
		);

		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_import_vitals_to_cct_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_Health_Records_Consolidate_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Health_Records_Consolidate_Page (Pro) not available.' );
		}

		$this->as_editor();

		$response = $this->dispatch(
			'wp_mcp_ai_import_vitals_to_cct',
			array(
				'nonce'     => wp_create_nonce( self::NONCE_HC ),
				'member_id' => 99999,
				'vitals'    => wp_json_encode( array() ),
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}
}
