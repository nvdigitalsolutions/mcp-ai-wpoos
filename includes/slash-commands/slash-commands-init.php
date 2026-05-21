<?php
/**
 * Slash Commands Initialization
 *
 * Loads slash command infrastructure and registers default commands.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize slash commands system
 *
 * @since 1.2.0
 */
function wp_mcp_ai_init_slash_commands() {
	// Load audit logger.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-audit.php';

	// Load parser.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';

	// Load handler.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';

	// Initialize global handler instance.
	global $wp_mcp_ai_slash_command_handler;
	$wp_mcp_ai_slash_command_handler = new WP_MCP_AI_Slash_Command_Handler();

	// Load default commands.
	wp_mcp_ai_load_default_slash_commands();

	// Load toolkit-specific commands.
	wp_mcp_ai_load_toolkit_slash_commands();

	// Register JavaScript files.
	wp_mcp_ai_register_slash_command_scripts();

	/**
	 * Fires after slash commands are initialized
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Command handler instance.
	 */
	do_action( 'wp_mcp_ai_slash_commands_initialized', $wp_mcp_ai_slash_command_handler );
}
add_action( 'init', 'wp_mcp_ai_init_slash_commands', 20 );

/**
 * Load default slash commands
 *
 * @since 1.2.0
 */
