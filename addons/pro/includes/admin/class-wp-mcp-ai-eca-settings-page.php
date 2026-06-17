<?php
/**
 * ECA Settings Page
 *
 * Provides settings page for configuring AI assistant and Research & Add
 * functionality for ECA (Extra-Curricular Activities) management.
 *
 * Extends WP_MCP_AI_CPT_Settings_Page_Base so the settings page appears
 * under the ECAs CPT menu (edit.php?post_type=mcp_ai_eca) instead of
 * under the NV oOS Pro dashboard — consistent with Quiz, Project, and
 * other CPT-based toolkits.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * ECA Settings Page
 *
 * @since 1.2.0
 */
class WP_MCP_AI_ECA_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_eca_settings';
		$this->post_type   = 'mcp_ai_eca';
		$this->page_title  = __( 'ECA Management Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'eca-settings';

		// Call parent constructor to register hooks (admin_menu at priority 25,
		// admin_init for settings registration).
		parent::__construct();
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.2.0
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'Extra-Curricular Activities (ECA) Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

		<p><?php esc_html_e( 'Comprehensive toolkit for managing school extra-curricular activities with 35 AI-powered tools covering activity management, student enrollment, attendance tracking, waitlist automation, scheduling, notifications, analytics, iSAMS/SOCS integration, term management, workflow rules, and CSV import/export.', 'mcp-ai-wpoos-pro' ); ?></p>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Activity Management: Create, update, list, and track extra-curricular activities', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Student Management: Full CRUD for student records with enrollment tracking', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Enrollment & Waitlists: Enroll, withdraw, bulk enroll students with automated waitlist management', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Attendance Tracking: Mark attendance per session, generate attendance reports, and track participation summaries', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Scheduling & Conflict Detection: Set activity schedules, view timetables, and detect scheduling conflicts', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Notifications & Communication: Send notifications, configure notification rules, and generate parent reports', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Analytics & Reporting: Generate participation analytics, attendance reports, and export data', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'AI Research: Discover new activity ideas and program structures using AI', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'iSAMS & SOCS Integration: Bi-directional sync with iSAMS and SOCS school management systems', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Term & Workflow Management: Manage academic terms and create automation workflow rules', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Data Import/Export: Import ECAs from CSV files and export data for external analysis', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Schools managing clubs, sports teams, societies, and after-school programs', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'ECA coordinators tracking attendance and participation across all activities', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Enrichment program coordinators planning and researching new activities', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Administrators monitoring capacity, waitlists, and enrollment equity', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Schools needing bi-directional sync with iSAMS or SOCS platforms', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Generating parent reports and automated notifications for ECA events', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Dashboard', 'mcp-ai-wpoos-pro' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: Dashboard link */
				esc_html__( 'Visit the %s to view real-time KPIs, enrollment analytics, attendance trends, and capacity utilization across all activities.', 'mcp-ai-wpoos-pro' ),
				'<a href="' . esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca&page=eca-dashboard' ) ) . '">' . esc_html__( 'ECA Dashboard', 'mcp-ai-wpoos-pro' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Register settings.
	 *
	 * Adds the enable_research toggle on top of the base assistant_id field.
	 *
	 * @since 1.2.0
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant_id).
		parent::register_settings();

		// Add ECA-specific enable_research field.
		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render the enable_research checkbox field.
	 *
	 * @since 1.2.0
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for ECA research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create extra-curricular activities using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * Returns all 35 ECA management tools organized by category.
	 *
	 * @since 1.2.0
	 * @return array Tools list with slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			// ECA CRUD (5 tools).
			'create_eca'                        => __( 'Create ECA', 'mcp-ai-wpoos-pro' ),
			'list_ecas'                         => __( 'List ECAs', 'mcp-ai-wpoos-pro' ),
			'get_eca'                           => __( 'Get ECA', 'mcp-ai-wpoos-pro' ),
			'update_eca'                        => __( 'Update ECA', 'mcp-ai-wpoos-pro' ),
			'delete_eca'                        => __( 'Delete ECA', 'mcp-ai-wpoos-pro' ),
			// Student Management (5 tools).
			'create_student'                    => __( 'Create Student', 'mcp-ai-wpoos-pro' ),
			'list_students'                     => __( 'List Students', 'mcp-ai-wpoos-pro' ),
			'get_student'                       => __( 'Get Student', 'mcp-ai-wpoos-pro' ),
			'update_student'                    => __( 'Update Student', 'mcp-ai-wpoos-pro' ),
			'delete_student'                    => __( 'Delete Student', 'mcp-ai-wpoos-pro' ),
			// Enrollment & Waitlist (4 tools).
			'enroll_student_eca'                => __( 'Enroll Student in ECA', 'mcp-ai-wpoos-pro' ),
			'withdraw_student_eca'              => __( 'Withdraw Student from ECA', 'mcp-ai-wpoos-pro' ),
			'bulk_enroll_students'              => __( 'Bulk Enroll Students', 'mcp-ai-wpoos-pro' ),
			'manage_eca_waitlist'               => __( 'Manage ECA Waitlist', 'mcp-ai-wpoos-pro' ),
			// Attendance & Participation (3 tools).
			'mark_eca_attendance'               => __( 'Mark ECA Attendance', 'mcp-ai-wpoos-pro' ),
			'get_eca_attendance_report'         => __( 'Get ECA Attendance Report', 'mcp-ai-wpoos-pro' ),
			'get_student_participation_summary' => __( 'Get Student Participation Summary', 'mcp-ai-wpoos-pro' ),
			// Scheduling (3 tools).
			'set_eca_schedule'                  => __( 'Set ECA Schedule', 'mcp-ai-wpoos-pro' ),
			'get_eca_timetable'                 => __( 'Get ECA Timetable', 'mcp-ai-wpoos-pro' ),
			'check_eca_conflicts'               => __( 'Check ECA Conflicts', 'mcp-ai-wpoos-pro' ),
			// Notifications & Communication (3 tools).
			'send_eca_notification'             => __( 'Send ECA Notification', 'mcp-ai-wpoos-pro' ),
			'configure_eca_notifications'       => __( 'Configure ECA Notifications', 'mcp-ai-wpoos-pro' ),
			'send_eca_parent_report'            => __( 'Send ECA Parent Report', 'mcp-ai-wpoos-pro' ),
			// Analytics & Reporting (3 tools).
			'generate_eca_analytics'            => __( 'Generate ECA Analytics', 'mcp-ai-wpoos-pro' ),
			'generate_eca_participation_report' => __( 'Generate ECA Participation Report', 'mcp-ai-wpoos-pro' ),
			'export_eca_data'                   => __( 'Export ECA Data', 'mcp-ai-wpoos-pro' ),
			// AI Research (1 tool).
			'research_eca'                      => __( 'Research ECA', 'mcp-ai-wpoos-pro' ),
			// Integration & Sync (5 tools).
			'sync_ecas_from_isams'              => __( 'Sync ECAs from iSAMS', 'mcp-ai-wpoos-pro' ),
			'sync_eca_enrollments_from_isams'   => __( 'Sync ECA Enrollments from iSAMS', 'mcp-ai-wpoos-pro' ),
			'sync_ecas_to_isams'                => __( 'Sync ECAs to iSAMS', 'mcp-ai-wpoos-pro' ),
			'sync_ecas_from_socs'               => __( 'Sync ECAs from SOCS', 'mcp-ai-wpoos-pro' ),
			'sync_students_from_isams'          => __( 'Sync Students from iSAMS', 'mcp-ai-wpoos-pro' ),
			// Term & Workflow (3 tools).
			'manage_eca_term'                   => __( 'Manage ECA Term', 'mcp-ai-wpoos-pro' ),
			'create_eca_workflow_rule'          => __( 'Create ECA Workflow Rule', 'mcp-ai-wpoos-pro' ),
			'import_ecas_csv'                   => __( 'Import ECAs from CSV', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 1.2.0
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent to sanitize base fields (assistant_id).
		$sanitized = parent::sanitize_settings( $input );

		// Sanitize enable_research.
		$sanitized['enable_research'] = ! empty( $input['enable_research'] );

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_ECA_Settings_Page();
