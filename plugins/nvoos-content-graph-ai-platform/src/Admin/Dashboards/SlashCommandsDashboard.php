<?php
/**
 * Slash Commands dashboard (Wave E-UI-1, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_Slash_Commands_Dashboard`
 * (`includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php`):
 * byte-identical dashboard surface — the `mcp-ai-slash-commands` page
 * slug with the `edit_posts` (Contributor+) menu capability, the five
 * AJAX actions (`wp_mcp_ai_execute_command`, `wp_mcp_ai_get_command_history`,
 * `wp_mcp_ai_get_history_entry`, `wp_mcp_ai_clear_command_history`,
 * `wp_mcp_ai_execute_slash_workflow`) with the `wp_mcp_ai_slash_commands`
 * nonce, the `wpMcpAiSlashCommands` localized config envelope, the
 * four in-page tabs (commands/workflows/history/test with the
 * `$_GET['tab']` whitelist), the command statistics + global/toolkit
 * grouping + help display, the workflows table + details + execution
 * output boxes, the execution history table + details box, the
 * command tester, the handler-driven command list (with the toolkit
 * manager re-registration call), the orchestrator-driven workflow
 * list (built-in + uploads-directory custom YAML), the execution
 * history option (`wp_mcp_ai_slash_command_history`, 100-entry cap,
 * 500-char output preview truncation, `uniqid('exec_')` IDs), and the
 * execute-command / execute-workflow / get-history / get-entry /
 * clear-history AJAX flows.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform`).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerAdmin()`;
 *    the base admin owns the same page under the base settings
 *    dashboard menu monolith. Standalone the page registers under the
 *    platform's "NV Platform" menu (`ai-platform-dashboard`).
 *  - The handler + workflow orchestrator resolve through the
 *    `wp_mcp_ai_get_slash_command_handler()` /
 *    `wp_mcp_ai_get_workflow_orchestrator()` global functions, which
 *    the base declares monolith and the platform shim
 *    (`SlashCommands/shim-functions.php`) declares standalone — so
 *    those call sites are byte-identical (no seam needed).
 *  - The toolkit grouping resolves per install mode via the
 *    `toolkit_manager()` / `toolkit_name()` seams
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): base
 *    `WP_MCP_AI_Slash_Command_Toolkit_Manager` +
 *    `WP_MCP_AI_Toolkit_Registry` monolith / the platform's
 *    `SlashCommands\SlashCommandToolkitManager` standalone with the
 *    slug-derived fallback name (no toolkit-name registry exists
 *    standalone — documented). The base's dead `$toolkits =
 *    $registry->get_toolkits();` assignment (unused local) is dropped.
 *  - The base's `private` helpers (command/workflow listing, history,
 *    logging, grouping) become `protected` — widening visibility is
 *    additive and lets the characterization suite expose them without
 *    reflection (documented deviation).
 *  - The `class_exists( 'WP_MCP_AI_Logger' )` guards are kept
 *    byte-identical — they resolve false standalone, so logging is a
 *    dormant no-op there (documented).
 *  - The dashboard's own assets (admin-slash-commands-dashboard.css/.js)
 *    are copied byte-identically into the platform asset tree.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Dashboards
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Dashboards;

/**
 * Slash Commands & Workflows dashboard.
 *
 * @since 2.0.0
 */
class SlashCommandsDashboard {

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
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mcp-ai-slash-commands';

