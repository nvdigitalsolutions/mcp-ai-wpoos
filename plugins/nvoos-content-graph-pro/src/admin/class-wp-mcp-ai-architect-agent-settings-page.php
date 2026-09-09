<?php
/**
 * WP_MCP_AI_Architect_Agent_Settings_Page (ecosystem port - Wave F2, architect-agent toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swap with the `src/` root for the toolkit-settings-base require.
 *
 * Architect Agent toolkit settings page.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architect_Agent
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Architect Agent Toolkit Settings Page
 *
 * Provides configuration options for the Architect Agent toolkit including:
 * - File management operations
 * - Shell command execution
 * - Git version control integration
 * - Code search capabilities
 *
 * Now extends WP_MCP_AI_Toolkit_Settings_Base for a consistent
 * tabbed interface with full MCP Server configuration.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Architect_Agent_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->toolkit_slug     = 'architect_agent'; // Kebab-converts to 'architect-agent' for MCP server lookup.
		$this->toolkit_name     = __( 'Architect Agent', 'nvoos-content-graph-pro' );
		$this->option_name      = 'wp_mcp_ai_architect_agent_toolkit_settings';
		$this->page_slug        = 'nvoos-architect-agent-toolkit';
		$this->icon             = 'dashicons-editor-code';
		$this->has_research     = false;
		$this->has_remote_sites = false;

		parent::__construct();
	}

	/**
	 * Get toolkit slug.
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name.
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab content.
	 *
	 * @since 1.1.0
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'About Architect Agent', 'nvoos-content-graph-pro' ); ?></h2>
			<p>
				<?php
				esc_html_e(
					'The Architect Agent Toolkit provides AI agents with self-editing capabilities, enabling them to read, modify, and improve plugin code. This toolkit achieves feature parity with GitHub Copilot CLI.',
					'nvoos-content-graph-pro'
				);
				?>
			</p>

			<h3><?php esc_html_e( 'Available Tools (4)', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><strong>manage_files</strong> — <?php esc_html_e( 'Read, write, and list files within the plugin directory', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>execute_shell_command</strong> — <?php esc_html_e( 'Run shell commands with safety controls and timeout protection', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>git_operations</strong> — <?php esc_html_e( 'Git version control operations (status, diff, log, commit, etc.)', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>search_codebase</strong> — <?php esc_html_e( 'Advanced code pattern search with grep-style matching', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Capability Flags (7)', 'nvoos-content-graph-pro' ); ?></h3>
			<p>
				<?php
				esc_html_e(
					'Each tool declares capability flags that provide metadata about its behavior and security requirements:',
					'nvoos-content-graph-pro'
				);
				?>
			</p>
			<ul>
				<li><strong>architect-agent</strong> — <?php esc_html_e( 'Core Architect Agent capability', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>code-modification</strong> — <?php esc_html_e( 'Can modify source code files', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>shell-execution</strong> — <?php esc_html_e( 'Can execute shell commands', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>version-control</strong> — <?php esc_html_e( 'Can perform git operations', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>code-search</strong> — <?php esc_html_e( 'Can search codebase', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>requires-workspace-trust</strong> — <?php esc_html_e( 'Requires workspace trust (security model)', 'nvoos-content-graph-pro' ); ?></li>
				<li><strong>development-workflow</strong> — <?php esc_html_e( 'Part of development lifecycle', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Security Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Requires edit_plugins capability', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'All operations confined to plugin directory (WP_MCP_AI_PATH)', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Path validation prevents directory traversal', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Dangerous command pattern detection (20+ patterns blocked)', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Timeout protection for shell commands (1-300 seconds)', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Comprehensive audit logging with user/assistant IDs', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Documentation', 'nvoos-content-graph-pro' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %s: documentation file path */
					esc_html__( 'For complete setup instructions and usage examples, see %s', 'nvoos-content-graph-pro' ),
					'<code>docs/getting-started/ARCHITECT_AGENT_SETUP.md</code>'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: documentation file path */
					esc_html__( 'For architecture details and GitHub Copilot CLI parity information, see %s', 'nvoos-content-graph-pro' ),
					'<code>docs/ARCHITECT_AGENT_COPILOT_CLI_PARITY.md</code>'
				);
				?>
			</p>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Warning', 'nvoos-content-graph-pro' ); ?></h2>
			<p style="color: #d63638;">
				<strong><?php esc_html_e( 'Important:', 'nvoos-content-graph-pro' ); ?></strong>
				<?php
				esc_html_e(
					'These tools provide powerful capabilities that enable AI agents to modify code and execute commands. Only grant access to trusted administrators with edit_plugins capability. Always use in development environments with version control and backups.',
					'nvoos-content-graph-pro'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render configuration tab content.
	 *
	 * @since 1.1.0
	 */
	protected function render_configuration_tab() {
		?>
		<h2><?php esc_html_e( 'Configuration', 'nvoos-content-graph-pro' ); ?></h2>
		<p>
			<?php
			esc_html_e(
				'To enable or disable this toolkit, go to Settings → NV oOS → Tools and toggle the "Enable Architect Agent Toolkit" option.',
				'nvoos-content-graph-pro'
			);
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings&tab=tools' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Go to Tools Settings', 'nvoos-content-graph-pro' ); ?>
			</a>
		</p>
		<p class="description">
			<?php esc_html_e( 'The MCP Server tab allows you to configure which tools are exposed via the JSON-RPC endpoint, manage ingestion surfaces, and set rate limits for the Architect Agent MCP server.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Get list of tools for this toolkit.
	 *
	 * @since 1.1.0
	 *
	 * @return array Array of tool slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			'manage_files'          => __( 'Manage Files', 'nvoos-content-graph-pro' ),
			'execute_shell_command' => __( 'Execute Shell Command', 'nvoos-content-graph-pro' ),
			'git_operations'        => __( 'Git Operations', 'nvoos-content-graph-pro' ),
			'search_codebase'       => __( 'Search Codebase', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized input.
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		return $input;
	}
}

// Initialize the settings page.
new WP_MCP_AI_Architect_Agent_Settings_Page();
