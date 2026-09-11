<?php
/**
 * Regulatory Registration Toolkit Initialization (ecosystem port — Wave F2,
 * regulatory-registration data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/regulatory-registration/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root.
 * 3. Slimmed wiring — the nine admin-page requires are file-gated (they
 *    land with the regulatory-registration admin slice) and the tool
 *    registry registration is replaced by the standalone-only tool wiring
 *    (deviation 5).
 * 4. Monolith guard — this init declares the global enqueue helper plus the
 *    four default-category/status/doc-type/country seeding helpers that the
 *    base init also declares; the collision is a compile-time fatal, so the
 *    ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_regulatory_registration_ecosystem_tools()` —
 *    both carry all sixty ported tools (the fifty-nine toolkit-gated tools
 *    + the tree-only import-blueprint tool).
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Monolith guard (deviation 4): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Load migration class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/migrations/class-wp-mcp-ai-migrate-requirement-post-type.php';

	// Run migration on admin init (only once).
	add_action(
		'admin_init',
		function () {
			// Only run migration if needed.
			$status = WP_MCP_AI_Migrate_Requirement_Post_Type::get_status();
			if ( $status['needs_migration'] && ! $status['migration_completed'] ) {
				// Run migration automatically.
				$result = WP_MCP_AI_Migrate_Requirement_Post_Type::run();

				// Log result.
				if ( 'success' === $result['status'] && function_exists( 'wp_mcp_ai_log_activity' ) ) {
					wp_mcp_ai_log_activity(
						'migration_requirement_post_type',
						sprintf( 'Migrated %d requirements from mcp_ai_reg_requirement to mcp_ai_requirement', $result['migrated'] )
					);
				}
			}
		}
	);

	// Load Regulatory Registration CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-regulatory-registration-cpt.php';

	// Load admin pages when in admin area (file-gated — they land with the
	// regulatory-registration admin slice).
	if ( is_admin() ) {
		// Check if regulatory registration toolkit is enabled and not in base version (unless Pro addon is active).
		$settings      = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled    = ! empty( $settings['enable_regulatory_registration_toolkit'] );
		$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

		if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
			$nvoos_content_graph_pro_admin_pages = array(
				'admin/class-wp-mcp-ai-regulatory-product-cpt-settings-page.php',
				'admin/class-wp-mcp-ai-reg-product-research-page.php',
				'admin/class-wp-mcp-ai-registration-settings-page.php',
				'admin/class-wp-mcp-ai-registration-dashboard-page.php',
				'admin/class-wp-mcp-ai-registration-research-page.php',
				'admin/class-wp-mcp-ai-reg-document-page.php',
				'admin/class-wp-mcp-ai-reg-document-research-page.php',
				'admin/class-wp-mcp-ai-reg-country-config-page.php',
				'admin/class-wp-mcp-ai-reg-migration-page.php',
			);

			foreach ( $nvoos_content_graph_pro_admin_pages as $nvoos_content_graph_pro_admin_page ) {
				$nvoos_content_graph_pro_admin_page_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/' . $nvoos_content_graph_pro_admin_page;
				if ( file_exists( $nvoos_content_graph_pro_admin_page_path ) ) {
					require_once $nvoos_content_graph_pro_admin_page_path;
				}
			}
		}
	}

	/**
	 * Enqueue regulatory registration admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	function wp_mcp_ai_enqueue_regulatory_registration_admin_styles( $hook ) {
		// Only load on regulatory registration edit screens.
		$screen = get_current_screen();
		if ( ! $screen || ! in_array(
			$screen->post_type,
			array(
				'mcp_ai_reg_product',
				'mcp_ai_registration',
				'mcp_ai_reg_document',
				'mcp_ai_reg_country',
				'mcp_ai_requirement',
			),
			true
		) ) {
			return;
		}

		// Check if regulatory registration toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-regulatory-registration.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-regulatory-registration-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-regulatory-registration.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_regulatory_registration_admin_styles' );

	/**
	 * Add default product categories on toolkit activation.
	 */
	function wp_mcp_ai_reg_add_default_categories() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			return;
		}

		// Check if categories already exist.
		$existing = get_terms(
			array(
				'taxonomy'   => 'mcp_ai_reg_category',
				'hide_empty' => false,
			)
		);

		if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
			return; // Categories already exist.
		}

		// Add default categories.
		$default_categories = array(
			'Skincare'  => 'Products for skin health and beauty',
			'Haircare'  => 'Products for hair care and styling',
			'Makeup'    => 'Cosmetic makeup products',
			'Perfumes'  => 'Fragrances and perfumes',
			'Cosmetics' => 'General cosmetic products',
		);

		foreach ( $default_categories as $name => $description ) {
			if ( ! term_exists( $name, 'mcp_ai_reg_category' ) ) {
				wp_insert_term(
					$name,
					'mcp_ai_reg_category',
					array(
						'description' => $description,
					)
				);
			}
		}
	}
	add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_categories' );

	/**
	 * Add default registration statuses on toolkit activation.
	 */
	function wp_mcp_ai_reg_add_default_statuses() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			return;
		}

		// Check if statuses already exist.
		$existing = get_terms(
			array(
				'taxonomy'   => 'mcp_ai_reg_status',
				'hide_empty' => false,
			)
		);

		if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
			return; // Statuses already exist.
		}

		// Add default statuses based on industry best practices.
		$default_statuses = array(
			'Draft'                => 'Initial registration draft',
			'Pending Documents'    => 'Waiting for required documents',
			'Ready for Submission' => 'All documents ready, awaiting submission',
			'Submitted'            => 'Application submitted to authority',
			'Under Review'         => 'Under review by regulatory authority',
			'Approved'             => 'Registration approved',
			'Rejected'             => 'Registration rejected',
			'On Hold'              => 'Registration on hold',
			'Renewal Due'          => 'Registration renewal required',
		);

		foreach ( $default_statuses as $name => $description ) {
			if ( ! term_exists( $name, 'mcp_ai_reg_status' ) ) {
				wp_insert_term(
					$name,
					'mcp_ai_reg_status',
					array(
						'description' => $description,
					)
				);
			}
		}
	}
	add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_statuses' );

	/**
	 * Add default document types on toolkit activation.
	 */
	function wp_mcp_ai_reg_add_default_document_types() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			return;
		}

		// Check if document types already exist.
		$existing = get_terms(
			array(
				'taxonomy'   => 'mcp_ai_doc_type',
				'hide_empty' => false,
			)
		);

		if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
			return; // Document types already exist.
		}

		// Add default document types based on common regulatory requirements.
		$default_doc_types = array(
			'LOA'                      => 'Letter of Authorization',
			'Manufacturer Declaration' => 'Manufacturer declaration document',
			'Artwork'                  => 'Product artwork and labeling',
			'Formula Certificate'      => 'Product formula certificate',
			'Certificate of Analysis'  => 'Certificate of Analysis (CoA)',
			'Free Sale Certificate'    => 'Certificate of Free Sale',
			'Sample Import License'    => 'License for importing product samples',
			'MSDS'                     => 'Material Safety Data Sheet',
			'GMP Certificate'          => 'Good Manufacturing Practice certificate',
			'ISO Certificate'          => 'ISO certification document',
			'Registration Certificate' => 'Official registration certificate',
			'Payment Receipt'          => 'Payment receipt or proof',
			'INCI List'                => 'International Nomenclature Cosmetic Ingredient list',
		);

		foreach ( $default_doc_types as $name => $description ) {
			if ( ! term_exists( $name, 'mcp_ai_doc_type' ) ) {
				wp_insert_term(
					$name,
					'mcp_ai_doc_type',
					array(
						'description' => $description,
					)
				);
			}
		}
	}
	add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_document_types' );

	/**
	 * Add default countries on toolkit activation.
	 */
	function wp_mcp_ai_reg_add_default_countries() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			return;
		}

		// Check if option is already set to prevent duplicate insertion.
		if ( get_option( 'wp_mcp_ai_reg_default_countries_added', false ) ) {
			return;
		}

		// Add default countries based on PRD requirements.
		$default_countries = array(
			array(
				'name'      => 'Sri Lanka',
				'code'      => 'LK',
				'authority' => 'NMRA (National Medicines Regulatory Authority)',
			),
			array(
				'name'      => 'United Arab Emirates',
				'code'      => 'AE',
				'authority' => 'MOHAP / Dubai Municipality',
			),
			array(
				'name'      => 'Saudi Arabia',
				'code'      => 'SA',
				'authority' => 'SFDA (Saudi Food and Drug Authority)',
			),
			array(
				'name'      => 'Qatar',
				'code'      => 'QA',
				'authority' => 'Ministry of Public Health',
			),
			array(
				'name'      => 'Kuwait',
				'code'      => 'KW',
				'authority' => 'Ministry of Health',
			),
			array(
				'name'      => 'Oman',
				'code'      => 'OM',
				'authority' => 'Ministry of Health',
			),
			array(
				'name'      => 'India',
				'code'      => 'IN',
				'authority' => 'CDSCO (Central Drugs Standard Control Organisation)',
			),
		);

		foreach ( $default_countries as $country ) {
			$post_id = wp_insert_post(
				array(
					'post_title'   => $country['name'],
					'post_content' => sprintf( 'Regulatory Authority: %s', $country['authority'] ),
					'post_type'    => 'mcp_ai_reg_country',
					'post_status'  => 'publish',
					'meta_input'   => array(
						'country_code'         => $country['code'],
						'regulatory_authority' => $country['authority'],
					),
				)
			);
		}

		// Mark that default countries have been added.
		update_option( 'wp_mcp_ai_reg_default_countries_added', true );
	}
	add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_countries' );

	/**
	 * Standalone-only tool filter — carries the ported regulatory-registration
	 * tool subset (inert standalone, consumed by the base plugin monolith).
	 * The map fills as the regulatory-registration tool batches land.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_regulatory_registration_tools( $tools ) {
		$nvoos_content_graph_pro_reg_tools = array(
			'WP_MCP_AI_Tool_Add_Regulatory_Requirement'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-add-regulatory-requirement.php',
			'WP_MCP_AI_Tool_Approve_Registration'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-approve-registration.php',
			'WP_MCP_AI_Tool_Check_Authority_Status'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-check-authority-status.php',
			'WP_MCP_AI_Tool_Check_Document_Expiry'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-check-document-expiry.php',
			'WP_MCP_AI_Tool_Check_HS_Code'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-check-hs-code.php',
			'WP_MCP_AI_Tool_Check_Product_Compliance'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-check-product-compliance.php',
			'WP_MCP_AI_Tool_Configure_Email_Notifications' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-configure-email-notifications.php',
			'WP_MCP_AI_Tool_Create_Reg_Product'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-create-reg-product.php',
			'WP_MCP_AI_Tool_Create_Registration'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-create-registration.php',
			'WP_MCP_AI_Tool_Create_Workflow_Rule'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-create-workflow-rule.php',
			'WP_MCP_AI_Tool_Delete_Reg_Product'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-delete-reg-product.php',
			'WP_MCP_AI_Tool_Delete_Workflow_Rule'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-delete-workflow-rule.php',
			'WP_MCP_AI_Tool_Duplicate_Reg_Product'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-duplicate-reg-product.php',
			'WP_MCP_AI_Tool_Export_Products_To_Excel'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-export-products-to-excel.php',
			'WP_MCP_AI_Tool_Export_Registrations_To_Excel' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-export-registrations-to-excel.php',
			'WP_MCP_AI_Tool_Generate_Compliance_Certificate' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-compliance-certificate.php',
			'WP_MCP_AI_Tool_Generate_Compliance_Report'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-compliance-report.php',
			'WP_MCP_AI_Tool_Generate_Cost_Analysis'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-cost-analysis.php',
			'WP_MCP_AI_Tool_Generate_Country_Performance'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-country-performance.php',
			'WP_MCP_AI_Tool_Generate_Cover_Letter'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-cover-letter.php',
			'WP_MCP_AI_Tool_Generate_Expiry_Forecast'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-expiry-forecast.php',
			'WP_MCP_AI_Tool_Generate_Pdf_Dossier'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-pdf-dossier.php',
			'WP_MCP_AI_Tool_Generate_Pipeline_Report'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-pipeline-report.php',
			'WP_MCP_AI_Tool_Generate_Submission_Pack'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-generate-submission-pack.php',
			'WP_MCP_AI_Tool_Get_Notification_History'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-notification-history.php',
			'WP_MCP_AI_Tool_Get_Reg_Document'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-reg-document.php',
			'WP_MCP_AI_Tool_Get_Reg_Product'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-reg-product.php',
			'WP_MCP_AI_Tool_Get_Registration'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-registration.php',
			'WP_MCP_AI_Tool_Get_Registration_Timeline'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-registration-timeline.php',
			'WP_MCP_AI_Tool_Get_Regulatory_Requirements'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-regulatory-requirements.php',
			'WP_MCP_AI_Tool_Get_Regulatory_Updates'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-regulatory-updates.php',
			'WP_MCP_AI_Tool_Get_Workflow_Execution_Log'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-get-workflow-execution-log.php',
			'WP_MCP_AI_Tool_Import_Products_From_Excel'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-import-products-from-excel.php',
			'WP_MCP_AI_Tool_Import_Registrations_From_Excel' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-import-registrations-from-excel.php',
			'WP_MCP_AI_Tool_Import_Regulatory_Registration_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/examples/class-wp-mcp-ai-tool-import-regulatory-registration-blueprint.php',
			'WP_MCP_AI_Tool_List_Expiring_Registrations'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-list-expiring-registrations.php',
			'WP_MCP_AI_Tool_List_Reg_Documents'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-list-reg-documents.php',
			'WP_MCP_AI_Tool_List_Reg_Products'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-list-reg-products.php',
			'WP_MCP_AI_Tool_List_Registrations'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-list-registrations.php',
			'WP_MCP_AI_Tool_List_Registrations_By_Country' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-list-registrations-by-country.php',
			'WP_MCP_AI_Tool_List_Workflow_Rules'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-list-workflow-rules.php',
			'WP_MCP_AI_Tool_Renew_Registration'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-renew-registration.php',
			'WP_MCP_AI_Tool_Search_Reg_Products'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-search-reg-products.php',
			'WP_MCP_AI_Tool_Send_Expiry_Alerts'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-send-expiry-alerts.php',
			'WP_MCP_AI_Tool_Send_Status_Change_Notification' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-send-status-change-notification.php',
			'WP_MCP_AI_Tool_Submit_Registration'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-submit-registration.php',
			'WP_MCP_AI_Tool_Submit_To_Authority'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-submit-to-authority.php',
			'WP_MCP_AI_Tool_Sync_With_Mohap'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-sync-with-mohap.php',
			'WP_MCP_AI_Tool_Sync_With_Nmra'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-sync-with-nmra.php',
			'WP_MCP_AI_Tool_Test_Workflow_Rule'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-test-workflow-rule.php',
			'WP_MCP_AI_Tool_Track_Document_Version'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-track-document-version.php',
			'WP_MCP_AI_Tool_Update_Reg_Document'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-update-reg-document.php',
			'WP_MCP_AI_Tool_Update_Reg_Product'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-update-reg-product.php',
			'WP_MCP_AI_Tool_Update_Registration_Status'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-update-registration-status.php',
			'WP_MCP_AI_Tool_Update_Workflow_Rule'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-update-workflow-rule.php',
			'WP_MCP_AI_Tool_Upload_Reg_Document'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-upload-reg-document.php',
			'WP_MCP_AI_Tool_Validate_Document_Checklist'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-document-checklist.php',
			'WP_MCP_AI_Tool_Validate_Excel_Import'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-excel-import.php',
			'WP_MCP_AI_Tool_Validate_INCI_Ingredients'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-inci-ingredients.php',
			'WP_MCP_AI_Tool_Validate_Reg_Product'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-validate-reg-product.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_reg_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * regulatory-registration tools into the ecosystem graph ToolRegistry and
	 * the nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring
	 * as the image-production/video inits). Carries all sixty ported tools
	 * (the fifty-nine toolkit-gated tools + the tree-only import-blueprint
	 * tool).
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_regulatory_registration_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Add_Regulatory_Requirement',
				'WP_MCP_AI_Tool_Approve_Registration',
				'WP_MCP_AI_Tool_Check_Authority_Status',
				'WP_MCP_AI_Tool_Check_Document_Expiry',
				'WP_MCP_AI_Tool_Check_HS_Code',
				'WP_MCP_AI_Tool_Check_Product_Compliance',
				'WP_MCP_AI_Tool_Configure_Email_Notifications',
				'WP_MCP_AI_Tool_Create_Reg_Product',
				'WP_MCP_AI_Tool_Create_Registration',
				'WP_MCP_AI_Tool_Create_Workflow_Rule',
				'WP_MCP_AI_Tool_Delete_Reg_Product',
				'WP_MCP_AI_Tool_Delete_Workflow_Rule',
				'WP_MCP_AI_Tool_Duplicate_Reg_Product',
				'WP_MCP_AI_Tool_Export_Products_To_Excel',
				'WP_MCP_AI_Tool_Export_Registrations_To_Excel',
				'WP_MCP_AI_Tool_Generate_Compliance_Certificate',
				'WP_MCP_AI_Tool_Generate_Compliance_Report',
				'WP_MCP_AI_Tool_Generate_Cost_Analysis',
				'WP_MCP_AI_Tool_Generate_Country_Performance',
				'WP_MCP_AI_Tool_Generate_Cover_Letter',
				'WP_MCP_AI_Tool_Generate_Expiry_Forecast',
				'WP_MCP_AI_Tool_Generate_Pdf_Dossier',
				'WP_MCP_AI_Tool_Generate_Pipeline_Report',
				'WP_MCP_AI_Tool_Generate_Submission_Pack',
				'WP_MCP_AI_Tool_Get_Notification_History',
				'WP_MCP_AI_Tool_Get_Reg_Document',
				'WP_MCP_AI_Tool_Get_Reg_Product',
				'WP_MCP_AI_Tool_Get_Registration',
				'WP_MCP_AI_Tool_Get_Registration_Timeline',
				'WP_MCP_AI_Tool_Get_Regulatory_Requirements',
				'WP_MCP_AI_Tool_Get_Regulatory_Updates',
				'WP_MCP_AI_Tool_Get_Workflow_Execution_Log',
				'WP_MCP_AI_Tool_Import_Products_From_Excel',
				'WP_MCP_AI_Tool_Import_Registrations_From_Excel',
				'WP_MCP_AI_Tool_Import_Regulatory_Registration_Blueprint',
				'WP_MCP_AI_Tool_List_Expiring_Registrations',
				'WP_MCP_AI_Tool_List_Reg_Documents',
				'WP_MCP_AI_Tool_List_Reg_Products',
				'WP_MCP_AI_Tool_List_Registrations',
				'WP_MCP_AI_Tool_List_Registrations_By_Country',
				'WP_MCP_AI_Tool_List_Workflow_Rules',
				'WP_MCP_AI_Tool_Renew_Registration',
				'WP_MCP_AI_Tool_Search_Reg_Products',
				'WP_MCP_AI_Tool_Send_Expiry_Alerts',
				'WP_MCP_AI_Tool_Send_Status_Change_Notification',
				'WP_MCP_AI_Tool_Submit_Registration',
				'WP_MCP_AI_Tool_Submit_To_Authority',
				'WP_MCP_AI_Tool_Sync_With_Mohap',
				'WP_MCP_AI_Tool_Sync_With_Nmra',
				'WP_MCP_AI_Tool_Test_Workflow_Rule',
				'WP_MCP_AI_Tool_Track_Document_Version',
				'WP_MCP_AI_Tool_Update_Reg_Document',
				'WP_MCP_AI_Tool_Update_Reg_Product',
				'WP_MCP_AI_Tool_Update_Registration_Status',
				'WP_MCP_AI_Tool_Update_Workflow_Rule',
				'WP_MCP_AI_Tool_Upload_Reg_Document',
				'WP_MCP_AI_Tool_Validate_Document_Checklist',
				'WP_MCP_AI_Tool_Validate_Excel_Import',
				'WP_MCP_AI_Tool_Validate_INCI_Ingredients',
				'WP_MCP_AI_Tool_Validate_Reg_Product',
			) as $nvoos_content_graph_pro_tool_class
		) {
			$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
			try {
				$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}

			// Wrap into the nvoos/core registry so the agentic chat loop can
			// resolve and execute the tool (same path the AI addon uses).
			if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
				$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
				try {
					$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
				} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
					unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
				}
			}
		}
	}

	// ---- Standalone-only tool wiring (deviation 5). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_regulatory_registration_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_regulatory_registration_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
