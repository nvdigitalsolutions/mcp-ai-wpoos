<?php
/**
 * Project Settings Page (ecosystem port — Wave F2, PM admin slice B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-project-settings-page.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs — the addon's autoloader skips its copy when
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the cpt-settings-page-base require resolves from the addon's already-ported `src/admin/class-wp-mcp-ai-cpt-settings-page-base.php`.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Project Settings Page
 */
class WP_MCP_AI_Project_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_project_settings';
		$this->post_type   = 'mcp_ai_project';
		$this->page_title  = __( 'Project Management Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'project-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add project-specific settings.
		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'nvoos-content-graph-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable research field.
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
			<?php esc_html_e( 'Enable the Research & Add page for project research', 'nvoos-content-graph-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create projects using AI assistance.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Project Management AI features.', 'nvoos-content-graph-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Project Management Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'Comprehensive project management system with AI-powered tools for managing projects, tasks, events, timelines, and team collaboration.', 'nvoos-content-graph-pro' ); ?></p>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Project Management:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Create, track, and manage projects with AI assistance', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Task Management:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Break down projects into manageable tasks with dependencies and assignments', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Event Scheduling:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Schedule meetings, milestones, and deadlines with calendar integration', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Ralph Loop Orchestration:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Autonomous task execution with continuous improvement cycles', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Task Plans:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Markdown-based execution plans with checkbox progress tracking', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Task Templates:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Reusable templates for common workflows and project types', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'High-Performance Storage:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Automatic CCT (JetEngine) support for enterprise scalability', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Project Tools.
			'research_project'                 => __( 'Research Project', 'nvoos-content-graph-pro' ),
			'create_project'                   => __( 'Create Project', 'nvoos-content-graph-pro' ),
			'update_project'                   => __( 'Update Project', 'nvoos-content-graph-pro' ),
			'list_projects'                    => __( 'List Projects', 'nvoos-content-graph-pro' ),
			'get_project'                      => __( 'Get Project Details', 'nvoos-content-graph-pro' ),
			'delete_project'                   => __( 'Delete Project', 'nvoos-content-graph-pro' ),

			// Task Tools.
			'create_task'                      => __( 'Create Task', 'nvoos-content-graph-pro' ),
			'update_task'                      => __( 'Update Task', 'nvoos-content-graph-pro' ),
			'list_tasks'                       => __( 'List Tasks', 'nvoos-content-graph-pro' ),
			'get_task'                         => __( 'Get Task Details', 'nvoos-content-graph-pro' ),
			'delete_task'                      => __( 'Delete Task', 'nvoos-content-graph-pro' ),

			// Event Tools.
			'create_event'                     => __( 'Create Event', 'nvoos-content-graph-pro' ),
			'update_event'                     => __( 'Update Event', 'nvoos-content-graph-pro' ),
			'list_events'                      => __( 'List Events', 'nvoos-content-graph-pro' ),
			'get_event'                        => __( 'Get Event Details', 'nvoos-content-graph-pro' ),
			'delete_event'                     => __( 'Delete Event', 'nvoos-content-graph-pro' ),

			// Ralph Loop Orchestration Tools.
			'create_task_plan'                 => __( 'Create Task Plan', 'nvoos-content-graph-pro' ),
			'update_task_plan'                 => __( 'Update Task Plan', 'nvoos-content-graph-pro' ),
			'get_task_plan'                    => __( 'Get Task Plan', 'nvoos-content-graph-pro' ),
			'list_task_plans'                  => __( 'List Task Plans', 'nvoos-content-graph-pro' ),
			'manage_autonomous_session'        => __( 'Manage Autonomous Session', 'nvoos-content-graph-pro' ),
			'detect_completion_indicators'     => __( 'Detect Completion Indicators', 'nvoos-content-graph-pro' ),
			'check_exit_conditions'            => __( 'Check Exit Conditions', 'nvoos-content-graph-pro' ),
			'analyze_loop_health'              => __( 'Analyze Loop Health', 'nvoos-content-graph-pro' ),
			'get_session_status'               => __( 'Get Session Status', 'nvoos-content-graph-pro' ),
			'calculate_orchestration_capacity' => __( 'Calculate Orchestration Capacity', 'nvoos-content-graph-pro' ),

			// Task Template Tools.
			'create_template'                  => __( 'Create Task Template', 'nvoos-content-graph-pro' ),
			'list_templates'                   => __( 'List Task Templates', 'nvoos-content-graph-pro' ),
			'instantiate_template'             => __( 'Instantiate Template', 'nvoos-content-graph-pro' ),
			'seed_template_library'            => __( 'Seed Template Library', 'nvoos-content-graph-pro' ),

			// Calendar & View Tools.
			'get_calendar_view'                => __( 'Get Calendar View', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add project-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Project_Settings_Page();
