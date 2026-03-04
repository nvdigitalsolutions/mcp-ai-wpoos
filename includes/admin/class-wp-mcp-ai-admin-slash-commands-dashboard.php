<?php
/**
 * Admin Page: Slash Commands Dashboard
 *
 * Provides management interface for slash commands, workflows, and execution history.
 *
 * @package WP_MCP_AI
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slash Commands Dashboard Admin Page.
 *
 * @since 1.10.0
 */
class WP_MCP_AI_Admin_Slash_Commands_Dashboard {

	/**
	 * Maximum number of history entries to keep.
	 *
	 * @var int
	 */
	const MAX_HISTORY_ENTRIES = 100;

	/**
	 * Length to truncate history output preview.
	 *
	 * @var int
	 */
	const HISTORY_OUTPUT_PREVIEW_LENGTH = 500;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_command', array( $this, 'ajax_execute_command' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_command_history', array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_history_entry', array( $this, 'ajax_get_history_entry' ) );
		add_action( 'wp_ajax_wp_mcp_ai_clear_command_history', array( $this, 'ajax_clear_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_slash_workflow', array( $this, 'ajax_execute_workflow' ) );
	}

	/**
	 * Add admin menu page.
	 *
	 * Note: The menu uses 'edit_posts' capability (Contributor+) because individual
	 * slash commands enforce their own capability requirements. The command handler
	 * checks each command's required capability before execution, providing granular
	 * access control. For example, /optimize-perf requires 'manage_options' while
	 * /next-task requires 'edit_posts'.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Slash Commands', 'mcp-ai-wpoos' ),
			__( 'Slash Commands', 'mcp-ai-wpoos' ),
			'edit_posts',
			'mcp-ai-slash-commands',
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
		if ( false === strpos( $hook, 'mcp-ai-slash-commands' ) ) {
			return;
		}

		$css_path    = WP_MCP_AI_PATH . 'assets/css/admin-slash-commands-dashboard.css';
		$js_path     = WP_MCP_AI_PATH . 'assets/js/admin-slash-commands-dashboard.js';
		$css_version = file_exists( $css_path ) ? filemtime( $css_path ) : WP_MCP_AI_VERSION;
		$js_version  = file_exists( $js_path ) ? filemtime( $js_path ) : WP_MCP_AI_VERSION;

		wp_enqueue_style(
			'wp-mcp-ai-slash-commands-dashboard',
			WP_MCP_AI_URL . 'assets/css/admin-slash-commands-dashboard.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'wp-mcp-ai-slash-commands-dashboard',
			WP_MCP_AI_URL . 'assets/js/admin-slash-commands-dashboard.js',
			array( 'jquery' ),
			$js_version,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-slash-commands-dashboard',
			'wpMcpAiSlashCommands',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_slash_commands' ),
			)
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		// Get available commands.
		$commands = $this->get_available_commands();

		// Get available workflows.
		$workflows = $this->get_available_workflows();

		// Get recent execution history.
		$history = $this->get_execution_history( 10 );

		// Sanitize and validate active tab.
		$allowed_tabs = array( 'commands', 'workflows', 'history', 'test' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection, no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'commands';
		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'commands';
		}

		?>
		<div class="wrap wp-mcp-ai-slash-commands-dashboard">
			<h1>
				<?php esc_html_e( 'Slash Commands & Workflows', 'mcp-ai-wpoos' ); ?>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Manage and execute slash commands, configure workflows, and view execution history.', 'mcp-ai-wpoos' ); ?>
			</p>

			<!-- Tabs -->
			<h2 class="nav-tab-wrapper">
				<a href="?page=mcp-ai-slash-commands&tab=commands" class="nav-tab <?php echo esc_attr( 'commands' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Commands', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=workflows" class="nav-tab <?php echo esc_attr( 'workflows' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Workflows', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=history" class="nav-tab <?php echo esc_attr( 'history' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Execution History', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=test" class="nav-tab <?php echo esc_attr( 'test' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Test Commands', 'mcp-ai-wpoos' ); ?>
				</a>
			</h2>

			<div class="tab-content">
				<?php
				switch ( $active_tab ) {
					case 'workflows':
						$this->render_workflows_tab( $workflows );
						break;
					case 'history':
						$this->render_history_tab( $history );
						break;
					case 'test':
						$this->render_test_tab();
						break;
					case 'commands':
					default:
						$this->render_commands_tab( $commands );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render commands tab.
	 *
	 * @param array $commands Available commands.
	 * @return void
	 */
	private function render_commands_tab( $commands ) {
		// Group commands by toolkit.
		$toolkit_commands = $this->group_commands_by_toolkit( $commands );
		$global_commands  = $this->get_global_commands( $commands );
		?>
		<div class="commands-tab">
			<h2><?php esc_html_e( 'Available Commands', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'All registered slash commands and their capabilities. Commands are organized by toolkit and global commands.', 'mcp-ai-wpoos' ); ?></p>

			<!-- Statistics -->
			<div class="command-stats">
				<div class="stat-box">
					<strong><?php echo esc_html( count( $commands ) ); ?></strong>
					<span><?php esc_html_e( 'Total Commands', 'mcp-ai-wpoos' ); ?></span>
				</div>
				<div class="stat-box">
					<strong><?php echo esc_html( count( $toolkit_commands ) ); ?></strong>
					<span><?php esc_html_e( 'Toolkits', 'mcp-ai-wpoos' ); ?></span>
				</div>
				<div class="stat-box">
					<strong><?php echo esc_html( count( $global_commands ) ); ?></strong>
					<span><?php esc_html_e( 'Global Commands', 'mcp-ai-wpoos' ); ?></span>
				</div>
			</div>

			<!-- Global Commands -->
			<?php if ( ! empty( $global_commands ) ) : ?>
				<h3><?php esc_html_e( 'Global Commands', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Commands available system-wide:', 'mcp-ai-wpoos' ); ?></p>
				<?php $this->render_commands_table( $global_commands ); ?>
			<?php endif; ?>

			<!-- Toolkit Commands -->
			<?php if ( ! empty( $toolkit_commands ) ) : ?>
				<h3><?php esc_html_e( 'Toolkit-Specific Commands', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Commands organized by their respective toolkits:', 'mcp-ai-wpoos' ); ?></p>
				
				<?php foreach ( $toolkit_commands as $toolkit_name => $toolkit_cmds ) : ?>
					<div class="toolkit-commands-section">
						<h4 class="toolkit-name"><?php echo esc_html( $toolkit_name ); ?></h4>
						<p class="toolkit-command-count">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of commands */
									_n( '%d command', '%d commands', count( $toolkit_cmds ), 'mcp-ai-wpoos' ),
									count( $toolkit_cmds )
								)
							);
							?>
						</p>
						<?php $this->render_commands_table( $toolkit_cmds, true ); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<!-- Command Help Display -->
			<div id="command-help-display" class="command-help-box" style="display: none;">
				<h3><?php esc_html_e( 'Command Help', 'mcp-ai-wpoos' ); ?></h3>
				<button type="button" class="button button-small close-help" style="float: right;">
					<?php esc_html_e( 'Close', 'mcp-ai-wpoos' ); ?>
				</button>
				<div id="command-help-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render commands table.
	 *
	 * @param array $commands Commands to display.
	 * @param bool  $compact  Whether to use compact view.
	 * @return void
	 */
	private function render_commands_table( $commands, $compact = false ) {
		?>
		<table class="wp-list-table widefat fixed striped <?php echo esc_attr( $compact ? 'compact-view' : '' ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Command', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
					<?php if ( ! $compact ) : ?>
						<th><?php esc_html_e( 'Aliases', 'mcp-ai-wpoos' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Capability', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $commands ) ) : ?>
					<tr>
						<td colspan="<?php echo $compact ? '4' : '5'; ?>"><?php esc_html_e( 'No commands available.', 'mcp-ai-wpoos' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $commands as $command ) : ?>
						<tr>
							<td><code>/<?php echo esc_html( $command['name'] ); ?></code></td>
							<td><?php echo esc_html( $command['description'] ); ?></td>
							<?php if ( ! $compact ) : ?>
								<td>
									<?php
									if ( ! empty( $command['aliases'] ) ) {
										echo '<code>' . esc_html( implode( '</code>, <code>', $command['aliases'] ) ) . '</code>';
									} else {
										echo '—';
									}
									?>
								</td>
							<?php endif; ?>
							<td><code><?php echo esc_html( $command['capability'] ); ?></code></td>
							<td>
								<button type="button" class="button button-small view-command-help" data-command="<?php echo esc_attr( $command['name'] ); ?>">
									<?php esc_html_e( 'View Help', 'mcp-ai-wpoos' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Group commands by toolkit.
	 *
	 * @param array $commands All commands.
	 * @return array Commands grouped by toolkit name.
	 */
	private function group_commands_by_toolkit( $commands ) {
		$toolkit_manager = null;
		if ( class_exists( 'WP_MCP_AI_Slash_Command_Toolkit_Manager' ) ) {
			$toolkit_manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
		}

		if ( ! $toolkit_manager ) {
			return array();
		}

		$registry = WP_MCP_AI_Toolkit_Registry::get_instance();
		$toolkits = $registry->get_toolkits();

		$grouped = array();
		foreach ( $commands as $command ) {
			// Check if command has toolkit metadata.
			if ( ! empty( $command['toolkit'] ) ) {
				$toolkit_slug = $command['toolkit'];
				$toolkit_info = $registry->get_toolkit( $toolkit_slug );
				$toolkit_name = $toolkit_info ? $toolkit_info['name'] : ucwords( str_replace( '_', ' ', $toolkit_slug ) );

				if ( ! isset( $grouped[ $toolkit_name ] ) ) {
					$grouped[ $toolkit_name ] = array();
				}

				$grouped[ $toolkit_name ][] = $command;
			}
		}

		// Sort toolkits alphabetically.
		ksort( $grouped );

		return $grouped;
	}

	/**
	 * Get global (non-toolkit) commands.
	 *
	 * @param array $commands All commands.
	 * @return array Global commands.
	 */
	private function get_global_commands( $commands ) {
		$global = array();
		foreach ( $commands as $command ) {
			if ( empty( $command['toolkit'] ) ) {
				$global[] = $command;
			}
		}
		return $global;
	}

	/**
	 * Render workflows tab.
	 *
	 * @param array $workflows Available workflows.
	 * @return void
	 */
	private function render_workflows_tab( $workflows ) {
		?>
		<div class="workflows-tab">
			<h2><?php esc_html_e( 'Available Workflows', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'Pre-configured workflow templates and custom workflows:', 'mcp-ai-wpoos' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Workflow', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Steps', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $workflows ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No workflows available.', 'mcp-ai-wpoos' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $workflows as $workflow ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $workflow['name'] ); ?></strong></td>
								<td><?php echo esc_html( $workflow['description'] ); ?></td>
								<td><?php echo esc_html( $workflow['step_count'] ); ?></td>
								<td>
									<span class="workflow-type-badge <?php echo esc_attr( $workflow['type'] ); ?>">
										<?php echo esc_html( ucfirst( $workflow['type'] ) ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button button-small view-workflow" data-workflow="<?php echo esc_attr( $workflow['slug'] ); ?>">
										<?php esc_html_e( 'View', 'mcp-ai-wpoos' ); ?>
									</button>
									<button type="button" class="button button-primary button-small execute-workflow" data-workflow="<?php echo esc_attr( $workflow['slug'] ); ?>">
										<?php esc_html_e( 'Execute', 'mcp-ai-wpoos' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Workflow Details Display -->
			<div id="workflow-details-display" class="workflow-details-box" style="display: none;">
				<h3><?php esc_html_e( 'Workflow Details', 'mcp-ai-wpoos' ); ?></h3>
				<button type="button" class="button button-small close-details" style="float: right;">
					<?php esc_html_e( 'Close', 'mcp-ai-wpoos' ); ?>
				</button>
				<div id="workflow-details-content"></div>
			</div>

			<!-- Workflow Execution Output -->
			<div id="workflow-execution-output" class="workflow-execution-box" style="display: none;">
				<h3><?php esc_html_e( 'Workflow Execution', 'mcp-ai-wpoos' ); ?></h3>
				<button type="button" class="button button-small close-execution" style="float: right;">
					<?php esc_html_e( 'Close', 'mcp-ai-wpoos' ); ?>
				</button>
				<div id="workflow-execution-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render history tab.
	 *
	 * @param array $history Execution history.
	 * @return void
	 */
	private function render_history_tab( $history ) {
		?>
		<div class="history-tab">
			<h2><?php esc_html_e( 'Execution History', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'Recent command and workflow executions:', 'mcp-ai-wpoos' ); ?></p>

			<div class="history-actions">
				<button type="button" class="button button-secondary" id="refresh-history">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'mcp-ai-wpoos' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="clear-history">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear History', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>

			<table class="wp-list-table widefat fixed striped" id="history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Command/Workflow', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'User', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $history ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No execution history available.', 'mcp-ai-wpoos' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $history as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['timestamp'] ); ?></td>
								<td><?php echo esc_html( ucfirst( $entry['type'] ) ); ?></td>
								<td><code><?php echo esc_html( $entry['command'] ); ?></code></td>
								<td><?php echo esc_html( $entry['user'] ); ?></td>
								<td>
									<span class="status-badge status-<?php echo esc_attr( $entry['status'] ); ?>">
										<?php echo esc_html( ucfirst( $entry['status'] ) ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button button-small view-history-details" data-entry-id="<?php echo esc_attr( $entry['id'] ); ?>">
										<?php esc_html_e( 'Details', 'mcp-ai-wpoos' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- History Details Display -->
			<div id="history-details-display" class="history-details-box" style="display: none;">
				<h3><?php esc_html_e( 'Execution Details', 'mcp-ai-wpoos' ); ?></h3>
				<button type="button" class="button button-small close-history-details" style="float: right;">
					<?php esc_html_e( 'Close', 'mcp-ai-wpoos' ); ?>
				</button>
				<div id="history-details-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render test tab.
	 *
	 * @return void
	 */
	private function render_test_tab() {
		?>
		<div class="test-tab">
			<h2><?php esc_html_e( 'Test Commands', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'Execute slash commands directly from the admin interface:', 'mcp-ai-wpoos' ); ?></p>

			<div class="command-tester">
				<div class="test-input-section">
					<label for="command-input">
						<strong><?php esc_html_e( 'Enter Command:', 'mcp-ai-wpoos' ); ?></strong>
					</label>
					<input type="text" id="command-input" class="regular-text" placeholder="/help" />
					<button type="button" class="button button-primary" id="execute-command-btn">
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Execute', 'mcp-ai-wpoos' ); ?>
					</button>
					<p class="description">
						<?php esc_html_e( 'Examples: /help, /next-task --dry-run, /ship 123 --publish, /workflow daily-review', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>

				<div class="test-output-section">
					<h3><?php esc_html_e( 'Output:', 'mcp-ai-wpoos' ); ?></h3>
					<div id="command-output" class="command-output-box">
						<p class="no-output"><?php esc_html_e( 'No command executed yet. Enter a command above and click Execute.', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get available commands.
	 *
	 * @return array Array of commands with metadata.
	 */
	private function get_available_commands() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			return array();
		}

		// Ensure toolkit commands are registered if toolkit manager exists.
		if ( class_exists( 'WP_MCP_AI_Slash_Command_Toolkit_Manager' ) ) {
			$toolkit_manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
			// Force registration if not already done (in case of timing issues).
			if ( method_exists( $toolkit_manager, 'register_toolkit_commands' ) ) {
				// The register method is safe to call multiple times as it checks enabled status.
				$toolkit_manager->register_toolkit_commands();
			}
		}

		$commands = $handler->get_commands();

		$formatted = array();
		foreach ( $commands as $name => $config ) {
			$formatted[] = array(
				'name'        => $name,
				'description' => $config['description'] ?? '',
				'aliases'     => $config['aliases'] ?? array(),
				'capability'  => $config['capability'] ?? 'read',
				'toolkit'     => $config['toolkit'] ?? '',
			);
		}

		return $formatted;
	}

	/**
	 * Get available workflows.
	 *
	 * @return array Array of workflows with metadata.
	 */
	private function get_available_workflows() {
		$workflows = array();

		// Get workflows from the orchestrator.
		$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
		if ( $orchestrator ) {
			$handler                = wp_mcp_ai_get_slash_command_handler();
			$orchestrator_workflows = $orchestrator->get_workflows();

			foreach ( $orchestrator_workflows as $slug => $workflow ) {
				// Check if workflow can be executed by verifying its commands are available.
				// If a workflow uses commands from disabled toolkits, we should filter it out.
				$workflow_available = true;

				if ( $handler ) {
					$full_workflow = $orchestrator->get_workflow( $slug );
					if ( $full_workflow && isset( $full_workflow['steps'] ) ) {
						foreach ( $full_workflow['steps'] as $step ) {
							$command_name = isset( $step['command'] ) ? $step['command'] : '';
							if ( $command_name && ! $handler->command_exists( $command_name ) ) {
								// Command doesn't exist, likely from disabled toolkit.
								$workflow_available = false;
								break;
							}
						}
					}
				}

				// Only add workflow if all its commands are available.
				if ( $workflow_available ) {
					$workflows[] = array(
						'name'        => $workflow['name'],
						'description' => $workflow['description'],
						'step_count'  => $workflow['steps'],
						'type'        => 'built-in',
						'slug'        => $slug,
					);
				}
			}
		}

		// Custom workflows from uploads directory.
		$uploads_dir   = wp_upload_dir();
		$workflows_dir = trailingslashit( $uploads_dir['basedir'] ) . 'mcp-ai/workflows';

		if ( is_dir( $workflows_dir ) ) {
			$files = glob( $workflows_dir . '/*.yml' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					$slug        = basename( $file, '.yml' );
					$workflows[] = array(
						'name'        => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
						'description' => 'Custom workflow',
						'step_count'  => '?',
						'type'        => 'custom',
						'slug'        => $slug,
					);
				}
			}
		}

		return $workflows;
	}

	/**
	 * Get execution history.
	 *
	 * @param int $limit Number of entries to retrieve.
	 * @return array Execution history entries.
	 */
	private function get_execution_history( $limit = 10 ) {
		$history = get_option( 'wp_mcp_ai_slash_command_history', array() );

		// Sort by timestamp descending.
		usort(
			$history,
			function ( $a, $b ) {
				return $b['timestamp_raw'] <=> $a['timestamp_raw'];
			}
		);

		return array_slice( $history, 0, $limit );
	}

	/**
	 * AJAX handler: Execute command.
	 *
	 * @return void
	 */
	public function ajax_execute_command() {
		check_ajax_referer( 'wp_mcp_ai_slash_commands', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			// Log permission denial.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'command_permission_denied',
					'User lacks edit_posts capability to execute command.',
					array(
						'user_id' => get_current_user_id(),
					)
				);
			}
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$command = isset( $_POST['command'] ) ? sanitize_text_field( wp_unslash( $_POST['command'] ) ) : '';

		if ( empty( $command ) ) {
			wp_send_json_error( array( 'message' => __( 'No command provided.', 'mcp-ai-wpoos' ) ) );
		}

		// Log command execution attempt.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'command_execution_attempt',
				sprintf( 'Attempting to execute command: %s', $command ),
				array(
					'command' => $command,
					'user_id' => get_current_user_id(),
				)
			);
		}

		// Execute command.
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'command_handler_not_initialized',
					'Slash command handler not initialized.'
				);
			}
			wp_send_json_error( array( 'message' => __( 'Slash commands system not initialized.', 'mcp-ai-wpoos' ) ) );
		}
		$result = $handler->execute( $command, array( 'user_id' => get_current_user_id() ) );

		// Log execution.
		$this->log_execution( 'command', $command, $result );

		if ( is_wp_error( $result ) ) {
			// Log error with details.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'command_execution_error',
					sprintf( 'Command execution failed: %s', $result->get_error_message() ),
					array(
						'command'    => $command,
						'error_code' => $result->get_error_code(),
						'error_data' => $result->get_error_data(),
						'user_id'    => get_current_user_id(),
					)
				);
			}
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'output'  => '',
				)
			);
		}

		// Log success.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'command_execution_success',
				sprintf( 'Command executed successfully: %s', $command ),
				array(
					'command' => $command,
					'user_id' => get_current_user_id(),
				)
			);
		}

