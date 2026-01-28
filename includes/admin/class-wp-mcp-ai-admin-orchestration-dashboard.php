<?php
/**
 * Admin Page: Orchestration Dashboard
 *
 * Provides visual overview and management interface for the DeepSeek V4
 * multi-agent orchestration system.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
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
			'mcp-ai-orchestration-dashboard',
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
		if ( 'nv-oos_page_mcp-ai-orchestration-dashboard' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-orchestration-dashboard',
			plugins_url( 'assets/css/admin-orchestration-dashboard.css', WP_MCP_AI_FILE ),
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-orchestration-dashboard',
			plugins_url( 'assets/js/admin-orchestration-dashboard.js', WP_MCP_AI_FILE ),
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
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

			<!-- Status Banner -->
			<div class="orchestration-status-banner">
				<?php $this->render_status_banner( $stats ); ?>
			</div>

			<!-- Statistics Cards -->
			<div class="orchestration-stats-grid">
				<?php $this->render_statistics_cards( $stats ); ?>
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
					'meta_query'     => array(
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
				'meta_query'     => array(
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
				'meta_query'     => array(
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
				<span class="dashicons dashicons-<?php echo 'success' === $status_class ? 'yes-alt' : ( 'warning' === $status_class ? 'warning' : 'info' ); ?>"></span>
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
	 * Render statistics cards.
	 *
	 * @param array $stats Statistics data.
	 * @return void
	 */
	protected function render_statistics_cards( $stats ) {
		$cards = array(
			array(
				'title' => __( 'Total Professions', 'mcp-ai-wpoos' ),
				'value' => $stats['total_professions'],
				'icon'  => 'groups',
				'color' => '#2271b1',
			),
			array(
				'title' => __( 'Seeded Professions', 'mcp-ai-wpoos' ),
				'value' => $stats['seeded_professions'],
				'icon'  => 'yes-alt',
				'color' => '#00a32a',
			),
			array(
				'title' => __( 'With Task Patterns', 'mcp-ai-wpoos' ),
				'value' => $stats['with_task_patterns'],
				'icon'  => 'list-view',
				'color' => '#f0b849',
			),
			array(
				'title'       => __( 'Agent Tools', 'mcp-ai-wpoos' ),
				'value'       => 3,
				'icon'        => 'admin-tools',
				'color'       => '#8c8f94',
				'description' => __( 'create_agent_team, delegate_to_agent, aggregate_agent_results', 'mcp-ai-wpoos' ),
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
					<div class="stat-value"><?php echo esc_html( $card['value'] ); ?></div>
					<?php if ( isset( $card['description'] ) ) : ?>
						<p class="stat-description"><?php echo esc_html( $card['description'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		endforeach;
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

		$force = isset( $_POST['force'] ) && $_POST['force'];

		$seeder = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$result = $seeder->seed_all( $force );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
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

		$stats = $this->get_orchestration_statistics();
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
		// Get all workflow transients.
		global $wpdb;

		$transient_prefix = '_transient_wp_mcp_ai_workflow_';
		$transients       = $wpdb->get_results(
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

		// Check if Enhanced Workflow Coordinator is available.
		if ( ! class_exists( 'WP_MCP_AI_Enhanced_Workflow_Coordinator' ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow coordinator not available.', 'mcp-ai-wpoos' ) ) );
		}

		try {
			$coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();
			$result      = $coordinator->execute_workflow( $workflow_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
						'code'    => $result->get_error_code(),
					)
				);
			}

			wp_send_json_success(
				array(
					'message'     => __( 'Workflow execution started successfully.', 'mcp-ai-wpoos' ),
					'workflow_id' => $workflow_id,
					'result'      => $result,
				)
			);

		} catch ( Exception $e ) {
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

		// Get the existing workflow data.
		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		$workflow_data = get_transient( $transient_key );

		if ( false === $workflow_data ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

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
				}
			}
		}

		// Save the reset workflow.
		set_transient( $transient_key, $workflow_data, 7 * DAY_IN_SECONDS );

		wp_send_json_success(
			array(
				'message'     => __( 'Workflow reset successfully. You can now continue it.', 'mcp-ai-wpoos' ),
				'workflow_id' => $workflow_id,
				'workflow'    => $workflow_data,
			)
		);
	}
}

// Initialize.
new WP_MCP_AI_Admin_Orchestration_Dashboard();
