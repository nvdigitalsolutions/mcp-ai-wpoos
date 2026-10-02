<?php
/**
 * WP-CLI commands for the NV oOS plugin.
 *
 * Legacy dispatcher: since Proposal 050 the command classes live in
 * includes/cli/ and self-register. This file loads them and keeps the
 * registrations for classes that load lazily (JetEngine-gated etc.).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO, Generic.Files, PSR1.Files.SideEffects.FoundWithSymbols, Squiz.Commenting.FunctionComment.Missing -- CLI command file with multiple command classes and helper functions.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-root-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-plugins-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-queue-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-token-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-rabbitmq-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-stdio-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-health-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-cache-command.php';
	require_once __DIR__ . '/cli/class-wp-mcp-ai-cli-version-command.php';

	// Load additional CLI command classes.
	if ( ! class_exists( 'WP_MCP_AI_CLI_DLQ' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-dlq.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-dlq.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_SLA' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-sla.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-sla.php';
	}

	// Load Profession Orchestration CLI commands.
	if ( file_exists( WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-orchestration-cli.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-orchestration-cli.php';
	}

	// Load Slash Command CLI commands.
	if ( file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-slash-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-slash-command.php';
	}

	// Load Assistant CLI commands.
	if ( ! class_exists( 'WP_MCP_AI_CLI_Assistant_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-assistant-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-assistant-command.php';
	}

	// Load Tool CLI commands.
	if ( ! class_exists( 'WP_MCP_AI_CLI_Tool_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-tool-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-tool-command.php';
	}

	// Load Settings CLI commands.
	if ( ! class_exists( 'WP_MCP_AI_CLI_Settings_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-settings-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-settings-command.php';
	}

	// Load Credential CLI commands.
	if ( ! class_exists( 'WP_MCP_AI_CLI_Credential_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-credential-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-credential-command.php';
	}

	// Load Log CLI commands.
	if ( ! class_exists( 'WP_MCP_AI_CLI_Log_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-log-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-log-command.php';
	}

	// Load Measurement CLI commands (PR 11).
	if ( ! class_exists( 'WP_MCP_AI_CLI_Measurement_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-measurement-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-measurement-command.php';
	}

	// Load Bulk-tool / massive-data CLI commands (Phase 5).
	if ( ! class_exists( 'WP_MCP_AI_CLI_Bulk_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-bulk-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-bulk-command.php';
	}

	// Load v1.1.30 CLI commands — memory, thread, provider, cron, chat, transcript, approval.
	if ( ! class_exists( 'WP_MCP_AI_CLI_Memory_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-memory-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-memory-command.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_Thread_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-thread-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-thread-command.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_Provider_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-provider-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-provider-command.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_Cron_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-cron-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-cron-command.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_Chat_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-chat-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-chat-command.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_Transcript_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-transcript-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-transcript-command.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_Approval_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-approval-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-approval-command.php';
	}
	if ( ! class_exists( 'WP_MCP_AI_CLI_Restriction_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-restriction-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-restriction-command.php';
	}

	if ( ! class_exists( 'WP_MCP_AI_CLI_Harness_Search_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-harness-search-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-harness-search-command.php';
	}

	// Load OOS parity CLI commands (Proposal 029, Phase 4).
	if ( ! class_exists( 'WP_MCP_AI_CLI_OOS_Parity_Command' ) && file_exists( WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-oos-parity-command.php' ) ) {
		require_once WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-oos-parity-command.php';
	}

	// Conversation import commands load only when the import pipeline
	// classes were registered (JetEngine-available environments).
	if ( class_exists( 'WP_MCP_AI_CLI_Conversation_Import_Command' ) ) {
		WP_CLI::add_command( 'mcp-ai conversation-import', 'WP_MCP_AI_CLI_Conversation_Import_Command' );
	}

	// Register DLQ and SLA commands only if the classes were successfully loaded.
	if ( class_exists( 'WP_MCP_AI_CLI_DLQ' ) ) {
		WP_CLI::add_command( 'mcp-ai dlq', 'WP_MCP_AI_CLI_DLQ' );
	}
	if ( class_exists( 'WP_MCP_AI_CLI_SLA' ) ) {
		WP_CLI::add_command( 'mcp-ai sla', 'WP_MCP_AI_CLI_SLA' );
	}

	// Register restriction management commands.
	if ( class_exists( 'WP_MCP_AI_CLI_Restriction_Command' ) ) {
		WP_CLI::add_command( 'mcp-ai restrictions', 'WP_MCP_AI_CLI_Restriction_Command' );
	}

	// Register Profession Orchestration commands.
	if ( class_exists( 'WP_MCP_AI_Profession_Orchestration_CLI' ) ) {
		WP_CLI::add_command( 'profession seed-orchestration', array( 'WP_MCP_AI_Profession_Orchestration_CLI', 'seed_orchestration' ) );
		WP_CLI::add_command( 'profession orchestration-stats', array( 'WP_MCP_AI_Profession_Orchestration_CLI', 'orchestration_stats' ) );
	}

	// Register harness search command.
	if ( class_exists( 'WP_MCP_AI_CLI_Harness_Search_Command' ) ) {
		WP_CLI::add_command( 'mcp-ai harness search', 'WP_MCP_AI_CLI_Harness_Search_Command' );
		WP_CLI::add_command( 'mcp-ai harness population', 'WP_MCP_AI_CLI_Harness_Search_Command' );
		WP_CLI::add_command( 'mcp-ai harness trace', 'WP_MCP_AI_CLI_Harness_Search_Command' );
	}
}