	/**
	 * Nonce action for the dashboard AJAX handlers.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_slash_commands';

	/**
	 * Register the dashboard hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu_page' ), 21 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_execute_command', array( $this, 'ajax_execute_command' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_get_command_history', array( $this, 'ajax_get_history' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_get_history_entry', array( $this, 'ajax_get_history_entry' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_clear_command_history', array( $this, 'ajax_clear_history' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_execute_slash_workflow', array( $this, 'ajax_execute_workflow' ) );
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
	public function add_menu_page(): void {
		\add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Slash Commands', 'nvoos-content-graph-ai-platform' ),
			__( 'Slash Commands', 'nvoos-content-graph-ai-platform' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === \strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		\wp_enqueue_style(
			'wp-mcp-ai-slash-commands-dashboard',
			self::asset_url( 'css/admin-slash-commands-dashboard.css' ),
			array(),
			self::asset_version( 'css/admin-slash-commands-dashboard.css' )
		);

		\wp_enqueue_script(
			'wp-mcp-ai-slash-commands-dashboard',
			self::asset_url( 'js/admin-slash-commands-dashboard.js' ),
			array( 'jquery' ),
			self::asset_version( 'js/admin-slash-commands-dashboard.js' ),
			true
		);

		\wp_localize_script(
			'wp-mcp-ai-slash-commands-dashboard',
			'wpMcpAiSlashCommands',
			array(
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/**
	 * Asset URL for the platform's local copies (per-mode seam).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_url( $relative_path ) {
		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/' . \ltrim( $relative_path, '/' );
	}

	/**
	 * Asset version for the platform's local copies (per-file mtime).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_version( $relative_path ) {
		$absolute_path = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'assets/' . \ltrim( $relative_path, '/' );

		if ( \file_exists( $absolute_path ) ) {
			$modified = \filemtime( $absolute_path );
			if ( $modified ) {
				return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION . '.' . $modified;
			}
		}

		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;
	}

	/**
	 * Slash-command toolkit manager (per-mode seam).
	 *
	 * @return object|null
	 */
	protected static function toolkit_manager() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( \class_exists( 'WP_MCP_AI_Slash_Command_Toolkit_Manager' ) ) {
				return \WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
			}

