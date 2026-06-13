<?php
/**
 * Project Management Command Center Admin Page
 *
 * Unified PM dashboard providing project pipeline overview, task/event KPIs,
 * recent activity feed, PARA organization view, risk assessment,
 * workflow rules, and configuration — all under the "NV Projects" top-level
 * admin section.
 *
 * Mirrors the CRM Command Center pattern but is PM-specific.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Project Management Command Center Page Class
 */
class WP_MCP_AI_PM_Command_Center_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-pm-command-center';

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_pm_cc';

	/**
	 * Page hook.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Initialize the command center page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_cc_get_kpis', array( __CLASS__, 'ajax_get_kpis' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_cc_get_pipeline', array( __CLASS__, 'ajax_get_pipeline' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_cc_get_deadlines', array( __CLASS__, 'ajax_get_deadlines' ) );
		add_action( 'wp_ajax_wp_mcp_ai_pm_cc_get_activity', array( __CLASS__, 'ajax_get_activity' ) );
	}

	/**
	 * Register the submenu page under NV Projects.
	 */
	public static function register_page() {
		self::$page_hook = add_submenu_page(
			WP_MCP_AI_PM_Admin_Menu::PARENT_SLUG,
			__( 'PM Command Center', 'mcp-ai-wpoos-pro' ),
			__( 'Command Center', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the command center page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( self::$page_hook !== $hook ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection for asset enqueue.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( ! $page || ! in_array( $page, array( self::PAGE_SLUG, WP_MCP_AI_PM_Admin_Menu::PARENT_SLUG ), true ) ) {
				return;
			}
		}

		// PM command center styles (inline for now; extract later).
		add_action(
			'admin_head',
			function () {
				?>
				<style>
				.pm-cc-wrap { margin: 0 0 0 -20px; }
				.pm-cc-header {
					background: #fff;
					border-bottom: 1px solid #c3c4c7;
					padding: 16px 24px;
					display: flex;
					align-items: center;
					justify-content: space-between;
				}
				.pm-cc-header h1 {
					margin: 0;
					font-size: 20px;
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.pm-cc-header .dashicons { font-size: 28px; width: 28px; height: 28px; color: #2271b1; }
				.pm-cc-badge {
					background: #2271b1;
					color: #fff;
					font-size: 10px;
					padding: 2px 6px;
					border-radius: 3px;
					text-transform: uppercase;
					font-weight: 600;
				}
				.pm-cc-subtitle { color: #646970; margin: 4px 0 0; font-size: 13px; }
				.pm-cc-nav {
					background: #fff;
					border-bottom: 1px solid #c3c4c7;
					padding: 0 24px;
				}
				.pm-cc-nav .nav-tab-wrapper { border-bottom: none; margin-bottom: 0; padding-top: 8px; }
				.pm-cc-content { padding: 24px; }
				.pm-cc-kpi-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
					gap: 16px;
					margin-bottom: 24px;
				}
				.pm-cc-kpi {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 16px;
				}
				.pm-cc-kpi-label {
					font-size: 12px;
					color: #646970;
					text-transform: uppercase;
					font-weight: 600;
					margin-bottom: 8px;
				}
				.pm-cc-kpi-value {
					font-size: 28px;
					font-weight: 700;
					line-height: 1.2;
				}
				.pm-cc-kpi-sub {
					font-size: 12px;
					color: #646970;
					margin-top: 4px;
				}
				.pm-cc-kpi-value.good { color: #00a32a; }
				.pm-cc-kpi-value.warn { color: #dba617; }
				.pm-cc-kpi-value.danger { color: #d63638; }
				.pm-cc-pipeline-stage {
					display: flex;
					align-items: center;
					margin-bottom: 12px;
				}
				.pm-cc-pipeline-stage-name {
					width: 140px;
					font-weight: 600;
					font-size: 13px;
				}
				.pm-cc-pipeline-bar-wrap {
					flex: 1;
					background: #f0f0f1;
					border-radius: 3px;
					height: 20px;
					margin: 0 12px;
					overflow: hidden;
				}
				.pm-cc-pipeline-bar {
					background: #2271b1;
					height: 100%;
					border-radius: 3px;
					min-width: 2px;
					transition: width 0.3s ease;
				}
				.pm-cc-pipeline-count {
					font-weight: 600;
					font-size: 13px;
					min-width: 60px;
					text-align: right;
				}
				.pm-cc-section {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 20px;
					margin-bottom: 24px;
				}
				.pm-cc-section h2 {
					margin: 0 0 16px;
					font-size: 16px;
				}
				.pm-cc-inline-cards {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 16px;
				}
				.pm-cc-muted { color: #646970; font-style: italic; }
				.pm-cc-badge-status {
					display: inline-block;
					padding: 1px 7px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
				}
				.pm-cc-badge-status.planning { background: #d1ecf1; color: #0c5460; }
				.pm-cc-badge-status.idea { background: #e7e8ea; color: #3c434a; }
				.pm-cc-badge-status.active { background: #d4edda; color: #155724; }
				.pm-cc-badge-status.at-risk { background: #fff3cd; color: #856404; }
				.pm-cc-badge-status.on-hold { background: #f8d7da; color: #721c24; }
				.pm-cc-badge-status.completed { background: #cce5ff; color: #004085; }
				.pm-cc-badge-status.cancelled { background: #f8d7da; color: #721c24; }
				.pm-cc-badge-status.todo { background: #e7e8ea; color: #3c434a; }
				.pm-cc-badge-status.backlog { background: #d1ecf1; color: #0c5460; }
				.pm-cc-badge-status.in-progress { background: #d4edda; color: #155724; }
				.pm-cc-badge-status.review { background: #fff3cd; color: #856404; }
				.pm-cc-badge-status.blocked { background: #f8d7da; color: #721c24; }
				.pm-cc-badge-status.done { background: #cce5ff; color: #004085; }
				.pm-cc-priority.critical { color: #d63638; font-weight: 700; }
				.pm-cc-priority.high { color: #dba617; font-weight: 600; }
				.pm-cc-priority.highest { color: #e6502a; font-weight: 700; }
				.pm-cc-health-bar-wrap {
					background: #f0f0f1;
					border-radius: 3px;
					height: 8px;
					margin: 8px 0 4px;
					overflow: hidden;
				}
				.pm-cc-health-bar {
					height: 100%;
					border-radius: 3px;
					transition: width 0.4s ease;
					min-width: 2px;
				}
				@media (max-width: 768px) {
					.pm-cc-inline-cards { grid-template-columns: 1fr; }
					.pm-cc-kpi-grid { grid-template-columns: repeat(2, 1fr); }
				}
				</style>
				<?php
			}
		);
	}

	/**
	 * Render the main command center page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		$valid_tabs  = array( 'overview', 'projects', 'tasks', 'events', 'analytics', 'para', 'risk', 'workflows', 'templates', 'configuration' );
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'overview';
		}

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap pm-cc-wrap">
			<div class="pm-cc-header">
				<div>
					<h1>
						<span class="dashicons dashicons-portfolio"></span>
						<?php esc_html_e( 'PM Command Center', 'mcp-ai-wpoos-pro' ); ?>
						<span class="pm-cc-badge"><?php esc_html_e( 'PRO', 'mcp-ai-wpoos-pro' ); ?></span>
					</h1>
					<p class="pm-cc-subtitle">
						<?php esc_html_e( 'Monitor your project portfolio, track tasks and events, review analytics, and manage PARA organization.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</div>
			</div>

			<div class="pm-cc-nav">
				<nav class="nav-tab-wrapper">
					<?php
					$tabs = array(
						'overview'      => __( 'Overview', 'mcp-ai-wpoos-pro' ),
						'projects'      => __( 'Projects', 'mcp-ai-wpoos-pro' ),
						'tasks'         => __( 'Tasks', 'mcp-ai-wpoos-pro' ),
						'events'        => __( 'Events', 'mcp-ai-wpoos-pro' ),
						'analytics'     => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
						'para'          => __( 'PARA', 'mcp-ai-wpoos-pro' ),
						'risk'          => __( 'Risk', 'mcp-ai-wpoos-pro' ),
						'workflows'     => __( 'Workflows', 'mcp-ai-wpoos-pro' ),
						'templates'     => __( 'Templates', 'mcp-ai-wpoos-pro' ),
						'configuration' => __( 'Configuration', 'mcp-ai-wpoos-pro' ),
					);

					foreach ( $tabs as $slug => $label ) {
						$class = 'nav-tab' . ( $current_tab === $slug ? ' nav-tab-active' : '' );
						printf(
							'<a href="%s" class="%s">%s</a>',
							esc_url( add_query_arg( 'tab', $slug, $base_url ) ),
							esc_attr( $class ),
							esc_html( $label )
						);
					}
					?>
				</nav>
			</div>

			<div class="pm-cc-content">
				<?php
				switch ( $current_tab ) {
					case 'projects':
						self::render_projects_tab();
						break;
					case 'tasks':
						self::render_tasks_tab();
						break;
					case 'events':
						self::render_events_tab();
						break;
					case 'analytics':
						self::render_analytics_tab();
						break;
					case 'para':
						self::render_para_tab();
						break;
					case 'risk':
						self::render_risk_tab();
						break;
					case 'workflows':
						self::render_workflows_tab();
						break;
					case 'templates':
						self::render_templates_tab();
						break;
					case 'configuration':
						self::render_configuration_tab();
						break;
					default:
						self::render_overview_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// Overview Tab
	// =========================================================================

	/**
	 * Render the Overview tab with PM KPIs, pipeline, deadlines, and recent activity.
	 */
	private static function render_overview_tab() {
		$settings = class_exists( 'WP_MCP_AI_PM_Engine' ) ? WP_MCP_AI_PM_Engine::get_toolkit_settings() : array();

		// KPI counts
		$active_projects    = self::get_active_project_count();
		$total_projects     = self::get_cpt_count( 'mcp_ai_project' );
		$open_tasks         = self::get_open_task_count();
		$completed_this_week = self::get_completed_this_week();
		$upcoming_events    = self::get_cpt_count_by_meta_date( 'mcp_ai_event', '_event_date', 7 );
		$events_today       = self::get_cpt_count_by_meta_date( 'mcp_ai_event', '_event_date', 1 );
		$overdue_tasks      = self::get_overdue_task_count();
		$blocked_tasks      = self::get_cpt_count_by_meta( 'mcp_ai_task', '_task_status', 'blocked' );
		$new_blocked_week   = self::get_new_blocked_this_week();

		$health = class_exists( 'WP_MCP_AI_PM_Engine' ) ? WP_MCP_AI_PM_Engine::calculate_portfolio_health() : array( 'score' => 0 );

		$health_class = 'good';
		if ( $health['score'] < 40 ) {
			$health_class = 'danger';
		} elseif ( $health['score'] < 70 ) {
			$health_class = 'warn';
		}

		// Pipeline data
		$pipeline = array();
		if ( class_exists( 'WP_MCP_AI_PM_Pipeline_Stages' ) ) {
			$stages = WP_MCP_AI_PM_Pipeline_Stages::get_open_stages();
			foreach ( $stages as $stage_id => $stage_def ) {
				$count = self::get_cpt_count_by_meta( 'mcp_ai_project', '_project_status', $stage_id );
				$pipeline[ $stage_id ] = array(
					'label' => $stage_def['label'],
					'count' => $count,
					'color' => $stage_def['color'] ?? '#2271b1',
				);
			}
		}

		$total_pipeline = array_sum( wp_list_pluck( $pipeline, 'count' ) );
		$deadlines      = class_exists( 'WP_MCP_AI_PM_Engine' ) ? WP_MCP_AI_PM_Engine::get_upcoming_deadlines( 7, 10 ) : array();
		$recent_tasks   = self::get_recent_activity( 5 );
		?>
		<div class="pm-cc-kpi-grid">
			<div class="pm-cc-kpi">
				<div class="pm-cc-kpi-label"><?php esc_html_e( 'Active Projects', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="pm-cc-kpi-value"><?php echo esc_html( $active_projects ); ?></div>
				<div class="pm-cc-kpi-sub">
					<?php
					printf(
						/* translators: %d: total number of projects */
						esc_html__( '%d total', 'mcp-ai-wpoos-pro' ),
						(int) $total_projects
					);
					?>
				</div>
			</div>
			<div class="pm-cc-kpi">
				<div class="pm-cc-kpi-label"><?php esc_html_e( 'Open Tasks', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="pm-cc-kpi-value"><?php echo esc_html( $open_tasks ); ?></div>
				<div class="pm-cc-kpi-sub">
					<?php
					printf(
						/* translators: %d: number of tasks completed this week */
						esc_html__( '%d completed this week', 'mcp-ai-wpoos-pro' ),
						(int) $completed_this_week
					);
					?>
				</div>
			</div>
			<div class="pm-cc-kpi">
				<div class="pm-cc-kpi-label"><?php esc_html_e( 'Upcoming Events', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="pm-cc-kpi-value"><?php echo esc_html( $upcoming_events ); ?></div>
				<div class="pm-cc-kpi-sub">
					<?php
					printf(
						/* translators: %d: number of events today */
						esc_html__( '%d today', 'mcp-ai-wpoos-pro' ),
						(int) $events_today
					);
					?>
				</div>
			</div>
			<div class="pm-cc-kpi">
				<div class="pm-cc-kpi-label"><?php esc_html_e( 'Overdue Tasks', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="pm-cc-kpi-value <?php echo $overdue_tasks > 5 ? 'danger' : ( $overdue_tasks > 0 ? 'warn' : 'good' ); ?>"><?php echo esc_html( $overdue_tasks ); ?></div>
				<div class="pm-cc-kpi-sub">
					<?php
					printf(
						/* translators: %.0f: percentage of open tasks that are overdue */
						esc_html__( '%.0f%% of open tasks', 'mcp-ai-wpoos-pro' ),
						$open_tasks > 0 ? round( $overdue_tasks / $open_tasks * 100 ) : 0
					);
					?>
				</div>
			</div>
			<div class="pm-cc-kpi">
				<div class="pm-cc-kpi-label"><?php esc_html_e( 'Blocked Tasks', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="pm-cc-kpi-value <?php echo $blocked_tasks > 3 ? 'danger' : ( $blocked_tasks > 0 ? 'warn' : 'good' ); ?>"><?php echo esc_html( $blocked_tasks ); ?></div>
				<div class="pm-cc-kpi-sub">
					<?php
					printf(
						/* translators: %d: number of newly blocked tasks this week */
						esc_html__( '%d new this week', 'mcp-ai-wpoos-pro' ),
						(int) $new_blocked_week
					);
					?>
				</div>
			</div>
			<div class="pm-cc-kpi">
				<div class="pm-cc-kpi-label"><?php esc_html_e( 'Portfolio Health', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="pm-cc-kpi-value <?php echo esc_attr( $health_class ); ?>"><?php echo esc_html( $health['score'] ); ?>%</div>
				<div class="pm-cc-health-bar-wrap">
					<div class="pm-cc-health-bar" style="width: <?php echo esc_attr( $health['score'] ); ?>%; background: <?php echo esc_attr( $health['score'] >= 70 ? '#00a32a' : ( $health['score'] >= 40 ? '#dba617' : '#d63638' ) ); ?>;"></div>
				</div>
			</div>
		</div>

		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Project Pipeline', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $pipeline ) ) : ?>
				<p class="pm-cc-muted"><?php esc_html_e( 'No active projects.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<?php foreach ( $pipeline as $stage ) : ?>
					<div class="pm-cc-pipeline-stage">
						<span class="pm-cc-pipeline-stage-name"><?php echo esc_html( $stage['label'] ); ?></span>
						<div class="pm-cc-pipeline-bar-wrap">
							<div class="pm-cc-pipeline-bar" style="width: <?php echo $total_pipeline > 0 ? esc_attr( round( $stage['count'] / $total_pipeline * 100 ) ) : 0; ?>%; background: <?php echo esc_attr( $stage['color'] ); ?>;"></div>
						</div>
						<span class="pm-cc-pipeline-count"><?php echo esc_html( $stage['count'] ); ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="pm-cc-inline-cards">
			<div class="pm-cc-section">
				<h2><?php esc_html_e( 'Upcoming Deadlines (Next 7 Days)', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $deadlines ) ) : ?>
					<p class="pm-cc-muted"><?php esc_html_e( 'No upcoming deadlines.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<table class="widefat fixed striped">
						<tbody>
						<?php foreach ( $deadlines as $dl ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $dl['title'] ); ?></strong>
									<br><small><?php echo esc_html( $dl['type'] ?? '' ); ?></small>
								</td>
								<td><?php echo esc_html( $dl['due_date'] ); ?></td>
								<td>
									<?php if ( isset( $dl['priority'] ) && 'critical' === $dl['priority'] ) : ?>
										<span class="pm-cc-priority critical"><?php esc_html_e( 'Critical', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php elseif ( isset( $dl['priority'] ) && in_array( $dl['priority'], array( 'high', 'highest' ), true ) ) : ?>
										<span class="pm-cc-priority high"><?php echo esc_html( ucfirst( $dl['priority'] ) ); ?></span>
									<?php endif; ?>
									<?php if ( isset( $dl['days_until'] ) && $dl['days_until'] < 0 ) : ?>
										<span class="pm-cc-priority critical"><?php esc_html_e( 'Overdue', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="pm-cc-section">
				<h2><?php esc_html_e( 'Recent Activity', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $recent_tasks ) ) : ?>
					<p class="pm-cc-muted"><?php esc_html_e( 'No recent activity.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<table class="widefat fixed striped">
						<tbody>
						<?php foreach ( $recent_tasks as $item ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<br><small><?php echo esc_html( $item['type'] ); ?></small>
								</td>
								<td>
									<span class="pm-cc-badge-status <?php echo esc_attr( $item['status'] ?? '' ); ?>"><?php echo esc_html( ucfirst( $item['status'] ?? '' ) ); ?></span>
								</td>
								<td><small><?php echo esc_html( self::get_relative_time( $item['modified'] ) ); ?></small></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// Projects Tab
	// =========================================================================

	/**
	 * Render the Projects tab.
	 */
	private static function render_projects_tab() {
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_project',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Projects', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $projects ) ) : ?>
				<p class="pm-cc-muted"><?php esc_html_e( 'No projects found.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Project', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Tasks', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Due Date', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $projects as $project ) :
						$status     = get_post_meta( $project->ID, '_project_status', true ) ?: 'planning';
						$end_date   = get_post_meta( $project->ID, '_project_end_date', true );
						$task_count = class_exists( 'WP_MCP_AI_PM_Engine' ) ? WP_MCP_AI_PM_Engine::count_tasks( $project->ID ) : 0;
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $project->ID ) ); ?>"><strong><?php echo esc_html( $project->post_title ); ?></strong></a>
							</td>
							<td><span class="pm-cc-badge-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
							<td><?php echo esc_html( $task_count ); ?></td>
							<td><?php echo esc_html( $end_date ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_project' ) ); ?>" class="button"><?php esc_html_e( 'View All Projects', 'mcp-ai-wpoos-pro' ); ?></a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Tasks Tab
	// =========================================================================

	/**
	 * Render the Tasks tab.
	 */
	private static function render_tasks_tab() {
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Recent Tasks', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $tasks ) ) : ?>
				<p class="pm-cc-muted"><?php esc_html_e( 'No tasks found.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Task', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Priority', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Project', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Due Date', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $tasks as $task ) :
						$status     = get_post_meta( $task->ID, '_task_status', true ) ?: 'todo';
						$priority   = get_post_meta( $task->ID, '_task_priority', true ) ?: 'medium';
						$project_id = get_post_meta( $task->ID, '_task_project_id', true );
						$due_date   = get_post_meta( $task->ID, '_task_due_date', true );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $task->ID ) ); ?>"><strong><?php echo esc_html( $task->post_title ); ?></strong></a></td>
							<td><span class="pm-cc-badge-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
							<td class="pm-cc-priority <?php echo in_array( $priority, array( 'critical', 'highest', 'high' ), true ) ? esc_attr( $priority ) : ''; ?>"><?php echo esc_html( ucfirst( $priority ) ); ?></td>
							<td>
								<?php
								if ( $project_id ) {
									echo '<a href="' . esc_url( get_edit_post_link( $project_id ) ) . '">' . esc_html( get_the_title( $project_id ) ) . '</a>';
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo esc_html( $due_date ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_task' ) ); ?>" class="button"><?php esc_html_e( 'View All Tasks', 'mcp-ai-wpoos-pro' ); ?></a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Events Tab
	// =========================================================================

	/**
	 * Render the Events tab.
	 */
	private static function render_events_tab() {
		$events = get_posts(
			array(
				'post_type'      => 'mcp_ai_event',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'meta_key'       => '_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Ordered display of date-oriented CPT.
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Events', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $events ) ) : ?>
				<p class="pm-cc-muted"><?php esc_html_e( 'No events found.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Event', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Time', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Location', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $events as $event ) :
						$event_date = get_post_meta( $event->ID, '_event_date', true );
						$event_time = get_post_meta( $event->ID, '_event_time', true );
						$location   = get_post_meta( $event->ID, '_event_location', true );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $event->ID ) ); ?>"><strong><?php echo esc_html( $event->post_title ); ?></strong></a></td>
							<td><?php echo esc_html( $event_date ?: '—' ); ?></td>
							<td><?php echo esc_html( $event_time ?: '—' ); ?></td>
							<td><?php echo esc_html( $location ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_event' ) ); ?>" class="button"><?php esc_html_e( 'View All Events', 'mcp-ai-wpoos-pro' ); ?></a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Analytics Tab
	// =========================================================================

	/**
	 * Render the Analytics tab placeholder.
	 */
	private static function render_analytics_tab() {
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'PM Analytics', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'Use the AI Assistant to run analytics queries:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Burndown charts via get_burndown_chart', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Team velocity via get_team_velocity', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Portfolio health via get_portfolio_health', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Resource utilization via get_resource_utilization', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	// =========================================================================
	// PARA Tab
	// =========================================================================

	/**
	 * Render the PARA organization tab.
	 */
	private static function render_para_tab() {
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'PARA Organization', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) && WP_MCP_AI_PARA_Taxonomy::is_enabled() ) : ?>
				<p><?php esc_html_e( 'PARA methodology is active. Use AI tools to classify items:', 'mcp-ai-wpoos-pro' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'para_classify_item — Classify any item into P/A/R/A', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'para_create_area — Create a new Area of responsibility', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'para_list_areas — List all Areas', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'para_weekly_review — Run weekly review', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'Enable PARA organization in Settings to use the PARA methodology.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// Risk Tab
	// =========================================================================

	/**
	 * Render the Risk Assessment tab.
	 */
	private static function render_risk_tab() {
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Risk Assessment', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php
			if ( class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
				$stale_tasks = WP_MCP_AI_PM_Engine::detect_stale_tasks();
				if ( ! empty( $stale_tasks ) ) {
					echo '<h3>' . esc_html__( 'Stale Tasks (>14 days)', 'mcp-ai-wpoos-pro' ) . '</h3>';
					echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'Task', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Days Stale', 'mcp-ai-wpoos-pro' ) . '</th></tr></thead><tbody>';
					foreach ( $stale_tasks as $t ) {
						echo '<tr><td><a href="' . esc_url( get_edit_post_link( $t['id'] ) ) . '">' . esc_html( $t['title'] ) . '</a></td><td>' . esc_html( $t['status'] ) . '</td><td>' . esc_html( $t['days_stale'] ) . '</td></tr>';
					}
					echo '</tbody></table>';
				} else {
					echo '<p>' . esc_html__( 'No stale tasks detected.', 'mcp-ai-wpoos-pro' ) . '</p>';
				}

				$utilization = WP_MCP_AI_PM_Engine::get_resource_utilization();
				if ( ! empty( $utilization ) ) {
					echo '<h3>' . esc_html__( 'Resource Utilization', 'mcp-ai-wpoos-pro' ) . '</h3>';
					echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'User', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Tasks', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Utilization %', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th></tr></thead><tbody>';
					foreach ( $utilization as $u ) {
						$class = 'over_allocated' === $u['status'] ? 'danger' : ( 'under_allocated' === $u['status'] ? 'warn' : '' );
						echo '<tr><td>' . esc_html( $u['display_name'] ) . '</td><td>' . esc_html( $u['task_count'] ) . '</td><td>' . esc_html( $u['utilization_pct'] ) . '%</td><td><span class="pm-cc-kpi-value ' . esc_attr( $class ) . '" style="font-size:14px;">' . esc_html( ucfirst( str_replace( '_', ' ', $u['status'] ) ) ) . '</span></td></tr>';
					}
					echo '</tbody></table>';
				}
			} else {
				echo '<p class="pm-cc-muted">' . esc_html__( 'PM Engine not available.', 'mcp-ai-wpoos-pro' ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	// =========================================================================
	// Workflows Tab
	// =========================================================================

	/**
	 * Render the Workflow Rules tab.
	 */
	private static function render_workflows_tab() {
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Workflow Rules', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php
			$rules = get_posts(
				array(
					'post_type'      => 'mcp_ai_pm_workflow_rule',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
				)
			);
			if ( empty( $rules ) ) :
				?>
				<p><?php esc_html_e( 'No workflow rules defined yet. Use the AI Assistant to create automation rules.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rule', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $rules as $rule ) :
						$trigger = get_post_meta( $rule->ID, '_pm_wf_trigger_type', true );
						$active  = get_post_meta( $rule->ID, '_pm_wf_active', true );
						?>
						<tr>
							<td><strong><?php echo esc_html( $rule->post_title ); ?></strong></td>
							<td><?php echo esc_html( $trigger ); ?></td>
							<td><?php echo '1' === $active ? '✅' : '❌'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// Templates Tab
	// =========================================================================

	/**
	 * Render the Task Templates tab.
	 */
	private static function render_templates_tab() {
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Task Templates', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php
			$templates = get_posts(
				array(
					'post_type'      => 'mcp_task_template',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
				)
			);
			if ( empty( $templates ) ) :
				?>
				<p><?php esc_html_e( 'No task templates found.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Template', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $templates as $tpl ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $tpl->ID ) ); ?>"><strong><?php echo esc_html( $tpl->post_title ); ?></strong></a></td>
							<td><?php echo esc_html( $tpl->post_excerpt ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// Configuration Tab
	// =========================================================================

	/**
	 * Render the Configuration tab.
	 */
	private static function render_configuration_tab() {
		?>
		<div class="pm-cc-section">
			<h2><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php
			if ( class_exists( 'WP_MCP_AI_PM_Engine' ) ) :
				$settings = WP_MCP_AI_PM_Engine::get_toolkit_settings();
				?>
				<table class="widefat fixed striped" style="max-width:700px;">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Default Project Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $settings['default_project_status'] ?? 'planning' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Default Task Priority', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $settings['default_task_priority'] ?? 'medium' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Estimation Method', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $settings['estimation_method'] ?? 'hours' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Sprint Duration (days)', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $settings['sprint_duration_days'] ?? '14' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Stale Task Threshold (days)', 'mcp-ai-wpoos-pro' ); ?></th>
							<td><?php echo esc_html( $settings['risk_thresholds']['stale_task_days'] ?? '14' ); ?></td>
						</tr>
					</tbody>
				</table>
			<?php else : ?>
				<p class="pm-cc-muted"><?php esc_html_e( 'PM Engine not available.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>
			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-project-management-toolkit-settings' ) ); ?>" class="button"><?php esc_html_e( 'Full Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX Handlers
	// =========================================================================

	/**
	 * AJAX handler: get KPI data.
	 */
	public static function ajax_get_kpis() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}
		wp_send_json_success(
			array(
				'active_projects' => self::get_active_project_count(),
				'open_tasks'      => self::get_open_task_count(),
				'health'          => class_exists( 'WP_MCP_AI_PM_Engine' ) ? WP_MCP_AI_PM_Engine::calculate_portfolio_health() : array( 'score' => 0 ),
			)
		);
	}

	/**
	 * AJAX handler: get pipeline data.
	 */
	public static function ajax_get_pipeline() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}
		$pipeline = array();
		if ( class_exists( 'WP_MCP_AI_PM_Pipeline_Stages' ) ) {
			foreach ( WP_MCP_AI_PM_Pipeline_Stages::get_open_stages() as $id => $def ) {
				$pipeline[ $id ] = array(
					'label' => $def['label'],
					'count' => self::get_cpt_count_by_meta( 'mcp_ai_project', '_project_status', $id ),
					'color' => $def['color'] ?? '#2271b1',
				);
			}
		}
		wp_send_json_success( $pipeline );
	}

	/**
	 * AJAX handler: get upcoming deadlines.
	 */
	public static function ajax_get_deadlines() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}
		$deadlines = class_exists( 'WP_MCP_AI_PM_Engine' ) ? WP_MCP_AI_PM_Engine::get_upcoming_deadlines( 7, 20 ) : array();
		wp_send_json_success( $deadlines );
	}

	/**
	 * AJAX handler: get recent activity.
	 */
	public static function ajax_get_activity() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}
		wp_send_json_success( self::get_recent_activity( 20 ) );
	}

	// =========================================================================
	// Data helpers
	// =========================================================================

	/**
	 * Get count of posts for a given post type and status.
	 *
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 * @return int
	 */
	private static function get_cpt_count( $post_type, $status = 'publish' ) {
		$counts = wp_count_posts( $post_type );
		return isset( $counts->$status ) ? (int) $counts->$status : 0;
	}

	/**
	 * Get count of posts filtered by a single meta value.
	 *
	 * @param string $post_type  Post type.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value (string for single, array for IN).
	 * @return int
	 */
	private static function get_cpt_count_by_meta( $post_type, $meta_key, $meta_value ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional single-key count lookup.
				'meta_key'       => is_array( $meta_value ) ? '' : $meta_key,
				'meta_value'     => is_array( $meta_value ) ? '' : $meta_value,
				// phpcs:enable
				'no_found_rows'  => false,
				'meta_query'     => is_array( $meta_value ) ? array(
					array(
						'key'     => $meta_key,
						'value'   => $meta_value,
						'compare' => 'IN',
					),
				) : array(),
			)
		);
		$count = $query->found_posts;
		wp_reset_postdata();
		return $count;
	}

	/**
	 * Get count of posts filtered by a date-range meta value.
	 *
	 * @param string $post_type Post type.
	 * @param string $meta_key  Meta key.
	 * @param int    $days      Number of days from now.
	 * @return int
	 */
	private static function get_cpt_count_by_meta_date( $post_type, $meta_key, $days ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Controlled, intentional date-range count lookup.
					array(
						'key'     => $meta_key,
						'value'   => array( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', time() + ( $days * DAY_IN_SECONDS ) ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);
		$count = $query->found_posts;
		wp_reset_postdata();
		return $count;
	}

	/**
	 * Get the count of active projects (idea, planning, active, at-risk).
	 *
	 * @return int
	 */
	private static function get_active_project_count() {
		return self::get_cpt_count_by_meta(
			'mcp_ai_project',
			'_project_status',
			array( 'idea', 'planning', 'active', 'at-risk' )
		);
	}

	/**
	 * Get the count of open tasks (backlog, todo, in-progress, review, blocked).
	 *
	 * @return int
	 */
	private static function get_open_task_count() {
		return self::get_cpt_count_by_meta(
			'mcp_ai_task',
			'_task_status',
			array( 'backlog', 'todo', 'in-progress', 'review', 'blocked' )
		);
	}

	/**
	 * Get the number of tasks completed this week.
	 *
	 * @return int
	 */
	private static function get_completed_this_week() {
		global $wpdb;
		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = 'mcp_ai_task'
				AND p.post_status = 'publish'
				AND pm.meta_key = '_task_status'
				AND pm.meta_value = 'completed'
				AND p.post_modified >= %s",
				$week_start
			)
		);
	}

	/**
	 * Get the number of overdue tasks.
	 *
	 * @return int
	 */
	private static function get_overdue_task_count() {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Controlled, intentional count lookup.
					'relation' => 'AND',
					array(
						'key'     => '_task_due_date',
						'value'   => gmdate( 'Y-m-d' ),
						'compare' => '<',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_task_status',
						'value'   => array( 'completed', 'cancelled' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);
		$count = $query->found_posts;
		wp_reset_postdata();
		return $count;
	}

	/**
	 * Get the number of tasks newly blocked this week.
	 *
	 * @return int
	 */
	private static function get_new_blocked_this_week() {
		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
		$query      = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'date_query'     => array(
					array(
						'column' => 'post_modified',
						'after'  => $week_start,
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Controlled, intentional count lookup.
					array(
						'key'   => '_task_status',
						'value' => 'blocked',
					),
				),
			)
		);
		$count = $query->found_posts;
		wp_reset_postdata();
		return $count;
	}

	/**
	 * Get recent task activity.
	 *
	 * @param int $limit Maximum number of items.
	 * @return array
	 */
	private static function get_recent_activity( $limit = 5 ) {
		$items = array();
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		foreach ( $tasks as $task ) {
			$items[] = array(
				'id'       => $task->ID,
				'title'    => $task->post_title,
				'type'     => 'task',
				'status'   => get_post_meta( $task->ID, '_task_status', true ),
				'modified' => $task->post_modified,
			);
		}
		return $items;
	}

	/**
	 * Get relative time string (e.g. "2 hours ago").
	 *
	 * Mirrors the CRM Command Center get_relative_time pattern.
	 *
	 * @since 2.6.0
	 * @param string $datetime Datetime string.
	 * @return string Relative time description.
	 */
	private static function get_relative_time( $datetime ) {
		if ( ! $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );
		if ( ! $timestamp ) {
			return '';
		}

		$diff = time() - $timestamp;

		if ( $diff < 60 ) {
			return __( 'Just now', 'mcp-ai-wpoos-pro' );
		} elseif ( $diff < HOUR_IN_SECONDS ) {
			$mins = round( $diff / MINUTE_IN_SECONDS );
			return sprintf(
				/* translators: %d: number of minutes */
				_n( '%d min ago', '%d mins ago', $mins, 'mcp-ai-wpoos-pro' ),
				$mins
			);
		} elseif ( $diff < DAY_IN_SECONDS ) {
			$hours = round( $diff / HOUR_IN_SECONDS );
			return sprintf(
				/* translators: %d: number of hours */
				_n( '%d hour ago', '%d hours ago', $hours, 'mcp-ai-wpoos-pro' ),
				$hours
			);
		} elseif ( $diff < WEEK_IN_SECONDS ) {
			$days = round( $diff / DAY_IN_SECONDS );
			return sprintf(
				/* translators: %d: number of days */
				_n( '%d day ago', '%d days ago', $days, 'mcp-ai-wpoos-pro' ),
				$days
			);
		} elseif ( $diff < MONTH_IN_SECONDS ) {
			$weeks = round( $diff / WEEK_IN_SECONDS );
			return sprintf(
				/* translators: %d: number of weeks */
				_n( '%d week ago', '%d weeks ago', $weeks, 'mcp-ai-wpoos-pro' ),
				$weeks
			);
		} else {
			$months = round( $diff / MONTH_IN_SECONDS );
			return sprintf(
				/* translators: %d: number of months */
				_n( '%d month ago', '%d months ago', $months, 'mcp-ai-wpoos-pro' ),
				$months
			);
		}
	}
}
