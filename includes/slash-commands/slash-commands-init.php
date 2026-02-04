<?php
/**
 * Slash Commands Initialization
 *
 * Loads slash command infrastructure and registers default commands.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
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
				'command' => array(
					'description' => __( 'Specific command to get help for', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--detailed' => array(
					'description' => __( 'Show detailed information for all commands', 'mcp-ai-wpoos' ),
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
				'--filter' => array(
					'description' => __( 'Filter tasks by type (all, drafts, seo, updates)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--type' => array(
					'description' => __( 'Specific task type to focus on', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--limit' => array(
					'description' => __( 'Maximum number of tasks to process (default: 5)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Show what would be done without making changes', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto' => array(
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
				'post_id' => array(
					'description' => __( 'Post ID(s) to ship. If omitted, finds draft posts ready to ship.', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Preview checks without publishing', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--publish' => array(
					'description' => __( 'Automatically publish posts that pass checks (70%+ score)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--schedule' => array(
					'description' => __( 'Schedule publication for a future date (YYYY-MM-DD HH:MM)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-checks' => array(
					'description' => __( 'Skip all pre-flight, SEO, and quality checks', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-seo' => array(
					'description' => __( 'Skip SEO verification checks', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-images' => array(
					'description' => __( 'Skip image optimization checks', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-links' => array(
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
				'target' => array(
					'description' => __( 'Post ID, "recent" (default), or "all"', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--phase' => array(
					'description' => __( 'Run specific phase only: 1 (regex), 2 (analysis), 3 (AI review)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--limit' => array(
					'description' => __( 'Maximum number of posts to check (default: 10)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Show issues without making changes', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto-fix' => array(
					'description' => __( 'Automatically fix HIGH certainty issues', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--post-type' => array(
					'description' => __( 'Post type to check (default: post)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--verbose' => array(
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
				'--phases' => array(
					'description' => __( 'Comma-separated phase numbers to run (1-10, default: all)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--url' => array(
					'description' => __( 'URL to analyze (default: home page)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Analyze without applying optimizations', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto-apply' => array(
					'description' => __( 'Automatically apply safe optimizations', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--detailed' => array(
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
				'--type' => array(
					'description' => __( 'Type of documentation to sync (all, posts, pages, readme)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Check without making changes', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--auto-fix' => array(
					'description' => __( 'Automatically fix detected issues', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-links' => array(
					'description' => __( 'Skip broken link checking', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--skip-code' => array(
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
				'name' => array(
					'description' => __( 'Workflow name to execute', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--action' => array(
					'description' => __( 'Action to perform: run, list, show (default: run)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--dry-run' => array(
					'description' => __( 'Preview workflow without executing steps', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--list' => array(
					'description' => __( 'List available workflows', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--show' => array(
					'description' => __( 'Show workflow definition', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
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
		'mcp-ai-command-autocomplete',
		WP_MCP_AI_URL . 'assets/js/command-autocomplete.js',
		array(),
		WP_MCP_AI_VERSION,
		true
	);

	// Register slash commands integration script.
	wp_register_script(
		'mcp-ai-slash-commands',
		WP_MCP_AI_URL . 'assets/js/slash-commands.js',
		array( 'mcp-ai-command-autocomplete' ),
		WP_MCP_AI_VERSION,
		true
	);

	// Localize script with REST API data.
	wp_localize_script(
		'mcp-ai-slash-commands',
		'mcpAiData',
		array(
			'restUrl' => esc_url_raw( rest_url() ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);

	// Enqueue with chat bundle if it's loaded.
	add_action(
		'wp_enqueue_scripts',
		function() {
			if ( wp_script_is( 'wp-mcp-ai-chat', 'enqueued' ) ) {
				wp_enqueue_script( 'mcp-ai-slash-commands' );
			}
		},
		20
	);
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
		return array(
			'success' => false,
			'error'   => 'orchestrator_not_available',
			'message' => __( 'Workflow orchestrator not available.', 'mcp-ai-wpoos' ),
		);
	}

	return $orchestrator->execute_workflow( $workflow_name, $params, $context );
}

