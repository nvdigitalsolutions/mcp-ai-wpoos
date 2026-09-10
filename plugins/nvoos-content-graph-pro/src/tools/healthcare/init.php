<?php
/**
 * Healthcare Toolkit Initialization (ecosystem port — Wave F4, healthcare
 * data layer).
 *
 * Slimmed standalone init for the `nvoos-content-graph-pro` addon. The base
 * Pro addon owns the same init monolith (booted via its module registry's
 * `toolkit_healthcare` module) — the addon boots nothing when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the
 * sub-init and data-class requires resolve from the addon's already-ported
 * `src/` copies; the OpenMed tool requires + the `wp_mcp_ai_register_tools`
 * registration are file-gated until the healthcare tool batches land; NEW
 * standalone-only wiring (deviation, same as the CRM init): a
	 * `wp_mcp_ai_pro_tools` filter plus
	 * `wp_mcp_ai_pro_register_healthcare_ecosystem_tools()` — both carry the
	 * seventy-five healthcare tools (the full wellness/vitals/imaging/interop/OpenMed map + blueprint) and fill further as the
	 * healthcare tool batches land; local vars
 * prefixed `$nvoos_content_graph_pro_*`.
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Full-body monolith guard (deviation): the base init boots via the base
// registry monolith; the wellness sub-init declares global helper functions,
// so the standalone copy must not compile alongside the base copy in the
// monorepo test matrix.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Always load shared infrastructure so other Pro code can rely on it.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-engine.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-codes.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-fhir.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-capabilities.php';

	// OpenMed clinical NLP client (v1.4.0). Always loaded for health checks.
	// Configuration-gated — tools only register when OpenMed service is configured.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-openmed-client.php';

	// PHI-acknowledged gate (multisite); single-site installs always pass.
	if ( ! WP_MCP_AI_Healthcare_Engine::phi_acknowledged() ) {
		return;
	}

	$nvoos_content_graph_pro_settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( ! is_array( $nvoos_content_graph_pro_settings ) ) {
		$nvoos_content_graph_pro_settings = array();
	}

	// Sub-toolkit B: Health & Wellness Management (members / records / etc.).
	// Loaded unconditionally to preserve pre-existing behaviour — the init file
	// itself gates on `enable_health_wellness_management` for admin UI bits and
	// always registers its CPTs and migration so existing data remains
	// accessible even when the toggle is off.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/init.php';

	// Sub-toolkit C: Healthcare Imaging.
	if ( ! empty( $nvoos_content_graph_pro_settings['enable_healthcare_imaging'] ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging-init.php';
	}

	// Sub-toolkit A: Medical Vitals (Phase B).
	// Defaults to the value of `enable_health_wellness_management` for BC.
	$nvoos_content_graph_pro_vitals_enabled = array_key_exists( 'enable_medical_vitals', $nvoos_content_graph_pro_settings )
		? ! empty( $nvoos_content_graph_pro_settings['enable_medical_vitals'] )
		: ! empty( $nvoos_content_graph_pro_settings['enable_health_wellness_management'] );
	if ( $nvoos_content_graph_pro_vitals_enabled ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vaccination-schedules.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vital-log-cpt.php';
		WP_MCP_AI_Healthcare_Vital_Log_CPT::init();
	}

	// --- Performance optimization (per-member autoload, reminder pruning, care-plan cap) ---
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-healthcare-optimization.php';
	WP_MCP_AI_Healthcare_Optimization::init();

	// --- OpenMed clinical NLP tools (v1.4.0) ---
	// Registered via wp_mcp_ai_register_tools action; tools gate themselves on
	// OpenMed client availability at execution time. File-gated until the
	// healthcare tool batches land (the base ships both files — this gate is a
	// documented standalone-only deviation).
	$nvoos_content_graph_pro_deidentify = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php';
	$nvoos_content_graph_pro_extract    = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php';
	if ( file_exists( $nvoos_content_graph_pro_deidentify ) && file_exists( $nvoos_content_graph_pro_extract ) ) {
		require_once $nvoos_content_graph_pro_deidentify;
		require_once $nvoos_content_graph_pro_extract;

		add_action(
			'wp_mcp_ai_register_tools',
			function ( $registry ) {
				$registry->register_tool( new WP_MCP_AI_Tool_Deidentify_Health_Record() );
				$registry->register_tool( new WP_MCP_AI_Tool_Extract_Clinical_Entities() );
			}
		);
	}

	unset(
		$nvoos_content_graph_pro_vitals_enabled,
		$nvoos_content_graph_pro_settings,
		$nvoos_content_graph_pro_deidentify,
		$nvoos_content_graph_pro_extract
	);

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_healthcare_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_healthcare_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline healthcare map
 * (the wellness/vitals/imaging/interop gates in `mcp-ai-wpoos-pro.php`). The
 * map fills as the healthcare tool batches land.
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_healthcare_tools( $tools ) {
	$nvoos_content_graph_pro_health_tools = array(
		'WP_MCP_AI_Tool_Create_Member'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-create-member.php',
		'WP_MCP_AI_Tool_List_Members'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-list-members.php',
		'WP_MCP_AI_Tool_Get_Member'                        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-get-member.php',
		'WP_MCP_AI_Tool_Update_Member'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-update-member.php',
		'WP_MCP_AI_Tool_Delete_Member'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-delete-member.php',
		'WP_MCP_AI_Tool_Create_Policy'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-create-policy.php',
		'WP_MCP_AI_Tool_List_Policies'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-list-policies.php',
		'WP_MCP_AI_Tool_Get_Policy'                        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-get-policy.php',
		'WP_MCP_AI_Tool_Update_Policy'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-update-policy.php',
		'WP_MCP_AI_Tool_Delete_Policy'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-delete-policy.php',
		'WP_MCP_AI_Tool_Search_Policies'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/policies/class-wp-mcp-ai-tool-search-policies.php',
		'WP_MCP_AI_Tool_Create_Prescription'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-create-prescription.php',
		'WP_MCP_AI_Tool_List_Prescriptions'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-list-prescriptions.php',
		'WP_MCP_AI_Tool_Get_Prescription'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-get-prescription.php',
		'WP_MCP_AI_Tool_Update_Prescription'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-update-prescription.php',
		'WP_MCP_AI_Tool_Delete_Prescription'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-delete-prescription.php',
		'WP_MCP_AI_Tool_Search_Prescriptions'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-search-prescriptions.php',
		'WP_MCP_AI_Tool_Create_Medical_Record'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php',
		'WP_MCP_AI_Tool_List_Medical_Records'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-list-medical-records.php',
		'WP_MCP_AI_Tool_Get_Medical_Record'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-get-medical-record.php',
		'WP_MCP_AI_Tool_Update_Medical_Record'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-update-medical-record.php',
		'WP_MCP_AI_Tool_Delete_Medical_Record'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-delete-medical-record.php',
		'WP_MCP_AI_Tool_Search_Medical_Records'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-search-medical-records.php',
		'WP_MCP_AI_Tool_Create_Checkup'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-create-checkup.php',
		'WP_MCP_AI_Tool_List_Checkups'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-list-checkups.php',
		'WP_MCP_AI_Tool_Get_Checkup'                       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-checkup.php',
		'WP_MCP_AI_Tool_Update_Checkup'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-update-checkup.php',
		'WP_MCP_AI_Tool_Delete_Checkup'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-delete-checkup.php',
		'WP_MCP_AI_Tool_Get_Upcoming_Checkups'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-get-upcoming-checkups.php',
		'WP_MCP_AI_Tool_Create_Allergy'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-create-allergy.php',
		'WP_MCP_AI_Tool_List_Allergies'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-list-allergies.php',
		'WP_MCP_AI_Tool_Get_Allergy'                       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-get-allergy.php',
		'WP_MCP_AI_Tool_Update_Allergy'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-update-allergy.php',
		'WP_MCP_AI_Tool_Delete_Allergy'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-delete-allergy.php',
		'WP_MCP_AI_Tool_Check_Member_Allergies'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-check-member-allergies.php',
		'WP_MCP_AI_Tool_Generate_Visit_Summary'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-generate-visit-summary.php',
		'WP_MCP_AI_Tool_Get_Health_Timeline'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-get-health-timeline.php',
		'WP_MCP_AI_Tool_Get_Recent_Health_Appointments'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-get-recent-health-appointments.php',
		'WP_MCP_AI_Tool_Link_Prescription_To_Record'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-link-prescription-to-record.php',
		'WP_MCP_AI_Tool_Manage_Care_Plan'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-manage-care-plan.php',
		'WP_MCP_AI_Tool_Merge_Duplicate_Members'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-merge-duplicate-members.php',
		'WP_MCP_AI_Tool_Send_Appointment_Followup'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-send-appointment-followup.php',
		'WP_MCP_AI_Tool_Verify_Prescription_Interactions'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/class-wp-mcp-ai-tool-verify-prescription-interactions.php',
		'WP_MCP_AI_Tool_Get_Member_Health_Summary'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-member-health-summary.php',
		'WP_MCP_AI_Tool_Get_Medication_Schedule'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-medication-schedule.php',
		'WP_MCP_AI_Tool_Generate_Health_Chart'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-generate-health-chart.php',
		'WP_MCP_AI_Tool_Create_Health_Reminder'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-create-health-reminder.php',
		'WP_MCP_AI_Tool_Compile_Health_Research_Data'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-compile-health-research-data.php',
		'WP_MCP_AI_Tool_Guide_Health_Record_Creation'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-guide-health-record-creation.php',
		'WP_MCP_AI_Tool_Parse_Health_Information'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-parse-health-information.php',
		'WP_MCP_AI_Tool_Health_Capture_Encounter'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-tool-health-capture-encounter.php',
		'WP_MCP_AI_Tool_Analyze_Vital_Trends'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-analyze-vital-trends.php',
		'WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-compute-bmi-and-growth-percentile.php',
		'WP_MCP_AI_Tool_Flag_Abnormal_Vitals'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-flag-abnormal-vitals.php',
		'WP_MCP_AI_Tool_Get_Vaccination_Schedule'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-get-vaccination-schedule.php',
		'WP_MCP_AI_Tool_Import_Vitals'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-import-vitals.php',
		'WP_MCP_AI_Tool_Log_Health_Metrics'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-log-health-metrics.php',
		'WP_MCP_AI_Tool_Log_Vital_Signs'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-log-vital-signs.php',
		'WP_MCP_AI_Tool_Track_Vaccinations'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/vitals/class-wp-mcp-ai-tool-track-vaccinations.php',
		'WP_MCP_AI_Tool_Manage_Imaging_Studies'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-manage-imaging-studies.php',
		'WP_MCP_AI_Tool_Interpret_Imaging_Study'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-interpret-imaging-study.php',
		'WP_MCP_AI_Tool_Connect_DICOMweb'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-connect-dicomweb.php',
		'WP_MCP_AI_Tool_Import_DICOM_Study'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-import-dicom-study.php',
		'WP_MCP_AI_Tool_Export_DICOM_Study'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-export-dicom-study.php',
		'WP_MCP_AI_Tool_Attach_Radiology_Report'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-attach-radiology-report.php',
		'WP_MCP_AI_Tool_Compare_Imaging_Studies'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-compare-imaging-studies.php',
		'WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging/class-wp-mcp-ai-tool-get-imaging-hanging-protocol.php',
		'WP_MCP_AI_Tool_Import_FHIR_Bundle'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/interop/class-wp-mcp-ai-tool-import-fhir-bundle.php',
		'WP_MCP_AI_Tool_Export_CCDA_Document'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/interop/class-wp-mcp-ai-tool-export-ccda-document.php',
		'WP_MCP_AI_Tool_Import_HL7v2_Message'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/interop/class-wp-mcp-ai-tool-import-hl7v2-message.php',
		'WP_MCP_AI_Tool_Connect_To_EHR'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/interop/class-wp-mcp-ai-tool-connect-to-ehr.php',
		'WP_MCP_AI_Tool_Export_FHIR_Data'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/interop/class-wp-mcp-ai-tool-export-fhir-data.php',
		'WP_MCP_AI_Tool_Import_Healthcare_Blueprint'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/examples/class-wp-mcp-ai-tool-import-healthcare-blueprint.php',
		'WP_MCP_AI_Tool_Deidentify_Health_Record'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php',
		'WP_MCP_AI_Tool_Extract_Clinical_Entities'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_health_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported healthcare
 * tools into the ecosystem graph ToolRegistry and the nvoos/core registry
 * via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the image-production
 * inits). The list fills as the healthcare tool batches land.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_healthcare_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_Create_Member',
			'WP_MCP_AI_Tool_List_Members',
			'WP_MCP_AI_Tool_Get_Member',
			'WP_MCP_AI_Tool_Update_Member',
			'WP_MCP_AI_Tool_Delete_Member',
			'WP_MCP_AI_Tool_Create_Policy',
			'WP_MCP_AI_Tool_List_Policies',
			'WP_MCP_AI_Tool_Get_Policy',
			'WP_MCP_AI_Tool_Update_Policy',
			'WP_MCP_AI_Tool_Delete_Policy',
			'WP_MCP_AI_Tool_Search_Policies',
			'WP_MCP_AI_Tool_Create_Prescription',
			'WP_MCP_AI_Tool_List_Prescriptions',
			'WP_MCP_AI_Tool_Get_Prescription',
			'WP_MCP_AI_Tool_Update_Prescription',
			'WP_MCP_AI_Tool_Delete_Prescription',
			'WP_MCP_AI_Tool_Search_Prescriptions',
			'WP_MCP_AI_Tool_Create_Medical_Record',
			'WP_MCP_AI_Tool_List_Medical_Records',
			'WP_MCP_AI_Tool_Get_Medical_Record',
			'WP_MCP_AI_Tool_Update_Medical_Record',
			'WP_MCP_AI_Tool_Delete_Medical_Record',
			'WP_MCP_AI_Tool_Search_Medical_Records',
			'WP_MCP_AI_Tool_Create_Checkup',
			'WP_MCP_AI_Tool_List_Checkups',
			'WP_MCP_AI_Tool_Get_Checkup',
			'WP_MCP_AI_Tool_Update_Checkup',
			'WP_MCP_AI_Tool_Delete_Checkup',
			'WP_MCP_AI_Tool_Get_Upcoming_Checkups',
			'WP_MCP_AI_Tool_Create_Allergy',
			'WP_MCP_AI_Tool_List_Allergies',
			'WP_MCP_AI_Tool_Get_Allergy',
			'WP_MCP_AI_Tool_Update_Allergy',
			'WP_MCP_AI_Tool_Delete_Allergy',
			'WP_MCP_AI_Tool_Check_Member_Allergies',
			'WP_MCP_AI_Tool_Generate_Visit_Summary',
			'WP_MCP_AI_Tool_Get_Health_Timeline',
			'WP_MCP_AI_Tool_Get_Recent_Health_Appointments',
			'WP_MCP_AI_Tool_Link_Prescription_To_Record',
			'WP_MCP_AI_Tool_Manage_Care_Plan',
			'WP_MCP_AI_Tool_Merge_Duplicate_Members',
			'WP_MCP_AI_Tool_Send_Appointment_Followup',
			'WP_MCP_AI_Tool_Verify_Prescription_Interactions',
			'WP_MCP_AI_Tool_Get_Member_Health_Summary',
			'WP_MCP_AI_Tool_Get_Medication_Schedule',
			'WP_MCP_AI_Tool_Generate_Health_Chart',
			'WP_MCP_AI_Tool_Create_Health_Reminder',
			'WP_MCP_AI_Tool_Compile_Health_Research_Data',
			'WP_MCP_AI_Tool_Guide_Health_Record_Creation',
			'WP_MCP_AI_Tool_Parse_Health_Information',
			'WP_MCP_AI_Tool_Health_Capture_Encounter',
			'WP_MCP_AI_Tool_Analyze_Vital_Trends',
			'WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile',
			'WP_MCP_AI_Tool_Flag_Abnormal_Vitals',
			'WP_MCP_AI_Tool_Get_Vaccination_Schedule',
			'WP_MCP_AI_Tool_Import_Vitals',
			'WP_MCP_AI_Tool_Log_Health_Metrics',
			'WP_MCP_AI_Tool_Log_Vital_Signs',
			'WP_MCP_AI_Tool_Track_Vaccinations',
			'WP_MCP_AI_Tool_Manage_Imaging_Studies',
			'WP_MCP_AI_Tool_Interpret_Imaging_Study',
			'WP_MCP_AI_Tool_Connect_DICOMweb',
			'WP_MCP_AI_Tool_Import_DICOM_Study',
			'WP_MCP_AI_Tool_Export_DICOM_Study',
			'WP_MCP_AI_Tool_Attach_Radiology_Report',
			'WP_MCP_AI_Tool_Compare_Imaging_Studies',
			'WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol',
			'WP_MCP_AI_Tool_Import_FHIR_Bundle',
			'WP_MCP_AI_Tool_Export_CCDA_Document',
			'WP_MCP_AI_Tool_Import_HL7v2_Message',
			'WP_MCP_AI_Tool_Connect_To_EHR',
			'WP_MCP_AI_Tool_Export_FHIR_Data',
			'WP_MCP_AI_Tool_Import_Healthcare_Blueprint',
			'WP_MCP_AI_Tool_Deidentify_Health_Record',
			'WP_MCP_AI_Tool_Extract_Clinical_Entities',
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
