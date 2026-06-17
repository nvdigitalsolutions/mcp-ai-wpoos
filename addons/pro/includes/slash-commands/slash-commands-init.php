<?php
/**
 * Pro Slash Commands Initialization
 *
 * Loads Pro slash command classes and registers them with the
 * WP_MCP_AI_Slash_Command_Handler instance passed by the
 * `wp_mcp_ai_slash_commands_initialized` action.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_pro_load_slash_commands' ) ) {
	/**
	 * Load and register all Pro slash commands.
	 *
	 * Hooked to `wp_mcp_ai_slash_commands_initialized`.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Slash command handler instance.
	 */
	function wp_mcp_ai_pro_load_slash_commands( $handler ) {
		$commands_dir = WP_MCP_AI_PRO_PATH . 'includes/slash-commands/commands/';

		// ── /schedule ─────────────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-schedule.php';
		$schedule_cmd = new WP_MCP_AI_Pro_Slash_Command_Schedule();
		$handler->register(
			'schedule',
			array(
				'handler'     => array( $schedule_cmd, 'execute' ),
				'description' => __( 'Manage Pro schedules: list, show, create, pause, resume, delete, run, history.', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/schedule [list|show|create|pause|resume|delete|run|history] [id] [--name=<n>] [--type=<t>] [--cron=<c>] [--all] [--json] [--limit=<n>]',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'sched' ),
				'parameters'  => array(
					'action'   => array(
						'description' => __( 'Sub-action: list, show, create, pause, resume, delete, run, history (default: list)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--name'   => array(
						'description' => __( 'Schedule name (for create)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--type'   => array(
						'description' => __( 'Schedule type: task|workflow|assistant_run|channel_broadcast|workflow_builder', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--cron'   => array(
						'description' => __( 'WP cron interval (for create)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--all'    => array(
						'description' => __( 'List all schedules (requires manage_options)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--limit'  => array(
						'description' => __( 'Max results (default 20)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--notify' => array(
						'description' => __( 'Enable failure notifications (for create)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json'   => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /schedule-preset ──────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-schedule-preset.php';
		$schedule_preset_cmd = new WP_MCP_AI_Pro_Slash_Command_Schedule_Preset();
		$handler->register(
			'schedule-preset',
			array(
				'handler'     => array( $schedule_preset_cmd, 'execute' ),
				'description' => __( 'Browse and install Pro schedule presets.', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/schedule-preset [--list] [--toolkit=<cat>] [--show=<id>] [--install=<id>] [--categories] [--json]',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'sched-preset' ),
				'parameters'  => array(
					'--toolkit'    => array(
						'description' => __( 'Filter by toolkit/category', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--show'       => array(
						'description' => __( 'Show full details for a preset ID', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--install'    => array(
						'description' => __( 'Install preset as a new schedule (requires manage_options)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--categories' => array(
						'description' => __( 'List available categories/toolkits', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json'       => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /workflow-preset ──────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-workflow-preset.php';
		$workflow_preset_cmd = new WP_MCP_AI_Pro_Slash_Command_Workflow_Preset();
		$handler->register(
			'workflow-preset',
			array(
				'handler'     => array( $workflow_preset_cmd, 'execute' ),
				'description' => __( 'Browse and install Workflow Builder presets.', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/workflow-preset [--list] [--category=<cat>] [--categories] [--show=<id>] [--install=<id>] [--json]',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'wf-preset' ),
				'parameters'  => array(
					'--category'   => array(
						'description' => __( 'Filter by category', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--categories' => array(
						'description' => __( 'List available categories', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--show'       => array(
						'description' => __( 'Show full details for a preset', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--install'    => array(
						'description' => __( 'Install preset (requires manage_options)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json'       => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /run ──────────────────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-run.php';
		$run_cmd = new WP_MCP_AI_Pro_Slash_Command_Run();
		$handler->register(
			'run',
			array(
				'handler'     => array( $run_cmd, 'execute' ),
				'description' => __( 'Run a saved Workflow Builder DAG by ID or name.', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/run <workflow-id-or-name> [--dry-run] [--list] [--json]',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'run-workflow' ),
				'parameters'  => array(
					'workflow'  => array(
						'description' => __( 'Workflow ID or name', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--list'    => array(
						'description' => __( 'List available saved workflows', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--dry-run' => array(
						'description' => __( 'Preview workflow without executing', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json'    => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /agent ────────────────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-agent.php';
		$agent_cmd = new WP_MCP_AI_Pro_Slash_Command_Agent();
		$handler->register(
			'agent',
			array(
				'handler'     => array( $agent_cmd, 'execute' ),
				'description' => __( 'A2A Agent delegation: list tasks, send messages, cancel, discover agents.', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/agent [--list] [--status=<id>] [--cancel=<id>] [--send=<url> --message=<text>] [--discover=<url>] [--limit=<n>] [--json]',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'a2a' ),
				'parameters'  => array(
					'--status'   => array(
						'description' => __( 'Get status of a task by ID', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--cancel'   => array(
						'description' => __( 'Cancel a task by ID', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--send'     => array(
						'description' => __( 'Send a message to a remote agent URL', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--message'  => array(
						'description' => __( 'Message text for --send', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--discover' => array(
						'description' => __( 'Discover capabilities of a remote agent URL', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--limit'    => array(
						'description' => __( 'Max tasks to list (default 10)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json'     => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /mcp-app ──────────────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-mcp-app.php';
		$mcp_app_cmd = new WP_MCP_AI_Pro_Slash_Command_Mcp_App();
		$handler->register(
			'mcp-app',
			array(
				'handler'     => array( $mcp_app_cmd, 'execute' ),
				'description' => __( 'Manage MCP App connections for an assistant (list, test, discover tools).', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/mcp-app [--list] [--assistant-id=<n>] [--test=<label>] [--discover=<label>] [--json]',
				'capability'  => 'manage_options',
				'aliases'     => array( 'mcp-apps' ),
				'parameters'  => array(
					'--assistant-id' => array(
						'description' => __( 'Assistant ID (falls back to context)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--test'         => array(
						'description' => __( 'Test connection for app with this label', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--discover'     => array(
						'description' => __( 'Discover tools for app with this label', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json'         => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /persona ──────────────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-persona.php';
		$persona_cmd = new WP_MCP_AI_Pro_Slash_Command_Persona();
		$handler->register(
			'persona',
			array(
				'handler'     => array( $persona_cmd, 'execute' ),
				'description' => __( 'Switch the assistant persona / profession.', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/persona [<slug>] [--list] [--show=<slug>] [--json]',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'profile', 'assistant' ),
				'parameters'  => array(
					'slug'   => array(
						'description' => __( 'Profession slug to load', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--list' => array(
						'description' => __( 'List all available professions', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--show' => array(
						'description' => __( 'Show full details for a profession', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json' => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /mcp-server ───────────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-mcp-server.php';
		$mcp_server_cmd = new WP_MCP_AI_Pro_Slash_Command_Mcp_Server();
		$handler->register(
			'mcp-server',
			array(
				'handler'     => array( $mcp_server_cmd, 'execute' ),
				'description' => __( 'Inspect and toggle per-toolkit MCP servers (list, show, enable, disable, tools).', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/mcp-server [list|show|enable|disable|tools] [<slug>] [--json]',
				'capability'  => 'edit_posts',
				'aliases'     => array( 'mcp-servers', 'toolkit-mcp' ),
				'parameters'  => array(
					'action' => array(
						'description' => __( 'Sub-action: list (default), show, enable, disable, tools', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'slug'   => array(
						'description' => __( 'Server slug (required for show/enable/disable/tools)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json' => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);

		// ── /broadcast ────────────────────────────────────────────────────────
		require_once $commands_dir . 'class-wp-mcp-ai-pro-slash-command-broadcast.php';
		$broadcast_cmd = new WP_MCP_AI_Pro_Slash_Command_Broadcast();
		$handler->register(
			'broadcast',
			array(
				'handler'     => array( $broadcast_cmd, 'execute' ),
				'description' => __( 'Send a one-shot message to a chat channel (Telegram, Slack, Discord, Teams…).', 'mcp-ai-wpoos-pro' ),
				'usage'       => '/broadcast <message> --channel=<channel> [--dry-run] [--json]',
				'capability'  => 'manage_options',
				'aliases'     => array(),
				'parameters'  => array(
					'message'   => array(
						'description' => __( 'Message text (positional or --message=<text>)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--channel' => array(
						'description' => __( 'Target channel: telegram|slack|discord|teams|messenger|whatsapp', 'mcp-ai-wpoos-pro' ),
						'required'    => true,
					),
					'--message' => array(
						'description' => __( 'Message text (flag alternative)', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--dry-run' => array(
						'description' => __( 'Preview without sending', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
					'--json'    => array(
						'description' => __( 'Return JSON envelope', 'mcp-ai-wpoos-pro' ),
						'required'    => false,
					),
				),
			)
		);
	}
}

add_action( 'wp_mcp_ai_slash_commands_initialized', 'wp_mcp_ai_pro_load_slash_commands' );
