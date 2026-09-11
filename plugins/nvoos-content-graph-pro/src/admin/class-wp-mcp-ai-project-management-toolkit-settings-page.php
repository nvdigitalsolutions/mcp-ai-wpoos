<?php
/**
 * PM Toolkit Settings Page (ecosystem port — Wave F2, PM admin slice A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-project-management-toolkit-settings-page.php` (or `research-add/`) for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The
 * base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain\n * `nvoos-content-graph-pro`; the toolkit-settings-base require resolves from the addon's already-ported `src/admin/class-wp-mcp-ai-toolkit-settings-base.php`.
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

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Project Management Toolkit Settings Page Class
 */
class WP_MCP_AI_Project_Management_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'project_management';
		$this->toolkit_name     = __( 'Project Management Toolkit', 'nvoos-content-graph-pro' );
		$this->option_name      = 'wp_mcp_ai_project_management_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-project-management-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = false;
		$this->icon             = 'dashicons-portfolio';
		// Parent slug defaults to 'nvoos-pro-dashboard' from base class.
		// Removed override to keep toolkit settings under Pro Dashboard, not Projects CPT menu.

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
				<h2><?php esc_html_e( 'Project Management Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
				
				<div class="toolkit-description">
					<p><?php esc_html_e( 'Comprehensive project management system with AI-powered tools for managing projects, tasks, events, timelines, and team collaboration.', 'nvoos-content-graph-pro' ); ?></p>
				</div>

				<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Project Management:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Create, track, and manage projects with AI assistance', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Task Management:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Break down projects into manageable tasks with dependencies and assignments', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Event Scheduling:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Schedule meetings, milestones, and deadlines with calendar integration', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Ralph Loop Orchestration:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Autonomous task execution with continuous improvement cycles', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Task Plans:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Markdown-based execution plans with checkbox progress tracking', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Task Templates:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Reusable templates for common workflows and project types', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'High-Performance Storage:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Automatic CCT (JetEngine) support for enterprise scalability', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Timeline Views:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Gantt charts and calendar views for project visualization', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'AI Research:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Research project ideas and best practices before creating', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Resource Management:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Track team assignments, budgets, and resource allocation', 'nvoos-content-graph-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Ralph Loop Integration (Task-Level Orchestration)', 'nvoos-content-graph-pro' ); ?></h3>
				<p><?php esc_html_e( 'Task Plans utilize the Ralph Wiggum autonomous orchestration pattern for long-running, iterative task execution:', 'nvoos-content-graph-pro' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Autonomous Task Execution:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'AI-driven continuous task execution with intelligent exit detection', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Markdown-Based Plans:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Checkbox-driven progress tracking for autonomous agents', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Loop Health Monitoring:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Real-time analysis of iteration efficiency and progress', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Circuit Breakers:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Automatic error detection and recovery mechanisms', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Session Continuity:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Context preservation across multiple loop iterations', 'nvoos-content-graph-pro' ); ?></li>
					<li><strong><?php esc_html_e( 'Budget Enforcement:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Token budget tracking and rate limiting for cost control', 'nvoos-content-graph-pro' ); ?></li>
				</ul>
				<p class="description">
					<em><?php esc_html_e( 'Note: Ralph loop orchestration applies to Task Plans for autonomous execution. Projects, Tasks, and Events use standard AI-assisted management.', 'nvoos-content-graph-pro' ); ?></em>
				</p>

				<h3><?php esc_html_e( 'Storage Backend', 'nvoos-content-graph-pro' ); ?></h3>
				<div class="storage-info">
					<?php
					$jetengine_active = class_exists( 'Jet_Engine' );
					$cct_enabled      = false;

					// Check if JetEngine is active and CCT module is enabled.
					if ( $jetengine_active ) {
						$engine = jet_engine();

						// Verify engine instance is valid and check if CCT module is active.
						if ( $engine && isset( $engine->modules ) && method_exists( $engine->modules, 'is_module_active' ) ) {
							$cct_enabled = $engine->modules->is_module_active( 'custom-content-types' );
						}
					}
					?>
					<p>
						<strong><?php esc_html_e( 'JetEngine Status:', 'nvoos-content-graph-pro' ); ?></strong>
						<?php if ( $jetengine_active ) : ?>
							<span style="color: green;">✓ <?php esc_html_e( 'Active', 'nvoos-content-graph-pro' ); ?></span>
						<?php else : ?>
							<span style="color: orange;">○ <?php esc_html_e( 'Not Installed', 'nvoos-content-graph-pro' ); ?></span>
						<?php endif; ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'CCT Module:', 'nvoos-content-graph-pro' ); ?></strong>
						<?php if ( $cct_enabled ) : ?>
							<span style="color: green;">✓ <?php esc_html_e( 'Enabled - Using high-performance CCT storage', 'nvoos-content-graph-pro' ); ?></span>
						<?php elseif ( $jetengine_active ) : ?>
							<span style="color: orange;">○ <?php esc_html_e( 'Available - Enable in JetEngine settings for better performance', 'nvoos-content-graph-pro' ); ?></span>
						<?php else : ?>
							<span style="color: gray;">○ <?php esc_html_e( 'Using standard WordPress CPT storage', 'nvoos-content-graph-pro' ); ?></span>
						<?php endif; ?>
					</p>
					<p class="description">
						<?php
						if ( ! $jetengine_active ) {
							echo wp_kses_post(
								sprintf(
									/* translators: %s: JetEngine URL */
									__( 'For enterprise-scale projects, consider <a href="%s" target="_blank">JetEngine</a> for CCT-based storage with 10-100x performance improvements.', 'nvoos-content-graph-pro' ),
									'https://crocoblock.com/plugins/jetengine/'
								)
							);
						} elseif ( ! $cct_enabled ) {
							esc_html_e( 'Enable Custom Content Types in JetEngine → Settings → Modules for better performance with large datasets.', 'nvoos-content-graph-pro' );
						} else {
							esc_html_e( 'Using JetEngine Custom Content Types for optimal performance with enterprise-scale project management.', 'nvoos-content-graph-pro' );
						}
						?>
					</p>
				</div>

				<h3><?php esc_html_e( 'Custom Post Types', 'nvoos-content-graph-pro' ); ?></h3>
				<div class="cpt-list">
					<div class="cpt-item">
						<span class="dashicons dashicons-portfolio"></span>
						<strong><?php esc_html_e( 'Projects', 'nvoos-content-graph-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'High-level project containers with metadata, status tracking, and team assignments', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-list-view"></span>
						<strong><?php esc_html_e( 'Tasks', 'nvoos-content-graph-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Individual work items linked to projects with priority, status, and assignees', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-calendar-alt"></span>
						<strong><?php esc_html_e( 'Events', 'nvoos-content-graph-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Scheduled meetings, milestones, and deadlines with date/time tracking', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-list-view"></span>
						<strong><?php esc_html_e( 'Task Plans', 'nvoos-content-graph-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Detailed execution plans generated by AI or created manually', 'nvoos-content-graph-pro' ); ?></p>
					</div>
					<div class="cpt-item">
						<span class="dashicons dashicons-clipboard"></span>
						<strong><?php esc_html_e( 'Task Templates', 'nvoos-content-graph-pro' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Reusable project and workflow templates for common use cases', 'nvoos-content-graph-pro' ); ?></p>
					</div>
				</div>

				<h3><?php esc_html_e( 'Quick Links', 'nvoos-content-graph-pro' ); ?></h3>
				<p class="quick-links">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_project' ) ); ?>" class="button">
						<span class="dashicons dashicons-portfolio"></span>
						<?php esc_html_e( 'View Projects', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_task' ) ); ?>" class="button">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'View Tasks', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_event' ) ); ?>" class="button">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'View Events', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_project&page=research-project' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Research & Add Project', 'nvoos-content-graph-pro' ); ?>
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
			<h2><?php esc_html_e( 'Project Management Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Project Status', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[default_project_status]">
							<option value="planning" <?php selected( $options['default_project_status'] ?? 'planning', 'planning' ); ?>><?php esc_html_e( 'Planning', 'nvoos-content-graph-pro' ); ?></option>
							<option value="active" <?php selected( $options['default_project_status'] ?? '', 'active' ); ?>><?php esc_html_e( 'Active', 'nvoos-content-graph-pro' ); ?></option>
							<option value="on_hold" <?php selected( $options['default_project_status'] ?? '', 'on_hold' ); ?>><?php esc_html_e( 'On Hold', 'nvoos-content-graph-pro' ); ?></option>
							<option value="completed" <?php selected( $options['default_project_status'] ?? '', 'completed' ); ?>><?php esc_html_e( 'Completed', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default status when creating new projects', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Task Priority', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[default_task_priority]">
							<option value="low" <?php selected( $options['default_task_priority'] ?? 'medium', 'low' ); ?>><?php esc_html_e( 'Low', 'nvoos-content-graph-pro' ); ?></option>
							<option value="medium" <?php selected( $options['default_task_priority'] ?? 'medium', 'medium' ); ?>><?php esc_html_e( 'Medium', 'nvoos-content-graph-pro' ); ?></option>
							<option value="high" <?php selected( $options['default_task_priority'] ?? 'medium', 'high' ); ?>><?php esc_html_e( 'High', 'nvoos-content-graph-pro' ); ?></option>
							<option value="urgent" <?php selected( $options['default_task_priority'] ?? 'medium', 'urgent' ); ?>><?php esc_html_e( 'Urgent', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default priority level for new tasks', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Budget Tracking', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_budget_tracking]" value="1" <?php checked( ! empty( $options['enable_budget_tracking'] ) ); ?> />
							<?php esc_html_e( 'Track budgets and expenses for projects', 'nvoos-content-graph-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Adds budget fields to project management interface', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Time Tracking', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_time_tracking]" value="1" <?php checked( ! empty( $options['enable_time_tracking'] ) ); ?> />
							<?php esc_html_e( 'Track time spent on tasks and projects', 'nvoos-content-graph-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Adds time logging capabilities to tasks', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Gantt Charts', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_gantt_charts]" value="1" <?php checked( ! empty( $options['enable_gantt_charts'] ) ); ?> />
							<?php esc_html_e( 'Show Gantt chart visualizations for project timelines', 'nvoos-content-graph-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Enables timeline visualization features', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-generate Task Plans', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[auto_generate_task_plans]" value="1" <?php checked( ! empty( $options['auto_generate_task_plans'] ) ); ?> />
							<?php esc_html_e( 'Automatically generate task plans when creating projects', 'nvoos-content-graph-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Uses AI to create detailed execution plans for new projects', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Calendar Start Day', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[calendar_start_day]">
							<option value="0" <?php selected( $options['calendar_start_day'] ?? '1', '0' ); ?>><?php esc_html_e( 'Sunday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="1" <?php selected( $options['calendar_start_day'] ?? '1', '1' ); ?>><?php esc_html_e( 'Monday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="6" <?php selected( $options['calendar_start_day'] ?? '1', '6' ); ?>><?php esc_html_e( 'Saturday', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'First day of the week in calendar views', 'nvoos-content-graph-pro' ); ?></p>
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
		$sanitized['enable_budget_tracking']   = ! empty( $input['enable_budget_tracking'] );
		$sanitized['enable_time_tracking']     = ! empty( $input['enable_time_tracking'] );
		$sanitized['enable_gantt_charts']      = ! empty( $input['enable_gantt_charts'] );
		$sanitized['auto_generate_task_plans'] = ! empty( $input['auto_generate_task_plans'] );
		$sanitized['enable_research']          = ! empty( $input['enable_research'] );

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
			<h2><?php esc_html_e( 'Project Management Architecture', 'nvoos-content-graph-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'This toolkit provides two levels of project management:', 'nvoos-content-graph-pro' ); ?></p>
			
			<h3><?php esc_html_e( '📋 Standard Management (Projects, Tasks, Events)', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Projects:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'High-level containers for organizing related work', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Tasks:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Individual work items with manual or AI-assisted creation', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Events:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Scheduled meetings, milestones, and deadlines', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'These use standard AI assistance - you interact with the assistant to create, update, and manage items.', 'nvoos-content-graph-pro' ); ?></p>
			
			<h3><?php esc_html_e( '🔄 Autonomous Orchestration (Task Plans + Ralph Loop)', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Task Plans:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Markdown-based execution plans with checkbox progress tracking', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Ralph Loop:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Autonomous execution cycles with continuous improvement', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Templates:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Reusable workflow templates for common task patterns', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Task Plans can run autonomously - the AI iteratively works through checkboxes, self-heals errors, and exits when complete.', 'nvoos-content-graph-pro' ); ?></p>
			
			<h3><?php esc_html_e( '🔗 How They Work Together', 'nvoos-content-graph-pro' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Create a Project to organize your high-level initiative', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Add Tasks for manual tracking or AI-assisted work', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Create a Task Plan for complex, autonomous execution', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Link the Task Plan to your Project for organization', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Let the Ralph loop autonomously execute the plan', 'nvoos-content-graph-pro' ); ?></li>
			</ol>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Getting Started with Project Management', 'nvoos-content-graph-pro' ); ?></h2>
			
			<h3><?php esc_html_e( '1. Create Your First Project', 'nvoos-content-graph-pro' ); ?></h3>
			<p><?php esc_html_e( 'Use the "Research & Add Project" page to leverage AI for project planning:', 'nvoos-content-graph-pro' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Navigate to Projects → Research & Add', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Ask the AI assistant to research your project type', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Review AI suggestions for tasks, milestones, and timeline', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Create the project with AI-generated content', 'nvoos-content-graph-pro' ); ?></li>
			</ol>

			<h3><?php esc_html_e( '2. Break Down Projects into Tasks', 'nvoos-content-graph-pro' ); ?></h3>
			<p><?php esc_html_e( 'Tasks represent individual work items within a project:', 'nvoos-content-graph-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Create tasks manually or ask AI to generate them', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Set priority, status, and assignees', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Link tasks to projects for organization', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Track dependencies between tasks', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( '3. Schedule Events and Milestones', 'nvoos-content-graph-pro' ); ?></h3>
			<p><?php esc_html_e( 'Events help you track important dates and meetings:', 'nvoos-content-graph-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Create events for meetings, deadlines, and milestones', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Use the calendar view to visualize your schedule', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Link events to projects for context', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( '4. Use Task Plans for Complex Projects', 'nvoos-content-graph-pro' ); ?></h3>
			<p><?php esc_html_e( 'Task plans provide detailed execution roadmaps:', 'nvoos-content-graph-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Generate task plans with AI assistance', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Break down complex objectives into steps', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Track progress and completion status', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( '5. Leverage Task Templates', 'nvoos-content-graph-pro' ); ?></h3>
			<p><?php esc_html_e( 'Save time with reusable workflow templates:', 'nvoos-content-graph-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Seed the template library with professional templates', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Create custom templates for your common workflows', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Instantiate templates to quickly start new projects', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'AI Assistant Tools', 'nvoos-content-graph-pro' ); ?></h2>
			<p><?php esc_html_e( 'Your AI assistants have access to these project management capabilities:', 'nvoos-content-graph-pro' ); ?></p>
			
			<h3><?php esc_html_e( 'Example Prompts', 'nvoos-content-graph-pro' ); ?></h3>
			<div class="example-prompts">
				<h4><?php esc_html_e( 'Project Management:', 'nvoos-content-graph-pro' ); ?></h4>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Research a project:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Research best practices for a website redesign project with timeline and deliverables"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Create a project:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Create a project called 'Q1 Marketing Campaign' with start date Jan 1 and end date Mar 31"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'List projects:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Show me all active projects"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Create tasks:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Create 5 tasks for the website redesign project"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'View calendar:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Show me the calendar for this month with all project events"</code>
				</div>
				
				<h4 style="margin-top: 20px;"><?php esc_html_e( 'Autonomous Task Orchestration (Ralph Loop):', 'nvoos-content-graph-pro' ); ?></h4>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Create task plan:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Create a task plan for launching an e-commerce store with 10 high-priority tasks"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Start autonomous session:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Start an autonomous session to execute the e-commerce launch task plan"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Check session status:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"What's the status of my autonomous session? How many tasks completed?"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Analyze loop health:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Analyze the health of the current orchestration loop"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Use template:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Instantiate the 'Content Marketing Launch' template for my blog project"</code>
				</div>
				<div class="prompt-example">
					<strong><?php esc_html_e( 'Seed templates:', 'nvoos-content-graph-pro' ); ?></strong>
					<code>"Seed the template library with professional workflow templates"</code>
				</div>
			</div>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Tips & Best Practices', 'nvoos-content-graph-pro' ); ?></h2>
			<ul>
				<li><strong><?php esc_html_e( 'Start with Research:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Use the Research & Add page to gather information before creating projects', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Use Templates:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Create templates for recurring project types to save time', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Set Realistic Timelines:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Use AI to estimate task duration and project timelines', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Track Dependencies:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Link related tasks and projects to maintain context', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Regular Updates:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Keep project status and task progress up to date', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Leverage AI:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Ask your assistant for suggestions, analytics, and optimization ideas', 'nvoos-content-graph-pro' ); ?></li>
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
