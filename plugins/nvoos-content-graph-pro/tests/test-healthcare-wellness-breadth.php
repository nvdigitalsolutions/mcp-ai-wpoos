<?php
/**
 * Characterization tests for the Wave F4 healthcare wellness breadth batch —
 * the seventeen ported root wellness / reminders-research / capture tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/healthcare/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare wellness breadth tests.
 */
class Test_Healthcare_Wellness_Breadth extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the seventeen gated base files
	 * deterministically. Standalone: the entry autoloader serves them on
	 * demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-check-member-allergies.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-generate-visit-summary.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-get-health-timeline.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-get-recent-health-appointments.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-link-prescription-to-record.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-manage-care-plan.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-merge-duplicate-members.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-send-appointment-followup.php',
				'tools/healthcare/wellness/class-wp-mcp-ai-tool-verify-prescription-interactions.php',
				'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-member-health-summary.php',
				'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-medication-schedule.php',
				'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-generate-health-chart.php',
				'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-create-health-reminder.php',
				'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-compile-health-research-data.php',
				'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-guide-health-record-creation.php',
				'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-parse-health-information.php',
				'tools/healthcare/class-wp-mcp-ai-tool-health-capture-encounter.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The seventeen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Check_Member_Allergies'       => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-check-member-allergies.php',
			'WP_MCP_AI_Tool_Generate_Visit_Summary'       => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-generate-visit-summary.php',
			'WP_MCP_AI_Tool_Get_Health_Timeline'          => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-get-health-timeline.php',
			'WP_MCP_AI_Tool_Get_Recent_Health_Appointments' => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-get-recent-health-appointments.php',
			'WP_MCP_AI_Tool_Link_Prescription_To_Record'  => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-link-prescription-to-record.php',
			'WP_MCP_AI_Tool_Manage_Care_Plan'             => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-manage-care-plan.php',
			'WP_MCP_AI_Tool_Merge_Duplicate_Members'      => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-merge-duplicate-members.php',
			'WP_MCP_AI_Tool_Send_Appointment_Followup'    => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-send-appointment-followup.php',
			'WP_MCP_AI_Tool_Verify_Prescription_Interactions' => 'tools/healthcare/wellness/class-wp-mcp-ai-tool-verify-prescription-interactions.php',
			'WP_MCP_AI_Tool_Get_Member_Health_Summary'    => 'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-member-health-summary.php',
			'WP_MCP_AI_Tool_Get_Medication_Schedule'      => 'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-medication-schedule.php',
			'WP_MCP_AI_Tool_Generate_Health_Chart'        => 'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-generate-health-chart.php',
			'WP_MCP_AI_Tool_Create_Health_Reminder'       => 'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-create-health-reminder.php',
			'WP_MCP_AI_Tool_Compile_Health_Research_Data' => 'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-compile-health-research-data.php',
			'WP_MCP_AI_Tool_Guide_Health_Record_Creation' => 'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-guide-health-record-creation.php',
			'WP_MCP_AI_Tool_Parse_Health_Information'     => 'tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-parse-health-information.php',
			'WP_MCP_AI_Tool_Health_Capture_Encounter'     => 'tools/healthcare/class-wp-mcp-ai-tool-health-capture-encounter.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The tool surfaces must be byte-identical (incl. the non-edit_posts
	 * capabilities and the capture-base inheritance).
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Check_Member_Allergies'       => array( 'check_member_allergies', 'edit_posts' ),
			'WP_MCP_AI_Tool_Generate_Visit_Summary'       => array( 'generate_visit_summary', 'edit_posts' ),
			'WP_MCP_AI_Tool_Get_Health_Timeline'          => array( 'get_health_timeline', 'edit_posts' ),
			'WP_MCP_AI_Tool_Get_Recent_Health_Appointments' => array( 'get_recent_health_appointments', 'read' ),
			'WP_MCP_AI_Tool_Link_Prescription_To_Record'  => array( 'link_prescription_to_record', 'edit_posts' ),
			'WP_MCP_AI_Tool_Manage_Care_Plan'             => array( 'manage_care_plan', 'edit_posts' ),
			'WP_MCP_AI_Tool_Merge_Duplicate_Members'      => array( 'merge_duplicate_members', 'edit_posts' ),
			'WP_MCP_AI_Tool_Send_Appointment_Followup'    => array( 'send_appointment_followup', 'edit_posts' ),
			'WP_MCP_AI_Tool_Verify_Prescription_Interactions' => array( 'verify_prescription_interactions', 'edit_posts' ),
			'WP_MCP_AI_Tool_Get_Member_Health_Summary'    => array( 'get_member_health_summary', 'edit_posts' ),
			'WP_MCP_AI_Tool_Get_Medication_Schedule'      => array( 'get_medication_schedule', 'edit_posts' ),
			'WP_MCP_AI_Tool_Generate_Health_Chart'        => array( 'generate_health_chart', 'read_private_posts' ),
			'WP_MCP_AI_Tool_Create_Health_Reminder'       => array( 'create_health_reminder', 'edit_posts' ),
			'WP_MCP_AI_Tool_Compile_Health_Research_Data' => array( 'compile_health_research_data', 'edit_posts' ),
			'WP_MCP_AI_Tool_Guide_Health_Record_Creation' => array( 'guide_health_record_creation', 'edit_posts' ),
			'WP_MCP_AI_Tool_Parse_Health_Information'     => array( 'parse_health_information', 'edit_posts' ),
			'WP_MCP_AI_Tool_Health_Capture_Encounter'     => array( 'health_capture_encounter', 'edit_posts' ),
		);

		foreach ( $slugs as $class => $contract ) {
			$tool = new $class();
			$this->assertSame( $contract[0], $tool->get_slug(), $class );
			$this->assertSame( $contract[1], $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The capture-encounter tool must extend the capture-tool base
	 * (inheriting the byte-identical capability + envelope contract).
	 */
	public function test_capture_encounter_inheritance(): void {
		$tool = new WP_MCP_AI_Tool_Health_Capture_Encounter();
		$this->assertInstanceOf( 'WP_MCP_AI_Pro_Capture_Tool_Base', $tool );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
	}
}