function wp_mcp_ai_load_default_slash_commands() {
	global $wp_mcp_ai_slash_command_handler;

	if ( ! $wp_mcp_ai_slash_command_handler ) {
		return;
	}

	// Load help command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-help.php';

	// Register /help command.
	$help_command = new WP_MCP_AI_Slash_Command_Help( $wp_mcp_ai_slash_command_handler );
	$wp_mcp_ai_slash_command_handler->register(
		'help',
		array(
			'handler'     => array( $help_command, 'execute' ),
			'description' => __( 'Display help information about available commands', 'mcp-ai-wpoos' ),
			'usage'       => '/help [command] [--detailed|-d]',
			'capability'  => 'read',
			'aliases'     => array( 'h', '?' ),
			'parameters'  => array(
				'command'    => array(
					'description' => __( 'Specific command to get help for', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--detailed' => array(
					'description' => __( 'Show detailed information for all commands', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--new'      => array(
					'description' => __( 'List commands added since v2.0', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load next-task command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-next-task.php';

	// Register /next-task command.
	$next_task_command = new WP_MCP_AI_Slash_Command_Next_Task();
	$wp_mcp_ai_slash_command_handler->register(
		'next-task',
		array(
			'handler'     => array( $next_task_command, 'execute' ),
			'description' => __( 'Autonomous task discovery and execution for WordPress content', 'mcp-ai-wpoos' ),
			'usage'       => '/next-task [--filter=<type>] [--type=<task-type>] [--limit=<number>] [--dry-run|-n] [--auto|-a]',
			'capability'  => 'edit_posts',
			'aliases'     => array( 'next' ),
			'parameters'  => array(
				'--filter'  => array(
					'description' => __( 'Filter tasks by type (all, drafts, seo, updates)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--type'    => array(
					'description' => __( 'Specific task type to focus on', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--limit'   => array(
					'description' => __( 'Maximum number of tasks to process (default: 5)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Show what would be done without making changes', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto'    => array(
					'description' => __( 'Execute without waiting for approval', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load ship command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-ship.php';

	// Register /ship command.
	$ship_command = new WP_MCP_AI_Slash_Command_Ship();
	$wp_mcp_ai_slash_command_handler->register(
		'ship',
		array(
			'handler'     => array( $ship_command, 'execute' ),
			'description' => __( 'Automated content review, optimization, and publishing workflow', 'mcp-ai-wpoos' ),
			'usage'       => '/ship [post_id...] [--dry-run|-n] [--publish|-p] [--schedule=<date>] [--skip-checks|-s] [--skip-seo] [--skip-images] [--skip-links]',
			'capability'  => 'publish_posts',
			'parameters'  => array(
				'post_id'       => array(
					'description' => __( 'Post ID(s) to ship. If omitted, finds draft posts ready to ship.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run'     => array(
					'description' => __( 'Preview checks without publishing', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--publish'     => array(
					'description' => __( 'Automatically publish posts that pass checks (70%+ score)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--schedule'    => array(
					'description' => __( 'Schedule publication for a future date (YYYY-MM-DD HH:MM)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-checks' => array(
					'description' => __( 'Skip all pre-flight, SEO, and quality checks', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-seo'    => array(
					'description' => __( 'Skip SEO verification checks', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-images' => array(
					'description' => __( 'Skip image optimization checks', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-links'  => array(
					'description' => __( 'Skip internal linking suggestions', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load clean-content command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-clean-content.php';

	// Register /clean-content command.
	$clean_content_command = new WP_MCP_AI_Slash_Command_Clean_Content();
	$wp_mcp_ai_slash_command_handler->register(
		'clean-content',
		array(
			'handler'     => array( $clean_content_command, 'execute' ),
			'description' => __( 'Content quality assurance with 3-phase detection (HIGH/MEDIUM/LOW certainty)', 'mcp-ai-wpoos' ),
			'usage'       => '/clean-content [post_id|recent|all] [--phase=<1-3>] [--limit=<number>] [--dry-run|-n] [--auto-fix|-a] [--post-type=<type>] [--verbose|-v]',
			'capability'  => 'edit_posts',
			'aliases'     => array( 'clean' ),
			'parameters'  => array(
				'target'      => array(
					'description' => __( 'Post ID, "recent" (default), or "all"', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--phase'     => array(
					'description' => __( 'Run specific phase only: 1 (regex), 2 (analysis), 3 (AI review)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--limit'     => array(
					'description' => __( 'Maximum number of posts to check (default: 10)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run'   => array(
					'description' => __( 'Show issues without making changes', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto-fix'  => array(
					'description' => __( 'Automatically fix HIGH certainty issues', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--post-type' => array(
					'description' => __( 'Post type to check (default: post)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--verbose'   => array(
					'description' => __( 'Show detailed output', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load optimize-perf command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-optimize-perf.php';

	// Register /optimize-perf command.
	$optimize_perf_command = new WP_MCP_AI_Slash_Command_Optimize_Perf();
	$wp_mcp_ai_slash_command_handler->register(
		'optimize-perf',
		array(
			'handler'     => array( $optimize_perf_command, 'execute' ),
			'description' => __( 'Automated performance analysis and optimization for WordPress sites', 'mcp-ai-wpoos' ),
			'usage'       => '/optimize-perf [--phases=<1-10>] [--url=<url>] [--dry-run|-n] [--auto-apply|-a] [--detailed|-v]',
			'capability'  => 'manage_options',
			'aliases'     => array( 'perf' ),
			'parameters'  => array(
				'--phases'     => array(
					'description' => __( 'Comma-separated phase numbers to run (1-10, default: all)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--url'        => array(
					'description' => __( 'URL to analyze (default: home page)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run'    => array(
					'description' => __( 'Analyze without applying optimizations', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto-apply' => array(
					'description' => __( 'Automatically apply safe optimizations', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--detailed'   => array(
					'description' => __( 'Show detailed analysis output', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load sync-docs command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-sync-docs.php';

	// Register /sync-docs command.
	$sync_docs_command = new WP_MCP_AI_Slash_Command_Sync_Docs();
	$wp_mcp_ai_slash_command_handler->register(
		'sync-docs',
		array(
			'handler'     => array( $sync_docs_command, 'execute' ),
			'description' => __( 'Documentation drift detection and synchronization', 'mcp-ai-wpoos' ),
			'usage'       => '/sync-docs [--type=<all|posts|pages|readme>] [--dry-run|-n] [--auto-fix|-a] [--skip-links] [--skip-code]',
			'capability'  => 'edit_posts',
			'aliases'     => array( 'docs' ),
			'parameters'  => array(
				'--type'       => array(
					'description' => __( 'Type of documentation to sync (all, posts, pages, readme)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run'    => array(
					'description' => __( 'Check without making changes', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto-fix'   => array(
					'description' => __( 'Automatically fix detected issues', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-links' => array(
					'description' => __( 'Skip broken link checking', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-code'  => array(
					'description' => __( 'Skip code example validation', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load workflow command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-workflow.php';

	// Register /workflow command.
	$workflow_command = new WP_MCP_AI_Slash_Command_Workflow();
	$wp_mcp_ai_slash_command_handler->register(
		'workflow',
		array(
			'handler'     => array( $workflow_command, 'execute' ),
			'description' => __( 'Execute and manage custom automation workflows', 'mcp-ai-wpoos' ),
			'usage'       => '/workflow <name> [--action=<run|list|show>] [--dry-run|-n] [--list|-l] [--show|-s]',
			'capability'  => 'edit_posts',
			'parameters'  => array(
				'name'      => array(
					'description' => __( 'Workflow name to execute', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--action'  => array(
					'description' => __( 'Action to perform: run, list, show (default: run)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Preview workflow without executing steps', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--list'    => array(
					'description' => __( 'List available workflows', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--show'    => array(
					'description' => __( 'Show workflow definition', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load compact command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-compact.php';

	// Register /compact command.
	$compact_command = new WP_MCP_AI_Slash_Command_Compact();
	$wp_mcp_ai_slash_command_handler->register(
		'compact',
		array(
			'handler'     => array( $compact_command, 'execute' ),
			'description' => __( 'Proactive context compaction — summarize conversation history to free token budget', 'mcp-ai-wpoos' ),
			'usage'       => '/compact [--strategy=<summarize|trim-tools|keep-recent|full>] [--keep=<number>]',
			'capability'  => 'read',
			'parameters'  => array(
				'--strategy' => array(
					'description' => __( 'Compaction strategy: summarize (default), trim-tools, keep-recent, full', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--keep'     => array(
					'description' => __( 'Number of recent messages to preserve (default: 6)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load context command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-context.php';

	// Register /context command.
	$context_command = new WP_MCP_AI_Slash_Command_Context();
	$wp_mcp_ai_slash_command_handler->register(
		'context',
		array(
			'handler'     => array( $context_command, 'execute' ),
			'description' => __( 'Show context budget status — token usage, message count, and capacity remaining', 'mcp-ai-wpoos' ),
			'usage'       => '/context [--verbose|-v]',
			'capability'  => 'read',
			'aliases'     => array( 'ctx' ),
			'parameters'  => array(
				'--verbose' => array(
					'description' => __( 'Show detailed token breakdown', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load memory commands (/remember, /forget, /scope) — Phase 4 of chat ⇄ memory integration.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-memory.php';

	$memory_command = new WP_MCP_AI_Slash_Command_Memory();

	$wp_mcp_ai_slash_command_handler->register(
		'remember',
		array(
			'handler'     => array( $memory_command, 'remember' ),
			'description' => __( 'Store the supplied text as a verbatim long-term memory for the current assistant.', 'mcp-ai-wpoos' ),
			'usage'       => '/remember <text> [--tag=<tag>] [--importance=<low|medium|high|critical>] [--wing=<wing>] [--room=<room>] [--summary]',
			'capability'  => 'edit_posts',
			'aliases'     => array( 'memorize' ),
			'parameters'  => array(
				'--tag'        => array(
					'description' => __( 'Tag(s) to attach (comma-separated or repeated).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--importance' => array(
					'description' => __( 'Importance level: low, medium, high, critical (default: medium).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--wing'       => array(
					'description' => __( 'Wing scope (project / client / matter).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--room'       => array(
					'description' => __( 'Room scope (topic cluster).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--summary'    => array(
					'description' => __( 'Summarise instead of storing verbatim.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	$wp_mcp_ai_slash_command_handler->register(
		'forget',
		array(
			'handler'     => array( $memory_command, 'forget' ),
			'description' => __( 'Delete a stored memory by its context_id.', 'mcp-ai-wpoos' ),
			'usage'       => '/forget <context_id>',
			'capability'  => 'edit_posts',
			'parameters'  => array(
				'context_id' => array(
					'description' => __( 'The context_id of the memory to delete.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
			),
		)
	);

	$wp_mcp_ai_slash_command_handler->register(
		'scope',
		array(
			'handler'     => array( $memory_command, 'scope' ),
			'description' => __( 'Set the active wing/room scope for subsequent memory operations in this conversation.', 'mcp-ai-wpoos' ),
			'usage'       => '/scope [<wing> [<room>]]',
			'capability'  => 'edit_posts',
			'parameters'  => array(
				'wing' => array(
					'description' => __( 'Wing name (project / client / matter). Omit to clear scope.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'room' => array(
					'description' => __( 'Optional room (topic cluster).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load markup-stats command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-markup-stats.php';
	$markup_stats_command = new WP_MCP_AI_Slash_Command_Markup_Stats();
	$wp_mcp_ai_slash_command_handler->register(
		'markup-stats',
		array(
			'handler'     => array( $markup_stats_command, 'execute' ),
			'description' => __( 'Show aggregate markup telemetry counters (completion/cancellation rates).', 'mcp-ai-wpoos' ),
			'usage'       => '/markup-stats [--verbose|-v] [--json] [--reset]',
			'capability'  => 'manage_options',
			'aliases'     => array( 'mstats' ),
			'parameters'  => array(
				'--verbose' => array(
					'description' => __( 'Show per-tool and per-mode breakdown.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--json'    => array(
					'description' => __( 'Return raw JSON data.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--reset'   => array(
					'description' => __( 'Reset all telemetry counters (manage_options required).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load jobs command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-jobs.php';
	$jobs_command = new WP_MCP_AI_Slash_Command_Jobs();
	$wp_mcp_ai_slash_command_handler->register(
		'jobs',
		array(
			'handler'     => array( $jobs_command, 'execute' ),
			'description' => __( 'List and manage async background jobs.', 'mcp-ai-wpoos' ),
			'usage'       => '/jobs [--list] [--all] [--cancel=<job_id>] [--status=<status>] [--limit=<n>] [--json]',
			'capability'  => 'edit_posts',
			'aliases'     => array(),
			'parameters'  => array(
				'--all'    => array(
					'description' => __( 'List jobs for all users (requires manage_options).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--cancel' => array(
					'description' => __( 'Cancel a specific job by ID.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--status' => array(
					'description' => __( 'Filter by status: queued, running, completed, failed, paused.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--limit'  => array(
					'description' => __( 'Maximum number of rows (default: 10).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--json'   => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load status command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-status.php';
	$status_command = new WP_MCP_AI_Slash_Command_Status();
	$wp_mcp_ai_slash_command_handler->register(
		'status',
		array(
			'handler'     => array( $status_command, 'execute' ),
			'description' => __( 'Show aggregated system health: async health, job counts, and tool registry status.', 'mcp-ai-wpoos' ),
			'usage'       => '/status [--json]',
			'capability'  => 'edit_posts',
			'aliases'     => array(),
			'parameters'  => array(
				'--json' => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load cost command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-cost.php';
	$cost_command = new WP_MCP_AI_Slash_Command_Cost();
	$wp_mcp_ai_slash_command_handler->register(
		'cost',
		array(
			'handler'     => array( $cost_command, 'execute' ),
			'description' => __( 'Show token usage and cost summary.', 'mcp-ai-wpoos' ),
			'usage'       => '/cost [--days=<n>] [--user-id=<n>] [--json]',
			'capability'  => 'edit_posts',
			'aliases'     => array(),
			'parameters'  => array(
				'--days'    => array(
					'description' => __( 'Look-back window in days (default: 7, max: 365).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--user-id' => array(
					'description' => __( 'Target user ID — requires manage_options.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--json'    => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load diagnose command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-diagnose.php';
	$diagnose_command = new WP_MCP_AI_Slash_Command_Diagnose();
	$wp_mcp_ai_slash_command_handler->register(
		'diagnose',
		array(
			'handler'     => array( $diagnose_command, 'execute' ),
			'description' => __( 'Generate a diagnostic bundle for support (version, PHP, errors, async health, tool count).', 'mcp-ai-wpoos' ),
			'usage'       => '/diagnose [--json]',
			'capability'  => 'manage_options',
			'aliases'     => array( 'debug' ),
			'parameters'  => array(
				'--json' => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load tools command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-tools.php';
	$tools_command = new WP_MCP_AI_Slash_Command_Tools();
	$wp_mcp_ai_slash_command_handler->register(
		'tools',
		array(
			'handler'     => array( $tools_command, 'execute' ),
			'description' => __( 'Browse, filter, and inspect registered tools.', 'mcp-ai-wpoos' ),
			'usage'       => '/tools [<search>] [--capability-flag=<flag>] [--list] [--page=<n>] [--show=<slug>] [--json]',
			'capability'  => 'edit_posts',
			'aliases'     => array(),
			'parameters'  => array(
				'search'            => array(
					'description' => __( 'Search term to filter by slug or description.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--capability-flag' => array(
					'description' => __( 'Filter by capability flag (read-only, write, state-changing, etc.).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--page'            => array(
					'description' => __( 'Page number for listing (20 per page).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--show'            => array(
					'description' => __( 'Show full definition for a single tool slug.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--json'            => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load skills command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-skills.php';
	$skills_command = new WP_MCP_AI_Slash_Command_Skills();
	$wp_mcp_ai_slash_command_handler->register(
		'skills',
		array(
			'handler'     => array( $skills_command, 'execute' ),
			'description' => __( 'List, inspect, and install agent skill packs.', 'mcp-ai-wpoos' ),
			'usage'       => '/skills [--list] [--install=<slug>] [--show=<slug>] [--json]',
			'capability'  => 'edit_posts',
			'aliases'     => array(),
			'parameters'  => array(
				'--install' => array(
					'description' => __( 'Install a skill pack (requires manage_options).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--show'    => array(
					'description' => __( 'Show skill pack details.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--json'    => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load preset command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-preset.php';
	$preset_command = new WP_MCP_AI_Slash_Command_Preset();
	$wp_mcp_ai_slash_command_handler->register(
		'preset',
		array(
			'handler'     => array( $preset_command, 'execute' ),
			'description' => __( 'List, inspect, and apply orchestration presets.', 'mcp-ai-wpoos' ),
			'usage'       => '/preset [--list] [--show=<id>] [--apply=<id>] [--active] [--json]',
			'capability'  => 'edit_posts',
			'aliases'     => array(),
			'parameters'  => array(
				'--show'   => array(
					'description' => __( 'Show full config for a preset ID.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--apply'  => array(
					'description' => __( 'Apply a preset by ID (requires manage_options).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--active' => array(
					'description' => __( 'Show the currently active preset.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--json'   => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load model command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-model.php';
	$model_command = new WP_MCP_AI_Slash_Command_Model();
	$wp_mcp_ai_slash_command_handler->register(
		'model',
		array(
			'handler'     => array( $model_command, 'execute' ),
			'description' => __( 'List available models, view or set the model for an assistant.', 'mcp-ai-wpoos' ),
			'usage'       => '/model [--list] [--current] [--set=<slug>] [--assistant-id=<n>] [--discover] [--json]',
			'capability'  => 'edit_posts',
			'aliases'     => array(),
			'parameters'  => array(
				'--set'          => array(
					'description' => __( 'Set model on an assistant (requires manage_options).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--assistant-id' => array(
					'description' => __( 'Target assistant post ID.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--discover'     => array(
					'description' => __( 'Trigger model discovery refresh (requires manage_options).', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--current'      => array(
					'description' => __( 'Show model for the current/specified assistant.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--json'         => array(
					'description' => __( 'Return raw JSON output.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	// Load session commands (/clear, /reset, /resume).
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-session.php';
	$session_command = new WP_MCP_AI_Slash_Command_Session();

	$wp_mcp_ai_slash_command_handler->register(
		'clear',
		array(
			'handler'     => array( $session_command, 'clear' ),
			'description' => __( 'Clear the chat window (front-end signal only — no server state changed).', 'mcp-ai-wpoos' ),
			'usage'       => '/clear',
			'capability'  => 'read',
			'aliases'     => array(),
			'parameters'  => array(),
		)
	);

	$wp_mcp_ai_slash_command_handler->register(
		'reset',
		array(
			'handler'     => array( $session_command, 'reset' ),
			'description' => __( 'Reset the current session context.', 'mcp-ai-wpoos' ),
			'usage'       => '/reset',
			'capability'  => 'read',
			'aliases'     => array(),
			'parameters'  => array(),
		)
	);

	$wp_mcp_ai_slash_command_handler->register(
		'resume',
		array(
			'handler'     => array( $session_command, 'resume' ),
			'description' => __( 'Resume the most recent saved session transcript.', 'mcp-ai-wpoos' ),
			'usage'       => '/resume',
			'capability'  => 'read',
			'aliases'     => array(),
			'parameters'  => array(),
		)
	);

	/**
	 * Fires after default slash commands are loaded
	 *
	 * Allows plugins to register additional commands.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Command handler instance.
	 */
	do_action( 'wp_mcp_ai_default_slash_commands_loaded', $wp_mcp_ai_slash_command_handler );
}

/**
 * Load toolkit-specific slash commands
 *
 * Initializes toolkit command manager and registers toolkit commands.
 *
 * @since 1.3.0
 */
function wp_mcp_ai_load_toolkit_slash_commands() {
	global $wp_mcp_ai_slash_command_handler;

	if ( ! $wp_mcp_ai_slash_command_handler ) {
		return;
	}

	// Ensure toolkit registry is loaded.
	if ( ! class_exists( 'WP_MCP_AI_Toolkit_Registry' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-toolkit-registry.php';
	}

	// Load toolkit manager.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php';

	// Load validator (industry best practices).
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-validator.php';

	// Load workflow orchestrator.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-workflow-orchestrator.php';

	// Load cache adapter (Redis/Memcached support).
	if ( file_exists( WP_MCP_AI_PATH . 'includes/cache/class-wp-mcp-ai-cache-adapter.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cache/class-wp-mcp-ai-cache-adapter.php';
	}

	// Load performance optimizer.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-performance-optimizer.php';

	// Initialize toolkit manager (singleton pattern).
	WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();

	/**
	 * Fires after toolkit slash commands are loaded
	 *
	 * Allows plugins to register additional toolkit commands.
	 *
	 * @since 1.3.0
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Command handler instance.
	 */
	do_action( 'wp_mcp_ai_toolkit_slash_commands_loaded', $wp_mcp_ai_slash_command_handler );
}

/**
 * Get global slash command handler instance
 *
 * @since 1.2.0
 *
 * @return WP_MCP_AI_Slash_Command_Handler|null Handler instance or null if not initialized.
 */
function wp_mcp_ai_get_slash_command_handler() {
	global $wp_mcp_ai_slash_command_handler;
	return $wp_mcp_ai_slash_command_handler;
}

/**
 * Execute a slash command
 *
 * Helper function to execute slash commands from anywhere in the plugin.
 *
 * @since 1.2.0
 *
 * @param string $input   Command input (e.g., "/help").
 * @param array  $context Execution context.
 * @return mixed Command result or WP_Error.
 */
function wp_mcp_ai_execute_slash_command( $input, $context = array() ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return new WP_Error(
			'slash_commands_not_initialized',
			__( 'Slash commands system not initialized.', 'mcp-ai-wpoos' )
		);
	}

	return $handler->execute( $input, $context );
}

/**
 * Register a custom slash command
 *
 * Helper function for other plugins/themes to register commands.
 *
 * @since 1.2.0
 *
 * @param string $command Command name (without leading slash).
 * @param array  $config  Command configuration.
 * @return bool True on success, false on failure.
 */
function wp_mcp_ai_register_slash_command( $command, $config ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return false;
	}

	return $handler->register( $command, $config );
}

/**
 * Check if a slash command exists
 *
 * @since 1.2.0
 *
 * @param string $command Command name.
 * @return bool True if command exists.
 */
function wp_mcp_ai_slash_command_exists( $command ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return false;
	}

	return $handler->command_exists( $command );
}

/**
 * Get all registered slash commands
 *
 * @since 1.2.0
 *
 * @param bool $filter_by_capability Filter by current user capability.
 * @return array Registered commands.
 */
function wp_mcp_ai_get_slash_commands( $filter_by_capability = false ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return array();
	}

	return $handler->get_commands( $filter_by_capability );
}

/**
 * Register slash command JavaScript files
 *
 * @since 1.2.0
 */
function wp_mcp_ai_register_slash_command_scripts() {
	// Register autocomplete script.
	wp_register_script(
		'wp-mcp-ai-command-autocomplete',
		WP_MCP_AI_URL . 'assets/js/command-autocomplete.js',
		array(),
		WP_MCP_AI_VERSION,
		true
	);

	// Register slash commands integration script.
	wp_register_script(
		'wp-mcp-ai-slash-commands',
		WP_MCP_AI_URL . 'assets/js/slash-commands.js',
		array( 'wp-mcp-ai-command-autocomplete' ),
		WP_MCP_AI_VERSION,
		true
	);

	// Localize script with REST API data.
	// Build endpoint URLs by providing complete, pre-built URLs to JavaScript.
	// This prevents any client-side URL construction issues and ensures consistency.

	// Strategy: Build each endpoint as a complete absolute URL.
	// We DON'T pass the namespace to rest_url() to avoid filter-based duplication.
	// Instead, we get the REST root and manually append the namespace + endpoint path.
	$rest_root = rest_url(); // e.g., https://example.com/wp-json/.
	$namespace = WP_MCP_AI_REST::REST_NAMESPACE; // mcp-ai/v1.

	// Build complete URLs for each endpoint.
	$rest_url_base               = trailingslashit( $rest_root ) . trailingslashit( $namespace );
	$slash_command_endpoint      = $rest_url_base . 'slash-command';
	$slash_command_list_endpoint = $rest_url_base . 'slash-command/list';

	wp_localize_script(
		'wp-mcp-ai-slash-commands',
		'mcpAiData',
		array(
			'restUrl'                  => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( $rest_url_base ) ),
			'slashCommandEndpoint'     => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( $slash_command_endpoint ) ),
			'slashCommandListEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( $slash_command_list_endpoint ) ),
			'nonce'                    => wp_create_nonce( 'wp_rest' ),
		)
	);

	// Note: Slash command scripts are enqueued by the shortcode renderer
	// when the chat interface is loaded. This ensures proper loading order
	// and compatibility with all AI providers including OpenAI, Gemini, and Ollama.
}

/**
 * Get workflow orchestrator instance.
 *
 * @since 1.3.0
 *
 * @return WP_MCP_AI_Slash_Command_Workflow_Orchestrator Orchestrator instance.
 */
function wp_mcp_ai_get_workflow_orchestrator() {
	static $orchestrator = null;

	if ( null === $orchestrator ) {
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( class_exists( 'WP_MCP_AI_Slash_Command_Workflow_Orchestrator' ) ) {
			$orchestrator = new WP_MCP_AI_Slash_Command_Workflow_Orchestrator( $handler );
		}
	}

	return $orchestrator;
}

/**
 * Get performance optimizer instance.
 *
 * @since 1.3.0
 *
 * @return WP_MCP_AI_Slash_Command_Performance_Optimizer Optimizer instance.
 */
function wp_mcp_ai_get_performance_optimizer() {
	static $optimizer = null;

	if ( null === $optimizer && class_exists( 'WP_MCP_AI_Slash_Command_Performance_Optimizer' ) ) {
		$optimizer = new WP_MCP_AI_Slash_Command_Performance_Optimizer();
	}

	return $optimizer;
}

/**
 * Execute a workflow.
 *
 * Helper function to execute workflows from anywhere in the plugin.
 *
 * @since 1.3.0
 *
 * @param string $workflow_name Workflow name.
 * @param array  $params Workflow parameters.
 * @param array  $context Execution context.
 * @return array Workflow result.
 */
function wp_mcp_ai_execute_workflow( $workflow_name, $params = array(), $context = array() ) {
	$orchestrator = wp_mcp_ai_get_workflow_orchestrator();

	if ( ! $orchestrator ) {
		return new WP_Error( 'wp_mcp_ai_error', __( 'Workflow orchestrator not available.', 'mcp-ai-wpoos' ) );
	}

	return $orchestrator->execute_workflow( $workflow_name, $params, $context );
}


/**
 * Create audit table on plugin activation
 *
 * @since 1.2.0
 */
function wp_mcp_ai_create_slash_command_audit_table() {
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-audit.php';

	$audit = new WP_MCP_AI_Slash_Command_Audit();
	$audit->create_table();
}

/**
 * Schedule cleanup of old audit logs
 *
 * @since 1.2.0
 */
function wp_mcp_ai_schedule_audit_cleanup() {
	if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_slash_audit' ) ) {
		wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_slash_audit' );
	}
}
add_action( 'init', 'wp_mcp_ai_schedule_audit_cleanup' );

/**
 * Clean old audit logs (runs daily)
 *
 * @since 1.2.0
 */
function wp_mcp_ai_cleanup_slash_audit() {
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-audit.php';

	$audit = new WP_MCP_AI_Slash_Command_Audit();

	// Keep 90 days of audit logs by default.
	$days_to_keep = apply_filters( 'wp_mcp_ai_slash_audit_retention_days', 90 );
	$deleted      = $audit->clean_old_logs( $days_to_keep );

	if ( $deleted && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log used as a diagnostic fallback logger; active only when WP_DEBUG is enabled or as last-resort error capture in catch blocks.
		error_log( sprintf( '[SlashCommands:AUDIT] Cleaned %d old audit log entries (older than %d days)', $deleted, $days_to_keep ) );
	}
}
add_action( 'wp_mcp_ai_cleanup_slash_audit', 'wp_mcp_ai_cleanup_slash_audit' );
