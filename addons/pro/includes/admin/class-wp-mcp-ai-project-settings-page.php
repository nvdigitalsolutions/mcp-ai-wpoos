<?php
/**
 * Project Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Project Management functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

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
		$this->page_title  = __( 'Project Management Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
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
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
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
			<?php esc_html_e( 'Enable the Research & Add page for project research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create projects using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Project Management AI features.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Project Management Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'Comprehensive project management system with AI-powered tools for managing projects, tasks, events, timelines, and team collaboration.', 'mcp-ai-wpoos-pro' ); ?></p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Project Management:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create, track, and manage projects with AI assistance', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Task Management:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Break down projects into manageable tasks with dependencies and assignments', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Event Scheduling:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Schedule meetings, milestones, and deadlines with calendar integration', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Ralph Loop Orchestration:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Autonomous task execution with continuous improvement cycles', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Task Plans:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Markdown-based execution plans with checkbox progress tracking', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Task Templates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Reusable templates for common workflows and project types', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'High-Performance Storage:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Automatic CCT (JetEngine) support for enterprise scalability', 'mcp-ai-wpoos-pro' ); ?></li>
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
			'research_project'                 => __( 'Research Project', 'mcp-ai-wpoos-pro' ),
			'create_project'                   => __( 'Create Project', 'mcp-ai-wpoos-pro' ),
			'update_project'                   => __( 'Update Project', 'mcp-ai-wpoos-pro' ),
			'list_projects'                    => __( 'List Projects', 'mcp-ai-wpoos-pro' ),
			'get_project'                      => __( 'Get Project Details', 'mcp-ai-wpoos-pro' ),
			'delete_project'                   => __( 'Delete Project', 'mcp-ai-wpoos-pro' ),

			// Task Tools.
			'create_task'                      => __( 'Create Task', 'mcp-ai-wpoos-pro' ),
			'update_task'                      => __( 'Update Task', 'mcp-ai-wpoos-pro' ),
			'list_tasks'                       => __( 'List Tasks', 'mcp-ai-wpoos-pro' ),
			'get_task'                         => __( 'Get Task Details', 'mcp-ai-wpoos-pro' ),
			'delete_task'                      => __( 'Delete Task', 'mcp-ai-wpoos-pro' ),

			// Event Tools.
			'create_event'                     => __( 'Create Event', 'mcp-ai-wpoos-pro' ),
			'update_event'                     => __( 'Update Event', 'mcp-ai-wpoos-pro' ),
			'list_events'                      => __( 'List Events', 'mcp-ai-wpoos-pro' ),
			'get_event'                        => __( 'Get Event Details', 'mcp-ai-wpoos-pro' ),
			'delete_event'                     => __( 'Delete Event', 'mcp-ai-wpoos-pro' ),

			// Ralph Loop Orchestration Tools.
			'create_task_plan'                 => __( 'Create Task Plan', 'mcp-ai-wpoos-pro' ),
			'update_task_plan'                 => __( 'Update Task Plan', 'mcp-ai-wpoos-pro' ),
			'get_task_plan'                    => __( 'Get Task Plan', 'mcp-ai-wpoos-pro' ),
			'list_task_plans'                  => __( 'List Task Plans', 'mcp-ai-wpoos-pro' ),
			'manage_autonomous_session'        => __( 'Manage Autonomous Session', 'mcp-ai-wpoos-pro' ),
			'detect_completion_indicators'     => __( 'Detect Completion Indicators', 'mcp-ai-wpoos-pro' ),
			'check_exit_conditions'            => __( 'Check Exit Conditions', 'mcp-ai-wpoos-pro' ),
			'analyze_loop_health'              => __( 'Analyze Loop Health', 'mcp-ai-wpoos-pro' ),
			'get_session_status'               => __( 'Get Session Status', 'mcp-ai-wpoos-pro' ),
			'calculate_orchestration_capacity' => __( 'Calculate Orchestration Capacity', 'mcp-ai-wpoos-pro' ),

			// Task Template Tools.
			'create_template'                  => __( 'Create Task Template', 'mcp-ai-wpoos-pro' ),
			'list_templates'                   => __( 'List Task Templates', 'mcp-ai-wpoos-pro' ),
			'instantiate_template'             => __( 'Instantiate Template', 'mcp-ai-wpoos-pro' ),
			'seed_template_library'            => __( 'Seed Template Library', 'mcp-ai-wpoos-pro' ),

			// Calendar & View Tools.
			'get_calendar_view'                => __( 'Get Calendar View', 'mcp-ai-wpoos-pro' ),
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
