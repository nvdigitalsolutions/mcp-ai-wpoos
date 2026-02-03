<?php
/**
 * Architect Agent Toolkit Settings Page
 *
 * Admin page for configuring the Architect Agent toolkit with self-editing capabilities.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Architect Agent Toolkit Settings Page
 *
 * Provides configuration options for the Architect Agent toolkit including:
 * - File management operations
 * - Shell command execution
 * - Git version control integration
 * - Code search capabilities
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Architect_Agent_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 31 );
	}

	/**
	 * Add settings page to admin menu under Pro Dashboard.
	 *
	 * @since 1.1.0
	 */
	public function add_settings_page() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Architect Agent Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'Architect Agent', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			'nvoos-architect-agent-toolkit',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.1.0
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Architect Agent Toolkit', 'mcp-ai-wpoos-pro' ); ?></h1>

			<div class="card">
				<h2><?php esc_html_e( 'About Architect Agent', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'The Architect Agent Toolkit provides AI agents with self-editing capabilities, enabling them to read, modify, and improve plugin code. This toolkit achieves feature parity with GitHub Copilot CLI.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Available Tools (4)', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><strong>manage_files</strong> - <?php esc_html_e( 'Read, write, and list files within the plugin directory', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>execute_shell_command</strong> - <?php esc_html_e( 'Run shell commands with safety controls and timeout protection', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>git_operations</strong> - <?php esc_html_e( 'Git version control operations (status, diff, log, commit, etc.)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>search_codebase</strong> - <?php esc_html_e( 'Advanced code pattern search with grep-style matching', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Capability Flags (11 Total)', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php
					esc_html_e(
						'Each tool declares capability flags that provide metadata about its behavior and security requirements:',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<ul>
					<li><strong>architect-agent</strong> - <?php esc_html_e( 'Core Architect Agent capability', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>code-modification</strong> - <?php esc_html_e( 'Can modify source code files', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>shell-execution</strong> - <?php esc_html_e( 'Can execute shell commands', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>version-control</strong> - <?php esc_html_e( 'Can perform git operations', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>code-search</strong> - <?php esc_html_e( 'Can search codebase', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>requires-workspace-trust</strong> - <?php esc_html_e( 'Requires workspace trust (security model)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><strong>development-workflow</strong> - <?php esc_html_e( 'Part of development lifecycle', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Security Features', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Requires edit_plugins capability', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'All operations confined to plugin directory (WP_MCP_AI_PATH)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Path validation prevents directory traversal', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Dangerous command pattern detection (20+ patterns blocked)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Timeout protection for shell commands (1-300 seconds)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Comprehensive audit logging with user/assistant IDs', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: %s: documentation file path */
						esc_html__( 'For complete setup instructions and usage examples, see %s', 'mcp-ai-wpoos-pro' ),
						'<code>docs/guides/setup/ARCHITECT_AGENT_SETUP.md</code>'
					);
					?>
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: documentation file path */
						esc_html__( 'For architecture details and GitHub Copilot CLI parity information, see %s', 'mcp-ai-wpoos-pro' ),
						'<code>docs/ARCHITECT_AGENT_COPILOT_CLI_PARITY.md</code>'
					);
					?>
				</p>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'To enable or disable this toolkit, go to Settings → NV oOS → Tools and toggle the "Enable Architect Agent Toolkit" option.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings&tab=tools' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Tools Settings', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Warning', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p style="color: #d63638;">
					<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php
					esc_html_e(
						'These tools provide powerful capabilities that enable AI agents to modify code and execute commands. Only grant access to trusted administrators with edit_plugins capability. Always use in development environments with version control and backups.',
						'mcp-ai-wpoos-pro'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}

// Initialize the settings page.
new WP_MCP_AI_Architect_Agent_Settings_Page();
