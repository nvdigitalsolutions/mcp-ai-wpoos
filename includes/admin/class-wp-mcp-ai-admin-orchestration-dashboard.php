<?php
/**
 * Admin Page: Orchestration Dashboard
 *
 * Provides visual overview and management interface for the DeepSeek V4
 * multi-agent orchestration system.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration Dashboard Admin Page.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Admin_Orchestration_Dashboard {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_run_orchestration_seeder', array( $this, 'ajax_run_seeder' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_orchestration_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_recent_workflows', array( $this, 'ajax_get_recent_workflows' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_workflow', array( $this, 'ajax_execute_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_restart_workflow', array( $this, 'ajax_restart_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_refresh_memory_stats', array( $this, 'ajax_refresh_memory_stats' ) );
	}

	/**
	 * Add admin menu page.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Orchestration Dashboard', 'mcp-ai-wpoos' ),
			__( 'Orchestration', 'mcp-ai-wpoos' ),
			'manage_options',
			'mcp-ai-orchestration',
			array( $this, 'render_dashboard' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// WordPress generates submenu hooks as: {sanitized_parent_title}_page_{submenu_slug}
		// Parent menu title: "NV oOS" -> sanitized to "nv-oos"
		// Submenu slug: "mcp-ai-orchestration"
		// Expected hook: nv-oos_page_mcp-ai-orchestration (or variants like toplevel_page_mcp-ai-orchestration).

		// Check if this is the base orchestration page (not the Pro version).
		// Pro version uses slug 'mcp-ai-orchestration-pro', we want to exclude that.
		$is_orchestration_page = false !== strpos( $hook, 'mcp-ai-orchestration' );
		$is_pro_page           = false !== strpos( $hook, 'mcp-ai-orchestration-pro' );

		// Only enqueue on base orchestration page, not Pro page or other pages.
		if ( ! $is_orchestration_page || $is_pro_page ) {
			return;
		}

		// Use file modification time for cache busting to ensure CSS/JS updates are loaded.
		$css_path           = WP_MCP_AI_PATH . 'assets/css/admin-orchestration-dashboard.css';
		$js_path            = WP_MCP_AI_PATH . 'assets/js/admin-orchestration-dashboard.js';
		$shared_css_path    = WP_MCP_AI_PATH . 'assets/css/admin-monitor-shared.css';
		$css_version        = file_exists( $css_path ) ? filemtime( $css_path ) : WP_MCP_AI_VERSION;
		$js_version         = file_exists( $js_path ) ? filemtime( $js_path ) : WP_MCP_AI_VERSION;
		$shared_css_version = file_exists( $shared_css_path ) ? filemtime( $shared_css_path ) : WP_MCP_AI_VERSION;

		// Enqueue shared monitor CSS for auto-refresh controls.
		wp_enqueue_style(
			'wp-mcp-ai-admin-monitor-shared',
			WP_MCP_AI_URL . 'assets/css/admin-monitor-shared.css',
			array(),
			$shared_css_version
		);

		wp_enqueue_style(
			'wp-mcp-ai-orchestration-dashboard',
			WP_MCP_AI_URL . 'assets/css/admin-orchestration-dashboard.css',
			array( 'wp-mcp-ai-admin-monitor-shared' ),
			$css_version
		);

		wp_enqueue_script(
			'wp-mcp-ai-orchestration-dashboard',
			WP_MCP_AI_URL . 'assets/js/admin-orchestration-dashboard.js',
			array( 'jquery' ),
			$js_version,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-orchestration-dashboard',
			'wpMcpAiOrchestration',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		// Get statistics.
		$stats = $this->get_orchestration_statistics();

		?>
		<div class="wrap wp-mcp-ai-orchestration-dashboard">
			<h1>
				<?php esc_html_e( 'DeepSeek V4 Multi-Agent Orchestration', 'mcp-ai-wpoos' ); ?>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Manage and monitor your multi-agent orchestration system. View statistics, configure agent roles, and seed orchestration metadata.', 'mcp-ai-wpoos' ); ?>
			</p>

			<!-- Auto-Refresh Controls -->
			<div class="auto-refresh-controls">
				<label>
					<input type="checkbox" id="toggle-auto-refresh" />
					<?php esc_html_e( 'Auto-refresh', 'mcp-ai-wpoos' ); ?>
				</label>
				<button type="button" class="button button-secondary" id="manual-refresh-btn">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh Now', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="last-refresh-time">
					<?php esc_html_e( 'Last updated:', 'mcp-ai-wpoos' ); ?>
					<strong id="last-refresh-time"><?php echo esc_html( current_time( 'H:i:s' ) ); ?></strong>
				</span>
			</div>

			<!-- Status Banner -->
			<div class="orchestration-status-banner">
				<?php $this->render_status_banner( $stats ); ?>
			</div>

			<!-- Statistics Cards -->
			<div class="orchestration-stats-grid">
				<?php $this->render_statistics_cards( $stats ); ?>
			</div>

			<!-- System Status Monitor -->
			<div class="orchestration-system-status-container">
				<h2><?php esc_html_e( 'System Status', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_system_status(); ?>
			</div>

			<!-- Agent Memory Usage (NEW - Phase 4/5) -->
			<div class="orchestration-memory-container">
				<h2><?php esc_html_e( 'Agent Memory Usage', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_agent_memory_stats(); ?>
			</div>

			<!-- Agent Role Distribution Chart -->
			<div class="orchestration-chart-container">
				<h2><?php esc_html_e( 'Agent Role Distribution', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_role_distribution_chart( $stats ); ?>
			</div>

			<!-- Recent Workflows -->
			<div class="orchestration-workflows-container">
				<h2><?php esc_html_e( 'Recent Workflows', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_recent_workflows(); ?>
			</div>

			<!-- Quick Actions -->
			<div class="orchestration-quick-actions">
				<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_quick_actions( $stats ); ?>
			</div>

			<!-- Documentation Links -->
			<div class="orchestration-documentation">
				<h2><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_documentation_links(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get orchestration statistics.
	 *
	 * @return array Statistics data.
	 */
	protected function get_orchestration_statistics() {
		$roles = array( 'planner', 'executor', 'critic', 'specialist', 'generalist' );
		$stats = array(
			'total_professions'  => 0,
			'seeded_professions' => 0,
			'roles'              => array(),
			'with_task_patterns' => 0,
			'seeder_version'     => get_option( 'wp_mcp_ai_profession_orchestration_version', __( 'Not seeded', 'mcp-ai-wpoos' ) ),
		);

		// Get total professions.
		$total_query                = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_profession',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$stats['total_professions'] = $total_query->found_posts;

		// Count by role.
		foreach ( $roles as $role ) {
			$role_query              = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_profession',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter assistant CPT by orchestration role; no alternative index-based query available.
						array(
							'key'   => WP_MCP_AI_Profession_CPT::META_AGENT_ROLE,
							'value' => $role,
						),
					),
					'fields'         => 'ids',
				)
			);
			$stats['roles'][ $role ] = $role_query->found_posts;
		}

		// Count seeded professions (have agent role assigned).
		$seeded_query                = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_profession',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter assistant CPT by orchestration role; no alternative index-based query available.
					array(
						'key'     => WP_MCP_AI_Profession_CPT::META_AGENT_ROLE,
						'compare' => 'EXISTS',
					),
				),
				'fields'         => 'ids',
			)
		);
		$stats['seeded_professions'] = $seeded_query->found_posts;

		// Count professions with task patterns.
		$patterns_query              = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_profession',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to filter assistant CPT by orchestration role; no alternative index-based query available.
					array(
						'key'     => WP_MCP_AI_Profession_CPT::META_TASK_PATTERNS,
						'value'   => '{}',
						'compare' => '!=',
					),
				),
				'fields'         => 'ids',
			)
		);
		$stats['with_task_patterns'] = $patterns_query->found_posts;

		return $stats;
	}

	/**
	 * Render status banner.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_status_banner( $stats ) {
		$seeding_percentage = $stats['total_professions'] > 0
			? round( ( $stats['seeded_professions'] / $stats['total_professions'] ) * 100 )
			: 0;

		$status_class   = 'success';
		$status_message = __( 'System Ready', 'mcp-ai-wpoos' );

		if ( $seeding_percentage < 50 ) {
			$status_class   = 'warning';
			$status_message = __( 'Seeding Incomplete', 'mcp-ai-wpoos' );
		} elseif ( $seeding_percentage < 90 ) {
			$status_class   = 'info';
			$status_message = __( 'Partially Seeded', 'mcp-ai-wpoos' );
		}

		?>
		<div class="status-banner status-<?php echo esc_attr( $status_class ); ?>">
			<div class="status-icon">
				<span class="dashicons dashicons-<?php echo esc_attr( 'success' === $status_class ? 'yes-alt' : ( 'warning' === $status_class ? 'warning' : 'info' ) ); ?>"></span>
			</div>
			<div class="status-content">
				<h3><?php echo esc_html( $status_message ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: 1: seeded count, 2: total count, 3: percentage */
						esc_html__( '%1$d of %2$d professions have orchestration data (%3$d%%)', 'mcp-ai-wpoos' ),
						esc_html( $stats['seeded_professions'] ),
						esc_html( $stats['total_professions'] ),
						esc_html( $seeding_percentage )
					);
					?>
				</p>
			</div>
			<div class="status-action">
				<?php if ( $seeding_percentage < 100 ) : ?>
					<button type="button" class="button button-primary" id="run-seeder-btn">
						<span class="dashicons dashicons-admin-tools"></span>
						<?php esc_html_e( 'Run Seeder', 'mcp-ai-wpoos' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="button button-secondary" id="refresh-stats-btn">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh Stats', 'mcp-ai-wpoos' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Count orchestration and agent-related tools.
	 *
	 * @return int Number of orchestration and agent tools.
	 */
	protected function count_orchestration_tools() {
		$count = 0;

		// Get tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry ) {
			return 0;
		}

		$all_tools = $registry->get_tools();
		if ( ! is_array( $all_tools ) ) {
			return 0;
		}

		// Count tools with slugs that contain 'orchestration', 'agent', 'delegate', or 'team'.
		$orchestration_keywords = array( 'orchestration', 'agent', 'delegate', 'team', 'autonomous' );

		foreach ( $all_tools as $tool ) {
			if ( ! ( $tool instanceof WP_MCP_AI_Tool_Interface ) ) {
				continue;
			}

			$tool_slug = $tool->get_slug();
			foreach ( $orchestration_keywords as $keyword ) {
				if ( false !== strpos( $tool_slug, $keyword ) ) {
					++$count;
					break; // Count each tool only once.
				}
			}
		}

		return $count;
	}

	/**
	 * Get agent tool names for display.
	 *
	 * @return array Array of agent tool slugs.
	 */
	protected function get_agent_tool_names() {
		$agent_tools = array();

		// Get tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		if ( ! $registry ) {
			return array();
		}

		$all_tools = $registry->get_tools();
		if ( ! is_array( $all_tools ) ) {
			return array();
		}

		// Get tools with slugs that contain 'agent', 'delegate', or 'team'.
		$agent_keywords = array( 'agent', 'delegate', 'team' );

		foreach ( $all_tools as $tool ) {
			if ( ! ( $tool instanceof WP_MCP_AI_Tool_Interface ) ) {
				continue;
			}

			$tool_slug = $tool->get_slug();
			foreach ( $agent_keywords as $keyword ) {
				if ( false !== strpos( $tool_slug, $keyword ) ) {
					$agent_tools[] = $tool_slug;
					break; // Add each tool only once.
				}
			}
		}

		return $agent_tools;
	}

	/**
	 * Render statistics cards.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_statistics_cards( $stats ) {
		// Count orchestration and agent-related tools dynamically.
		$orchestration_tool_count = $this->count_orchestration_tools();
		$agent_tool_names         = $this->get_agent_tool_names();

		$cards = array(
			array(
				'title'     => __( 'Total Professions', 'mcp-ai-wpoos' ),
				'value'     => $stats['total_professions'],
				'icon'      => 'groups',
				'color'     => '#2271b1',
				'data_attr' => 'total_professions',
			),
			array(
				'title'     => __( 'Seeded Professions', 'mcp-ai-wpoos' ),
				'value'     => $stats['seeded_professions'],
				'icon'      => 'yes-alt',
				'color'     => '#00a32a',
				'data_attr' => 'seeded_professions',
			),
			array(
				'title'     => __( 'With Task Patterns', 'mcp-ai-wpoos' ),
				'value'     => $stats['with_task_patterns'],
				'icon'      => 'list-view',
				'color'     => '#f0b849',
				'data_attr' => 'with_task_patterns',
			),
			array(
				'title'       => __( 'Agent Tools', 'mcp-ai-wpoos' ),
				'value'       => $orchestration_tool_count,
				'icon'        => 'admin-tools',
				'color'       => '#8c8f94',
				'description' => esc_html( implode( ', ', $agent_tool_names ) ),
				'data_attr'   => 'agent_tools',
			),
		);

		foreach ( $cards as $card ) :
			?>
			<div class="stat-card" style="border-left-color: <?php echo esc_attr( $card['color'] ); ?>">
				<div class="stat-icon" style="color: <?php echo esc_attr( $card['color'] ); ?>">
					<span class="dashicons dashicons-<?php echo esc_attr( $card['icon'] ); ?>"></span>
				</div>
				<div class="stat-content">
					<h3><?php echo esc_html( $card['title'] ); ?></h3>
					<div class="stat-value" data-stat="<?php echo esc_attr( $card['data_attr'] ); ?>"><?php echo esc_html( $card['value'] ); ?></div>
					<?php if ( isset( $card['description'] ) ) : ?>
						<p class="stat-description"><?php echo esc_html( $card['description'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		endforeach;
	}

	/**
	 * Render system status section.
	 *
	 * @return void
	 */
	protected function render_system_status() {
		?>
		<div class="system-status-grid">
			<!-- Cron Jobs Status -->
			<div class="status-card" id="cron-status-card">
				<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Cron Jobs', 'mcp-ai-wpoos' ); ?></h3>
				<div class="status-metrics">
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Active:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value" data-system-status="cron_active">-</span>
					</div>
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Pending:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value" data-system-status="cron_pending">-</span>
					</div>
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Failed:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value error" data-system-status="cron_failed">-</span>
					</div>
				</div>
			</div>

			<!-- Async Operations Status -->
			<div class="status-card" id="async-status-card">
				<h3><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Async Operations', 'mcp-ai-wpoos' ); ?></h3>
				<div class="status-metrics">
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Status:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value status-badge" data-system-status="async_status">-</span>
					</div>
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Stuck Jobs:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value warning" data-system-status="async_stuck_jobs">-</span>
					</div>
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Long Running:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value" data-system-status="async_long_running">-</span>
					</div>
				</div>
			</div>

			<!-- System Health Status -->
			<div class="status-card" id="health-status-card">
				<h3><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?></h3>
				<div class="status-metrics">
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Overall:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value status-badge" data-system-status="health_status">-</span>
					</div>
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Label:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value" data-system-status="health_label">-</span>
					</div>
				</div>
			</div>

			<!-- SSE Connectivity -->
			<div class="status-card" id="sse-status-card">
				<h3><span class="dashicons dashicons-update-alt"></span> <?php esc_html_e( 'SSE Streaming', 'mcp-ai-wpoos' ); ?></h3>
				<div class="status-metrics">
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Available:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value" data-system-status="sse_available">-</span>
					</div>
					<div class="metric">
						<span class="label"><?php esc_html_e( 'Endpoint:', 'mcp-ai-wpoos' ); ?></span>
						<span class="value small" data-system-status="sse_endpoint">-</span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render role distribution chart.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_role_distribution_chart( $stats ) {
		$role_colors = array(
			'planner'    => '#2271b1',
			'executor'   => '#00a32a',
			'critic'     => '#d63638',
			'specialist' => '#f0b849',
			'generalist' => '#8c8f94',
		);

		$role_labels = array(
			'planner'    => __( 'Planner', 'mcp-ai-wpoos' ),
			'executor'   => __( 'Executor', 'mcp-ai-wpoos' ),
			'critic'     => __( 'Critic', 'mcp-ai-wpoos' ),
			'specialist' => __( 'Specialist', 'mcp-ai-wpoos' ),
			'generalist' => __( 'Generalist', 'mcp-ai-wpoos' ),
		);

		$total_seeded = array_sum( $stats['roles'] );
		?>
		<div class="role-chart">
			<?php if ( $total_seeded > 0 ) : ?>
				<div class="role-bars">
					<?php
					foreach ( $stats['roles'] as $role => $count ) :
						$percentage = $total_seeded > 0 ? round( ( $count / $total_seeded ) * 100, 1 ) : 0;
						?>
						<div class="role-bar-row">
							<div class="role-label">
								<span class="role-color-dot" style="background-color: <?php echo esc_attr( $role_colors[ $role ] ); ?>"></span>
								<strong><?php echo esc_html( $role_labels[ $role ] ); ?></strong>
							</div>
							<div class="role-bar-container">
								<div 
									class="role-bar" 
									style="width: <?php echo esc_attr( $percentage ); ?>%; background-color: <?php echo esc_attr( $role_colors[ $role ] ); ?>"
									title="<?php echo esc_attr( $count . ' (' . $percentage . '%)' ); ?>"
								></div>
							</div>
							<div class="role-count">
								<span class="count-value"><?php echo esc_html( $count ); ?></span>
								<span class="count-percentage">(<?php echo esc_html( $percentage ); ?>%)</span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="no-data">
					<p><?php esc_html_e( 'No agent roles assigned yet. Run the seeder to populate orchestration data.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render quick actions section.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_quick_actions( $stats ) {
		?>
		<div class="quick-actions-grid">
			<div class="action-card">
				<span class="dashicons dashicons-admin-settings"></span>
				<h3><?php esc_html_e( 'Orchestration Settings', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Configure orchestration layer settings, budgets, presets, and advanced options.', 'mcp-ai-wpoos' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration' ) ); ?>" class="button">
					<?php esc_html_e( 'Configure Settings', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>

			<div class="action-card">
				<span class="dashicons dashicons-admin-tools"></span>
				<h3><?php esc_html_e( 'Run Orchestration Seeder', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Automatically assign agent roles and task patterns to all professions using AI-powered heuristics.', 'mcp-ai-wpoos' ); ?></p>
				<button type="button" class="button button-primary" id="action-run-seeder">
					<?php esc_html_e( 'Run Seeder', 'mcp-ai-wpoos' ); ?>
				</button>
				<p class="action-note">
					<?php
					if ( 'Not seeded' === $stats['seeder_version'] ) {
						esc_html_e( 'Not run yet', 'mcp-ai-wpoos' );
					} else {
						printf(
							/* translators: %s: version number */
							esc_html__( 'Last run: Version %s', 'mcp-ai-wpoos' ),
							esc_html( $stats['seeder_version'] )
						);
					}
					?>
				</p>
			</div>

			<div class="action-card">
				<span class="dashicons dashicons-chart-bar"></span>
				<h3><?php esc_html_e( 'View Statistics', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Get detailed orchestration statistics via WP-CLI for comprehensive reporting.', 'mcp-ai-wpoos' ); ?></p>
				<code>wp profession orchestration-stats</code>
			</div>

			<div class="action-card">
				<span class="dashicons dashicons-edit"></span>
				<h3><?php esc_html_e( 'Edit Professions', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Manually configure agent roles and orchestration settings for individual professions.', 'mcp-ai-wpoos' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button">
					<?php esc_html_e( 'Manage Professions', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>

			<div class="action-card">
				<span class="dashicons dashicons-book-alt"></span>
				<h3><?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?></h3>
				<p><?php esc_html_e( 'Learn how to use the multi-agent orchestration system with practical examples.', 'mcp-ai-wpoos' ); ?></p>
				<a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/DEEPSEEK-V4-README.md" target="_blank" class="button">
					<?php esc_html_e( 'Read Docs', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render documentation links.
	 *
	 * @return void
	 */
	protected function render_documentation_links() {
		$docs = array(
			array(
				'title'       => __( 'Usage Guide', 'mcp-ai-wpoos' ),
				'url'         => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/DEEPSEEK-V4-USAGE-GUIDE.md',
				'description' => __( 'Complete how-to guide with examples for administrators and developers', 'mcp-ai-wpoos' ),
			),
			array(
				'title'       => __( 'Workflow Examples', 'mcp-ai-wpoos' ),
				'url'         => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/DEEPSEEK-V4-WORKFLOW-EXAMPLES.md',
				'description' => __( 'End-to-end workflow examples with production-ready code', 'mcp-ai-wpoos' ),
			),
			array(
				'title'       => __( 'Quick Reference', 'mcp-ai-wpoos' ),
				'url'         => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/DEEPSEEK-V4-QUICK-REFERENCE-CARD.md',
				'description' => __( 'Developer cheat sheet with quick commands and code snippets', 'mcp-ai-wpoos' ),
			),
			array(
				'title'       => __( 'Implementation Summary', 'mcp-ai-wpoos' ),
				'url'         => 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/DEEPSEEK-V4-IMPLEMENTATION-SUMMARY.md',
				'description' => __( 'Technical overview and validation results', 'mcp-ai-wpoos' ),
			),
		);

		?>
		<div class="documentation-links">
			<?php foreach ( $docs as $doc ) : ?>
				<div class="doc-link-card">
					<h4><a href="<?php echo esc_url( $doc['url'] ); ?>" target="_blank"><?php echo esc_html( $doc['title'] ); ?></a></h4>
					<p><?php echo esc_html( $doc['description'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render recent workflows section.
	 *
	 * @return void
	 */
	protected function render_recent_workflows() {
		?>
		<div class="workflows-list-container">
			<div class="workflows-header">
				<button type="button" class="button button-secondary" id="refresh-workflows-btn">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>
			<div id="workflows-list-content">
				<div class="workflows-loading">
					<span class="spinner is-active"></span>
					<p><?php esc_html_e( 'Loading workflows...', 'mcp-ai-wpoos' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Run orchestration seeder.
	 *
	 * @return void
	 */
	public function ajax_run_seeder() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Profession_Orchestration_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-orchestration-seeder.php';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$force = isset( $_POST['force'] ) && sanitize_text_field( wp_unslash( $_POST['force'] ) );

		$seeder = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$result = $seeder->seed_all( $force );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Get system status information for dashboard updates.
	 *
	 * Includes cron status, async job health, orchestration health, and SSE connectivity.
	 *
	 * @return array System status data.
	 */
	protected function get_system_status() {
		$status = array(
			'cron'   => array(),
			'async'  => array(),
			'sse'    => array(),
			'health' => array(),
		);

		// Get cron job status if service is available.
		if ( class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			try {
				$cron_service   = new WP_MCP_AI_Cron_Status_Service();
				$cron_summary   = $cron_service->get_status_summary( 0, 5 );
				$status['cron'] = array(
					'total'     => count( $cron_summary ),
					'active'    => 0,
					'completed' => 0,
					'pending'   => 0,
					'failed'    => 0,
					'jobs'      => array(),
				);

				foreach ( $cron_summary as $job ) {
					$job_status = isset( $job['status'] ) ? $job['status'] : 'unknown';

					if ( 'active' === $job_status || 'running' === $job_status ) {
						++$status['cron']['active'];
					} elseif ( 'completed' === $job_status ) {
						++$status['cron']['completed'];
					} elseif ( 'pending' === $job_status ) {
						++$status['cron']['pending'];
					} elseif ( 'failed' === $job_status ) {
						++$status['cron']['failed'];
					}

					// Include recent jobs for display.
					if ( count( $status['cron']['jobs'] ) < 5 ) {
						$status['cron']['jobs'][] = array(
							'job_id' => isset( $job['job_id'] ) ? $job['job_id'] : '',
							'title'  => isset( $job['title'] ) ? $job['title'] : 'Unknown',
							'status' => $job_status,
						);
					}
				}
			} catch ( Exception $e ) {
				// Silently fail - status monitoring should not break the dashboard.
				$status['cron']['error'] = $e->getMessage();
			}
		}

		// Get async health status if monitor is available.
		if ( class_exists( 'WP_MCP_AI_Async_Health_Monitor' ) ) {
			try {
				WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] Collecting async status' );
				$async_health    = WP_MCP_AI_Async_Health_Monitor::check_async_health();
				$status['async'] = array(
					'status'         => isset( $async_health['status'] ) ? $async_health['status'] : 'unknown',
					'stuck_jobs'     => isset( $async_health['stuck_jobs'] ) ? $async_health['stuck_jobs'] : 0,
					'long_running'   => isset( $async_health['long_running'] ) ? $async_health['long_running'] : 0,
					'pending_jobs'   => isset( $async_health['pending_jobs'] ) ? $async_health['pending_jobs'] : 0,
					'failed_jobs'    => isset( $async_health['failed_jobs'] ) ? $async_health['failed_jobs'] : 0,
					'cron_scheduled' => isset( $async_health['cron_scheduled'] ) ? $async_health['cron_scheduled'] : false,
					'issues'         => isset( $async_health['issues'] ) ? $async_health['issues'] : array(),
				);
				WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] Async status collected', $status['async'] );
			} catch ( Exception $e ) {
				$status['async']['error'] = $e->getMessage();
				WP_MCP_AI_Logger::log_error( '[Admin Dashboard] Failed to collect async status: ' . $e->getMessage() );
			}
		} else {
			WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] WP_MCP_AI_Async_Health_Monitor class not available' );
		}

		// Get orchestration health status if service is available.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
			try {
				WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] Collecting health status' );
				$health_status    = WP_MCP_AI_Orchestration_Health_Service::get_health_status();
				$status['health'] = array(
					'status'  => isset( $health_status['status'] ) ? $health_status['status'] : 'unknown',
					'label'   => isset( $health_status['label'] ) ? $health_status['label'] : 'Unknown',
					'icon'    => isset( $health_status['icon'] ) ? $health_status['icon'] : '❓',
					'metrics' => isset( $health_status['metrics'] ) ? $health_status['metrics'] : array(),
				);
				WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] Health status collected', $status['health'] );
			} catch ( Exception $e ) {
				$status['health']['error'] = $e->getMessage();
				WP_MCP_AI_Logger::log_error( '[Admin Dashboard] Failed to collect health status: ' . $e->getMessage() );
			}
		} else {
			WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] WP_MCP_AI_Orchestration_Health_Service class not available' );
		}

		// SSE connectivity check - basic check if SSE endpoint is configured.
		WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] Collecting SSE status' );
		$status['sse'] = array(
			'available' => class_exists( 'WP_MCP_AI_SSE_Stream' ),
			'endpoint'  => rest_url( 'mcp-ai/v1/jobs' ),
		);
		WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] SSE status collected', $status['sse'] );

		// Diagnostic: Log final collected status.
		WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] System status collection complete', $status );

		return $status;
	}

	/**
	 * AJAX handler: Get orchestration statistics.
	 *
	 * @return void
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		WP_MCP_AI_Logger::log_debug( '[Admin Dashboard] AJAX get_stats called' );

		$stats = $this->get_orchestration_statistics();

		// Add system status information.
		$stats['system_status'] = $this->get_system_status();

		WP_MCP_AI_Logger::log_debug(
			'[Admin Dashboard] AJAX get_stats response prepared',
			array(
				'has_system_status'  => isset( $stats['system_status'] ),
				'system_status_keys' => isset( $stats['system_status'] ) ? array_keys( $stats['system_status'] ) : array(),
			)
		);

		wp_send_json_success( $stats );
	}

	/**
	 * AJAX handler: Get recent workflows.
	 *
	 * @return void
	 */
	public function ajax_get_recent_workflows() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_recent_workflows();
		wp_send_json_success( $workflows );
	}

	/**
	 * Get recent workflows from transients.
	 *
	 * @return array List of recent workflows.
	 */
	protected function get_recent_workflows() {
		// Try to get from cache first (5 minute cache for dashboard performance).
		$cache_key = 'wp_mcp_ai_recent_workflows';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// Get all workflow transients.
		global $wpdb;

		$transient_prefix = '_transient_wp_mcp_ai_workflow_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached with transient API above.
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				ORDER BY option_id DESC 
				LIMIT 10",
				$wpdb->esc_like( $transient_prefix ) . '%'
			)
		);

		$workflows = array();
		foreach ( $transients as $transient ) {
			$workflow_id   = str_replace( $transient_prefix, '', $transient->option_name );
			$workflow_data = maybe_unserialize( $transient->option_value );

			if ( is_array( $workflow_data ) && isset( $workflow_data['workflow_id'] ) ) {
				$tasks_total = isset( $workflow_data['tasks'] ) ? count( $workflow_data['tasks'] ) : 0;
				$tasks_done  = 0;

				if ( isset( $workflow_data['tasks'] ) && is_array( $workflow_data['tasks'] ) ) {
					foreach ( $workflow_data['tasks'] as $task ) {
						if ( isset( $task['status'] ) && 'completed' === $task['status'] ) {
							++$tasks_done;
						}
					}
				}

				$workflows[] = array(
					'workflow_id'  => $workflow_data['workflow_id'],
					'state'        => $workflow_data['state'] ?? 'unknown',
					'tasks_total'  => $tasks_total,
					'tasks_done'   => $tasks_done,
					'created_at'   => $workflow_data['created_at'] ?? '',
					'updated_at'   => $workflow_data['updated_at'] ?? '',
					'started_at'   => $workflow_data['started_at'] ?? null,
					'completed_at' => $workflow_data['completed_at'] ?? null,
					'team_id'      => $workflow_data['team_id'] ?? null,
					'task_type'    => $workflow_data['task_type'] ?? 'generic',
				);
			}
		}

		// Cache the results for 5 minutes.
		set_transient( $cache_key, $workflows, 5 * MINUTE_IN_SECONDS );

		return $workflows;
	}

	/**
	 * AJAX handler: Execute workflow.
	 *
	 * @return void
	 */
	public function ajax_execute_workflow() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workflow_id'] ) ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID is required.', 'mcp-ai-wpoos' ) ) );
		}

		// Log workflow execution start.
		WP_MCP_AI_Logger::log_event(
			'workflow_execution_started',
			'Workflow execution initiated from dashboard',
			array(
				'workflow_id' => $workflow_id,
				'user_id'     => get_current_user_id(),
				'timestamp'   => current_time( 'mysql' ),
			)
		);

		// Check if Enhanced Workflow Coordinator is available.
		if ( ! class_exists( 'WP_MCP_AI_Enhanced_Workflow_Coordinator' ) ) {
			WP_MCP_AI_Logger::log_error(
				'workflow_coordinator_unavailable',
				'Enhanced Workflow Coordinator class not found',
				array( 'workflow_id' => $workflow_id )
			);
			wp_send_json_error( array( 'message' => __( 'Workflow coordinator not available.', 'mcp-ai-wpoos' ) ) );
		}

		try {
			$start_time  = microtime( true );
			$coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();
			$result      = $coordinator->execute_workflow( $workflow_id );
			$end_time    = microtime( true );
			$duration    = round( $end_time - $start_time, 2 );

			if ( is_wp_error( $result ) ) {
				// Log workflow execution error.
				WP_MCP_AI_Logger::log_error(
					'workflow_execution_error',
					'Workflow execution failed',
					array(
						'workflow_id' => $workflow_id,
						'error_code'  => $result->get_error_code(),
						'error_msg'   => $result->get_error_message(),
						'duration'    => $duration,
					)
				);

				wp_send_json_error(
					array(
						'message'  => $result->get_error_message(),
						'code'     => $result->get_error_code(),
						'duration' => $duration,
					)
				);
			}

			// Extract metrics from result if available.
			$metrics = array(
				'duration'       => $duration,
				'workflow_id'    => $workflow_id,
				'tasks_executed' => isset( $result['tasks_completed'] ) ? $result['tasks_completed'] : 0,
			);

			// Log token usage if available in result.
			if ( isset( $result['tokens_used'] ) ) {
				$metrics['tokens_used'] = $result['tokens_used'];
			}
			if ( isset( $result['estimated_cost'] ) ) {
				$metrics['estimated_cost'] = $result['estimated_cost'];
			}

			// Log successful workflow execution with metrics.
			WP_MCP_AI_Logger::log_event(
				'workflow_execution_completed',
				'Workflow execution completed successfully',
				$metrics
			);

			wp_send_json_success(
				array(
					'message'     => __( 'Workflow execution started successfully.', 'mcp-ai-wpoos' ),
					'workflow_id' => $workflow_id,
					'result'      => $result,
					'metrics'     => $metrics,
				)
			);

		} catch ( Exception $e ) {
			// Log exception.
			WP_MCP_AI_Logger::log_error(
				'workflow_execution_exception',
				'Exception during workflow execution',
				array(
					'workflow_id' => $workflow_id,
					'exception'   => $e->getMessage(),
					'trace'       => $e->getTraceAsString(),
				)
			);

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Error executing workflow: %s', 'mcp-ai-wpoos' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * AJAX handler: Refresh agent memory statistics.
	 *
	 * Clears the cache and returns fresh memory stats.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function ajax_refresh_memory_stats() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Clear the cache to force fresh data retrieval.
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );

		// Get fresh stats (will be recalculated and cached).
		$stats = $this->get_agent_memory_stats();

		wp_send_json_success(
			array(
				'message' => __( 'Memory stats refreshed successfully.', 'mcp-ai-wpoos' ),
				'stats'   => $stats,
			)
		);
	}

	/**
	 * AJAX handler: Restart workflow.
	 *
	 * @return void
	 */
	public function ajax_restart_workflow() {
		check_ajax_referer( 'wp_mcp_ai_orchestration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workflow_id'] ) ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID is required.', 'mcp-ai-wpoos' ) ) );
		}

		// Log workflow restart attempt.
		WP_MCP_AI_Logger::log_event(
			'workflow_restart_initiated',
			'Workflow restart requested from dashboard',
			array(
				'workflow_id' => $workflow_id,
				'user_id'     => get_current_user_id(),
				'timestamp'   => current_time( 'mysql' ),
			)
		);

		// Get the existing workflow data.
		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		$workflow_data = get_transient( $transient_key );

		if ( false === $workflow_data ) {
			WP_MCP_AI_Logger::log_warning(
				'workflow_restart_not_found',
				'Attempted to restart non-existent workflow',
				array( 'workflow_id' => $workflow_id )
			);
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		// Store original state for logging.
		$original_state = $workflow_data['state'];
		$tasks_count    = isset( $workflow_data['tasks'] ) ? count( $workflow_data['tasks'] ) : 0;
		$tasks_reset    = 0;

		// Reset workflow state to initialized.
		$workflow_data['state']        = 'initialized';
		$workflow_data['started_at']   = null;
		$workflow_data['completed_at'] = null;
		$workflow_data['updated_at']   = current_time( 'mysql' );

		// Reset all tasks to pending status.
		if ( isset( $workflow_data['tasks'] ) && is_array( $workflow_data['tasks'] ) ) {
			foreach ( $workflow_data['tasks'] as &$task ) {
				if ( 'composition' !== $task['type'] ) {
					$task['status'] = 'pending';
					unset( $task['completed_at'] );
					unset( $task['error'] );
					++$tasks_reset;
				}
			}
		}

		// Save the reset workflow.
		set_transient( $transient_key, $workflow_data, 7 * DAY_IN_SECONDS );

		// Log successful restart with metrics.
		WP_MCP_AI_Logger::log_event(
			'workflow_restarted',
			'Workflow successfully reset to initialized state',
			array(
				'workflow_id'    => $workflow_id,
				'original_state' => $original_state,
				'tasks_total'    => $tasks_count,
				'tasks_reset'    => $tasks_reset,
				'timestamp'      => current_time( 'mysql' ),
			)
		);

		wp_send_json_success(
			array(
				'message'     => __( 'Workflow reset successfully. You can now continue it.', 'mcp-ai-wpoos' ),
				'workflow_id' => $workflow_id,
				'workflow'    => $workflow_data,
				'metrics'     => array(
					'original_state' => $original_state,
					'tasks_reset'    => $tasks_reset,
				),
			)
		);
	}

	/**
	 * Render a single breakdown table for the memory widget.
	 *
	 * Used for the Phase 4a group-by toggle (type / wing / importance). Hidden
	 * panes carry an `aria-hidden="true"` attribute and a `hidden` class so they
	 * remain accessible to assistive tech as inactive tab panels.
	 *
	 * @since 1.1.0
	 *
	 * @param string $group_key       Group identifier matching the `<select>` value.
	 * @param string $column_label    Localised label for the first column header.
	 * @param array  $counts          Map of bucket label => count.
	 * @param int    $total_contexts  Grand total used for percentage calculation.
	 * @param bool   $is_active       Whether this is the initially-visible pane.
	 * @return void
	 */
	protected function render_breakdown_table( $group_key, $column_label, array $counts, $total_contexts, $is_active ) {
		// Sort by count descending so the largest bucket is on top.
		arsort( $counts );

		$pane_classes = 'memory-breakdown-pane';
		if ( ! $is_active ) {
			$pane_classes .= ' hidden';
		}

		?>
		<div
			class="<?php echo esc_attr( $pane_classes ); ?>"
			data-group-pane="<?php echo esc_attr( $group_key ); ?>"
			<?php
			if ( ! $is_active ) :
				?>
				aria-hidden="true"<?php endif; ?>
		>
			<?php if ( empty( $counts ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'No data available for this grouping.', 'mcp-ai-wpoos' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php echo esc_html( $column_label ); ?></th>
							<th><?php esc_html_e( 'Count', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Percentage', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $counts as $bucket => $count ) :
							$percentage = $total_contexts > 0 ? round( ( $count / $total_contexts ) * 100, 1 ) : 0;
							?>
							<tr>
								<td><strong><?php echo esc_html( ucfirst( (string) $bucket ) ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
								<td><?php echo esc_html( $percentage ); ?>%</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the retrieval-path mini-chart.
	 *
	 * Visualises the 7-day rolling mix of `wake_up_context` retrieval paths
	 * (graph vs transient) as a single horizontal stacked bar plus textual
	 * percentages. Lets operators see at a glance whether the Graphify bridge
	 * is actually doing work or whether requests are silently falling through
	 * to the legacy transient-only path.
	 *
	 * @since 1.1.0
	 *
	 * @param array $telemetry {
	 *     Telemetry totals for the rolling 7-day window.
	 *
	 *     @type int $graph     Count of graph-mode retrievals.
	 *     @type int $transient Count of transient-mode retrievals.
	 *     @type int $total     Sum across paths.
	 * }
	 * @return void
	 */
	protected function render_retrieval_path_chart( array $telemetry ) {
		$graph     = isset( $telemetry['graph'] ) ? (int) $telemetry['graph'] : 0;
		$transient = isset( $telemetry['transient'] ) ? (int) $telemetry['transient'] : 0;
		$total     = isset( $telemetry['total'] ) ? (int) $telemetry['total'] : ( $graph + $transient );

		$graph_pct     = $total > 0 ? round( ( $graph / $total ) * 100, 1 ) : 0;
		$transient_pct = $total > 0 ? round( ( $transient / $total ) * 100, 1 ) : 0;

		?>
		<div class="memory-retrieval-path">
			<div class="memory-retrieval-path-header">
				<h4><?php esc_html_e( 'Retrieval Path Mix', 'mcp-ai-wpoos' ); ?> <span class="memory-retrieval-path-window">(<?php esc_html_e( 'last 7 days', 'mcp-ai-wpoos' ); ?>)</span></h4>
			</div>

			<?php if ( $total <= 0 ) : ?>
				<p class="description">
					<?php esc_html_e( 'No wake_up_context calls recorded in the last 7 days.', 'mcp-ai-wpoos' ); ?>
				</p>
			<?php else : ?>
				<div class="memory-retrieval-path-bar" role="img" aria-label="
				<?php
					echo esc_attr(
						sprintf(
							/* translators: 1: graph percentage, 2: transient percentage */
							__( 'Retrieval path mix: graph %1$s%%, transient %2$s%%.', 'mcp-ai-wpoos' ),
							number_format_i18n( $graph_pct, 1 ),
							number_format_i18n( $transient_pct, 1 )
						)
					);
				?>
				">
					<span
						class="memory-retrieval-path-bar-segment memory-retrieval-path-bar-segment--graph"
						style="width: <?php echo esc_attr( (float) $graph_pct ); ?>%;"
					></span>
					<span
						class="memory-retrieval-path-bar-segment memory-retrieval-path-bar-segment--transient"
						style="width: <?php echo esc_attr( (float) $transient_pct ); ?>%;"
					></span>
				</div>
				<p class="memory-retrieval-path-legend">
					<span class="memory-retrieval-path-swatch memory-retrieval-path-swatch--graph"></span>
					<?php
					printf(
						/* translators: 1: percentage, 2: count */
						esc_html__( 'graph: %1$s%% (%2$s)', 'mcp-ai-wpoos' ),
						esc_html( number_format_i18n( $graph_pct, 1 ) ),
						esc_html( number_format_i18n( $graph ) )
					);
					?>
					<span class="memory-retrieval-path-separator">·</span>
					<span class="memory-retrieval-path-swatch memory-retrieval-path-swatch--transient"></span>
					<?php
					printf(
						/* translators: 1: percentage, 2: count */
						esc_html__( 'transient: %1$s%% (%2$s)', 'mcp-ai-wpoos' ),
						esc_html( number_format_i18n( $transient_pct, 1 ) ),
						esc_html( number_format_i18n( $transient ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get agent memory statistics.
	 *
	 * Retrieves stats from cache or calculates them fresh.
	 *
	 * @return array Array containing total_contexts, total_agents, and contexts_by_type.
	 * @since 1.1.0
	 */
	protected function get_agent_memory_stats() {
		// Try to get stats from cache first (5 minute cache for dashboard performance).
		$cache_key = 'wp_mcp_ai_agent_memory_stats';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			// Always recompute `bridge_active` because Graphify can be
			// activated/deactivated at any time and the class_exists() check is
			// cheap. Without this, a stale `false` from before Graphify was
			// enabled would survive for up to 5 minutes after activation,
			// causing the dashboard to incorrectly show "Graphify Memory
			// Bridge: not installed" even though the add-on is active.
			//
			// Same applies to `persistent_storage.available`: when JetEngine
			// (or its agent-memory CCT table) becomes available after the
			// dashboard was first rendered, the stale `available => false`
			// would otherwise keep the JetEngine "Install…" notice up for
			// the lifetime of the cache.
			if ( is_array( $cached ) ) {
				$cached['bridge_active'] = class_exists( 'NV_oOS_Graphify_Memory_Bridge' );

				$persistent_cached = isset( $cached['persistent_storage'] ) && is_array( $cached['persistent_storage'] )
					? $cached['persistent_storage']
					: array();
				$cached_available  = ! empty( $persistent_cached['available'] );
				$live_available    = $this->is_persistent_memory_available();

				if ( $cached_available !== $live_available ) {
					// Availability flipped since the cache was written.
					// Drop the cache entirely so the next call recomputes
					// the row count + tier breakdown against the now-live
					// CCT table (or stops querying it if it disappeared).
					delete_transient( $cache_key );
				} else {
					$cached['persistent_storage'] = array_merge(
						array(
							'cct_count'      => 0,
							'available'      => false,
							'tier_breakdown' => array(),
						),
						$persistent_cached,
						array( 'available' => $live_available )
					);
					return $cached;
				}
			} else {
				return $cached;
			}
		}

		global $wpdb;

		// Count total stored contexts.
		$total_contexts = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached with transient API above.
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} 
				WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_index_' ) . '%'
			)
		);

		$contexts_by_type       = array();
		$contexts_by_wing       = array();
		$contexts_by_importance = array();
		$rooms_by_wing          = array();
		$mined_count            = 0;
		$total_agents           = 0;

		foreach ( $transients as $transient ) {
			$index = maybe_unserialize( $transient->option_value );
			if ( is_array( $index ) && ! empty( $index ) ) {
				++$total_agents;
				$total_contexts += count( $index );

				foreach ( $index as $context_id => $context_meta ) {
					if ( ! is_array( $context_meta ) ) {
						continue;
					}

					// Count by type (existing behaviour).
					$type = isset( $context_meta['type'] ) && '' !== $context_meta['type'] ? (string) $context_meta['type'] : 'generic';
					if ( ! isset( $contexts_by_type[ $type ] ) ) {
						$contexts_by_type[ $type ] = 0;
					}
					++$contexts_by_type[ $type ];

					// Count by importance (Phase 4a).
					$importance = isset( $context_meta['importance'] ) && '' !== $context_meta['importance']
						? (string) $context_meta['importance']
						: 'medium';
					if ( ! isset( $contexts_by_importance[ $importance ] ) ) {
						$contexts_by_importance[ $importance ] = 0;
					}
					++$contexts_by_importance[ $importance ];

					// Count by wing (Phase 4a). Empty wing reported as "(unscoped)".
					$wing_raw = isset( $context_meta['wing'] ) ? (string) $context_meta['wing'] : '';
					$wing_key = '' === $wing_raw ? '(unscoped)' : $wing_raw;
					if ( ! isset( $contexts_by_wing[ $wing_key ] ) ) {
						$contexts_by_wing[ $wing_key ] = 0;
					}
					++$contexts_by_wing[ $wing_key ];

					// Track rooms per wing for the (wing, room) cardinality count.
					$room_raw = isset( $context_meta['room'] ) ? (string) $context_meta['room'] : '';
					if ( '' !== $room_raw ) {
						if ( ! isset( $rooms_by_wing[ $wing_key ] ) ) {
							$rooms_by_wing[ $wing_key ] = array();
						}
						$rooms_by_wing[ $wing_key ][ $room_raw ] = true;
					}

					// Count mined memories (`mine_agent_memory` stores with verbatim=true).
					if ( ! empty( $context_meta['verbatim'] ) ) {
						++$mined_count;
					}
				}
			}
		}

		// Distinct wing count excludes the synthetic "(unscoped)" bucket so the
		// card answers "how many real wings have content?".
		$wings_count = 0;
		foreach ( array_keys( $contexts_by_wing ) as $wing_key ) {
			if ( '(unscoped)' !== $wing_key ) {
				++$wings_count;
			}
		}

		$rooms_count = 0;
		foreach ( $rooms_by_wing as $rooms ) {
			$rooms_count += count( $rooms );
		}

		$stats = array(
			'total_contexts'         => $total_contexts,
			'total_agents'           => $total_agents,
			'contexts_by_type'       => $contexts_by_type,
			'contexts_by_wing'       => $contexts_by_wing,
			'contexts_by_importance' => $contexts_by_importance,
			'wings_count'            => $wings_count,
			'rooms_count'            => $rooms_count,
			'mined_count'            => $mined_count,
			'bridge_active'          => class_exists( 'NV_oOS_Graphify_Memory_Bridge' ),
			'retrieval_path'         => $this->get_retrieval_path_telemetry(),
			'persistent_storage'     => $this->get_persistent_memory_stats(),
		);

		// Cache the results for 5 minutes.
		set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Aggregate `wake_up_context` retrieval-path telemetry into a 7-day total.
	 *
	 * Reads the rolling buckets written by
	 * `WP_MCP_AI_Tool_Wake_Up_Context::record_retrieval_telemetry()` and
	 * collapses them to a flat `path => count` array, plus a `total` for
	 * percentage rendering. The tool keeps the bucket window pruned, so we
	 * just sum what's there.
	 *
	 * @since 1.1.0
	 *
	 * @return array {
	 *     @type int $graph     Count of `graph` retrievals.
	 *     @type int $transient Count of `transient` retrievals.
	 *     @type int $total     Sum across known paths.
	 * }
	 */
	protected function get_retrieval_path_telemetry() {
		$telemetry = get_option( 'wp_mcp_ai_wake_up_telemetry', array() );
		if ( ! is_array( $telemetry ) ) {
			$telemetry = array();
		}

		$totals = array(
			'graph'     => 0,
			'transient' => 0,
		);

		$cutoff = gmdate( 'Y-m-d', time() - ( 7 * DAY_IN_SECONDS ) );

		foreach ( $telemetry as $bucket_date => $paths ) {
			if ( ! is_string( $bucket_date ) || $bucket_date < $cutoff ) {
				continue;
			}
			if ( ! is_array( $paths ) ) {
				continue;
			}
			foreach ( $totals as $path => $_count ) {
				if ( isset( $paths[ $path ] ) ) {
					$totals[ $path ] += (int) $paths[ $path ];
				}
			}
		}

		$totals['total'] = $totals['graph'] + $totals['transient'];
		return $totals;
	}

	/**
	 * Cheap "is the agent-memory CCT actually queryable right now?" probe.
	 *
	 * Used on cache hits to detect when JetEngine (or its CCT table) has
	 * become available since the cached stats were written, without
	 * paying the full {@see get_persistent_memory_stats()} cost. Issues a
	 * single `SHOW TABLES LIKE` query.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the JetEngine agent-memory CCT table exists.
	 */
	protected function is_persistent_memory_available() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return false;
		}

		global $wpdb;
		$slug = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();

		// Defense-in-depth: validate the slug is a safe alphanumeric string
		// before interpolating into a table name. The slug is currently a
		// hardcoded class constant, but this guard protects against future
		// changes that might introduce dynamic input.
		if ( ! preg_match( '/^[a-z0-9_]+$/', $slug ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Direct read on custom plugin transient and stats tables; no WP Core API available for these schemas. ALTER TABLE on custom plugin tables only; managed by the plugin's own schema migration system.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists === $table;
	}

	/**
	 * Aggregate persistent (CCT) vs transient agent-memory counts.
	 *
	 * Phase 4b-4: surfaces durability of the agent-memory store. The
	 * `ai_agent_memories` JetEngine CCT mirrors every transient write via
	 * `WP_MCP_AI_Agent_Memory_CCT_Bridge`, so this returns:
	 *   - `cct_count`        Rows in the durable CCT table (0 when JetEngine
	 *                        is missing or the CCT hasn't been provisioned).
	 *   - `available`        Whether the CCT table exists and is queryable.
	 *   - `tier_breakdown`   Memory-tier distribution from CCT rows
	 *                        (working/episodic/semantic/procedural).
	 *
	 * @since 1.1.0
	 *
	 * @return array {
	 *     @type int   $cct_count       Total rows in the durable CCT.
	 *     @type bool  $available       True when the CCT table exists.
	 *     @type array $tier_breakdown  Map of memory_tier => count.
	 * }
	 */
	protected function get_persistent_memory_stats() {
		$default = array(
			'cct_count'      => 0,
			'available'      => false,
			'tier_breakdown' => array(),
		);

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return $default;
		}

		global $wpdb;
		$slug = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();
		// Defense-in-depth: validate slug is safe before table-name interpolation.
		if ( ! preg_match( '/^[a-z0-9_]+$/', $slug ) ) {
			return $default;
		}
		// Table name comes from the JetEngine convention `{prefix}jet_cct_{slug}`
		// where `$slug` is a class constant.
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Direct read on custom plugin transient and stats tables; no WP Core API available for these schemas. ALTER TABLE on custom plugin tables only; managed by the plugin's own schema migration system.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return $default;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct read on custom plugin transient and stats tables; no WP Core API available for these schemas. Table name is a hardcoded plugin constant; cannot be parameterised in a CREATE/ALTER statement.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		$tiers = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct read on custom plugin transient and stats tables; no WP Core API available for these schemas. Table name is a hardcoded plugin constant; cannot be parameterised in a CREATE/ALTER statement.
		$rows = $wpdb->get_results( "SELECT memory_tier, COUNT(*) AS n FROM `{$table}` GROUP BY memory_tier" );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$tier_key           = isset( $row->memory_tier ) && '' !== $row->memory_tier ? (string) $row->memory_tier : 'unspecified';
				$tiers[ $tier_key ] = isset( $row->n ) ? (int) $row->n : 0;
			}
		}

		return array(
			'cct_count'      => $count,
			'available'      => true,
			'tier_breakdown' => $tiers,
		);
	}

	/**
	 * Render agent memory statistics widget.
	 *
	 * Shows usage statistics for the new agent memory tools (Phase 4/5).
	 *
	 * @return void
	 * @since 1.1.0
	 */
	protected function render_agent_memory_stats() {
		$stats = $this->get_agent_memory_stats();

		$total_contexts         = isset( $stats['total_contexts'] ) ? (int) $stats['total_contexts'] : 0;
		$total_agents           = isset( $stats['total_agents'] ) ? (int) $stats['total_agents'] : 0;
		$contexts_by_type       = isset( $stats['contexts_by_type'] ) && is_array( $stats['contexts_by_type'] ) ? $stats['contexts_by_type'] : array();
		$contexts_by_wing       = isset( $stats['contexts_by_wing'] ) && is_array( $stats['contexts_by_wing'] ) ? $stats['contexts_by_wing'] : array();
		$contexts_by_importance = isset( $stats['contexts_by_importance'] ) && is_array( $stats['contexts_by_importance'] ) ? $stats['contexts_by_importance'] : array();
		$wings_count            = isset( $stats['wings_count'] ) ? (int) $stats['wings_count'] : 0;
		$rooms_count            = isset( $stats['rooms_count'] ) ? (int) $stats['rooms_count'] : 0;
		$mined_count            = isset( $stats['mined_count'] ) ? (int) $stats['mined_count'] : 0;
		$bridge_active          = ! empty( $stats['bridge_active'] );
		$persistent             = isset( $stats['persistent_storage'] ) && is_array( $stats['persistent_storage'] ) ? $stats['persistent_storage'] : array(
			'cct_count'      => 0,
			'available'      => false,
			'tier_breakdown' => array(),
		);
		$retrieval_path         = isset( $stats['retrieval_path'] ) && is_array( $stats['retrieval_path'] ) ? $stats['retrieval_path'] : array(
			'graph'     => 0,
			'transient' => 0,
			'total'     => 0,
		);

		$graph_explorer_url = $bridge_active ? admin_url( 'admin.php?page=nvoos-graphify' ) : '';

		?>
		<div class="agent-memory-stats-widget">
			<!-- Phase 4a: Graphify Memory Bridge status pill. -->
			<div class="memory-bridge-status">
				<?php if ( $bridge_active ) : ?>
					<span class="memory-bridge-pill memory-bridge-pill--active">
						<span class="dashicons dashicons-networking" aria-hidden="true"></span>
						<?php esc_html_e( 'Graphify Memory Bridge: active', 'mcp-ai-wpoos' ); ?>
					</span>
					<a href="<?php echo esc_url( $graph_explorer_url ); ?>" class="button button-small">
						<?php esc_html_e( 'Open Graph Explorer', 'mcp-ai-wpoos' ); ?>
					</a>
				<?php else : ?>
					<span class="memory-bridge-pill memory-bridge-pill--inactive">
						<span class="dashicons dashicons-marker" aria-hidden="true"></span>
						<?php esc_html_e( 'Graphify Memory Bridge: not installed', 'mcp-ai-wpoos' ); ?>
					</span>
					<span class="description">
						<?php esc_html_e( 'Install the NV oOS Graphify add-on to enable graph-mode retrieval.', 'mcp-ai-wpoos' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="memory-stats-header">
				<button type="button" class="button button-secondary refresh-memory-stats" title="<?php esc_attr_e( 'Refresh memory statistics', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>
			<div class="memory-stats-grid">
				<div class="memory-stat-card">
					<div class="stat-icon">💾</div>
					<div class="stat-content">
						<h3><?php echo esc_html( number_format_i18n( $total_contexts ) ); ?></h3>
						<p><?php esc_html_e( 'Total Contexts Stored', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>

				<div class="memory-stat-card">
					<div class="stat-icon">🤖</div>
					<div class="stat-content">
						<h3><?php echo esc_html( number_format_i18n( $total_agents ) ); ?></h3>
						<p><?php esc_html_e( 'Agents with Memory', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>

				<div class="memory-stat-card">
					<div class="stat-icon">📊</div>
					<div class="stat-content">
						<h3><?php echo esc_html( number_format_i18n( count( $contexts_by_type ) ) ); ?></h3>
						<p><?php esc_html_e( 'Context Types Used', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>

				<!-- Phase 4a: Wings/Rooms card. -->
				<div class="memory-stat-card memory-stat-card--wings">
					<div class="stat-icon">🏛️</div>
					<div class="stat-content">
						<h3>
							<span class="memory-wings-count"><?php echo esc_html( number_format_i18n( $wings_count ) ); ?></span>
							<small> / <span class="memory-rooms-count"><?php echo esc_html( number_format_i18n( $rooms_count ) ); ?></span></small>
						</h3>
						<p><?php esc_html_e( 'Wings / Rooms', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>

				<!-- Phase 4a: Mined memories card. -->
				<div class="memory-stat-card memory-stat-card--mined">
					<div class="stat-icon">⛏️</div>
					<div class="stat-content">
						<h3 class="memory-mined-count"><?php echo esc_html( number_format_i18n( $mined_count ) ); ?></h3>
						<p><?php esc_html_e( 'Mined Memories', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>

				<!-- Phase 4b: Persistent (CCT) vs cache (transient) split. -->
				<?php
				$persistent_count = isset( $persistent['cct_count'] ) ? (int) $persistent['cct_count'] : 0;
				$persistent_avail = ! empty( $persistent['available'] );
				$cache_only       = max( 0, $total_contexts - $persistent_count );
				?>
				<div class="memory-stat-card memory-stat-card--durable">
					<div class="stat-icon">🗄️</div>
					<div class="stat-content">
						<h3>
							<span class="memory-persistent-count"><?php echo esc_html( number_format_i18n( $persistent_count ) ); ?></span>
							<small> / <span class="memory-cache-only-count"><?php echo esc_html( number_format_i18n( $cache_only ) ); ?></span></small>
						</h3>
						<p>
							<?php esc_html_e( 'Persistent (CCT) / Cache only', 'mcp-ai-wpoos' ); ?>
						</p>
						<?php if ( ! $persistent_avail ) : ?>
							<small class="description">
								<?php esc_html_e( 'Install JetEngine to enable durable agent-memory storage that survives object-cache flushes.', 'mcp-ai-wpoos' ); ?>
							</small>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Phase 4a: Retrieval-path mini-chart (7-day rolling). -->
			<?php $this->render_retrieval_path_chart( $retrieval_path ); ?>

			<?php if ( ! empty( $contexts_by_type ) ) : ?>
				<div class="memory-contexts-breakdown" data-group="type">
					<div class="memory-breakdown-header">
						<h4><?php esc_html_e( 'Memory Breakdown', 'mcp-ai-wpoos' ); ?></h4>
						<label class="memory-group-by-label">
							<?php esc_html_e( 'Group by:', 'mcp-ai-wpoos' ); ?>
							<select class="memory-group-by">
								<option value="type"><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></option>
								<option value="wing"<?php echo empty( $contexts_by_wing ) ? ' disabled' : ''; ?>><?php esc_html_e( 'Wing', 'mcp-ai-wpoos' ); ?></option>
								<option value="importance"<?php echo empty( $contexts_by_importance ) ? ' disabled' : ''; ?>><?php esc_html_e( 'Importance', 'mcp-ai-wpoos' ); ?></option>
							</select>
						</label>
					</div>

					<?php
					$this->render_breakdown_table( 'type', __( 'Context Type', 'mcp-ai-wpoos' ), $contexts_by_type, $total_contexts, true );
					$this->render_breakdown_table( 'wing', __( 'Wing', 'mcp-ai-wpoos' ), $contexts_by_wing, $total_contexts, false );
					$this->render_breakdown_table( 'importance', __( 'Importance', 'mcp-ai-wpoos' ), $contexts_by_importance, $total_contexts, false );
					?>
				</div>
			<?php else : ?>
				<div class="memory-empty-state">
					<p>
						<?php
						esc_html_e( 'No agent memories stored yet. Agents will automatically store context when using the store_agent_context tool.', 'mcp-ai-wpoos' );
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="memory-tool-info">
				<h4><?php esc_html_e( 'Agent Memory Tools (Phase 5)', 'mcp-ai-wpoos' ); ?></h4>
				<ul>
					<?php
					// Get agent memory tools from registry dynamically.
					$memory_tool_slugs = array( 'store_agent_context', 'retrieve_agent_memory', 'prioritize_context', 'semantic_context_search', 'manage_context_lifecycle' );
					$registry          = WP_MCP_AI_Tool_Registry::get_instance();

					if ( $registry ) {
						$all_tools = $registry->get_tools();
						foreach ( $memory_tool_slugs as $tool_slug ) {
							foreach ( $all_tools as $tool ) {
								if ( ! ( $tool instanceof WP_MCP_AI_Tool_Interface ) ) {
									continue;
								}
								if ( $tool_slug === $tool->get_slug() ) {
									?>
									<li>
										<strong><?php echo esc_html( $tool_slug ); ?>:</strong>
										<?php echo esc_html( $tool->get_description() ); ?>
									</li>
									<?php
									break;
								}
							}
						}
					} else {
						// Fallback if registry is not available.
						?>
						<li>
							<strong>store_agent_context:</strong>
							<?php esc_html_e( 'Store important context with 10 types, TTL, importance levels, and tags', 'mcp-ai-wpoos' ); ?>
						</li>
						<li>
							<strong>retrieve_agent_memory:</strong>
							<?php esc_html_e( 'Retrieve contexts with semantic search, filtering, and relevance scoring', 'mcp-ai-wpoos' ); ?>
						</li>
						<li>
							<strong>prioritize_context:</strong>
							<?php esc_html_e( 'Prioritize contexts within token budgets using relevance, importance, and recency scoring', 'mcp-ai-wpoos' ); ?>
						</li>
						<li>
							<strong>semantic_context_search:</strong>
							<?php esc_html_e( 'Search contexts using vector embeddings for superior semantic understanding', 'mcp-ai-wpoos' ); ?>
						</li>
						<li>
							<strong>manage_context_lifecycle:</strong>
							<?php esc_html_e( 'Advanced lifecycle management: refresh TTL, compress, merge contexts, and prune unused', 'mcp-ai-wpoos' ); ?>
						</li>
						<?php
					}
					?>
				</ul>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button">
						<?php esc_html_e( 'Configure Tools', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/DEEPSEEK-V4-USAGE-GUIDE.md#using-agent-memory-tools" class="button button-secondary" target="_blank">
						<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>

			<!-- RAG Architecture Features -->
			<div class="rag-architecture-info">
				<h4><?php esc_html_e( 'RAG Architecture Enhancements', 'mcp-ai-wpoos' ); ?></h4>
				<p><?php esc_html_e( 'The memory management system implements industry-standard RAG (Retrieval-Augmented Generation) best practices:', 'mcp-ai-wpoos' ); ?></p>
				
				<div class="rag-features-grid">
					<div class="rag-feature-card">
						<div class="feature-icon">🧩</div>
						<h5><?php esc_html_e( 'Semantic Chunking', 'mcp-ai-wpoos' ); ?></h5>
						<p><?php esc_html_e( 'Optimal 150-1000 token chunks with 10-20% overlap for context preservation', 'mcp-ai-wpoos' ); ?></p>
					</div>
					
					<div class="rag-feature-card">
						<div class="feature-icon">🗜️</div>
						<h5><?php esc_html_e( 'Context Compression', 'mcp-ai-wpoos' ); ?></h5>
						<p><?php esc_html_e( 'Automatic summarization based on context age with TTL-aware policies', 'mcp-ai-wpoos' ); ?></p>
					</div>
					
					<div class="rag-feature-card">
						<div class="feature-icon">📊</div>
						<h5><?php esc_html_e( 'Enhanced Scoring', 'mcp-ai-wpoos' ); ?></h5>
						<p><?php esc_html_e( 'Multi-factor scoring: recency decay, frequency tracking, importance, TTL', 'mcp-ai-wpoos' ); ?></p>
					</div>
					
					<div class="rag-feature-card">
						<div class="feature-icon">🔍</div>
						<h5><?php esc_html_e( 'Hybrid Retrieval', 'mcp-ai-wpoos' ); ?></h5>
						<p><?php esc_html_e( 'Combines semantic (vector) and keyword search for optimal relevance', 'mcp-ai-wpoos' ); ?></p>
					</div>
					
					<div class="rag-feature-card">
						<div class="feature-icon">⏱️</div>
						<h5><?php esc_html_e( 'Exponential Decay', 'mcp-ai-wpoos' ); ?></h5>
						<p><?php esc_html_e( 'Time-based relevance decay ensures recent contexts are prioritized', 'mcp-ai-wpoos' ); ?></p>
					</div>
					
					<div class="rag-feature-card">
						<div class="feature-icon">🎯</div>
						<h5><?php esc_html_e( 'Token Budget Management', 'mcp-ai-wpoos' ); ?></h5>
						<p><?php esc_html_e( 'Intelligent context selection within LLM token constraints', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Context Health Metrics -->
			<?php
			// Display health metrics for memory system.
			$context_manager = WP_MCP_AI_Agent_Context_Manager::get_instance();

			// Get a sample agent ID or aggregate metrics.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
			$sample_agent_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} 
					WHERE option_name LIKE %s 
					LIMIT 1",
					$wpdb->esc_like( '_transient_mcp_ai_ctx_index_' ) . '%'
				)
			);

			if ( $sample_agent_id ) {
				$sample_agent_id = str_replace( '_transient_mcp_ai_ctx_index_', '', $sample_agent_id );
				// This is the md5 hash, but we can use it to get one agent's metrics.
				$health_metrics = $context_manager->get_context_health_metrics( $sample_agent_id );

				if ( $health_metrics['total_count'] > 0 ) :
					?>
					<div class="memory-health-metrics">
						<h4><?php esc_html_e( 'Memory Health Metrics', 'mcp-ai-wpoos' ); ?></h4>
						
						<div class="health-score-display">
							<div class="health-score-circle <?php echo esc_attr( $health_metrics['health_score'] >= 70 ? 'good' : ( $health_metrics['health_score'] >= 40 ? 'fair' : 'poor' ) ); ?>">
								<span class="score-value"><?php echo esc_html( $health_metrics['health_score'] ); ?></span>
								<span class="score-label"><?php esc_html_e( 'Health Score', 'mcp-ai-wpoos' ); ?></span>
							</div>
							
							<div class="health-metrics-grid">
								<div class="metric-item">
									<span class="metric-value"><?php echo esc_html( number_format_i18n( $health_metrics['metrics']['active_contexts'] ) ); ?></span>
									<span class="metric-label"><?php esc_html_e( 'Active Contexts', 'mcp-ai-wpoos' ); ?></span>
								</div>
								
								<div class="metric-item">
									<span class="metric-value"><?php echo esc_html( $health_metrics['metrics']['avg_age_days'] ); ?>d</span>
									<span class="metric-label"><?php esc_html_e( 'Avg Age', 'mcp-ai-wpoos' ); ?></span>
								</div>
								
								<div class="metric-item">
									<span class="metric-value"><?php echo esc_html( $health_metrics['metrics']['avg_access_count'] ); ?></span>
									<span class="metric-label"><?php esc_html_e( 'Avg Accesses', 'mcp-ai-wpoos' ); ?></span>
								</div>
								
								<div class="metric-item <?php echo esc_attr( $health_metrics['metrics']['expiring_soon'] > 0 ? 'warning' : '' ); ?>">
									<span class="metric-value"><?php echo esc_html( number_format_i18n( $health_metrics['metrics']['expiring_soon'] ) ); ?></span>
									<span class="metric-label"><?php esc_html_e( 'Expiring Soon', 'mcp-ai-wpoos' ); ?></span>
								</div>
							</div>
						</div>

						<div class="health-insights">
							<h5><?php esc_html_e( 'Health Insights', 'mcp-ai-wpoos' ); ?></h5>
							<ul>
								<?php if ( $health_metrics['metrics']['never_accessed'] > 0 ) : ?>
									<li class="info">
										<?php
										printf(
											/* translators: %d: number of contexts */
											esc_html__( '%d contexts have never been accessed. Consider reviewing their relevance.', 'mcp-ai-wpoos' ),
											esc_html( $health_metrics['metrics']['never_accessed'] )
										);
										?>
									</li>
								<?php endif; ?>
								
								<?php if ( $health_metrics['metrics']['frequently_accessed'] > 0 ) : ?>
									<li class="success">
										<?php
										printf(
											/* translators: %d: number of contexts */
											esc_html__( '%d contexts are frequently accessed (5+ times). These are high-value memories.', 'mcp-ai-wpoos' ),
											esc_html( $health_metrics['metrics']['frequently_accessed'] )
										);
										?>
									</li>
								<?php endif; ?>
								
								<?php if ( $health_metrics['metrics']['expiring_soon'] > 0 ) : ?>
									<li class="warning">
										<?php
										printf(
											/* translators: %d: number of contexts */
											esc_html__( '%d contexts expiring within 7 days. Consider extending TTL for important memories.', 'mcp-ai-wpoos' ),
											esc_html( $health_metrics['metrics']['expiring_soon'] )
										);
										?>
									</li>
								<?php endif; ?>
							</ul>
						</div>
					</div>
					<?php
				endif;
			}
			?>
		</div>
		<?php
	}
}

// Initialize.
new WP_MCP_AI_Admin_Orchestration_Dashboard();