		wp_send_json_success(
			array(
				'output' => $result,
			)
		);
	}

	/**
	 * AJAX handler: Execute workflow.
	 *
	 * @return void
	 */
	public function ajax_execute_workflow() {
		check_ajax_referer( 'wp_mcp_ai_slash_commands', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			// Log permission denial.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'workflow_permission_denied',
					'User lacks edit_posts capability to execute workflow.',
					array(
						'user_id' => get_current_user_id(),
					)
				);
			}
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ),
				)
			);
		}

		$workflow = isset( $_POST['workflow'] ) ? sanitize_text_field( wp_unslash( $_POST['workflow'] ) ) : '';

		if ( empty( $workflow ) ) {
			wp_send_json_error( array( 'message' => __( 'No workflow provided.', 'mcp-ai-wpoos' ) ) );
		}

		// Log workflow execution attempt.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'workflow_execution_attempt',
				sprintf( 'Attempting to execute workflow: %s', $workflow ),
				array(
					'workflow' => $workflow,
					'user_id'  => get_current_user_id(),
				)
			);
		}

		// Execute workflow via command.
		$command = '/workflow ' . $workflow;
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'workflow_handler_not_initialized',
					'Slash command handler not initialized.'
				);
			}
			wp_send_json_error( array( 'message' => __( 'Slash commands system not initialized.', 'mcp-ai-wpoos' ) ) );
		}
		$result = $handler->execute( $command, array( 'user_id' => get_current_user_id() ) );

		// Log execution.
		$this->log_execution( 'workflow', $workflow, $result );

		if ( is_wp_error( $result ) ) {
			// Log error with details.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'workflow_execution_error',
					sprintf( 'Workflow execution failed: %s', $result->get_error_message() ),
					array(
						'workflow'   => $workflow,
						'error_code' => $result->get_error_code(),
						'error_data' => $result->get_error_data(),
						'user_id'    => get_current_user_id(),
					)
				);
			}
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'output'  => '',
				)
			);
		}

		// Log success.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'workflow_execution_success',
				sprintf( 'Workflow executed successfully: %s', $workflow ),
				array(
					'workflow' => $workflow,
					'user_id'  => get_current_user_id(),
				)
			);
		}

		wp_send_json_success(
			array(
				'output' => $result,
			)
		);
	}

	/**
	 * AJAX handler: Get command history.
	 *
	 * @return void
	 */
	public function ajax_get_history() {
		check_ajax_referer( 'wp_mcp_ai_slash_commands', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$limit   = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;
		$history = $this->get_execution_history( $limit );

		wp_send_json_success(
			array(
				'history' => $history,
			)
		);
	}

	/**
	 * AJAX handler: Get single history entry.
	 *
	 * @return void
	 */
	public function ajax_get_history_entry() {
		check_ajax_referer( 'wp_mcp_ai_slash_commands', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_id'] ) ) : '';

		if ( empty( $entry_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No entry ID provided.', 'mcp-ai-wpoos' ) ) );
		}

		$history = get_option( 'wp_mcp_ai_slash_command_history', array() );

		// Find entry by ID.
		$entry = null;
		foreach ( $history as $item ) {
			if ( $item['id'] === $entry_id ) {
				$entry = $item;
				break;
			}
		}

		if ( ! $entry ) {
			wp_send_json_error(
				array(
					'message' => __( 'History entry not found.', 'mcp-ai-wpoos' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'entry' => $entry,
			)
		);
	}

	/**
	 * AJAX handler: Clear command history.
	 *
	 * @return void
	 */
	public function ajax_clear_history() {
		check_ajax_referer( 'wp_mcp_ai_slash_commands', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		delete_option( 'wp_mcp_ai_slash_command_history' );

		wp_send_json_success(
			array(
				'message' => __( 'History cleared successfully.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * Log command/workflow execution.
	 *
	 * @param string $type   Type (command or workflow).
	 * @param string $command Command or workflow name.
	 * @param mixed  $result Execution result.
	 * @return void
	 */
	private function log_execution( $type, $command, $result ) {
		$history = get_option( 'wp_mcp_ai_slash_command_history', array() );

		$user  = wp_get_current_user();
		$entry = array(
			'id'            => uniqid( 'exec_', true ),
			'timestamp'     => current_time( 'Y-m-d H:i:s' ),
			'timestamp_raw' => time(),
			'type'          => $type,
			'command'       => $command,
			'user'          => $user->display_name,
			'user_id'       => $user->ID,
			'status'        => is_wp_error( $result ) ? 'error' : 'success',
			'output'        => is_wp_error( $result ) ? $result->get_error_message() : ( is_string( $result ) ? substr( $result, 0, self::HISTORY_OUTPUT_PREVIEW_LENGTH ) : wp_json_encode( $result ) ),
		);

		array_unshift( $history, $entry );

		// Keep only last MAX_HISTORY_ENTRIES entries.
		$history = array_slice( $history, 0, self::MAX_HISTORY_ENTRIES );

		update_option( 'wp_mcp_ai_slash_command_history', $history );
	}
}
