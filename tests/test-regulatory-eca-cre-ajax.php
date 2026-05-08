<?php
/**
 * AJAX tests for regulatory, ECA, CRE and consolidate handlers (Pro addon).
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_cre_dashboard_filter        (CRE Debt dashboard)
 *   - wp_mcp_ai_eca_dashboard_data          (ECA dashboard)
 *   - wp_mcp_ai_create_cre_loan_from_research   (CRE Debt research page)
 *   - wp_mcp_ai_create_cre_property_from_research
 *   - wp_mcp_ai_create_eca_from_research    (ECA research page)
 *   - wp_mcp_ai_import_eca                  (ECA research page)
 *   - wp_mcp_ai_create_reg_product_from_research (Reg product research)
 *   - wp_mcp_ai_import_reg_product          (Reg product research)
 *   - wp_mcp_ai_bulk_import_reg_products    (Reg product research)
 *   - wp_mcp_ai_upload_reg_document         (Reg product research)
 *   - wp_mcp_ai_import_reg_document         (Reg document research)
 *   - wp_mcp_ai_upload_reg_document_from_research
 *   - wp_mcp_ai_consolidate_bulk_import     (Consolidate base)
 *   - wp_mcp_ai_consolidate_upload_document
 *   - wp_mcp_ai_consolidate_validate_data
 *   - wp_mcp_ai_consolidate_check_completeness
 *
 * All handlers live in the Pro addon; the whole class is skipped when the
 * relevant Pro classes are absent from the environment.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Regulatory / ECA / CRE / Consolidate (Pro).
 */
class Test_Regulatory_ECA_CRE_AJAX extends WP_MCP_AI_Ajax_TestCase {

	// ---
	// Nonces (each handler documents its own nonce action).
	// ---

	const NONCE_CRE_DASHBOARD = 'wp_mcp_ai_cre_dashboard';
	const NONCE_ECA_DASHBOARD = 'wp_mcp_ai_eca_dashboard';
	const NONCE_CRE_RESEARCH  = 'wp_mcp_ai_cre_research';
	const NONCE_ECA_RESEARCH  = 'wp_mcp_ai_research_eca';
	const NONCE_REG_RESEARCH  = 'wp_mcp_ai_research_reg_product';
	const NONCE_REG_CONSOL    = 'wp_mcp_ai_reg_consolidate';
	const NONCE_BULK_IMPORT   = 'wp_mcp_ai_bulk_import';
	const NONCE_UPLOAD_DOC    = 'wp_mcp_ai_upload_document';
	const NONCE_VALIDATE      = 'wp_mcp_ai_validate_data';
	const NONCE_CHECK_COMP    = 'wp_mcp_ai_check_completeness';

