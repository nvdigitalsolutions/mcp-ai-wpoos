<?php
/**
 * Project Management Toolkit Settings Page
 *
 * Comprehensive settings page for Project Management toolkit with tabs for
 * overview, configuration, tools management, research, and help.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Project Management Toolkit Settings Page Class
 */
class WP_MCP_AI_Project_Management_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'project_management';
		$this->toolkit_name     = __( 'Project Management Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_project_management_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-project-management-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = false;
		$this->icon             = 'dashicons-portfolio';
		$this->parent_slug      = 'edit.php?post_type=mcp_ai_project';

		parent::__construct();
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<div class="toolkit-card">
				<h2><?php esc_html_e( 'Project Management Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
				
				<div class="toolkit-description">
					<p><?php esc_html_e( 'Comprehensive project management system with AI-powered tools for managing projects, tasks, events, timelines, and team collaboration.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>

				<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Project Management:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create, track, and manage projects with AI assistance', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Task Management:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Break down projects into manageable tasks with dependencies and assignments', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Event Scheduling:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Schedule meetings, milestones, and deadlines with calendar integration', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Ralph Loop Orchestration:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Autonomous task execution with continuous improvement cycles', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Task Plans:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Markdown-based execution plans with checkbox progress tracking', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Task Templates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Reusable templates for common workflows and project types', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'High-Performance Storage:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Automatic CCT (JetEngine) support for enterprise scalability', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Timeline Views:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Gantt charts and calendar views for project visualization', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'AI Research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research project ideas and best practices before creating', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Resource Management:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Track team assignments, budgets, and resource allocation', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Ralph Loop Integration (Task-Level Orchestration)', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p><?php esc_html_e( 'Task Plans utilize the Ralph Wiggum autonomous orchestration pattern for long-running, iterative task execution:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Autonomous Task Execution:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'AI-driven continuous task execution with intelligent exit detection', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Markdown-Based Plans:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Checkbox-driven progress tracking for autonomous agents', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Loop Health Monitoring:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Real-time analysis of iteration efficiency and progress', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Circuit Breakers:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Automatic error detection and recovery mechanisms', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Session Continuity:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Context preservation across multiple loop iterations', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Budget Enforcement:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Token budget tracking and rate limiting for cost control', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
				<p class="description">
					<em><?php esc_html_e( 'Note: Ralph loop orchestration applies to Task Plans for autonomous execution. Projects, Tasks, and Events use standard AI-assisted management.', 'mcp-ai-wpoos-pro' ); ?></em>
				</p>

				<h3><?php esc_html_e( 'Storage Backend', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="storage-info">
					<?php
					$jetengine_active = class_exists( 'Jet_Engine' );
					$cct_module       = null;
					$cct_enabled      = false;
					
					if ( $jetengine_active && method_exists( jet_engine(), 'module_loader' ) ) {
						$cct_module = jet_engine()->module_loader->get_module( 'custom-content-types' );
					}
					
					if ( $cct_module && method_exists( $cct_module, 'is_active' ) ) {
						$cct_enabled = $cct_module->is_active();
					}
					?>
					<p>
						<strong><?php esc_html_e( 'JetEngine Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php if ( $jetengine_active ) : ?>
							<span style="color: green;">✓ <?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></span>
						<?php else : ?>
							<span style="color: orange;">○ <?php esc_html_e( 'Not Installed', 'mcp-ai-wpoos-pro' ); ?></span>
						<?php endif; ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'CCT Module:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php if ( $cct_enabled ) : ?>
							<span style="color: green;">✓ <?php esc_html_e( 'Enabled - Using high-performance CCT storage', 'mcp-ai-wpoos-pro' ); ?></span>
						<?php elseif ( $jetengine_active ) : ?>
							<span style="color: orange;">○ <?php esc_html_e( 'Available - Enable in JetEngine settings for better performance', 'mcp-ai-wpoos-pro' ); ?></span>
						<?php else : ?>
							<span style="color: gray;">○ <?php esc_html_e( 'Using standard WordPress CPT storage', 'mcp-ai-wpoos-pro' ); ?></span>
						<?php endif; ?>
					</p>
					<p class="description">
						<?php
						if ( ! $jetengine_active ) {
							echo wp_kses_post(
								sprintf(
									/* translators: %s: JetEngine URL */
									__( 'For enterprise-scale projects, consider <a href="%s" target="_blank">JetEngine</a> for CCT-based storage with 10-100x performance improvements.', 'mcp-ai-wpoos-pro' ),
									'https://crocoblock.com/plugins/jetengine/'
								)
							);
						} elseif ( ! $cct_enabled ) {
							esc_html_e( 'Enable Custom Content Types in JetEngine → Settings → Modules for better performance with large datasets.', 'mcp-ai-wpoos-pro' );
						} else {
							esc_html_e( 'Using JetEngine Custom Content Types for optimal performance with enterprise-scale project management.', 'mcp-ai-wpoos-pro' );
						}
						?>
					</p>
				</div>

				<h3><?php esc_html_e( 'Custom Post Types', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="cpt-list">
					<div class="cpt-item">
						<span class="dashicons dashicons-portfolio"></span>
						<strong><?php esc_html_e( 'Projects', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'High-level project containers with metadata, status tracking, and team assignments', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-list-view"></span>
						<strong><?php esc_html_e( 'Tasks', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Individual work items linked to projects with priority, status, and assignees', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-calendar-alt"></span>
						<strong><?php esc_html_e( 'Events', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Scheduled meetings, milestones, and deadlines with date/time tracking', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-list-view"></span>
						<strong><?php esc_html_e( 'Task Plans', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Detailed execution plans generated by AI or created manually', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-clipboard"></span>
						<strong><?php esc_html_e( 'Task Templates', 'mcp-ai-wpoos-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Reusable project and workflow templates for common use cases', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				</div>

				<h3><?php esc_html_e( 'Quick Links', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="quick-links">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_project' ) ); ?>" class="button">
						<span class="dashicons dashicons-portfolio"></span>
						<?php esc_html_e( 'View Projects', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_task' ) ); ?>" class="button">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'View Tasks', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_event' ) ); ?>" class="button">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'View Events', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_project&page=research-project' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Research & Add Project', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>

		<style>
			.cpt-list {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
				gap: 15px;
				margin: 20px 0;
			}
			.cpt-item {
				background: #f9f9f9;
				border: 1px solid #ddd;
				border-radius: 4px;
				padding: 15px;
			}
			.cpt-item .dashicons {
				font-size: 24px;
				width: 24px;
				height: 24px;
				vertical-align: middle;
				margin-right: 8px;
				color: #2271b1;
			}
			.cpt-item strong {
				font-size: 16px;
				display: inline-block;
				vertical-align: middle;
			}
			.cpt-item .description {
				margin: 8px 0 0 32px;
				color: #666;
			}
			.quick-links .button {
				margin-right: 8px;
				margin-bottom: 8px;
			}
			.quick-links .button .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
				vertical-align: middle;
				margin-right: 4px;
			}
		</style>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		$options = get_option( $this->option_name, array() );
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Project Management Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Project Status', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[default_project_status]">
							<option value="planning" <?php selected( $options['default_project_status'] ?? 'planning', 'planning' ); ?>><?php esc_html_e( 'Planning', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="active" <?php selected( $options['default_project_status'] ?? '', 'active' ); ?>><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="on_hold" <?php selected( $options['default_project_status'] ?? '', 'on_hold' ); ?>><?php esc_html_e( 'On Hold', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="completed" <?php selected( $options['default_project_status'] ?? '', 'completed' ); ?>><?php esc_html_e( 'Completed', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default status when creating new projects', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Task Priority', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[default_task_priority]">
							<option value="low" <?php selected( $options['default_task_priority'] ?? 'medium', 'low' ); ?>><?php esc_html_e( 'Low', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="medium" <?php selected( $options['default_task_priority'] ?? 'medium', 'medium' ); ?>><?php esc_html_e( 'Medium', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="high" <?php selected( $options['default_task_priority'] ?? 'medium', 'high' ); ?>><?php esc_html_e( 'High', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="urgent" <?php selected( $options['default_task_priority'] ?? 'medium', 'urgent' ); ?>><?php esc_html_e( 'Urgent', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default priority level for new tasks', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Budget Tracking', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_budget_tracking]" value="1" <?php checked( ! empty( $options['enable_budget_tracking'] ) ); ?> />
							<?php esc_html_e( 'Track budgets and expenses for projects', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Adds budget fields to project management interface', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Time Tracking', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_time_tracking]" value="1" <?php checked( ! empty( $options['enable_time_tracking'] ) ); ?> />
							<?php esc_html_e( 'Track time spent on tasks and projects', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Adds time logging capabilities to tasks', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Gantt Charts', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_gantt_charts]" value="1" <?php checked( ! empty( $options['enable_gantt_charts'] ) ); ?> />
							<?php esc_html_e( 'Show Gantt chart visualizations for project timelines', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Enables timeline visualization features', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-generate Task Plans', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[auto_generate_task_plans]" value="1" <?php checked( ! empty( $options['auto_generate_task_plans'] ) ); ?> />
							<?php esc_html_e( 'Automatically generate task plans when creating projects', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Uses AI to create detailed execution plans for new projects', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Calendar Start Day', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[calendar_start_day]">
							<option value="0" <?php selected( $options['calendar_start_day'] ?? '1', '0' ); ?>><?php esc_html_e( 'Sunday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="1" <?php selected( $options['calendar_start_day'] ?? '1', '1' ); ?>><?php esc_html_e( 'Monday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="6" <?php selected( $options['calendar_start_day'] ?? '1', '6' ); ?>><?php esc_html_e( 'Saturday', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'First day of the week in calendar views', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get tools list
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
	 * Sanitize settings
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		// Sanitize dropdown/select values.
		if ( isset( $input['default_project_status'] ) ) {
			$sanitized['default_project_status'] = sanitize_text_field( $input['default_project_status'] );
		}

		if ( isset( $input['default_task_priority'] ) ) {
			$sanitized['default_task_priority'] = sanitize_text_field( $input['default_task_priority'] );
		}

		if ( isset( $input['calendar_start_day'] ) ) {
			$sanitized['calendar_start_day'] = absint( $input['calendar_start_day'] );
		}

		// Sanitize checkboxes.
		$sanitized['enable_budget_tracking']     = ! empty( $input['enable_budget_tracking'] );
		$sanitized['enable_time_tracking']       = ! empty( $input['enable_time_tracking'] );
		$sanitized['enable_gantt_charts']        = ! empty( $input['enable_gantt_charts'] );
		$sanitized['auto_generate_task_plans']   = ! empty( $input['auto_generate_task_plans'] );
		$sanitized['enable_research']            = ! empty( $input['enable_research'] );
		
		if ( isset( $input['research_assistant_id'] ) ) {
			$sanitized['research_assistant_id'] = absint( $input['research_assistant_id'] );
		}

		return $sanitized;
	}

	/**
	 * Render help tab
	 */
	protected function render_help_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Project Management Architecture', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'This toolkit provides two levels of project management:', 'mcp-ai-wpoos-pro' ); ?></p>
			
			<h3><?php esc_html_e( '📋 Standard Management (Projects, Tasks, Events)', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Projects:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'High-level containers for organizing related work', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Tasks:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Individual work items with manual or AI-assisted creation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Events:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Scheduled meetings, milestones, and deadlines', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'These use standard AI assistance - you interact with the assistant to create, update, and manage items.', 'mcp-ai-wpoos-pro' ); ?></p>
			
			<h3><?php esc_html_e( '🔄 Autonomous Orchestration (Task Plans + Ralph Loop)', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Task Plans:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Markdown-based execution plans with checkbox progress tracking', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Ralph Loop:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Autonomous execution cycles with continuous improvement', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Templates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Reusable workflow templates for common task patterns', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Task Plans can run autonomously - the AI iteratively works through checkboxes, self-heals errors, and exits when complete.', 'mcp-ai-wpoos-pro' ); ?></p>
			
			<h3><?php esc_html_e( '🔗 How They Work Together', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Create a Project to organize your high-level initiative', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Add Tasks for manual tracking or AI-assisted work', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Create a Task Plan for complex, autonomous execution', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Link the Task Plan to your Project for organization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Let the Ralph loop autonomously execute the plan', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Getting Started with Project Management', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<h3><?php esc_html_e( '1. Create Your First Project', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Use the "Research & Add Project" page to leverage AI for project planning:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Navigate to Projects → Research & Add', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Ask the AI assistant to research your project type', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Review AI suggestions for tasks, milestones, and timeline', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Create the project with AI-generated content', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>

			<h3><?php esc_html_e( '2. Break Down Projects into Tasks', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Tasks represent individual work items within a project:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Create tasks manually or ask AI to generate them', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Set priority, status, and assignees', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Link tasks to projects for organization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Track dependencies between tasks', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( '3. Schedule Events and Milestones', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Events help you track important dates and meetings:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Create events for meetings, deadlines, and milestones', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Use the calendar view to visualize your schedule', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Link events to projects for context', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( '4. Use Task Plans for Complex Projects', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Task plans provide detailed execution roadmaps:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Generate task plans with AI assistance', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Break down complex objectives into steps', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Track progress and completion status', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( '5. Leverage Task Templates', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'Save time with reusable workflow templates:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Seed the template library with professional templates', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Create custom templates for your common workflows', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Instantiate templates to quickly start new projects', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'AI Assistant Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'Your AI assistants have access to these project management capabilities:', 'mcp-ai-wpoos-pro' ); ?></p>
			
			<h3><?php esc_html_e( 'Example Prompts', 'mcp-ai-wpoos-pro' ); ?></h3>
			<div class="example-prompts">
				<h4><?php esc_html_e( 'Project Management:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Research a project:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Research best practices for a website redesign project with timeline and deliverables"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Create a project:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Create a project called 'Q1 Marketing Campaign' with start date Jan 1 and end date Mar 31"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'List projects:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Show me all active projects"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Create tasks:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Create 5 tasks for the website redesign project"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'View calendar:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Show me the calendar for this month with all project events"</code>
				</div>
				
				<h4 style="margin-top: 20px;"><?php esc_html_e( 'Autonomous Task Orchestration (Ralph Loop):', 'mcp-ai-wpoos-pro' ); ?></h4>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Create task plan:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Create a task plan for launching an e-commerce store with 10 high-priority tasks"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Start autonomous session:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Start an autonomous session to execute the e-commerce launch task plan"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Check session status:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"What's the status of my autonomous session? How many tasks completed?"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Analyze loop health:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Analyze the health of the current orchestration loop"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Use template:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Instantiate the 'Content Marketing Launch' template for my blog project"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Seed templates:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<code>"Seed the template library with professional workflow templates"</code>
				</div>
			</div>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Tips & Best Practices', 'mcp-ai-wpoos-pro' ); ?></h2>
			<ul>
				<li><strong><?php esc_html_e( 'Start with Research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use the Research & Add page to gather information before creating projects', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Use Templates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create templates for recurring project types to save time', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Set Realistic Timelines:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use AI to estimate task duration and project timelines', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Track Dependencies:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Link related tasks and projects to maintain context', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Regular Updates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Keep project status and task progress up to date', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Leverage AI:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Ask your assistant for suggestions, analytics, and optimization ideas', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>

		<style>
			.example-prompts {
				background: #f9f9f9;
				padding: 15px;
				border-radius: 4px;
				margin-top: 10px;
			}
			.prompt-example {
				margin-bottom: 15px;
				padding-bottom: 15px;
				border-bottom: 1px solid #ddd;
			}
			.prompt-example:last-child {
				margin-bottom: 0;
				padding-bottom: 0;
				border-bottom: none;
			}
			.prompt-example strong {
				display: block;
				margin-bottom: 5px;
				color: #2271b1;
			}
			.prompt-example code {
				display: block;
				background: white;
				padding: 8px 12px;
				border: 1px solid #ddd;
				border-radius: 3px;
				font-size: 13px;
			}
		</style>
		<?php
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Project_Management_Toolkit_Settings_Page();
}
