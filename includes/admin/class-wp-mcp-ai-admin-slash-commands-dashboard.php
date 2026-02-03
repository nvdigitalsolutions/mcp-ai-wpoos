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
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_command', array( $this, 'ajax_execute_command' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_command_history', array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_clear_command_history', array( $this, 'ajax_clear_history' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_workflow', array( $this, 'ajax_execute_workflow' ) );
	}

	/**
	 * Add admin menu page.
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

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'commands';

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
				<a href="?page=mcp-ai-slash-commands&tab=commands" class="nav-tab <?php echo 'commands' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Commands', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=workflows" class="nav-tab <?php echo 'workflows' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Workflows', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=history" class="nav-tab <?php echo 'history' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Execution History', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=test" class="nav-tab <?php echo 'test' === $active_tab ? 'nav-tab-active' : ''; ?>">
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
		?>
		<div class="commands-tab">
			<h2><?php esc_html_e( 'Available Commands', 'mcp-ai-wpoos' ); ?></h2>
			<p><?php esc_html_e( 'All registered slash commands and their capabilities:', 'mcp-ai-wpoos' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Command', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Aliases', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Capability', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $commands ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No commands available.', 'mcp-ai-wpoos' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $commands as $command ) : ?>
							<tr>
								<td><code>/<?php echo esc_html( $command['name'] ); ?></code></td>
								<td><?php echo esc_html( $command['description'] ); ?></td>
								<td>
									<?php
									if ( ! empty( $command['aliases'] ) ) {
										echo '<code>' . esc_html( implode( '</code>, <code>', $command['aliases'] ) ) . '</code>';
									} else {
										echo '—';
									}
									?>
								</td>
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
		$handler = WP_MCP_AI_Slash_Command_Handler::get_instance();
		$commands = $handler->get_registered_commands();

		$formatted = array();
		foreach ( $commands as $name => $command_obj ) {
			$definition = $command_obj->get_definition();
			$formatted[] = array(
				'name'        => $name,
				'description' => $definition['description'] ?? '',
				'aliases'     => $definition['aliases'] ?? array(),
				'capability'  => $definition['required_capability'] ?? 'read',
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

		// Built-in workflows.
		$built_in = array(
			'daily-review' => array(
				'name'        => 'Daily Content Review',
				'description' => 'Review draft posts and perform basic checks',
				'step_count'  => 2,
				'type'        => 'built-in',
				'slug'        => 'daily-review',
			),
			'publish-ready' => array(
				'name'        => 'Check and Publish Ready Posts',
				'description' => 'Find draft posts ready to publish and ship them',
				'step_count'  => 2,
				'type'        => 'built-in',
				'slug'        => 'publish-ready',
			),
			'site-health' => array(
				'name'        => 'Site Health Check',
				'description' => 'Comprehensive site health and performance check',
				'step_count'  => 3,
				'type'        => 'built-in',
				'slug'        => 'site-health',
			),
		);

		$workflows = array_merge( $workflows, array_values( $built_in ) );

		// Custom workflows from uploads directory.
		$uploads_dir = wp_upload_dir();
		$workflows_dir = trailingslashit( $uploads_dir['basedir'] ) . 'mcp-ai/workflows';

		if ( is_dir( $workflows_dir ) ) {
			$files = glob( $workflows_dir . '/*.yml' );
			foreach ( $files as $file ) {
				$slug = basename( $file, '.yml' );
				$workflows[] = array(
					'name'        => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
					'description' => 'Custom workflow',
					'step_count'  => '?',
					'type'        => 'custom',
					'slug'        => $slug,
				);
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
		usort( $history, function( $a, $b ) {
			return $b['timestamp_raw'] <=> $a['timestamp_raw'];
		});

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
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$command = isset( $_POST['command'] ) ? sanitize_text_field( wp_unslash( $_POST['command'] ) ) : '';

		if ( empty( $command ) ) {
			wp_send_json_error( array( 'message' => __( 'No command provided.', 'mcp-ai-wpoos' ) ) );
		}

		// Execute command.
		$handler = WP_MCP_AI_Slash_Command_Handler::get_instance();
		$result = $handler->handle( $command, array( 'user_id' => get_current_user_id() ) );

		// Log execution.
		$this->log_execution( 'command', $command, $result );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'output'  => '',
			) );
		}

		wp_send_json_success( array(
			'output' => $result,
		) );
	}

	/**
	 * AJAX handler: Execute workflow.
	 *
	 * @return void
	 */
	public function ajax_execute_workflow() {
		check_ajax_referer( 'wp_mcp_ai_slash_commands', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow = isset( $_POST['workflow'] ) ? sanitize_text_field( wp_unslash( $_POST['workflow'] ) ) : '';

		if ( empty( $workflow ) ) {
			wp_send_json_error( array( 'message' => __( 'No workflow provided.', 'mcp-ai-wpoos' ) ) );
		}

		// Execute workflow via command.
		$command = '/workflow ' . $workflow;
		$handler = WP_MCP_AI_Slash_Command_Handler::get_instance();
		$result = $handler->handle( $command, array( 'user_id' => get_current_user_id() ) );

		// Log execution.
		$this->log_execution( 'workflow', $workflow, $result );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'output'  => '',
			) );
		}

		wp_send_json_success( array(
			'output' => $result,
		) );
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

		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;
		$history = $this->get_execution_history( $limit );

		wp_send_json_success( array(
			'history' => $history,
		) );
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

		wp_send_json_success( array(
			'message' => __( 'History cleared successfully.', 'mcp-ai-wpoos' ),
		) );
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

		$user = wp_get_current_user();
		$entry = array(
			'id'            => uniqid( 'exec_', true ),
			'timestamp'     => current_time( 'Y-m-d H:i:s' ),
			'timestamp_raw' => time(),
			'type'          => $type,
			'command'       => $command,
			'user'          => $user->display_name,
			'user_id'       => $user->ID,
			'status'        => is_wp_error( $result ) ? 'error' : 'success',
			'output'        => is_wp_error( $result ) ? $result->get_error_message() : substr( $result, 0, 500 ),
		);

		array_unshift( $history, $entry );

		// Keep only last 100 entries.
		$history = array_slice( $history, 0, 100 );

		update_option( 'wp_mcp_ai_slash_command_history', $history );
	}
}