	// ---
	// wp_mcp_ai_cre_dashboard_filter
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_cre_dashboard_filter_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_CRE_Debt_Dashboard_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_cre_dashboard_filter' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_cre_dashboard_filter_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_CRE_Debt_Dashboard_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_cre_dashboard_filter',
			array( 'nonce' => wp_create_nonce( self::NONCE_CRE_DASHBOARD ) )
		);
		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_cre_dashboard_filter_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_CRE_Debt_Dashboard_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_cre_dashboard_filter',
			array( 'nonce' => wp_create_nonce( self::NONCE_CRE_DASHBOARD ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_eca_dashboard_data
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_eca_dashboard_data_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Dashboard_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_eca_dashboard_data' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_eca_dashboard_data_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Dashboard_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_eca_dashboard_data',
			array( 'nonce' => wp_create_nonce( self::NONCE_ECA_DASHBOARD ) )
		);
		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_eca_dashboard_data_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Dashboard_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Dashboard_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_eca_dashboard_data',
			array( 'nonce' => wp_create_nonce( self::NONCE_ECA_DASHBOARD ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_create_cre_loan_from_research
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_create_cre_loan_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_CRE_Debt_Research_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_create_cre_loan_from_research',
			array( 'data' => wp_json_encode( array( 'name' => 'Test' ) ) )
		);
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_create_cre_loan_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_CRE_Debt_Research_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_create_cre_loan_from_research',
			array(
				'nonce' => wp_create_nonce( self::NONCE_CRE_RESEARCH ),
				'data'  => wp_json_encode( array( 'name' => 'Test' ) ),
			)
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_create_cre_loan_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_CRE_Debt_Research_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_create_cre_loan_from_research',
			array(
				'nonce' => wp_create_nonce( self::NONCE_CRE_RESEARCH ),
				'data'  => wp_json_encode( array( 'name' => 'Test Loan' ) ),
			)
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_create_eca_from_research
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_create_eca_from_research_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Research_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_create_eca_from_research' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_create_eca_from_research_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Research_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_create_eca_from_research',
			array( 'nonce' => wp_create_nonce( self::NONCE_ECA_RESEARCH ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_create_eca_from_research_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Research_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_create_eca_from_research',
			array(
				'nonce' => wp_create_nonce( self::NONCE_ECA_RESEARCH ),
				'data'  => wp_json_encode( array( 'title' => 'Test ECA' ) ),
			)
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_import_eca
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_import_eca_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Research_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_import_eca' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_import_eca_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Research_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_import_eca',
			array( 'nonce' => wp_create_nonce( self::NONCE_ECA_RESEARCH ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_import_eca_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_ECA_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_ECA_Research_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_import_eca',
			array( 'nonce' => wp_create_nonce( self::NONCE_ECA_RESEARCH ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_create_reg_product_from_research
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_create_reg_product_from_research_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Product_Research_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_create_reg_product_from_research' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_create_reg_product_from_research_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Product_Research_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_create_reg_product_from_research',
			array( 'nonce' => wp_create_nonce( self::NONCE_REG_RESEARCH ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_create_reg_product_from_research_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Product_Research_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_create_reg_product_from_research',
			array(
				'nonce' => wp_create_nonce( self::NONCE_REG_RESEARCH ),
				'data'  => wp_json_encode( array( 'title' => 'Test Product' ) ),
			)
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_consolidate_bulk_import
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_consolidate_bulk_import_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_consolidate_bulk_import' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_consolidate_bulk_import_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_consolidate_bulk_import',
			array( 'nonce' => wp_create_nonce( self::NONCE_BULK_IMPORT ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for admin. */
	public function test_consolidate_bulk_import_returns_structured_response_for_admin() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_consolidate_bulk_import',
			array( 'nonce' => wp_create_nonce( self::NONCE_BULK_IMPORT ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_consolidate_validate_data
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_consolidate_validate_data_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_consolidate_validate_data' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_consolidate_validate_data_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_consolidate_validate_data',
			array( 'nonce' => wp_create_nonce( self::NONCE_VALIDATE ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for admin. */
	public function test_consolidate_validate_data_returns_structured_response_for_admin() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_consolidate_validate_data',
			array( 'nonce' => wp_create_nonce( self::NONCE_VALIDATE ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_consolidate_check_completeness
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_consolidate_check_completeness_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_consolidate_check_completeness' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_consolidate_check_completeness_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_consolidate_check_completeness',
			array( 'nonce' => wp_create_nonce( self::NONCE_CHECK_COMP ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for admin. */
	public function test_consolidate_check_completeness_returns_structured_response_for_admin() {
		if ( ! class_exists( 'WP_MCP_AI_Consolidate_Add_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Consolidate_Add_Base (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch(
			'wp_mcp_ai_consolidate_check_completeness',
			array( 'nonce' => wp_create_nonce( self::NONCE_CHECK_COMP ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_bulk_import_reg_products
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_bulk_import_reg_products_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Product_Research_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_bulk_import_reg_products' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_bulk_import_reg_products_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Product_Research_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		$response = $this->dispatch(
			'wp_mcp_ai_bulk_import_reg_products',
			array( 'nonce' => wp_create_nonce( self::NONCE_REG_CONSOL ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_bulk_import_reg_products_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Product_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Product_Research_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_bulk_import_reg_products',
			array( 'nonce' => wp_create_nonce( self::NONCE_REG_CONSOL ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_import_reg_document
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_import_reg_document_rejects_missing_nonce() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Document_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Document_Research_Page (Pro) not available.' );
		}

		$this->as_admin();
		$response = $this->dispatch( 'wp_mcp_ai_import_reg_document' );
		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_import_reg_document_rejects_subscriber() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Document_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Document_Research_Page (Pro) not available.' );
		}

		$this->as_subscriber();
		// This handler's nonce action is defined in the subclass; using REG_CONSOL as fallback.
		$response = $this->dispatch(
			'wp_mcp_ai_import_reg_document',
			array( 'nonce' => wp_create_nonce( self::NONCE_REG_CONSOL ) )
		);
		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for editor. */
	public function test_import_reg_document_returns_structured_response_for_editor() {
		if ( ! class_exists( 'WP_MCP_AI_Reg_Document_Research_Page' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Reg_Document_Research_Page (Pro) not available.' );
		}

		$this->as_editor();
		$response = $this->dispatch(
			'wp_mcp_ai_import_reg_document',
			array( 'nonce' => wp_create_nonce( self::NONCE_REG_CONSOL ) )
		);
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}
}