			return null;
		}

		if ( \class_exists( 'NvoosContentGraphAiPlatform\SlashCommands\SlashCommandToolkitManager' ) ) {
			return \NvoosContentGraphAiPlatform\SlashCommands\SlashCommandToolkitManager::get_instance();
		}

		return null;
	}

	/**
	 * Toolkit display name for a toolkit slug (per-mode seam).
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return string
	 */
	protected static function toolkit_name( $toolkit_slug ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Toolkit_Registry' ) ) {
			$registry = \WP_MCP_AI_Toolkit_Registry::get_instance();
			if ( $registry ) {
				$toolkit_info = $registry->get_toolkit( $toolkit_slug );
				if ( $toolkit_info ) {
					return $toolkit_info['name'];
				}
			}
		}

		// No toolkit-name registry standalone — slug-derived fallback (documented).
		return \ucwords( \str_replace( '_', ' ', $toolkit_slug ) );
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			\wp_die( \esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-ai-platform' ) );
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
		$active_tab = isset( $_GET['tab'] ) ? \sanitize_text_field( \wp_unslash( $_GET['tab'] ) ) : 'commands';
		if ( ! \in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'commands';
		}

		?>
		<div class="wrap wp-mcp-ai-slash-commands-dashboard">
			<h1>
				<?php \esc_html_e( 'Slash Commands & Workflows', 'nvoos-content-graph-ai-platform' ); ?>
			</h1>

			<p class="description">
				<?php \esc_html_e( 'Manage and execute slash commands, configure workflows, and view execution history.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<!-- Tabs -->
			<h2 class="nav-tab-wrapper">
				<a href="?page=mcp-ai-slash-commands&tab=commands" class="nav-tab <?php echo \esc_attr( 'commands' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php \esc_html_e( 'Commands', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=workflows" class="nav-tab <?php echo \esc_attr( 'workflows' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php \esc_html_e( 'Workflows', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=history" class="nav-tab <?php echo \esc_attr( 'history' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php \esc_html_e( 'Execution History', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
				<a href="?page=mcp-ai-slash-commands&tab=test" class="nav-tab <?php echo \esc_attr( 'test' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php \esc_html_e( 'Test Commands', 'nvoos-content-graph-ai-platform' ); ?>
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
	protected function render_commands_tab( $commands ) {
		// Group commands by toolkit.
		$toolkit_commands = $this->group_commands_by_toolkit( $commands );
		$global_commands  = $this->get_global_commands( $commands );
		?>
		<div class="commands-tab">
			<h2><?php \esc_html_e( 'Available Commands', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'All registered slash commands and their capabilities. Commands are organized by toolkit and global commands.', 'nvoos-content-graph-ai-platform' ); ?></p>

			<!-- Statistics -->
			<div class="command-stats">
				<div class="stat-box">
					<strong><?php echo \esc_html( \count( $commands ) ); ?></strong>
					<span><?php \esc_html_e( 'Total Commands', 'nvoos-content-graph-ai-platform' ); ?></span>
				</div>
				<div class="stat-box">
					<strong><?php echo \esc_html( \count( $toolkit_commands ) ); ?></strong>
					<span><?php \esc_html_e( 'Toolkits', 'nvoos-content-graph-ai-platform' ); ?></span>
				</div>
				<div class="stat-box">
					<strong><?php echo \esc_html( \count( $global_commands ) ); ?></strong>
					<span><?php \esc_html_e( 'Global Commands', 'nvoos-content-graph-ai-platform' ); ?></span>
				</div>
			</div>

			<!-- Global Commands -->
			<?php if ( ! empty( $global_commands ) ) : ?>
				<h3><?php \esc_html_e( 'Global Commands', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p class="description"><?php \esc_html_e( 'Commands available system-wide:', 'nvoos-content-graph-ai-platform' ); ?></p>
				<?php $this->render_commands_table( $global_commands ); ?>
			<?php endif; ?>

			<!-- Toolkit Commands -->
			<?php if ( ! empty( $toolkit_commands ) ) : ?>
				<h3><?php \esc_html_e( 'Toolkit-Specific Commands', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p class="description"><?php \esc_html_e( 'Commands organized by their respective toolkits:', 'nvoos-content-graph-ai-platform' ); ?></p>

				<?php foreach ( $toolkit_commands as $toolkit_name => $toolkit_cmds ) : ?>
					<div class="toolkit-commands-section">
						<h4 class="toolkit-name"><?php echo \esc_html( $toolkit_name ); ?></h4>
						<p class="toolkit-command-count">
							<?php
							echo \esc_html(
								\sprintf(
									/* translators: %d: number of commands */
									\_n( '%d command', '%d commands', \count( $toolkit_cmds ), 'nvoos-content-graph-ai-platform' ),
									\count( $toolkit_cmds )
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
				<h3><?php \esc_html_e( 'Command Help', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<button type="button" class="button button-small close-help" style="float: right;" title="<?php \esc_attr_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php \esc_html_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?></span>
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
	protected function render_commands_table( $commands, $compact = false ) {
		?>
		<table class="wp-list-table widefat fixed striped <?php echo \esc_attr( $compact ? 'compact-view' : '' ); ?>">
			<thead>
				<tr>
					<th><?php \esc_html_e( 'Command', 'nvoos-content-graph-ai-platform' ); ?></th>
					<th><?php \esc_html_e( 'Description', 'nvoos-content-graph-ai-platform' ); ?></th>
					<?php if ( ! $compact ) : ?>
						<th><?php \esc_html_e( 'Aliases', 'nvoos-content-graph-ai-platform' ); ?></th>
					<?php endif; ?>
					<th><?php \esc_html_e( 'Capability', 'nvoos-content-graph-ai-platform' ); ?></th>
					<th><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $commands ) ) : ?>
					<tr>
						<td colspan="<?php echo \absint( $compact ? 4 : 5 ); ?>"><?php \esc_html_e( 'No commands available.', 'nvoos-content-graph-ai-platform' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $commands as $command ) : ?>
						<tr>
							<td><code>/<?php echo \esc_html( $command['name'] ); ?></code></td>
							<td><?php echo \esc_html( $command['description'] ); ?></td>
							<?php if ( ! $compact ) : ?>
								<td>
									<?php
									if ( ! empty( $command['aliases'] ) ) {
										echo '<code>' . \esc_html( \implode( '</code>, <code>', $command['aliases'] ) ) . '</code>';
									} else {
										echo '—';
									}
									?>
								</td>
							<?php endif; ?>
							<td><code><?php echo \esc_html( $command['capability'] ); ?></code></td>
							<td>
								<button type="button" class="button button-small view-command-help" data-command="<?php echo \esc_attr( $command['name'] ); ?>">
									<?php \esc_html_e( 'View Help', 'nvoos-content-graph-ai-platform' ); ?>
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
	protected function group_commands_by_toolkit( $commands ) {
		$toolkit_manager = self::toolkit_manager();

		if ( ! $toolkit_manager ) {
			return array();
		}

		$grouped = array();
		foreach ( $commands as $command ) {
			// Check if command has toolkit metadata.
			if ( ! empty( $command['toolkit'] ) ) {
				$toolkit_slug = $command['toolkit'];
				$toolkit_name = self::toolkit_name( $toolkit_slug );

				if ( ! isset( $grouped[ $toolkit_name ] ) ) {
					$grouped[ $toolkit_name ] = array();
				}

				$grouped[ $toolkit_name ][] = $command;
			}
		}

		// Sort toolkits alphabetically.
		\ksort( $grouped );

		return $grouped;
	}

	/**
	 * Get global (non-toolkit) commands.
	 *
	 * @param array $commands All commands.
	 * @return array Global commands.
	 */
	protected function get_global_commands( $commands ) {
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
	protected function render_workflows_tab( $workflows ) {
		?>
		<div class="workflows-tab">
			<h2><?php \esc_html_e( 'Available Workflows', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'Pre-configured workflow templates and custom workflows:', 'nvoos-content-graph-ai-platform' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php \esc_html_e( 'Workflow', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Description', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Steps', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Type', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $workflows ) ) : ?>
						<tr>
							<td colspan="5"><?php \esc_html_e( 'No workflows available.', 'nvoos-content-graph-ai-platform' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $workflows as $workflow ) : ?>
							<tr>
								<td><strong><?php echo \esc_html( $workflow['name'] ); ?></strong></td>
								<td><?php echo \esc_html( $workflow['description'] ); ?></td>
								<td><?php echo \esc_html( $workflow['step_count'] ); ?></td>
								<td>
									<span class="workflow-type-badge <?php echo \esc_attr( $workflow['type'] ); ?>">
										<?php echo \esc_html( \ucfirst( $workflow['type'] ) ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button button-small view-workflow" data-workflow="<?php echo \esc_attr( $workflow['slug'] ); ?>" title="<?php \esc_attr_e( 'View', 'nvoos-content-graph-ai-platform' ); ?>">
										<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php \esc_html_e( 'View', 'nvoos-content-graph-ai-platform' ); ?></span>
									</button>
									<button type="button" class="button button-primary button-small execute-workflow" data-workflow="<?php echo \esc_attr( $workflow['slug'] ); ?>" title="<?php \esc_attr_e( 'Execute', 'nvoos-content-graph-ai-platform' ); ?>">
										<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php \esc_html_e( 'Execute', 'nvoos-content-graph-ai-platform' ); ?></span>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Workflow Details Display -->
			<div id="workflow-details-display" class="workflow-details-box" style="display: none;">
				<h3><?php \esc_html_e( 'Workflow Details', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<button type="button" class="button button-small close-details" style="float: right;" title="<?php \esc_attr_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php \esc_html_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?></span>
				</button>
				<div id="workflow-details-content"></div>
			</div>

			<!-- Workflow Execution Output -->
			<div id="workflow-execution-output" class="workflow-execution-box" style="display: none;">
				<h3><?php \esc_html_e( 'Workflow Execution', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<button type="button" class="button button-small close-execution" style="float: right;" title="<?php \esc_attr_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php \esc_html_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?></span>
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
	protected function render_history_tab( $history ) {
		?>
		<div class="history-tab">
			<h2><?php \esc_html_e( 'Execution History', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'Recent command and workflow executions:', 'nvoos-content-graph-ai-platform' ); ?></p>

			<div class="history-actions">
				<button type="button" class="button button-secondary" id="refresh-history">
					<span class="dashicons dashicons-update"></span>
					<?php \esc_html_e( 'Refresh', 'nvoos-content-graph-ai-platform' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="clear-history" title="<?php \esc_attr_e( 'Clear History', 'nvoos-content-graph-ai-platform' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php \esc_html_e( 'Clear History', 'nvoos-content-graph-ai-platform' ); ?></span>
				</button>
			</div>

			<table class="wp-list-table widefat fixed striped" id="history-table">
				<thead>
					<tr>
						<th><?php \esc_html_e( 'Time', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Type', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Command/Workflow', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'User', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Status', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $history ) ) : ?>
						<tr>
							<td colspan="6"><?php \esc_html_e( 'No execution history available.', 'nvoos-content-graph-ai-platform' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $history as $entry ) : ?>
							<tr>
								<td><?php echo \esc_html( $entry['timestamp'] ); ?></td>
								<td><?php echo \esc_html( \ucfirst( $entry['type'] ) ); ?></td>
								<td><code><?php echo \esc_html( $entry['command'] ); ?></code></td>
								<td><?php echo \esc_html( $entry['user'] ); ?></td>
								<td>
									<span class="status-badge status-<?php echo \esc_attr( $entry['status'] ); ?>">
										<?php echo \esc_html( \ucfirst( $entry['status'] ) ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button button-small view-history-details" data-entry-id="<?php echo \esc_attr( $entry['id'] ); ?>">
										<?php \esc_html_e( 'Details', 'nvoos-content-graph-ai-platform' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- History Details Display -->
			<div id="history-details-display" class="history-details-box" style="display: none;">
				<h3><?php \esc_html_e( 'Execution Details', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<button type="button" class="button button-small close-history-details" style="float: right;" title="<?php \esc_attr_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php \esc_html_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?></span>
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
	protected function render_test_tab() {
		?>
		<div class="test-tab">
			<h2><?php \esc_html_e( 'Test Commands', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'Execute slash commands directly from the admin interface:', 'nvoos-content-graph-ai-platform' ); ?></p>

			<div class="command-tester">
				<div class="test-input-section">
					<label for="command-input">
						<strong><?php \esc_html_e( 'Enter Command:', 'nvoos-content-graph-ai-platform' ); ?></strong>
					</label>
					<input type="text" id="command-input" class="regular-text" placeholder="/help" />
					<button type="button" class="button button-primary" id="execute-command-btn">
						<span class="dashicons dashicons-controls-play"></span>
						<?php \esc_html_e( 'Execute', 'nvoos-content-graph-ai-platform' ); ?>
					</button>
					<p class="description">
						<?php \esc_html_e( 'Examples: /help, /next-task --dry-run, /ship 123 --publish, /workflow daily-review', 'nvoos-content-graph-ai-platform' ); ?>
					</p>
				</div>

				<div class="test-output-section">
					<h3><?php \esc_html_e( 'Output:', 'nvoos-content-graph-ai-platform' ); ?></h3>
					<div id="command-output" class="command-output-box">
						<p class="no-output"><?php \esc_html_e( 'No command executed yet. Enter a command above and click Execute.', 'nvoos-content-graph-ai-platform' ); ?></p>
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
	protected function get_available_commands() {
		$handler = \wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			return array();
		}

		// Ensure toolkit commands are registered if toolkit manager exists.
		$toolkit_manager = self::toolkit_manager();
		if ( $toolkit_manager && \method_exists( $toolkit_manager, 'register_toolkit_commands' ) ) {
			// The register method is safe to call multiple times as it checks enabled status.
			$toolkit_manager->register_toolkit_commands();
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
	protected function get_available_workflows() {
		$workflows = array();

		// Get workflows from the orchestrator.
		$orchestrator = \wp_mcp_ai_get_workflow_orchestrator();
		if ( $orchestrator ) {
			$handler                = \wp_mcp_ai_get_slash_command_handler();
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
		$uploads_dir   = \wp_upload_dir();
		$workflows_dir = \trailingslashit( $uploads_dir['basedir'] ) . 'mcp-ai/workflows';

		if ( \is_dir( $workflows_dir ) ) {
			$files = \glob( $workflows_dir . '/*.yml' );
			if ( \is_array( $files ) ) {
				foreach ( $files as $file ) {
					$slug        = \basename( $file, '.yml' );
					$workflows[] = array(
						'name'        => \ucwords( \str_replace( array( '-', '_' ), ' ', $slug ) ),
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
	protected function get_execution_history( $limit = 10 ) {
		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );

		// Sort by timestamp descending.
		\usort(
			$history,
			function ( $a, $b ) {
				return $b['timestamp_raw'] <=> $a['timestamp_raw'];
			}
		);

		return \array_slice( $history, 0, $limit );
	}

	/**
	 * AJAX handler: Execute command.
	 *
	 * @return void
	 */
	public function ajax_execute_command(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'edit_posts' ) ) {
			// Log permission denial.
			if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'command_permission_denied',
					'User lacks edit_posts capability to execute command.',
					array(
						'user_id' => \get_current_user_id(),
					)
				);
			}
			\wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$command = isset( $_POST['command'] ) ? \sanitize_text_field( \wp_unslash( $_POST['command'] ) ) : '';

		if ( empty( $command ) ) {
			\wp_send_json_error( array( 'message' => __( 'No command provided.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		// Log command execution attempt.
		if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'command_execution_attempt',
				\sprintf( 'Attempting to execute command: %s', $command ),
				array(
					'command' => $command,
					'user_id' => \get_current_user_id(),
				)
			);
		}

		// Execute command.
		$handler = \wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'command_handler_not_initialized',
					'Slash command handler not initialized.'
				);
			}
			\wp_send_json_error( array( 'message' => __( 'Slash commands system not initialized.', 'nvoos-content-graph-ai-platform' ) ) );
		}
		$result = $handler->execute( $command, array( 'user_id' => \get_current_user_id() ) );

		// Log execution.
		$this->log_execution( 'command', $command, $result );

		if ( \is_wp_error( $result ) ) {
			// Log error with details.
			if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'command_execution_error',
					\sprintf( 'Command execution failed: %s', $result->get_error_message() ),
					array(
						'command'    => $command,
						'error_code' => $result->get_error_code(),
						'error_data' => $result->get_error_data(),
						'user_id'    => \get_current_user_id(),
					)
				);
			}
			\wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'output'  => '',
				)
			);
		}

		// Log success.
		if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'command_execution_success',
				\sprintf( 'Command executed successfully: %s', $command ),
				array(
					'command' => $command,
					'user_id' => \get_current_user_id(),
				)
			);
		}

		\wp_send_json_success(
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
	public function ajax_execute_workflow(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'edit_posts' ) ) {
			// Log permission denial.
			if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'workflow_permission_denied',
					'User lacks edit_posts capability to execute workflow.',
					array(
						'user_id' => \get_current_user_id(),
					)
				);
			}
			\wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$workflow = isset( $_POST['workflow'] ) ? \sanitize_text_field( \wp_unslash( $_POST['workflow'] ) ) : '';

		if ( empty( $workflow ) ) {
			\wp_send_json_error( array( 'message' => __( 'No workflow provided.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		// Log workflow execution attempt.
		if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'workflow_execution_attempt',
				\sprintf( 'Attempting to execute workflow: %s', $workflow ),
				array(
					'workflow' => $workflow,
					'user_id'  => \get_current_user_id(),
				)
			);
		}

		// Execute workflow via command.
		$command = '/workflow ' . $workflow;
		$handler = \wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'workflow_handler_not_initialized',
					'Slash command handler not initialized.'
				);
			}
			\wp_send_json_error( array( 'message' => __( 'Slash commands system not initialized.', 'nvoos-content-graph-ai-platform' ) ) );
		}
		$result = $handler->execute( $command, array( 'user_id' => \get_current_user_id() ) );

		// Log execution.
		$this->log_execution( 'workflow', $workflow, $result );

		if ( \is_wp_error( $result ) ) {
			// Log error with details.
			if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'workflow_execution_error',
					\sprintf( 'Workflow execution failed: %s', $result->get_error_message() ),
					array(
						'workflow'   => $workflow,
						'error_code' => $result->get_error_code(),
						'error_data' => $result->get_error_data(),
						'user_id'    => \get_current_user_id(),
					)
				);
			}
			\wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'output'  => '',
				)
			);
		}

		// Log success.
		if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'workflow_execution_success',
				\sprintf( 'Workflow executed successfully: %s', $workflow ),
				array(
					'workflow' => $workflow,
					'user_id'  => \get_current_user_id(),
				)
			);
		}

		\wp_send_json_success(
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
	public function ajax_get_history(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'edit_posts' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$limit   = isset( $_POST['limit'] ) ? \absint( \wp_unslash( $_POST['limit'] ) ) : 10;
		$history = $this->get_execution_history( $limit );

		\wp_send_json_success(
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
	public function ajax_get_history_entry(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'edit_posts' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
		$entry_id = isset( $_POST['entry_id'] ) ? \sanitize_text_field( \wp_unslash( $_POST['entry_id'] ) ) : '';

		if ( empty( $entry_id ) ) {
			\wp_send_json_error( array( 'message' => __( 'No entry ID provided.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );

		// Find entry by ID.
		$entry = null;
		foreach ( $history as $item ) {
			if ( $item['id'] === $entry_id ) {
				$entry = $item;
				break;
			}
		}

		if ( ! $entry ) {
			\wp_send_json_error(
				array(
					'message' => __( 'History entry not found.', 'nvoos-content-graph-ai-platform' ),
				)
			);
		}

		\wp_send_json_success(
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
	public function ajax_clear_history(): void {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		\delete_option( 'wp_mcp_ai_slash_command_history' );

		\wp_send_json_success(
			array(
				'message' => __( 'History cleared successfully.', 'nvoos-content-graph-ai-platform' ),
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
	protected function log_execution( $type, $command, $result ) {
		$history = \get_option( 'wp_mcp_ai_slash_command_history', array() );

		$user  = \wp_get_current_user();
		$entry = array(
			'id'            => \uniqid( 'exec_', true ),
			'timestamp'     => \current_time( 'Y-m-d H:i:s' ),
			'timestamp_raw' => \time(),
			'type'          => $type,
			'command'       => $command,
			'user'          => $user->display_name,
			'user_id'       => $user->ID,
			'status'        => \is_wp_error( $result ) ? 'error' : 'success',
			'output'        => \is_wp_error( $result ) ? $result->get_error_message() : ( \is_string( $result ) ? \substr( $result, 0, self::HISTORY_OUTPUT_PREVIEW_LENGTH ) : \wp_json_encode( $result ) ),
		);

		\array_unshift( $history, $entry );

		// Keep only last MAX_HISTORY_ENTRIES entries.
		$history = \array_slice( $history, 0, self::MAX_HISTORY_ENTRIES );

		\update_option( 'wp_mcp_ai_slash_command_history', $history );
	}
}
