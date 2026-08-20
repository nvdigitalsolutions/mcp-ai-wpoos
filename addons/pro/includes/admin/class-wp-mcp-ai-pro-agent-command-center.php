<?php
/**
 * Pro Agent Command Center
 *
 * Comprehensive AI agent management dashboard following 2026 industry best practices
 * for agent monitoring, activity logging, approval workflows, analytics,
 * uptime tracking, and strategic insights.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Admin
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Command Center Dashboard Class.
 *
 * Provides a unified dashboard for managing all AI assistants with real-time
 * monitoring, live activity feeds, approval workflows, analytics,
 * uptime/health tracking, and strategic performance insights.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Agent_Command_Center {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-pro-agent-command-center';

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_agent_command_center';

	/**
	 * Activity log option key.
	 *
	 * @var string
	 */
	const ACTIVITY_LOG_OPTION = 'wp_mcp_ai_agent_activity_log';

	/**
	 * Approvals option key.
	 *
	 * @var string
	 */
	const APPROVALS_OPTION = 'wp_mcp_ai_agent_approvals';

	/**
	 * Uptime history option key.
	 *
	 * @var string
	 */
	const UPTIME_OPTION = 'wp_mcp_ai_agent_uptime_history';

	/**
	 * Usage metrics option key.
	 *
	 * @var string
	 */
	const USAGE_METRICS_OPTION = 'wp_mcp_ai_agent_usage_metrics';

	/**
	 * Actual WordPress hook name returned by add_submenu_page().
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 *
	 * @since 2.1.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 27 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wp_mcp_ai_acc_get_dashboard_data', array( $this, 'ajax_get_dashboard_data' ) );
		add_action( 'wp_ajax_wp_mcp_ai_acc_get_activity_log', array( $this, 'ajax_get_activity_log' ) );
		add_action( 'wp_ajax_wp_mcp_ai_acc_handle_approval', array( $this, 'ajax_handle_approval' ) );
		add_action( 'wp_ajax_wp_mcp_ai_acc_get_analytics', array( $this, 'ajax_get_analytics' ) );
		add_action( 'wp_ajax_wp_mcp_ai_acc_get_restrictions', array( $this, 'ajax_get_restrictions' ) );
		add_action( 'wp_ajax_wp_mcp_ai_acc_lift_restriction', array( $this, 'ajax_lift_restriction' ) );

		// Record agent events for activity tracking.
		add_action( 'wp_mcp_ai_after_tool_execution', array( $this, 'record_tool_execution' ), 10, 4 );
		add_action( 'wp_mcp_ai_after_chat_response', array( $this, 'record_chat_response' ), 10, 3 );
		add_action( 'wp_mcp_ai_session_started', array( $this, 'record_session_start' ), 10, 2 );
		add_action( 'wp_mcp_ai_session_ended', array( $this, 'record_session_end' ), 10, 2 );
	}

	/**
	 * Register the admin submenu page.
	 *
	 * Uses priority 27 to appear after workflows and schedule manager.
	 *
	 * @since 2.1.0
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Command Center', 'mcp-ai-wpoos-pro' ),
			__( 'Command Center', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue styles and scripts for the command center page.
	 *
	 * @since 2.1.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$is_command_center = false;

		if ( ! empty( $this->page_hook ) && $hook === $this->page_hook ) {
			$is_command_center = true;
		}

		// Fallback: check GET parameter.
		if ( ! $is_command_center ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug check for asset enqueue.
			$is_command_center = isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'];
		}

		if ( ! $is_command_center ) {
			return;
		}

		// Chart.js for analytics charts.
		wp_enqueue_script(
			'chart-js',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
			array(),
			'4.4.7',
			true
		);

		// Command center styles.
		wp_enqueue_style(
			'wp-mcp-ai-agent-command-center',
			WP_MCP_AI_PRO_URL . 'assets/css/agent-command-center.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Command center scripts.
		wp_enqueue_script(
			'wp-mcp-ai-agent-command-center',
			WP_MCP_AI_PRO_URL . 'assets/js/agent-command-center.js',
			array( 'jquery', 'chart-js' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script data.
		wp_localize_script(
			'wp-mcp-ai-agent-command-center',
			'wpMcpAiCommandCenter',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'refreshInterval' => 10000,
				'strings'         => array(
					'loading'          => __( 'Loading...', 'mcp-ai-wpoos-pro' ),
					'error'            => __( 'Error loading data', 'mcp-ai-wpoos-pro' ),
					'approve'          => __( 'Approve', 'mcp-ai-wpoos-pro' ),
					'reject'           => __( 'Reject', 'mcp-ai-wpoos-pro' ),
					'approved'         => __( 'Approved', 'mcp-ai-wpoos-pro' ),
					'rejected'         => __( 'Rejected', 'mcp-ai-wpoos-pro' ),
					'noAgents'         => __( 'No agents configured yet.', 'mcp-ai-wpoos-pro' ),
					'noActivity'       => __( 'No recent activity.', 'mcp-ai-wpoos-pro' ),
					'noTasks'          => __( 'No active tasks.', 'mcp-ai-wpoos-pro' ),
					'noPending'        => __( 'No pending approvals.', 'mcp-ai-wpoos-pro' ),
					'confirmApprove'   => __( 'Are you sure you want to approve this action?', 'mcp-ai-wpoos-pro' ),
					'confirmReject'    => __( 'Are you sure you want to reject this action?', 'mcp-ai-wpoos-pro' ),
					'operationSuccess' => __( 'Operation completed successfully.', 'mcp-ai-wpoos-pro' ),
					'operationFailed'  => __( 'Operation failed. Please try again.', 'mcp-ai-wpoos-pro' ),
					'online'           => __( 'Online', 'mcp-ai-wpoos-pro' ),
					'offline'          => __( 'Offline', 'mcp-ai-wpoos-pro' ),
					'idle'             => __( 'Idle', 'mcp-ai-wpoos-pro' ),
					'lastActive'       => __( 'Last active', 'mcp-ai-wpoos-pro' ),
					/* translators: %s: time duration like "5 minutes" */
					'timeAgo'          => __( '%s ago', 'mcp-ai-wpoos-pro' ),
					'justNow'          => __( 'Just now', 'mcp-ai-wpoos-pro' ),
					/* translators: %d: number of seconds */
					'seconds'          => __( '%ds', 'mcp-ai-wpoos-pro' ),
					/* translators: %d: number of minutes */
					'minutes'          => __( '%dm', 'mcp-ai-wpoos-pro' ),
					/* translators: %d: number of hours */
					'hours'            => __( '%dh', 'mcp-ai-wpoos-pro' ),
					/* translators: %d: number of days */
					'days'             => __( '%dd', 'mcp-ai-wpoos-pro' ),
					'tokensUsed'       => __( 'Tokens Used', 'mcp-ai-wpoos-pro' ),
					'apiCalls'         => __( 'API Calls', 'mcp-ai-wpoos-pro' ),
					'avgResponseTime'  => __( 'Avg Response', 'mcp-ai-wpoos-pro' ),
					'successRate'      => __( 'Success Rate', 'mcp-ai-wpoos-pro' ),
					'noRestrictions'   => __( 'No users are currently restricted.', 'mcp-ai-wpoos-pro' ),
					'liftRestriction'  => __( 'Lift', 'mcp-ai-wpoos-pro' ),
					'confirmLift'      => __( 'Lift this restriction? The user will immediately regain access to AI features.', 'mcp-ai-wpoos-pro' ),
					'liftFailed'       => __( 'Failed to lift the restriction. Please try again.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the main command center page.
	 *
	 * @since 2.1.0
	 */
	public function render_page() {
		// Valid tabs.
		$valid_tabs = array( 'overview', 'activity', 'tasks', 'approvals', 'analytics', 'uptime', 'strategy', 'restrictions' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation parameter; validated against allowlist.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'overview';
		}
		?>
		<div class="wrap wp-mcp-ai-acc">
			<div class="acc-header">
				<div class="acc-header-left">
					<h1>
						<span class="dashicons dashicons-superhero-alt"></span>
						<?php esc_html_e( 'Command Center', 'mcp-ai-wpoos-pro' ); ?>
						<span class="acc-pro-badge"><?php esc_html_e( 'PRO', 'mcp-ai-wpoos-pro' ); ?></span>
					</h1>
					<p class="acc-subtitle"><?php esc_html_e( 'Unified dashboard for managing, monitoring, and optimizing your AI assistants.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
				<div class="acc-header-right">
					<span class="acc-live-indicator" id="acc-live-indicator">
						<span class="acc-pulse"></span>
						<?php esc_html_e( 'Live', 'mcp-ai-wpoos-pro' ); ?>
					</span>
					<button type="button" class="button acc-refresh-btn" id="acc-refresh-btn" title="<?php esc_attr_e( 'Refresh data', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-update"></span>
					</button>
				</div>
			</div>

			<!-- Tab Navigation -->
			<nav class="nav-tab-wrapper acc-nav-tabs" aria-label="<?php esc_attr_e( 'Command Center tabs', 'mcp-ai-wpoos-pro' ); ?>">
				<?php
				$tabs = array(
					'overview'     => array(
						'icon'  => 'dashicons-dashboard',
						'label' => __( 'Overview', 'mcp-ai-wpoos-pro' ),
					),
					'activity'     => array(
						'icon'  => 'dashicons-list-view',
						'label' => __( 'Activity Log', 'mcp-ai-wpoos-pro' ),
					),
					'tasks'        => array(
						'icon'  => 'dashicons-clipboard',
						'label' => __( 'Active Tasks', 'mcp-ai-wpoos-pro' ),
					),
					'approvals'    => array(
						'icon'  => 'dashicons-yes-alt',
						'label' => __( 'Approvals', 'mcp-ai-wpoos-pro' ),
					),
					'analytics'    => array(
						'icon'  => 'dashicons-chart-area',
						'label' => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
					),
					'uptime'       => array(
						'icon'  => 'dashicons-heart',
						'label' => __( 'Uptime & Health', 'mcp-ai-wpoos-pro' ),
					),
					'strategy'     => array(
						'icon'  => 'dashicons-lightbulb',
						'label' => __( 'Strategy', 'mcp-ai-wpoos-pro' ),
					),
					'restrictions' => array(
						'icon'  => 'dashicons-lock',
						'label' => __( 'Restrictions', 'mcp-ai-wpoos-pro' ),
					),
				);

				$active_restriction_count = 0;
				if ( class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
					$active_restriction_count = WP_MCP_AI_Restriction_Registry::count_active();
				}

				foreach ( $tabs as $slug => $tab ) {
					$url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug );
					$class = 'nav-tab' . ( $current_tab === $slug ? ' nav-tab-active' : '' );
					$badge = '';
					if ( 'restrictions' === $slug && $active_restriction_count > 0 ) {
						$badge = ' <span class="acc-nav-badge" title="' . esc_attr(
							sprintf(
								/* translators: %d: number of active restrictions */
								_n( '%d active restriction', '%d active restrictions', $active_restriction_count, 'mcp-ai-wpoos-pro' ),
								$active_restriction_count
							)
						) . '">' . esc_html( $active_restriction_count ) . '</span>';
					}
					printf(
						'<a href="%s" class="%s"><span class="dashicons %s"></span> %s%s</a>',
						esc_url( $url ),
						esc_attr( $class ),
						esc_attr( $tab['icon'] ),
						esc_html( $tab['label'] ),
						$badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge markup built from escaped parts above.
					);
				}
				?>
			</nav>

			<!-- Tab Content -->
			<div class="acc-tab-content">
				<?php
				switch ( $current_tab ) {
					case 'activity':
						$this->render_activity_tab();
						break;
					case 'tasks':
						$this->render_tasks_tab();
						break;
					case 'approvals':
						$this->render_approvals_tab();
						break;
					case 'analytics':
						$this->render_analytics_tab();
						break;
					case 'uptime':
						$this->render_uptime_tab();
						break;
					case 'strategy':
						$this->render_strategy_tab();
						break;
					case 'restrictions':
						$this->render_restrictions_tab();
						break;
					case 'overview':
					default:
						$this->render_overview_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// TAB: Overview
	// =========================================================================

	/**
	 * Render the overview tab with agent cards and KPIs.
	 *
	 * @since 2.1.0
	 */
	private function render_overview_tab() {
		$assistants    = $this->get_all_assistants();
		$session_data  = $this->get_session_overview();
		$recent_events = $this->get_recent_activity_events( 5 );

		$restriction_count = 0;
		if ( class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			$restriction_count = WP_MCP_AI_Restriction_Registry::count_active();
		}
		?>
		<?php if ( $restriction_count > 0 ) : ?>
			<div class="acc-restrictions-banner notice notice-warning inline">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of active restrictions */
							_n(
								'%d user is currently blocked by rate limits or token budgets.',
								'%d users are currently blocked by rate limits or token budgets.',
								$restriction_count,
								'mcp-ai-wpoos-pro'
							),
							$restriction_count
						)
					);
					?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=restrictions' ) ); ?>">
						<?php esc_html_e( 'Review restrictions', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>
		<!-- KPI Row -->
		<div class="acc-kpi-row" id="acc-kpi-row">
			<div class="acc-kpi-card" data-kpi="total-agents">
				<div class="acc-kpi-icon"><span class="dashicons dashicons-groups"></span></div>
				<div class="acc-kpi-content">
					<div class="acc-kpi-value" id="kpi-total-agents"><?php echo esc_html( count( $assistants ) ); ?></div>
					<div class="acc-kpi-label"><?php esc_html_e( 'Total Agents', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>
			<div class="acc-kpi-card accent-green" data-kpi="agents-online">
				<div class="acc-kpi-icon"><span class="dashicons dashicons-yes-alt"></span></div>
				<div class="acc-kpi-content">
					<div class="acc-kpi-value" id="kpi-agents-online"><?php echo esc_html( $session_data['active_count'] ); ?></div>
					<div class="acc-kpi-label"><?php esc_html_e( 'Agents Online', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>
			<div class="acc-kpi-card accent-blue" data-kpi="active-tasks">
				<div class="acc-kpi-icon"><span class="dashicons dashicons-clipboard"></span></div>
				<div class="acc-kpi-content">
					<div class="acc-kpi-value" id="kpi-active-tasks"><?php echo esc_html( $session_data['task_count'] ); ?></div>
					<div class="acc-kpi-label"><?php esc_html_e( 'Active Tasks', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>
			<div class="acc-kpi-card accent-amber" data-kpi="pending-approvals">
				<div class="acc-kpi-icon"><span class="dashicons dashicons-clock"></span></div>
				<div class="acc-kpi-content">
					<div class="acc-kpi-value" id="kpi-pending-approvals"><?php echo esc_html( $this->get_pending_approval_count() ); ?></div>
					<div class="acc-kpi-label"><?php esc_html_e( 'Pending Approvals', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>
			<div class="acc-kpi-card accent-purple" data-kpi="tokens-today">
				<div class="acc-kpi-icon"><span class="dashicons dashicons-admin-network"></span></div>
				<div class="acc-kpi-content">
					<div class="acc-kpi-value" id="kpi-tokens-today"><?php echo esc_html( $this->format_number( $this->get_tokens_today() ) ); ?></div>
					<div class="acc-kpi-label"><?php esc_html_e( 'Tokens Today', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>
			<div class="acc-kpi-card" data-kpi="uptime">
				<div class="acc-kpi-icon"><span class="dashicons dashicons-heart"></span></div>
				<div class="acc-kpi-content">
					<div class="acc-kpi-value" id="kpi-uptime"><?php echo esc_html( $this->get_system_uptime_pct() ); ?>%</div>
					<div class="acc-kpi-label"><?php esc_html_e( 'System Uptime', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>
		</div>

		<div class="acc-overview-grid">
			<!-- Agents Panel -->
			<div class="acc-panel acc-agents-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Agents', 'mcp-ai-wpoos-pro' ); ?></h2>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Manage All', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
				<div class="acc-agents-grid" id="acc-agents-grid">
					<?php
					if ( empty( $assistants ) ) {
						echo '<div class="acc-empty-state"><span class="dashicons dashicons-admin-users"></span>';
						echo '<p>' . esc_html__( 'No agents configured yet. Create your first assistant to get started.', 'mcp-ai-wpoos-pro' ) . '</p>';
						echo '<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ) . '" class="button button-primary">' . esc_html__( 'Create Agent', 'mcp-ai-wpoos-pro' ) . '</a>';
						echo '</div>';
					} else {
						foreach ( $assistants as $assistant ) {
							$this->render_agent_card( $assistant );
						}
					}
					?>
				</div>
			</div>

			<!-- Live Activity Panel -->
			<div class="acc-panel acc-activity-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-rss"></span> <?php esc_html_e( 'Live Activity', 'mcp-ai-wpoos-pro' ); ?></h2>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=activity' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'View All', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
				<div class="acc-activity-feed" id="acc-live-activity">
					<?php
					if ( empty( $recent_events ) ) {
						echo '<div class="acc-empty-state"><span class="dashicons dashicons-format-aside"></span>';
						echo '<p>' . esc_html__( 'No recent activity. Events will appear here as agents work.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
					} else {
						foreach ( $recent_events as $event ) {
							$this->render_activity_item( $event );
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render an individual agent card.
	 *
	 * @since 2.1.0
	 *
	 * @param array $assistant The assistant data array.
	 */
	private function render_agent_card( $assistant ) {
		$status       = $this->get_agent_status( $assistant['id'] );
		$status_class = 'agent-status-' . $status;
		$provider     = ! empty( $assistant['provider'] ) ? $assistant['provider'] : 'openai';
		$model        = ! empty( $assistant['model'] ) ? $assistant['model'] : __( 'Default', 'mcp-ai-wpoos-pro' );
		$tools_count  = ! empty( $assistant['tools'] ) ? count( $assistant['tools'] ) : 0;
		$last_active  = $this->get_agent_last_active( $assistant['id'] );
		?>
		<div class="acc-agent-card <?php echo esc_attr( $status_class ); ?>" data-agent-id="<?php echo esc_attr( $assistant['id'] ); ?>">
			<div class="acc-agent-status-indicator">
				<span class="acc-status-dot <?php echo esc_attr( $status_class ); ?>" title="<?php echo esc_attr( ucfirst( $status ) ); ?>"></span>
			</div>
			<div class="acc-agent-info">
				<h3 class="acc-agent-name">
					<a href="<?php echo esc_url( get_edit_post_link( $assistant['id'] ) ); ?>">
						<?php echo esc_html( $assistant['title'] ); ?>
					</a>
				</h3>
				<div class="acc-agent-meta">
					<span class="acc-agent-provider" title="<?php esc_attr_e( 'Provider', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-cloud"></span> <?php echo esc_html( ucfirst( $provider ) ); ?>
					</span>
					<span class="acc-agent-model" title="<?php esc_attr_e( 'Model', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span> <?php echo esc_html( $model ); ?>
					</span>
					<span class="acc-agent-tools" title="<?php esc_attr_e( 'Tools', 'mcp-ai-wpoos-pro' ); ?>">
						<span class="dashicons dashicons-admin-tools"></span> <?php echo esc_html( $tools_count ); ?>
					</span>
				</div>
				<?php if ( $last_active ) : ?>
					<div class="acc-agent-last-active">
						<span class="dashicons dashicons-clock"></span>
						<?php
						/* translators: %s: human-readable time ago string */
						printf( esc_html__( 'Last active %s ago', 'mcp-ai-wpoos-pro' ), esc_html( human_time_diff( $last_active ) ) );
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// TAB: Activity Log
	// =========================================================================

	/**
	 * Render the activity log tab.
	 *
	 * @since 2.1.0
	 */
	private function render_activity_tab() {
		?>
		<div class="acc-activity-tab">
			<!-- Filters -->
			<div class="acc-filters">
				<div class="acc-filter-group">
					<label for="acc-filter-type"><?php esc_html_e( 'Event Type:', 'mcp-ai-wpoos-pro' ); ?></label>
					<select id="acc-filter-type" class="acc-filter">
						<option value=""><?php esc_html_e( 'All Events', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="tool_execution"><?php esc_html_e( 'Tool Executions', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="tool_error"><?php esc_html_e( 'Tool Errors', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="chat_response"><?php esc_html_e( 'Chat Responses', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="chat_interaction"><?php esc_html_e( 'Chat Interactions', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="api_request"><?php esc_html_e( 'API Requests', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="api_response"><?php esc_html_e( 'API Responses', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="schedule_run"><?php esc_html_e( 'Schedule Runs', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="session_start"><?php esc_html_e( 'Session Start', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="session_end"><?php esc_html_e( 'Session End', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="approval_requested"><?php esc_html_e( 'Approval Requested', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="approval_resolved"><?php esc_html_e( 'Approval Resolved', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="error"><?php esc_html_e( 'Errors', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</div>
				<div class="acc-filter-group">
					<label for="acc-filter-agent"><?php esc_html_e( 'Agent:', 'mcp-ai-wpoos-pro' ); ?></label>
					<select id="acc-filter-agent" class="acc-filter">
						<option value=""><?php esc_html_e( 'All Agents', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php
						$assistants = $this->get_all_assistants();
						foreach ( $assistants as $a ) {
							printf(
								'<option value="%s">%s</option>',
								esc_attr( $a['id'] ),
								esc_html( $a['title'] )
							);
						}
						?>
					</select>
				</div>
				<div class="acc-filter-group">
					<label for="acc-filter-timeframe"><?php esc_html_e( 'Timeframe:', 'mcp-ai-wpoos-pro' ); ?></label>
					<select id="acc-filter-timeframe" class="acc-filter">
						<option value="1h"><?php esc_html_e( 'Last Hour', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="24h" selected><?php esc_html_e( 'Last 24 Hours', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="7d"><?php esc_html_e( 'Last 7 Days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="30d"><?php esc_html_e( 'Last 30 Days', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</div>
				<div class="acc-filter-group">
					<input type="text" id="acc-filter-search" class="acc-filter" placeholder="<?php esc_attr_e( 'Search activity...', 'mcp-ai-wpoos-pro' ); ?>" />
				</div>
			</div>

			<!-- Activity Summary -->
			<div class="acc-activity-summary" id="acc-activity-summary">
				<div class="acc-summary-stat">
					<span class="acc-summary-value" id="activity-total-events">0</span>
					<span class="acc-summary-label"><?php esc_html_e( 'Total Events', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-summary-stat">
					<span class="acc-summary-value" id="activity-tool-calls">0</span>
					<span class="acc-summary-label"><?php esc_html_e( 'Tool Calls', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-summary-stat">
					<span class="acc-summary-value" id="activity-chat-responses">0</span>
					<span class="acc-summary-label"><?php esc_html_e( 'Chat Responses', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-summary-stat">
					<span class="acc-summary-value" id="activity-chat-interactions">0</span>
					<span class="acc-summary-label"><?php esc_html_e( 'Chat Interactions', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-summary-stat">
					<span class="acc-summary-value" id="activity-api-requests">0</span>
					<span class="acc-summary-label"><?php esc_html_e( 'API Requests', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-summary-stat">
					<span class="acc-summary-value" id="activity-schedule-runs">0</span>
					<span class="acc-summary-label"><?php esc_html_e( 'Schedule Runs', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-summary-stat">
					<span class="acc-summary-value" id="activity-errors">0</span>
					<span class="acc-summary-label"><?php esc_html_e( 'Errors', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
			</div>

			<!-- Activity Stream -->
			<div class="acc-activity-stream" id="acc-activity-stream">
				<div class="acc-loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading activity log...', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single activity feed item.
	 *
	 * @since 2.1.0
	 *
	 * @param array $event The event data.
	 */
	private function render_activity_item( $event ) {
		$type_icons = array(
			'tool_execution'     => 'admin-tools',
			'tool_error'         => 'warning',
			'chat_response'      => 'format-chat',
			'chat_interaction'   => 'admin-comments',
			'api_request'        => 'cloud',
			'api_response'       => 'cloud',
			'schedule_run'       => 'calendar-alt',
			'session_start'      => 'migrate',
			'session_end'        => 'dismiss',
			'approval_requested' => 'clock',
			'approval_resolved'  => 'yes-alt',
			'error'              => 'warning',
			'system'             => 'info',
		);

		$type       = isset( $event['type'] ) ? $event['type'] : 'system';
		$icon       = isset( $type_icons[ $type ] ) ? $type_icons[ $type ] : 'info';
		$timestamp  = isset( $event['timestamp'] ) ? $event['timestamp'] : time();
		$agent_name = isset( $event['agent_name'] ) ? $event['agent_name'] : '';
		$message    = isset( $event['message'] ) ? $event['message'] : '';
		?>
		<div class="acc-activity-item acc-event-<?php echo esc_attr( $type ); ?>" data-timestamp="<?php echo esc_attr( $timestamp ); ?>">
			<div class="acc-activity-icon">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
			</div>
			<div class="acc-activity-body">
				<?php if ( $agent_name ) : ?>
					<span class="acc-activity-agent"><?php echo esc_html( $agent_name ); ?></span>
				<?php endif; ?>
				<span class="acc-activity-message"><?php echo esc_html( $message ); ?></span>
			</div>
			<div class="acc-activity-time" title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $timestamp ) ); ?>">
				<?php echo esc_html( human_time_diff( $timestamp ) ); ?>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// TAB: Active Tasks
	// =========================================================================

	/**
	 * Render the active tasks tab.
	 *
	 * @since 2.1.0
	 */
	private function render_tasks_tab() {
		$sessions   = $this->get_active_sessions();
		$workflows  = $this->get_active_workflows();
		$task_plans = $this->get_task_plans();
		?>
		<div class="acc-tasks-tab">
			<!-- Task Plans Section -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Task Plans', 'mcp-ai-wpoos-pro' ); ?></h2>
					<span class="acc-badge"><?php echo esc_html( count( $task_plans ) ); ?></span>
				</div>
				<?php if ( empty( $task_plans ) ) : ?>
					<div class="acc-empty-state">
						<span class="dashicons dashicons-clipboard"></span>
						<p><?php esc_html_e( 'No task plans found.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php else : ?>
					<div class="acc-tasks-table-wrapper">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Plan', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Goal', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Progress', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Tasks', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Author', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Created', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="acc-task-plans-tbody">
								<?php foreach ( $task_plans as $plan ) : ?>
									<?php $this->render_task_plan_row( $plan ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<!-- Active Sessions Section -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-performance"></span> <?php esc_html_e( 'Active Sessions', 'mcp-ai-wpoos-pro' ); ?></h2>
					<span class="acc-badge"><?php echo esc_html( count( $sessions ) ); ?></span>
				</div>
				<?php if ( empty( $sessions ) ) : ?>
					<div class="acc-empty-state">
						<span class="dashicons dashicons-performance"></span>
						<p><?php esc_html_e( 'No active sessions running.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php else : ?>
					<div class="acc-tasks-table-wrapper">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Session', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Agent', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Progress', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Health', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="acc-sessions-tbody">
								<?php foreach ( $sessions as $session ) : ?>
									<?php $this->render_session_row( $session ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<!-- Active Workflows Section -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Active Workflows', 'mcp-ai-wpoos-pro' ); ?></h2>
					<span class="acc-badge"><?php echo esc_html( count( $workflows ) ); ?></span>
				</div>
				<?php if ( empty( $workflows ) ) : ?>
					<div class="acc-empty-state">
						<span class="dashicons dashicons-randomize"></span>
						<p><?php esc_html_e( 'No active workflows running.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php else : ?>
					<div class="acc-tasks-table-wrapper">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Workflow', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'State', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Tasks', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Created', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Age', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="acc-workflows-tbody">
								<?php foreach ( $workflows as $wf ) : ?>
									<?php $this->render_workflow_row( $wf ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a session table row.
	 *
	 * @since 2.1.0
	 *
	 * @param array $session The session data.
	 */
	private function render_session_row( $session ) {
		$progress   = 0;
		$iterations = isset( $session['iterations'] ) ? (int) $session['iterations'] : 0;
		$max        = isset( $session['max_iterations'] ) ? (int) $session['max_iterations'] : 25;
		$progress   = $max > 0 ? min( 100, round( ( $iterations / $max ) * 100 ) ) : 0;
		$tokens     = isset( $session['tokens_used'] ) ? (int) $session['tokens_used'] : 0;
		$start_time = isset( $session['start_time'] ) ? (int) $session['start_time'] : time();
		$elapsed    = time() - $start_time;
		$status     = isset( $session['status'] ) ? $session['status'] : 'unknown';
		$health     = isset( $session['health'] ) ? $session['health'] : 'unknown';
		$session_id = isset( $session['session_id'] ) ? $session['session_id'] : '';
		?>
		<tr>
			<td><code><?php echo esc_html( substr( $session_id, 0, 12 ) ); ?></code></td>
			<td><?php echo esc_html( isset( $session['agent_name'] ) ? $session['agent_name'] : '—' ); ?></td>
			<td><span class="acc-status-badge status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
			<td>
				<div class="acc-progress-bar">
					<div class="acc-progress-fill" style="width: <?php echo esc_attr( $progress ); ?>%"></div>
				</div>
				<span class="acc-progress-text"><?php echo esc_html( $iterations . '/' . $max ); ?></span>
			</td>
			<td><?php echo esc_html( $this->format_number( $tokens ) ); ?></td>
			<td><?php echo esc_html( $this->format_duration( $elapsed ) ); ?></td>
			<td><span class="acc-health-badge health-<?php echo esc_attr( $health ); ?>"><?php echo esc_html( ucfirst( $health ) ); ?></span></td>
		</tr>
		<?php
	}

	/**
	 * Render a task plan table row.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_Post $plan The task plan post object.
	 */
	private function render_task_plan_row( $plan ) {
		$goal            = get_post_meta( $plan->ID, '_goal', true );
		$status          = get_post_meta( $plan->ID, '_status', true );
		$task_count      = (int) get_post_meta( $plan->ID, '_task_count', true );
		$completed_count = (int) get_post_meta( $plan->ID, '_completed_count', true );
		$progress        = (int) get_post_meta( $plan->ID, '_progress', true );
		$author          = get_the_author_meta( 'display_name', $plan->post_author );

		if ( ! $status ) {
			$status = 'draft';
		}
		?>
		<tr>
			<td>
				<a href="<?php echo esc_url( get_edit_post_link( $plan->ID ) ); ?>">
					<?php echo esc_html( $plan->post_title ); ?>
				</a>
			</td>
			<td><?php echo esc_html( $goal ? wp_trim_words( $goal, 10 ) : '—' ); ?></td>
			<td><span class="acc-status-badge status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
			<td>
				<div class="acc-progress-bar">
					<div class="acc-progress-fill" style="width: <?php echo esc_attr( $progress ); ?>%"></div>
				</div>
				<span class="acc-progress-text"><?php echo esc_html( $progress . '%' ); ?></span>
			</td>
			<td><?php echo esc_html( $completed_count . '/' . $task_count ); ?></td>
			<td><?php echo esc_html( $author ); ?></td>
			<td><?php echo esc_html( get_the_date( '', $plan ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render a workflow table row.
	 *
	 * @since 2.1.0
	 *
	 * @param array $wf The workflow data.
	 */
	private function render_workflow_row( $wf ) {
		$state       = isset( $wf['state'] ) ? $wf['state'] : 'unknown';
		$tasks       = isset( $wf['tasks'] ) ? $wf['tasks'] : array();
		$task_count  = count( $tasks );
		$created     = isset( $wf['created_at'] ) ? $wf['created_at'] : '';
		$workflow_id = isset( $wf['workflow_id'] ) ? $wf['workflow_id'] : '';
		$task_type   = isset( $wf['task_type'] ) ? $wf['task_type'] : '';

		$age = '';
		if ( $created ) {
			$created_ts = strtotime( $created );
			if ( $created_ts ) {
				$age = human_time_diff( $created_ts );
			}
		}
		?>
		<tr>
			<td><code><?php echo esc_html( substr( $workflow_id, 0, 12 ) ); ?></code></td>
			<td><?php echo esc_html( $task_type ); ?></td>
			<td><span class="acc-status-badge status-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( ucfirst( $state ) ); ?></span></td>
			<td><?php echo esc_html( $task_count ); ?></td>
			<td><?php echo esc_html( $created ); ?></td>
			<td><?php echo esc_html( $age ); ?></td>
		</tr>
		<?php
	}

	// =========================================================================
	// TAB: Approvals
	// =========================================================================

	/**
	 * Render the approvals tab.
	 *
	 * @since 2.1.0
	 */
	private function render_approvals_tab() {
		$approvals = $this->get_all_approvals();
		$pending   = array_filter(
			$approvals,
			function ( $a ) {
				return 'pending' === $a['status'];
			}
		);
		$resolved  = array_filter(
			$approvals,
			function ( $a ) {
				return 'pending' !== $a['status'];
			}
		);
		?>
		<div class="acc-approvals-tab">
			<!-- Pending Approvals -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2>
						<span class="dashicons dashicons-clock"></span>
						<?php esc_html_e( 'Pending Approvals', 'mcp-ai-wpoos-pro' ); ?>
						<?php if ( count( $pending ) > 0 ) : ?>
							<span class="acc-badge accent-amber"><?php echo esc_html( count( $pending ) ); ?></span>
						<?php endif; ?>
					</h2>
				</div>
				<?php if ( empty( $pending ) ) : ?>
					<div class="acc-empty-state">
						<span class="dashicons dashicons-yes-alt"></span>
						<p><?php esc_html_e( 'No pending approvals. All agent actions are cleared.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php else : ?>
					<div class="acc-approvals-list" id="acc-pending-approvals">
						<?php foreach ( $pending as $approval ) : ?>
							<?php $this->render_approval_card( $approval ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Approval History -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Approval History', 'mcp-ai-wpoos-pro' ); ?></h2>
				</div>
				<?php if ( empty( $resolved ) ) : ?>
					<div class="acc-empty-state">
						<span class="dashicons dashicons-backup"></span>
						<p><?php esc_html_e( 'No approval history yet.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php else : ?>
					<div class="acc-approvals-history-wrapper">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Action', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Agent', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Decision', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Decided By', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Reason', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_slice( array_reverse( $resolved ), 0, 50 ) as $a ) : ?>
									<tr>
										<td><?php echo esc_html( $a['action_label'] ); ?></td>
										<td><?php echo esc_html( isset( $a['agent_name'] ) ? $a['agent_name'] : '—' ); ?></td>
										<td>
											<span class="acc-status-badge status-<?php echo esc_attr( $a['status'] ); ?>">
												<?php echo esc_html( ucfirst( $a['status'] ) ); ?>
											</span>
										</td>
										<td><?php echo esc_html( isset( $a['decided_by'] ) ? $a['decided_by'] : '—' ); ?></td>
										<td><?php echo esc_html( isset( $a['decided_at'] ) ? wp_date( 'M j, Y g:i a', $a['decided_at'] ) : '—' ); ?></td>
										<td><?php echo esc_html( isset( $a['reason'] ) ? $a['reason'] : '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single approval card.
	 *
	 * @since 2.1.0
	 *
	 * @param array $approval The approval data.
	 */
	private function render_approval_card( $approval ) {
		$id         = isset( $approval['id'] ) ? $approval['id'] : '';
		$action     = isset( $approval['action_label'] ) ? $approval['action_label'] : __( 'Unknown Action', 'mcp-ai-wpoos-pro' );
		$agent_name = isset( $approval['agent_name'] ) ? $approval['agent_name'] : '';
		$details    = isset( $approval['details'] ) ? $approval['details'] : '';
		$severity   = isset( $approval['severity'] ) ? $approval['severity'] : 'normal';
		$requested  = isset( $approval['requested_at'] ) ? $approval['requested_at'] : time();
		?>
		<div class="acc-approval-card severity-<?php echo esc_attr( $severity ); ?>" data-approval-id="<?php echo esc_attr( $id ); ?>">
			<div class="acc-approval-header">
				<span class="acc-approval-severity severity-<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( ucfirst( $severity ) ); ?></span>
				<span class="acc-approval-time">
					<?php
					/* translators: %s: human-readable time ago string */
					printf( esc_html__( '%s ago', 'mcp-ai-wpoos-pro' ), esc_html( human_time_diff( $requested ) ) );
					?>
				</span>
			</div>
			<div class="acc-approval-body">
				<h3><?php echo esc_html( $action ); ?></h3>
				<?php if ( $agent_name ) : ?>
					<p class="acc-approval-agent">
						<span class="dashicons dashicons-admin-users"></span> <?php echo esc_html( $agent_name ); ?>
					</p>
				<?php endif; ?>
				<?php if ( $details ) : ?>
					<p class="acc-approval-details"><?php echo esc_html( $details ); ?></p>
				<?php endif; ?>
			</div>
			<div class="acc-approval-actions">
				<button type="button" class="button button-primary acc-approval-btn" data-action="approve" data-id="<?php echo esc_attr( $id ); ?>">
					<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Approve', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<button type="button" class="button acc-approval-btn" data-action="reject" data-id="<?php echo esc_attr( $id ); ?>">
					<span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Reject', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// TAB: Analytics
	// =========================================================================

	/**
	 * Render the analytics tab.
	 *
	 * @since 2.1.0
	 */
	private function render_analytics_tab() {
		?>
		<div class="acc-analytics-tab">
			<!-- Timeframe Controls -->
			<div class="acc-analytics-controls">
				<div class="acc-filter-group">
					<label for="acc-analytics-range"><?php esc_html_e( 'Time Range:', 'mcp-ai-wpoos-pro' ); ?></label>
					<select id="acc-analytics-range">
						<option value="24h"><?php esc_html_e( 'Last 24 Hours', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="7d" selected><?php esc_html_e( 'Last 7 Days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="30d"><?php esc_html_e( 'Last 30 Days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="90d"><?php esc_html_e( 'Last 90 Days', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</div>
			</div>

			<!-- Analytics KPIs -->
			<div class="acc-analytics-kpis" id="acc-analytics-kpis">
				<div class="acc-analytics-kpi">
					<span class="acc-analytics-kpi-value" id="analytics-total-tokens">0</span>
					<span class="acc-analytics-kpi-label"><?php esc_html_e( 'Total Tokens', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-analytics-kpi">
					<span class="acc-analytics-kpi-value" id="analytics-total-calls">0</span>
					<span class="acc-analytics-kpi-label"><?php esc_html_e( 'API Calls', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-analytics-kpi">
					<span class="acc-analytics-kpi-value" id="analytics-avg-response">0ms</span>
					<span class="acc-analytics-kpi-label"><?php esc_html_e( 'Avg Response Time', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="acc-analytics-kpi">
					<span class="acc-analytics-kpi-value" id="analytics-success-rate">0%</span>
					<span class="acc-analytics-kpi-label"><?php esc_html_e( 'Success Rate', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
			</div>

			<!-- Charts Grid -->
			<div class="acc-charts-grid">
				<div class="acc-chart-panel">
					<h3><?php esc_html_e( 'Usage Timeline', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="acc-chart-wrapper" style="height: 300px;">
						<canvas id="acc-chart-usage-timeline"></canvas>
					</div>
				</div>
				<div class="acc-chart-panel">
					<h3><?php esc_html_e( 'Token Consumption by Agent', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="acc-chart-wrapper" style="height: 300px;">
						<canvas id="acc-chart-tokens-by-agent"></canvas>
					</div>
				</div>
				<div class="acc-chart-panel">
					<h3><?php esc_html_e( 'Tool Usage Distribution', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="acc-chart-wrapper" style="height: 300px;">
						<canvas id="acc-chart-tool-distribution"></canvas>
					</div>
				</div>
				<div class="acc-chart-panel">
					<h3><?php esc_html_e( 'Response Time Trends', 'mcp-ai-wpoos-pro' ); ?></h3>
					<div class="acc-chart-wrapper" style="height: 300px;">
						<canvas id="acc-chart-response-times"></canvas>
					</div>
				</div>
			</div>

			<!-- Agent Performance Table -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Agent Performance', 'mcp-ai-wpoos-pro' ); ?></h2>
				</div>
				<div class="acc-performance-table-wrapper">
					<table class="widefat striped" id="acc-agent-performance-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Agent', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Sessions', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Tokens Used', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Tool Calls', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Avg Response', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Success Rate', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody id="acc-performance-tbody">
							<tr><td colspan="7" class="acc-loading"><?php esc_html_e( 'Loading performance data...', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// TAB: Uptime & Health
	// =========================================================================

	/**
	 * Render the uptime & health tab.
	 *
	 * @since 2.1.0
	 */
	private function render_uptime_tab() {
		$health = $this->get_system_health();
		$uptime = $this->get_uptime_history();
		?>
		<div class="acc-uptime-tab">
			<!-- System Health Overview -->
			<div class="acc-health-overview">
				<div class="acc-health-card <?php echo esc_attr( 'health-' . $health['overall_status'] ); ?>">
					<div class="acc-health-icon">
						<span class="dashicons dashicons-<?php echo esc_attr( $health['icon'] ); ?>"></span>
					</div>
					<div class="acc-health-details">
						<h2><?php echo esc_html( $health['label'] ); ?></h2>
						<p><?php echo esc_html( $health['description'] ); ?></p>
					</div>
				</div>
			</div>

			<!-- Health Metrics Grid -->
			<div class="acc-health-metrics" id="acc-health-metrics">
				<div class="acc-health-metric">
					<div class="acc-health-metric-header">
						<span class="dashicons dashicons-schedule"></span>
						<h3><?php esc_html_e( 'Cron Health', 'mcp-ai-wpoos-pro' ); ?></h3>
					</div>
					<div class="acc-health-metric-value" id="health-cron-status">
						<?php echo esc_html( $health['cron_status'] ); ?>
					</div>
					<div class="acc-health-metric-detail"><?php esc_html_e( 'Scheduled task execution', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div class="acc-health-metric">
					<div class="acc-health-metric-header">
						<span class="dashicons dashicons-database"></span>
						<h3><?php esc_html_e( 'Database', 'mcp-ai-wpoos-pro' ); ?></h3>
					</div>
					<div class="acc-health-metric-value" id="health-db-status">
						<?php echo esc_html( $health['db_status'] ); ?>
					</div>
					<div class="acc-health-metric-detail"><?php esc_html_e( 'Database connectivity', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div class="acc-health-metric">
					<div class="acc-health-metric-header">
						<span class="dashicons dashicons-rest-api"></span>
						<h3><?php esc_html_e( 'REST API', 'mcp-ai-wpoos-pro' ); ?></h3>
					</div>
					<div class="acc-health-metric-value" id="health-api-status">
						<?php echo esc_html( $health['api_status'] ); ?>
					</div>
					<div class="acc-health-metric-detail"><?php esc_html_e( 'API endpoint availability', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div class="acc-health-metric">
					<div class="acc-health-metric-header">
						<span class="dashicons dashicons-cloud"></span>
						<h3><?php esc_html_e( 'SSE Streaming', 'mcp-ai-wpoos-pro' ); ?></h3>
					</div>
					<div class="acc-health-metric-value" id="health-sse-status">
						<?php echo esc_html( $health['sse_status'] ); ?>
					</div>
					<div class="acc-health-metric-detail"><?php esc_html_e( 'Real-time streaming', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>

			<!-- Uptime Timeline Chart -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-chart-line"></span> <?php esc_html_e( 'Uptime Timeline (30 Days)', 'mcp-ai-wpoos-pro' ); ?></h2>
				</div>
				<div class="acc-uptime-timeline" id="acc-uptime-timeline">
					<div class="acc-chart-wrapper" style="height: 200px;">
						<canvas id="acc-chart-uptime"></canvas>
					</div>
				</div>
			</div>

			<!-- Restart / Event History -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Recent Events & Restarts', 'mcp-ai-wpoos-pro' ); ?></h2>
				</div>
				<?php if ( empty( $uptime ) ) : ?>
					<div class="acc-empty-state">
						<span class="dashicons dashicons-backup"></span>
						<p><?php esc_html_e( 'No uptime events recorded yet. Events will be tracked automatically.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php else : ?>
					<div class="acc-event-timeline">
						<?php foreach ( array_slice( array_reverse( $uptime ), 0, 20 ) as $event ) : ?>
							<div class="acc-timeline-item <?php echo esc_attr( 'event-' . $event['type'] ); ?>">
								<div class="acc-timeline-dot"></div>
								<div class="acc-timeline-content">
									<span class="acc-timeline-time"><?php echo esc_html( wp_date( 'M j, Y g:i a', $event['timestamp'] ) ); ?></span>
									<span class="acc-timeline-message"><?php echo esc_html( $event['message'] ); ?></span>
									<?php if ( ! empty( $event['duration'] ) ) : ?>
										<span class="acc-timeline-duration">
											<?php
											/* translators: %s: duration string */
											printf( esc_html__( 'Duration: %s', 'mcp-ai-wpoos-pro' ), esc_html( $this->format_duration( $event['duration'] ) ) );
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// TAB: Strategy
	// =========================================================================

	/**
	 * Render the strategy tab with performance insights and recommendations.
	 *
	 * @since 2.1.0
	 */
	private function render_strategy_tab() {
		$insights        = $this->generate_strategy_insights();
		$recommendations = $this->generate_recommendations();
		?>
		<div class="acc-strategy-tab">
			<!-- Strategy Score -->
			<div class="acc-strategy-score-panel">
				<div class="acc-strategy-score">
					<div class="acc-score-circle" id="acc-strategy-score">
						<span class="acc-score-value"><?php echo esc_html( $insights['overall_score'] ); ?></span>
						<span class="acc-score-label"><?php esc_html_e( 'Agent Efficiency Score', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>
				<div class="acc-score-breakdown">
					<div class="acc-score-item">
						<span class="acc-score-category"><?php esc_html_e( 'Utilization', 'mcp-ai-wpoos-pro' ); ?></span>
						<div class="acc-score-bar"><div class="acc-score-fill" style="width: <?php echo esc_attr( $insights['utilization_score'] ); ?>%"></div></div>
						<span class="acc-score-pct"><?php echo esc_html( $insights['utilization_score'] ); ?>%</span>
					</div>
					<div class="acc-score-item">
						<span class="acc-score-category"><?php esc_html_e( 'Reliability', 'mcp-ai-wpoos-pro' ); ?></span>
						<div class="acc-score-bar"><div class="acc-score-fill" style="width: <?php echo esc_attr( $insights['reliability_score'] ); ?>%"></div></div>
						<span class="acc-score-pct"><?php echo esc_html( $insights['reliability_score'] ); ?>%</span>
					</div>
					<div class="acc-score-item">
						<span class="acc-score-category"><?php esc_html_e( 'Efficiency', 'mcp-ai-wpoos-pro' ); ?></span>
						<div class="acc-score-bar"><div class="acc-score-fill" style="width: <?php echo esc_attr( $insights['efficiency_score'] ); ?>%"></div></div>
						<span class="acc-score-pct"><?php echo esc_html( $insights['efficiency_score'] ); ?>%</span>
					</div>
					<div class="acc-score-item">
						<span class="acc-score-category"><?php esc_html_e( 'Coverage', 'mcp-ai-wpoos-pro' ); ?></span>
						<div class="acc-score-bar"><div class="acc-score-fill" style="width: <?php echo esc_attr( $insights['coverage_score'] ); ?>%"></div></div>
						<span class="acc-score-pct"><?php echo esc_html( $insights['coverage_score'] ); ?>%</span>
					</div>
				</div>
			</div>

			<!-- Recommendations -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'Strategic Recommendations', 'mcp-ai-wpoos-pro' ); ?></h2>
				</div>
				<?php if ( empty( $recommendations ) ) : ?>
					<div class="acc-empty-state">
						<span class="dashicons dashicons-lightbulb"></span>
						<p><?php esc_html_e( 'Great job! No strategic improvements needed at this time.', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>
				<?php else : ?>
					<div class="acc-recommendations-list">
						<?php foreach ( $recommendations as $rec ) : ?>
							<div class="acc-recommendation priority-<?php echo esc_attr( $rec['priority'] ); ?>">
								<div class="acc-rec-icon">
									<span class="dashicons dashicons-<?php echo esc_attr( $rec['icon'] ); ?>"></span>
								</div>
								<div class="acc-rec-content">
									<h4><?php echo esc_html( $rec['title'] ); ?></h4>
									<p><?php echo esc_html( $rec['description'] ); ?></p>
								</div>
								<div class="acc-rec-priority">
									<span class="acc-priority-badge priority-<?php echo esc_attr( $rec['priority'] ); ?>">
										<?php echo esc_html( ucfirst( $rec['priority'] ) ); ?>
									</span>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Usage Scale Insights -->
			<div class="acc-panel">
				<div class="acc-panel-header">
					<h2><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e( 'Usage Scale & Capacity', 'mcp-ai-wpoos-pro' ); ?></h2>
				</div>
				<div class="acc-scale-metrics" id="acc-scale-metrics">
					<div class="acc-scale-item">
						<h4><?php esc_html_e( 'Current Load', 'mcp-ai-wpoos-pro' ); ?></h4>
						<div class="acc-scale-gauge" id="gauge-current-load">
							<div class="acc-chart-wrapper" style="height: 120px;">
								<canvas id="acc-chart-load-gauge"></canvas>
							</div>
						</div>
					</div>
					<div class="acc-scale-item">
						<h4><?php esc_html_e( 'Peak Usage (7d)', 'mcp-ai-wpoos-pro' ); ?></h4>
						<div class="acc-chart-wrapper" style="height: 200px;">
							<canvas id="acc-chart-peak-usage"></canvas>
						</div>
					</div>
					<div class="acc-scale-item">
						<h4><?php esc_html_e( 'Growth Trend', 'mcp-ai-wpoos-pro' ); ?></h4>
						<div class="acc-chart-wrapper" style="height: 200px;">
							<canvas id="acc-chart-growth-trend"></canvas>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX Handlers
	// =========================================================================

	/**
	 * AJAX handler: Get dashboard data for real-time updates.
	 *
	 * @since 2.1.0
	 */
	public function ajax_get_dashboard_data() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$session_data = $this->get_session_overview();
		$assistants   = $this->get_all_assistants();

		$agent_statuses = array();
		foreach ( $assistants as $a ) {
			$agent_statuses[ $a['id'] ] = array(
				'status'      => $this->get_agent_status( $a['id'] ),
				'last_active' => $this->get_agent_last_active( $a['id'] ),
			);
		}

		wp_send_json_success(
			array(
				'kpis'           => array(
					'total_agents'      => count( $assistants ),
					'agents_online'     => $session_data['active_count'],
					'active_tasks'      => $session_data['task_count'],
					'pending_approvals' => $this->get_pending_approval_count(),
					'tokens_today'      => $this->format_number( $this->get_tokens_today() ),
					'uptime'            => $this->get_system_uptime_pct() . '%',
				),
				'agent_statuses' => $agent_statuses,
				'recent_events'  => $this->get_recent_activity_events( 10 ),
			)
		);
	}

	/**
	 * AJAX handler: Get activity log with filters.
	 *
	 * @since 2.1.0
	 */
	public function ajax_get_activity_log() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$type      = isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : '';
		$agent_id  = isset( $_POST['agent_id'] ) ? absint( $_POST['agent_id'] ) : 0;
		$timeframe = isset( $_POST['timeframe'] ) ? sanitize_key( wp_unslash( $_POST['timeframe'] ) ) : '24h';
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$events = $this->get_filtered_activity( $type, $agent_id, $timeframe, $search );

		// Calculate summary stats.
		$summary = array(
			'total'             => count( $events ),
			'tool_calls'        => count(
				array_filter(
					$events,
					function ( $e ) {
						return 'tool_execution' === $e['type'];
					}
				)
			),
			'chat_responses'    => count(
				array_filter(
					$events,
					function ( $e ) {
						return 'chat_response' === $e['type'];
					}
				)
			),
			'chat_interactions' => count(
				array_filter(
					$events,
					function ( $e ) {
						return 'chat_interaction' === $e['type'];
					}
				)
			),
			'api_requests'      => count(
				array_filter(
					$events,
					function ( $e ) {
						return 'api_request' === $e['type'] || 'api_response' === $e['type'];
					}
				)
			),
			'schedule_runs'     => count(
				array_filter(
					$events,
					function ( $e ) {
						return 'schedule_run' === $e['type'];
					}
				)
			),
			'errors'            => count(
				array_filter(
					$events,
					function ( $e ) {
						return 'error' === $e['type'] || 'tool_error' === $e['type'];
					}
				)
			),
		);

		wp_send_json_success(
			array(
				'events'  => array_slice( $events, 0, 200 ),
				'summary' => $summary,
			)
		);
	}

	/**
	 * AJAX handler: Handle approval decision.
	 *
	 * @since 2.1.0
	 */
	public function ajax_handle_approval() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$approval_id = isset( $_POST['approval_id'] ) ? sanitize_text_field( wp_unslash( $_POST['approval_id'] ) ) : '';
		$decision    = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason      = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( empty( $approval_id ) || ! in_array( $decision, array( 'approved', 'rejected' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request parameters.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$approvals = get_option( self::APPROVALS_OPTION, array() );
		$found     = false;

		foreach ( $approvals as &$approval ) {
			if ( $approval['id'] === $approval_id && 'pending' === $approval['status'] ) {
				$current_user           = wp_get_current_user();
				$approval['status']     = $decision;
				$approval['decided_by'] = $current_user->display_name;
				$approval['decided_at'] = time();
				$approval['reason']     = $reason;
				$found                  = true;

				// Log the approval decision.
				$this->log_activity(
					'approval_resolved',
					0,
					'',
					/* translators: 1: action label, 2: approval decision */
					sprintf( __( 'Approval %1$s: %2$s', 'mcp-ai-wpoos-pro' ), $decision, $approval['action_label'] )
				);

				/**
				 * Fires when an agent approval decision is made.
				 *
				 * @since 2.1.0
				 *
				 * @param array  $approval The approval data.
				 * @param string $decision The decision: 'approved' or 'rejected'.
				 */
				do_action( 'wp_mcp_ai_approval_decided', $approval, $decision );
				break;
			}
		}
		unset( $approval );

		if ( ! $found ) {
			wp_send_json_error( array( 'message' => __( 'Approval not found or already resolved.', 'mcp-ai-wpoos-pro' ) ) );
		}

		update_option( self::APPROVALS_OPTION, $approvals );

		wp_send_json_success( array( 'message' => __( 'Approval decision recorded.', 'mcp-ai-wpoos-pro' ) ) );
	}

	/**
	 * AJAX handler: Get analytics data.
	 *
	 * @since 2.1.0
	 */
	public function ajax_get_analytics() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$range = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '7d';

		wp_send_json_success( $this->get_analytics_data( $range ) );
	}

	/**
	 * AJAX handler: Get active restrictions.
	 *
	 * @since 2.1.0
	 */
	public function ajax_get_restrictions() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			wp_send_json_success(
				array(
					'rows'  => array(),
					'total' => 0,
				)
			);
			return;
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		wp_send_json_success(
			WP_MCP_AI_Restriction_Registry::get_active(
				array(
					'type'     => $type,
					'per_page' => 100,
					'page'     => 1,
				)
			)
		);
	}

	/**
	 * AJAX handler: Lift a restriction.
	 *
	 * @since 2.1.0
	 */
	public function ajax_lift_restriction() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'all';

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'User not found.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			wp_send_json_error( array( 'message' => __( 'Restriction registry unavailable.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$result = WP_MCP_AI_Restriction_Registry::lift( $user_id, $type, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$this->log_activity(
			'restriction_lifted',
			$user_id,
			'',
			sprintf(
				/* translators: 1: user ID, 2: restriction type */
				__( 'Restriction (%2$s) lifted for user #%1$d.', 'mcp-ai-wpoos-pro' ),
				$user_id,
				$type
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Restriction lifted.', 'mcp-ai-wpoos-pro' ),
				'rows'    => WP_MCP_AI_Restriction_Registry::get_active(
					array(
						'per_page' => 100,
						'page'     => 1,
					)
				),
			)
		);
	}

	// =========================================================================
	// TAB: Restrictions
	// =========================================================================

	/**
	 * Render the restrictions tab listing users blocked by rate limits,
	 * token overages, and session budgets.
	 *
	 * @since 2.1.0
	 */
	private function render_restrictions_tab() {
		$rows = array();
		if ( class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			$data = WP_MCP_AI_Restriction_Registry::get_active(
				array(
					'per_page' => 100,
					'page'     => 1,
				)
			);
			$rows = $data['rows'];
		}

		$rate_limit_count    = 0;
		$token_overage_count = 0;
		$other_count         = 0;
		foreach ( $rows as $row ) {
			if ( 'rate_limit' === $row['type'] ) {
				++$rate_limit_count;
			} elseif ( 'token_overage' === $row['type'] ) {
				++$token_overage_count;
			} else {
				++$other_count;
			}
		}
		?>
		<div class="acc-restrictions-tab">
			<div class="acc-kpi-row" id="acc-restrictions-kpi-row">
				<div class="acc-kpi-card" data-kpi="restrictions-total">
					<div class="acc-kpi-icon"><span class="dashicons dashicons-lock"></span></div>
					<div class="acc-kpi-content">
						<div class="acc-kpi-value" id="kpi-restrictions-total"><?php echo esc_html( count( $rows ) ); ?></div>
						<div class="acc-kpi-label"><?php esc_html_e( 'Active Restrictions', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
				<div class="acc-kpi-card accent-red" data-kpi="restrictions-rate-limit">
					<div class="acc-kpi-icon"><span class="dashicons dashicons-clock"></span></div>
					<div class="acc-kpi-content">
						<div class="acc-kpi-value" id="kpi-restrictions-rate-limit"><?php echo esc_html( $rate_limit_count ); ?></div>
						<div class="acc-kpi-label"><?php esc_html_e( 'Rate Limits', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
				<div class="acc-kpi-card accent-amber" data-kpi="restrictions-token-overage">
					<div class="acc-kpi-icon"><span class="dashicons dashicons-chart-pie"></span></div>
					<div class="acc-kpi-content">
						<div class="acc-kpi-value" id="kpi-restrictions-token-overage"><?php echo esc_html( $token_overage_count ); ?></div>
						<div class="acc-kpi-label"><?php esc_html_e( 'Token Overages', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
				<div class="acc-kpi-card" data-kpi="restrictions-other">
					<div class="acc-kpi-icon"><span class="dashicons dashicons-shield"></span></div>
					<div class="acc-kpi-content">
						<div class="acc-kpi-value" id="kpi-restrictions-other"><?php echo esc_html( $other_count ); ?></div>
						<div class="acc-kpi-label"><?php esc_html_e( 'Other / Manual', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<div class="acc-restrictions-empty">
					<span class="dashicons dashicons-smiley"></span>
					<p><?php esc_html_e( 'No users are currently restricted.', 'mcp-ai-wpoos-pro' ); ?></p>
					<p class="description"><?php esc_html_e( 'Users who exceed rate limits or token budgets will be flagged here automatically.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php else : ?>
				<div class="acc-restrictions-table-wrap">
					<table class="widefat striped" id="acc-restrictions-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'User', 'mcp-ai-wpoos-pro' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Scope', 'mcp-ai-wpoos-pro' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Reason', 'mcp-ai-wpoos-pro' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Triggered', 'mcp-ai-wpoos-pro' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Auto-release', 'mcp-ai-wpoos-pro' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$triggered = ! empty( $row['triggered_at'] )
									? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $row['triggered_at'] ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
									: '-';
								$released  = ! empty( $row['released_at'] )
									? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $row['released_at'] ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
									: __( 'Until lifted', 'mcp-ai-wpoos-pro' );
								?>
								<tr data-user-id="<?php echo esc_attr( $row['user_id'] ); ?>" data-type="<?php echo esc_attr( $row['type'] ); ?>">
									<td>
										<strong><?php echo esc_html( $row['display_name'] ); ?></strong>
										<?php if ( ! empty( $row['user_login'] ) ) : ?>
											<br><small>@<?php echo esc_html( $row['user_login'] ); ?></small>
										<?php endif; ?>
									</td>
									<td><span class="acc-restriction-pill acc-restriction-pill--<?php echo esc_attr( $row['type'] ); ?>"><?php echo esc_html( $row['type_label'] ); ?></span></td>
									<td><?php echo esc_html( $row['scope'] ); ?><?php echo ( ! empty( $row['tool_slug'] ) ? ' · ' . esc_html( $row['tool_slug'] ) : '' ); ?></td>
									<td><?php echo esc_html( '' !== $row['reason'] ? $row['reason'] : '-' ); ?></td>
									<td><?php echo esc_html( $triggered ); ?></td>
									<td><?php echo esc_html( $released ); ?></td>
									<td>
										<button
											type="button"
											class="button button-small acc-lift-restriction"
											data-user-id="<?php echo esc_attr( $row['user_id'] ); ?>"
											data-type="<?php echo esc_attr( $row['type'] ); ?>"
										>
											<?php esc_html_e( 'Lift', 'mcp-ai-wpoos-pro' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// Data Retrieval Methods
	// =========================================================================

	/**
	 * Get all assistants as an array.
	 *
	 * @since 2.1.0
	 *
	 * @return array Array of assistant data.
	 */
	private function get_all_assistants() {
		$args = array(
			'post_type'      => 'mcp_ai_assistant',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$query      = new WP_Query( $args );
		$assistants = array();

		foreach ( $query->posts as $post ) {
			$assistants[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'provider' => get_post_meta( $post->ID, '_wp_mcp_ai_provider', true ),
				'model'    => get_post_meta( $post->ID, '_wp_mcp_ai_model', true ),
				'tools'    => (array) get_post_meta( $post->ID, '_wp_mcp_ai_tools', true ),
			);
		}

		return $assistants;
	}

	/**
	 * Get session overview data.
	 *
	 * @since 2.1.0
	 *
	 * @return array Session overview with active_count and task_count.
	 */
	private function get_session_overview() {
		global $wpdb;

		$active_count = 0;
		$task_count   = 0;

		// Count active sessions from transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient query for real-time dashboard; results change frequently.
		$sessions = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_transient_mcp_ai_session_%' AND option_name NOT LIKE '_transient_timeout_%'"
		);

		if ( $sessions ) {
			foreach ( $sessions as $row ) {
				$data = maybe_unserialize( $row->option_value );
				if ( is_array( $data ) && isset( $data['status'] ) && 'active' === $data['status'] ) {
					++$active_count;
				}
			}
		}

		// Count active task plans.
		$task_plans = wp_count_posts( 'mcp_task_plan' );
		if ( $task_plans && isset( $task_plans->publish ) ) {
			$task_count = (int) $task_plans->publish;
		}

		return array(
			'active_count' => $active_count,
			'task_count'   => $task_count,
		);
	}

	/**
	 * Get active sessions data — CCT-first with transient fallback.
	 *
	 * @since 2.1.0
	 *
	 * @return array Array of active session data.
	 */
	private function get_active_sessions() {
		// Try CCT first for durable, correctly-keyed session data.
		if ( class_exists( 'WP_MCP_AI_Autonomous_Sessions_CCT' ) && WP_MCP_AI_Autonomous_Sessions_CCT::is_available() ) {
			$cct_sessions = WP_MCP_AI_Autonomous_Sessions_CCT::get_active_sessions(
				array(
					'limit'   => 50,
					'orderby' => 'cct_created',
					'order'   => 'DESC',
				)
			);

			$sessions = array();
			foreach ( $cct_sessions as $row ) {
				// The CCT returns flat records with field-name keys
				// (iterations, max_iterations, tokens_used, health, etc.)
				// which is what the render methods expect.
				$sessions[] = $row;
			}
			return $sessions;
		}

		// Fallback: transient-based retrieval.
		global $wpdb;

		$sessions = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient query for real-time data; fallback path when CCT unavailable.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_transient_mcp_ai_session_%' AND option_name NOT LIKE '_transient_timeout_%'"
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$data = maybe_unserialize( $row->option_value );
				if ( is_array( $data ) ) {
					$session_id         = str_replace( '_transient_mcp_ai_session_', '', $row->option_name );
					$data['session_id'] = $session_id;
					$sessions[]         = $data;
				}
			}
		}

		return $sessions;
	}

	/**
	 * Get active workflows.
	 *
	 * @since 2.1.0
	 *
	 * @return array Array of active workflow data.
	 */
	private function get_active_workflows() {
		global $wpdb;

		$workflows = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient query for real-time data.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_workflow_%' AND option_name NOT LIKE '_transient_timeout_%'"
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$data = maybe_unserialize( $row->option_value );
				if ( is_array( $data ) ) {
					$workflows[] = $data;
				}
			}
		}

		return $workflows;
	}

	/**
	 * Get published task plans.
	 *
	 * @since 2.1.0
	 *
	 * @return WP_Post[] Array of task plan post objects.
	 */
	private function get_task_plans() {
		if ( ! post_type_exists( 'mcp_task_plan' ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => 'mcp_task_plan',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Get agent status (online, offline, idle).
	 *
	 * @since 2.1.0
	 *
	 * @param int $agent_id The assistant post ID.
	 * @return string Status string: 'online', 'idle', or 'offline'.
	 */
	private function get_agent_status( $agent_id ) {
		$last_active = $this->get_agent_last_active( $agent_id );

		if ( ! $last_active ) {
			return 'offline';
		}

		$diff = time() - $last_active;

		if ( $diff < 300 ) {
			return 'online';
		} elseif ( $diff < 3600 ) {
			return 'idle';
		}

		return 'offline';
	}

	/**
	 * Get the last active timestamp for an agent.
	 *
	 * @since 2.1.0
	 *
	 * @param int $agent_id The assistant post ID.
	 * @return int|false Last active timestamp or false.
	 */
	private function get_agent_last_active( $agent_id ) {
		$last_active = get_transient( 'mcp_ai_agent_active_' . $agent_id );
		return $last_active ? (int) $last_active : false;
	}

	/**
	 * Get pending approval count.
	 *
	 * @since 2.1.0
	 *
	 * @return int Number of pending approvals.
	 */
	private function get_pending_approval_count() {
		$approvals = get_option( self::APPROVALS_OPTION, array() );
		return count(
			array_filter(
				$approvals,
				function ( $a ) {
					return 'pending' === $a['status'];
				}
			)
		);
	}

	/**
	 * Get all approvals.
	 *
	 * @since 2.1.0
	 *
	 * @return array All approval records.
	 */
	private function get_all_approvals() {
		return get_option( self::APPROVALS_OPTION, array() );
	}

	/**
	 * Get tokens used today.
	 *
	 * @since 2.1.0
	 *
	 * @return int Total tokens used today.
	 */
	private function get_tokens_today() {
		$metrics = get_option( self::USAGE_METRICS_OPTION, array() );
		$today   = gmdate( 'Y-m-d' );

		if ( isset( $metrics[ $today ]['tokens'] ) ) {
			return (int) $metrics[ $today ]['tokens'];
		}

		return 0;
	}

	/**
	 * Get system uptime percentage.
	 *
	 * @since 2.1.0
	 *
	 * @return float Uptime percentage.
	 */
	private function get_system_uptime_pct() {
		$history = get_option( self::UPTIME_OPTION, array() );

		if ( empty( $history ) ) {
			return 99.9;
		}

		$downtime_seconds = 0;
		$total_period     = 30 * 24 * 3600; // 30 days.

		foreach ( $history as $event ) {
			if ( 'downtime' === $event['type'] && ! empty( $event['duration'] ) ) {
				$downtime_seconds += (int) $event['duration'];
			}
		}

		$uptime_pct = ( ( $total_period - $downtime_seconds ) / $total_period ) * 100;
		return round( max( 0, min( 100, $uptime_pct ) ), 1 );
	}

	/**
	 * Get recent activity events.
	 *
	 * @since 2.1.0
	 *
	 * @param int $limit Maximum number of events.
	 * @return array Activity events.
	 */
	private function get_recent_activity_events( $limit = 20 ) {
		$events = get_option( self::ACTIVITY_LOG_OPTION, array() );

		// Merge assistant activity entries from the core Logger.
		$events = $this->merge_logger_activity_entries( $events );

		// Sort by timestamp descending.
		usort(
			$events,
			function ( $a, $b ) {
				return ( $b['timestamp'] ?? 0 ) - ( $a['timestamp'] ?? 0 );
			}
		);

		return array_slice( $events, 0, $limit );
	}

	/**
	 * Get filtered activity events.
	 *
	 * @since 2.1.0
	 *
	 * @param string $type      Event type filter.
	 * @param int    $agent_id  Agent ID filter.
	 * @param string $timeframe Timeframe filter.
	 * @param string $search    Search query.
	 * @return array Filtered events.
	 */
	private function get_filtered_activity( $type, $agent_id, $timeframe, $search ) {
		$events = get_option( self::ACTIVITY_LOG_OPTION, array() );

		// Merge assistant activity entries from the core Logger.
		$events = $this->merge_logger_activity_entries( $events );

		// Timeframe filter.
		$cutoff = $this->get_timeframe_cutoff( $timeframe );
		$events = array_filter(
			$events,
			function ( $e ) use ( $cutoff ) {
				return isset( $e['timestamp'] ) && $e['timestamp'] >= $cutoff;
			}
		);

		// Type filter.
		if ( $type ) {
			$events = array_filter(
				$events,
				function ( $e ) use ( $type ) {
					return isset( $e['type'] ) && $e['type'] === $type;
				}
			);
		}

		// Agent filter.
		if ( $agent_id ) {
			$events = array_filter(
				$events,
				function ( $e ) use ( $agent_id ) {
					return isset( $e['agent_id'] ) && (int) $e['agent_id'] === $agent_id;
				}
			);
		}

		// Search filter.
		if ( $search ) {
			$search_lower = strtolower( $search );
			$events       = array_filter(
				$events,
				function ( $e ) use ( $search_lower ) {
					$message = isset( $e['message'] ) ? strtolower( $e['message'] ) : '';
					$agent   = isset( $e['agent_name'] ) ? strtolower( $e['agent_name'] ) : '';
					return false !== strpos( $message, $search_lower ) || false !== strpos( $agent, $search_lower );
				}
			);
		}

		// Sort descending.
		usort(
			$events,
			function ( $a, $b ) {
				return ( $b['timestamp'] ?? 0 ) - ( $a['timestamp'] ?? 0 );
			}
		);

		return array_values( $events );
	}

	/**
	 * Merge activity entries from the core Logger into the activity stream.
	 *
	 * Pulls assistant activity entries from WP_MCP_AI_Logger — including chat
	 * interactions, API requests/responses, tool errors, and schedule runs —
	 * and normalises them into the same format used by the command center
	 * activity log so they can be displayed alongside agent-level events.
	 *
	 * @since 2.1.0
	 * @since 2.2.0 Expanded to include API requests, tool errors, and schedule runs.
	 *
	 * @param array $events Existing activity events.
	 * @return array Merged events array.
	 */
	private function merge_logger_activity_entries( $events ) {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) || ! method_exists( 'WP_MCP_AI_Logger', 'get_recent_activity_entries' ) ) {
			return $events;
		}

		// Types to pull from the Logger that are NOT already tracked by the
		// command center's own hook-based recording (record_tool_execution, etc.).
		$logger_types = array(
			'chat_interaction',
			'tool_error',
			'openai_request',
			'openai_response',
			'anthropic_request',
			'anthropic_response',
			'gemini_request',
			'gemini_response',
			'gemini_image_request',
			'gemini_image_response',
			'gemini_stream_request',
			'gemini_stream_response',
			'lm_studio_request',
			'lm_studio_response',
			'lm_studio_completion_request',
			'lm_studio_completion_response',
			'openai_external_action_request',
			'openai_external_action_response',
			'schedule_run',
		);

		$logger_entries = WP_MCP_AI_Logger::get_recent_activity_entries( 200, $logger_types );

		if ( empty( $logger_entries ) ) {
			return $events;
		}

		// API request/response type suffixes used for normalisation.
		$api_request_types  = array(
			'openai_request',
			'anthropic_request',
			'gemini_request',
			'gemini_image_request',
			'gemini_stream_request',
			'lm_studio_request',
			'lm_studio_completion_request',
			'openai_external_action_request',
		);
		$api_response_types = array(
			'openai_response',
			'anthropic_response',
			'gemini_response',
			'gemini_image_response',
			'gemini_stream_response',
			'lm_studio_response',
			'lm_studio_completion_response',
			'openai_external_action_response',
		);

		foreach ( $logger_entries as $entry ) {
			$entry_type     = isset( $entry['type'] ) ? $entry['type'] : '';
			$assistant_id   = isset( $entry['context']['assistant_id'] ) ? absint( $entry['context']['assistant_id'] ) : 0;
			$assistant_name = $assistant_id ? get_the_title( $assistant_id ) : '';

			$timestamp = isset( $entry['time'] ) ? strtotime( $entry['time'] ) : false;
			if ( false === $timestamp && isset( $entry['timestamp'] ) && '' !== $entry['timestamp'] ) {
				// Logger stores MySQL datetime strings (e.g. "2026-04-04 08:42:04").
				if ( is_numeric( $entry['timestamp'] ) ) {
					$timestamp = (int) $entry['timestamp'];
				} else {
					$parsed    = strtotime( $entry['timestamp'] );
					$timestamp = ( false !== $parsed ) ? $parsed : 0;
				}
			}
			if ( false === $timestamp ) {
				$timestamp = 0;
			}

			// Determine the normalised type and human-readable message.
			if ( 'chat_interaction' === $entry_type ) {
				$normalised_type = 'chat_interaction';
				$message         = $this->build_chat_interaction_message( $entry, $assistant_name );
			} elseif ( in_array( $entry_type, $api_request_types, true ) ) {
				$normalised_type = 'api_request';
				$provider        = $this->extract_provider_from_type( $entry_type );
				$message         = isset( $entry['message'] ) && '' !== $entry['message']
					? $entry['message']
					/* translators: %s: AI provider name */
					: sprintf( __( 'API request to %s', 'mcp-ai-wpoos-pro' ), ucfirst( $provider ) );
			} elseif ( in_array( $entry_type, $api_response_types, true ) ) {
				$normalised_type = 'api_response';
				$provider        = $this->extract_provider_from_type( $entry_type );
				$message         = isset( $entry['message'] ) && '' !== $entry['message']
					? $entry['message']
					/* translators: %s: AI provider name */
					: sprintf( __( 'API response from %s', 'mcp-ai-wpoos-pro' ), ucfirst( $provider ) );
			} elseif ( 'tool_error' === $entry_type ) {
				$normalised_type = 'tool_error';
				$tool_slug       = isset( $entry['context']['tool_slug'] ) ? $entry['context']['tool_slug'] : '';
				$error_msg       = isset( $entry['context']['error_message'] ) ? $entry['context']['error_message'] : '';
				$message         = $tool_slug
					/* translators: 1: tool slug, 2: error description */
					? sprintf( __( 'Tool "%1$s" error: %2$s', 'mcp-ai-wpoos-pro' ), $tool_slug, $error_msg )
					: ( isset( $entry['message'] ) ? $entry['message'] : __( 'Tool execution failed', 'mcp-ai-wpoos-pro' ) );
			} elseif ( 'schedule_run' === $entry_type ) {
				$normalised_type = 'schedule_run';
				$message         = isset( $entry['message'] ) && '' !== $entry['message']
					? $entry['message']
					: __( 'Scheduled task executed', 'mcp-ai-wpoos-pro' );
			} else {
				continue;
			}

			$events[] = array(
				'type'       => $normalised_type,
				'agent_id'   => $assistant_id,
				'agent_name' => $assistant_name,
				'message'    => $message,
				'timestamp'  => $timestamp,
			);
		}

		return $events;
	}

	/**
	 * Build a human-readable message for a chat interaction Logger entry.
	 *
	 * @since 2.2.0
	 *
	 * @param array  $entry          Logger entry.
	 * @param string $assistant_name Resolved assistant title.
	 * @return string Formatted message.
	 */
	private function build_chat_interaction_message( $entry, $assistant_name ) {
		$user_id      = isset( $entry['context']['user_id'] ) ? absint( $entry['context']['user_id'] ) : 0;
		$user_display = '';

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user_display = $user->display_name;
			}
		}

		if ( $assistant_name ) {
			/* translators: 1: assistant name, 2: user display name */
			return sprintf( __( 'Chat with "%1$s" by %2$s', 'mcp-ai-wpoos-pro' ), $assistant_name, $user_display ? $user_display : __( 'Guest', 'mcp-ai-wpoos-pro' ) );
		}

		/* translators: %s: user display name */
		return sprintf( __( 'Chat interaction by %s', 'mcp-ai-wpoos-pro' ), $user_display ? $user_display : __( 'Guest', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Extract the AI provider name from a Logger event type string.
	 *
	 * @since 2.2.0
	 *
	 * @param string $type Logger event type (e.g. 'openai_request', 'gemini_stream_response').
	 * @return string Provider name (e.g. 'openai', 'gemini', 'anthropic', 'lm_studio').
	 */
	private function extract_provider_from_type( $type ) {
		$providers = array( 'openai_external_action', 'openai', 'anthropic', 'gemini', 'deepseek', 'openrouter', 'huggingface', 'nvidia', 'digitalocean', 'kimi', 'baseten', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );
		foreach ( $providers as $provider ) {
			if ( 0 === strpos( $type, $provider . '_' ) ) {
				// Normalise 'openai_external_action' to 'openai'.
				return 'openai_external_action' === $provider ? 'openai' : $provider;
			}
		}
		return 'api';
	}

	/**
	 * Get system health data.
	 *
	 * @since 2.1.0
	 *
	 * @return array System health data.
	 */
	private function get_system_health() {
		$health = array(
			'overall_status' => 'healthy',
			'icon'           => 'yes-alt',
			'label'          => __( 'All Systems Operational', 'mcp-ai-wpoos-pro' ),
			'description'    => __( 'All agent services are running normally.', 'mcp-ai-wpoos-pro' ),
			'cron_status'    => __( 'Operational', 'mcp-ai-wpoos-pro' ),
			'db_status'      => __( 'Operational', 'mcp-ai-wpoos-pro' ),
			'api_status'     => __( 'Operational', 'mcp-ai-wpoos-pro' ),
			'sse_status'     => __( 'Operational', 'mcp-ai-wpoos-pro' ),
		);

		// Check cron health.
		if ( class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			$cron_service = new WP_MCP_AI_Cron_Status_Service();
			if ( method_exists( $cron_service, 'get_status' ) ) {
				$cron_data = $cron_service->get_status();
				if ( ! empty( $cron_data['failed'] ) && $cron_data['failed'] > 0 ) {
					$health['cron_status']    = __( 'Degraded', 'mcp-ai-wpoos-pro' );
					$health['overall_status'] = 'warning';
					$health['icon']           = 'warning';
					$health['label']          = __( 'System Degraded', 'mcp-ai-wpoos-pro' );
					$health['description']    = __( 'Some cron jobs have failed. Check the schedule manager.', 'mcp-ai-wpoos-pro' );
				}
			}
		}

		// Check SSE availability.
		if ( ! class_exists( 'WP_MCP_AI_SSE_Stream' ) ) {
			$health['sse_status'] = __( 'Unavailable', 'mcp-ai-wpoos-pro' );
		}

		// Check REST API availability.
		if ( ! get_option( 'permalink_structure' ) ) {
			$health['api_status']     = __( 'Limited', 'mcp-ai-wpoos-pro' );
			$health['overall_status'] = 'warning';
		}

		return $health;
	}

	/**
	 * Get uptime history.
	 *
	 * @since 2.1.0
	 *
	 * @return array Uptime events.
	 */
	private function get_uptime_history() {
		return get_option( self::UPTIME_OPTION, array() );
	}

	/**
	 * Get analytics data for a time range.
	 *
	 * @since 2.1.0
	 *
	 * @param string $range Time range key.
	 * @return array Analytics data.
	 */
	private function get_analytics_data( $range ) {
		$metrics    = get_option( self::USAGE_METRICS_OPTION, array() );
		$assistants = $this->get_all_assistants();
		$cutoff     = $this->get_timeframe_cutoff( $range );
		$cutoff_day = gmdate( 'Y-m-d', $cutoff );

		$total_tokens    = 0;
		$total_calls     = 0;
		$total_response  = 0;
		$response_count  = 0;
		$total_successes = 0;
		$total_requests  = 0;
		$timeline_data   = array();

		foreach ( $metrics as $date => $day_data ) {
			if ( $date < $cutoff_day ) {
				continue;
			}

			$tokens = isset( $day_data['tokens'] ) ? (int) $day_data['tokens'] : 0;
			$calls  = isset( $day_data['api_calls'] ) ? (int) $day_data['api_calls'] : 0;
			$resp   = isset( $day_data['avg_response_ms'] ) ? (float) $day_data['avg_response_ms'] : 0;
			$succ   = isset( $day_data['successes'] ) ? (int) $day_data['successes'] : 0;
			$reqs   = isset( $day_data['total_requests'] ) ? (int) $day_data['total_requests'] : 0;

			$total_tokens    += $tokens;
			$total_calls     += $calls;
			$total_successes += $succ;
			$total_requests  += $reqs;

			if ( $resp > 0 ) {
				$total_response += $resp;
				++$response_count;
			}

			$timeline_data[] = array(
				'date'   => $date,
				'tokens' => $tokens,
				'calls'  => $calls,
			);
		}

		$avg_response = $response_count > 0 ? round( $total_response / $response_count ) : 0;
		$success_rate = $total_requests > 0 ? round( ( $total_successes / $total_requests ) * 100, 1 ) : 100;

		// Build agent performance data from per-agent metrics.
		$agent_performance = array();
		foreach ( $assistants as $a ) {
			$agent_id         = $a['id'];
			$aid              = (string) $agent_id;
			$agent_sessions   = 0;
			$agent_tokens     = 0;
			$agent_tool_calls = 0;
			$agent_successes  = 0;
			$agent_requests   = 0;

			foreach ( $metrics as $date => $day_data ) {
				if ( $date < $cutoff_day ) {
					continue;
				}
				if ( isset( $day_data['agents'][ $aid ] ) ) {
					$agent_day         = $day_data['agents'][ $aid ];
					$agent_sessions   += isset( $agent_day['sessions'] ) ? (int) $agent_day['sessions'] : 0;
					$agent_tokens     += isset( $agent_day['tokens'] ) ? (int) $agent_day['tokens'] : 0;
					$agent_tool_calls += isset( $agent_day['tool_calls'] ) ? (int) $agent_day['tool_calls'] : 0;
					$agent_successes  += isset( $agent_day['successes'] ) ? (int) $agent_day['successes'] : 0;
					$agent_requests   += isset( $agent_day['total_requests'] ) ? (int) $agent_day['total_requests'] : 0;
				}
			}

			$agent_success_rate = $agent_requests > 0
				? round( ( $agent_successes / $agent_requests ) * 100, 1 )
				: 100;

			$agent_performance[] = array(
				'name'         => $a['title'],
				'sessions'     => $agent_sessions,
				'tokens'       => $agent_tokens,
				'tool_calls'   => $agent_tool_calls,
				'avg_response' => '0ms',
				'success_rate' => $agent_success_rate . '%',
				'status'       => $this->get_agent_status( $agent_id ),
			);
		}

		return array(
			'summary'           => array(
				'total_tokens'    => $this->format_number( $total_tokens ),
				'total_calls'     => $this->format_number( $total_calls ),
				'avg_response_ms' => $avg_response . 'ms',
				'success_rate'    => $success_rate . '%',
			),
			'timeline'          => $timeline_data,
			'agent_performance' => $agent_performance,
		);
	}

	/**
	 * Generate strategic insights.
	 *
	 * @since 2.1.0
	 *
	 * @return array Insight scores.
	 */
	private function generate_strategy_insights() {
		$assistants   = $this->get_all_assistants();
		$session_data = $this->get_session_overview();
		$health       = $this->get_system_health();

		$agent_count = count( $assistants );

		// Utilization: based on active sessions vs total agents.
		$utilization = $agent_count > 0 ? min( 100, round( ( $session_data['active_count'] / max( 1, $agent_count ) ) * 100 ) ) : 0;

		// Reliability: based on system health.
		$reliability = 'healthy' === $health['overall_status'] ? 95 : ( 'warning' === $health['overall_status'] ? 70 : 40 );

		// Efficiency: based on uptime.
		$uptime_pct = $this->get_system_uptime_pct();
		$efficiency = min( 100, round( $uptime_pct ) );

		// Coverage: based on agents with tools configured.
		$agents_with_tools = count(
			array_filter(
				$assistants,
				function ( $a ) {
					return ! empty( $a['tools'] ) && count( $a['tools'] ) > 0;
				}
			)
		);
		$coverage          = $agent_count > 0 ? min( 100, round( ( $agents_with_tools / $agent_count ) * 100 ) ) : 0;

		$overall = round( ( $utilization + $reliability + $efficiency + $coverage ) / 4 );

		return array(
			'overall_score'     => $overall,
			'utilization_score' => $utilization,
			'reliability_score' => $reliability,
			'efficiency_score'  => $efficiency,
			'coverage_score'    => $coverage,
		);
	}

	/**
	 * Generate strategic recommendations.
	 *
	 * @since 2.1.0
	 *
	 * @return array Array of recommendation objects.
	 */
	private function generate_recommendations() {
		$recommendations = array();
		$assistants      = $this->get_all_assistants();
		$health          = $this->get_system_health();

		// Check for agents without tools.
		$agents_no_tools = array_filter(
			$assistants,
			function ( $a ) {
				return empty( $a['tools'] ) || 0 === count( array_filter( $a['tools'] ) );
			}
		);
		if ( ! empty( $agents_no_tools ) ) {
			$recommendations[] = array(
				'title'       => __( 'Configure Agent Tools', 'mcp-ai-wpoos-pro' ),
				'description' => sprintf(
					/* translators: %d: number of agents without tools */
					__( '%d agent(s) have no tools assigned. Assign tools to maximize their capabilities.', 'mcp-ai-wpoos-pro' ),
					count( $agents_no_tools )
				),
				'priority'    => 'high',
				'icon'        => 'admin-tools',
			);
		}

		// Check system health.
		if ( 'healthy' !== $health['overall_status'] ) {
			$recommendations[] = array(
				'title'       => __( 'Resolve System Health Issues', 'mcp-ai-wpoos-pro' ),
				'description' => $health['description'],
				'priority'    => 'critical',
				'icon'        => 'warning',
			);
		}

		// Check for no agents.
		if ( empty( $assistants ) ) {
			$recommendations[] = array(
				'title'       => __( 'Create Your First Agent', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Get started by creating an AI assistant to automate tasks and serve your users.', 'mcp-ai-wpoos-pro' ),
				'priority'    => 'high',
				'icon'        => 'admin-users',
			);
		}

		// Suggest workflow automation.
		if ( count( $assistants ) >= 3 ) {
			$recommendations[] = array(
				'title'       => __( 'Consider Multi-Agent Workflows', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'With multiple agents configured, consider using the Workflow Builder to create automated multi-step processes.', 'mcp-ai-wpoos-pro' ),
				'priority'    => 'medium',
				'icon'        => 'randomize',
			);
		}

		// Suggest scheduling.
		$recommendations[] = array(
			'title'       => __( 'Set Up Scheduled Tasks', 'mcp-ai-wpoos-pro' ),
			'description' => __( 'Use the Schedule Manager to automate routine agent tasks and ensure consistent performance.', 'mcp-ai-wpoos-pro' ),
			'priority'    => 'low',
			'icon'        => 'calendar-alt',
		);

		return $recommendations;
	}

	// =========================================================================
	// Activity Logging
	// =========================================================================

	/**
	 * Log an activity event.
	 *
	 * @since 2.1.0
	 *
	 * @param string $type       Event type.
	 * @param int    $agent_id   Agent post ID (0 for system events).
	 * @param string $agent_name Agent name.
	 * @param string $message    Event message.
	 */
	public function log_activity( $type, $agent_id, $agent_name, $message ) {
		$events = get_option( self::ACTIVITY_LOG_OPTION, array() );

		$events[] = array(
			'type'       => $type,
			'agent_id'   => $agent_id,
			'agent_name' => $agent_name,
			'message'    => $message,
			'timestamp'  => time(),
		);

		// Keep last 1000 events.
		if ( count( $events ) > 1000 ) {
			$events = array_slice( $events, -1000 );
		}

		update_option( self::ACTIVITY_LOG_OPTION, $events, false );
	}

	/**
	 * Record a tool execution event.
	 *
	 * Hooked to `wp_mcp_ai_after_tool_execution`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $tool_slug  Tool slug.
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 * @param mixed  $result     Tool result.
	 */
	public function record_tool_execution( $tool_slug, $arguments, $context, $result ) {
		$agent_id   = isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0;
		$agent_name = $agent_id ? get_the_title( $agent_id ) : '';

		$is_error = is_wp_error( $result ) || ( is_array( $result ) && ! empty( $result['error'] ) );
		$status   = $is_error ? 'failed' : 'success';

		/* translators: 1: tool name, 2: execution status */
		$message = sprintf( __( 'Tool "%1$s" executed: %2$s', 'mcp-ai-wpoos-pro' ), $tool_slug, $status );

		$this->log_activity( 'tool_execution', $agent_id, $agent_name, $message );

		// Update agent last active timestamp.
		if ( $agent_id ) {
			set_transient( 'mcp_ai_agent_active_' . $agent_id, time(), HOUR_IN_SECONDS );
		}

		// Update daily usage metrics.
		$this->increment_daily_metric( 'api_calls', 1 );
		$this->increment_daily_metric( 'total_requests', 1 );
		if ( 'success' === $status ) {
			$this->increment_daily_metric( 'successes', 1 );
		}

		// Update per-agent metrics.
		if ( $agent_id ) {
			$this->increment_agent_metric( $agent_id, 'tool_calls', 1 );
			$this->increment_agent_metric( $agent_id, 'total_requests', 1 );
			if ( 'success' === $status ) {
				$this->increment_agent_metric( $agent_id, 'successes', 1 );
			}
		}
	}

	/**
	 * Record a chat response event.
	 *
	 * Hooked to `wp_mcp_ai_after_chat_response`.
	 *
	 * @since 2.1.0
	 *
	 * @param int             $assistant_id Assistant post ID.
	 * @param array           $response     Response data from AI provider.
	 * @param WP_REST_Request $request      REST request instance.
	 */
	public function record_chat_response( $assistant_id, $response, $request ) {
		$agent_id   = (int) $assistant_id;
		$agent_name = $agent_id ? get_the_title( $agent_id ) : '';

		$tokens = 0;
		if ( isset( $response['usage']['total_tokens'] ) ) {
			$tokens = (int) $response['usage']['total_tokens'];
		}

		/* translators: %s: number of tokens used */
		$message = sprintf( __( 'Chat response generated (%s tokens)', 'mcp-ai-wpoos-pro' ), $this->format_number( $tokens ) );

		$this->log_activity( 'chat_response', $agent_id, $agent_name, $message );

		// Update agent last active.
		if ( $agent_id ) {
			set_transient( 'mcp_ai_agent_active_' . $agent_id, time(), HOUR_IN_SECONDS );
		}

		// Update token usage.
		if ( $tokens > 0 ) {
			$this->increment_daily_metric( 'tokens', $tokens );
		}

		// Update per-agent metrics.
		if ( $agent_id ) {
			$this->increment_agent_metric( $agent_id, 'sessions', 1 );
			if ( $tokens > 0 ) {
				$this->increment_agent_metric( $agent_id, 'tokens', $tokens );
			}
		}
	}

	/**
	 * Record a session start event.
	 *
	 * @since 2.1.0
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $context    Session context.
	 */
	public function record_session_start( $session_id, $context ) {
		$agent_id   = isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0;
		$agent_name = $agent_id ? get_the_title( $agent_id ) : '';

		$this->log_activity(
			'session_start',
			$agent_id,
			$agent_name,
			__( 'Autonomous session started', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Record a session end event.
	 *
	 * @since 2.1.0
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $context    Session context.
	 */
	public function record_session_end( $session_id, $context ) {
		$agent_id   = isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0;
		$agent_name = $agent_id ? get_the_title( $agent_id ) : '';
		$reason     = isset( $context['reason'] ) ? $context['reason'] : __( 'completed', 'mcp-ai-wpoos-pro' );

		/* translators: %s: session end reason */
		$message = sprintf( __( 'Session ended: %s', 'mcp-ai-wpoos-pro' ), $reason );

		$this->log_activity( 'session_end', $agent_id, $agent_name, $message );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Increment a daily usage metric.
	 *
	 * @since 2.1.0
	 *
	 * @param string $key   Metric key.
	 * @param int    $value Amount to add.
	 */
	private function increment_daily_metric( $key, $value ) {
		$metrics = get_option( self::USAGE_METRICS_OPTION, array() );
		$today   = gmdate( 'Y-m-d' );

		if ( ! isset( $metrics[ $today ] ) ) {
			$metrics[ $today ] = array();
		}

		if ( ! isset( $metrics[ $today ][ $key ] ) ) {
			$metrics[ $today ][ $key ] = 0;
		}

		$metrics[ $today ][ $key ] += $value;

		$metrics = $this->prune_old_metrics( $metrics );
		update_option( self::USAGE_METRICS_OPTION, $metrics, false );
	}

	/**
	 * Increment a per-agent daily usage metric.
	 *
	 * Stores agent-specific counters inside each day's metric entry under
	 * an 'agents' sub-array keyed by agent post ID.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $agent_id Agent (assistant) post ID.
	 * @param string $key      Metric key (e.g. 'tokens', 'tool_calls', 'sessions').
	 * @param int    $value    Amount to add.
	 */
	private function increment_agent_metric( $agent_id, $key, $value ) {
		$metrics = get_option( self::USAGE_METRICS_OPTION, array() );
		$today   = gmdate( 'Y-m-d' );
		$aid     = (string) $agent_id;

		if ( ! isset( $metrics[ $today ] ) ) {
			$metrics[ $today ] = array();
		}

		if ( ! isset( $metrics[ $today ]['agents'] ) ) {
			$metrics[ $today ]['agents'] = array();
		}

		if ( ! isset( $metrics[ $today ]['agents'][ $aid ] ) ) {
			$metrics[ $today ]['agents'][ $aid ] = array();
		}

		if ( ! isset( $metrics[ $today ]['agents'][ $aid ][ $key ] ) ) {
			$metrics[ $today ]['agents'][ $aid ][ $key ] = 0;
		}

		$metrics[ $today ]['agents'][ $aid ][ $key ] += $value;

		$metrics = $this->prune_old_metrics( $metrics );
		update_option( self::USAGE_METRICS_OPTION, $metrics, false );
	}

	/**
	 * Remove metric entries older than 90 days.
	 *
	 * @since 2.1.0
	 *
	 * @param array $metrics Daily metrics array keyed by date.
	 * @return array Pruned metrics array.
	 */
	private function prune_old_metrics( $metrics ) {
		$cutoff = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
		foreach ( array_keys( $metrics ) as $date ) {
			if ( $date < $cutoff ) {
				unset( $metrics[ $date ] );
			}
		}
		return $metrics;
	}

	/**
	 * Get timeframe cutoff timestamp.
	 *
	 * @since 2.1.0
	 *
	 * @param string $timeframe Timeframe key.
	 * @return int Unix timestamp.
	 */
	private function get_timeframe_cutoff( $timeframe ) {
		switch ( $timeframe ) {
			case '1h':
				return time() - HOUR_IN_SECONDS;
			case '24h':
				return time() - DAY_IN_SECONDS;
			case '7d':
				return time() - ( 7 * DAY_IN_SECONDS );
			case '30d':
				return time() - ( 30 * DAY_IN_SECONDS );
			case '90d':
				return time() - ( 90 * DAY_IN_SECONDS );
			default:
				return time() - DAY_IN_SECONDS;
		}
	}

	/**
	 * Format a large number for display.
	 *
	 * @since 2.1.0
	 *
	 * @param int $number The number to format.
	 * @return string Formatted number string.
	 */
	private function format_number( $number ) {
		if ( $number >= 1000000 ) {
			return round( $number / 1000000, 1 ) . 'M';
		} elseif ( $number >= 1000 ) {
			return round( $number / 1000, 1 ) . 'K';
		}
		return (string) $number;
	}

	/**
	 * Format a duration in seconds to human readable.
	 *
	 * @since 2.1.0
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration.
	 */
	private function format_duration( $seconds ) {
		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds */
			return sprintf( __( '%ds', 'mcp-ai-wpoos-pro' ), $seconds );
		} elseif ( $seconds < 3600 ) {
			$minutes = floor( $seconds / 60 );
			$secs    = $seconds % 60;
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$dm %2$ds', 'mcp-ai-wpoos-pro' ), $minutes, $secs );
		} else {
			$hours   = floor( $seconds / 3600 );
			$minutes = floor( ( $seconds % 3600 ) / 60 );
			/* translators: 1: hours, 2: minutes */
			return sprintf( __( '%1$dh %2$dm', 'mcp-ai-wpoos-pro' ), $hours, $minutes );
		}
	}
}

// Self-instantiate when the Pro plugin is active.
if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	new WP_MCP_AI_Pro_Agent_Command_Center();
}
