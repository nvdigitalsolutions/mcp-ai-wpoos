<?php
/**
 * Harness subsystem bootstrap.
 *
 * Loaded from the main plugin loader. Registers the harness-related tools
 * with the tool registry and exposes the seven harness-layer services.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-harness-profile.php';
require_once __DIR__ . '/class-wp-mcp-ai-pii-filter.php';
require_once __DIR__ . '/class-wp-mcp-ai-prompt-cue-library.php';
require_once __DIR__ . '/class-wp-mcp-ai-reasoning-trace.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-router-harness.php';
require_once __DIR__ . '/class-wp-mcp-ai-retrieval-harness.php';
require_once __DIR__ . '/class-wp-mcp-ai-self-refine-loop.php';
require_once __DIR__ . '/class-wp-mcp-ai-harness-prompt-injector.php';
require_once __DIR__ . '/class-wp-mcp-ai-harness-eval-scheduler.php';
require_once __DIR__ . '/class-wp-mcp-ai-guardrails.php';
require_once __DIR__ . '/class-wp-mcp-ai-necessity-gate.php';
require_once __DIR__ . '/class-wp-mcp-ai-harness-trace-store.php';
require_once __DIR__ . '/class-wp-mcp-ai-harness-trace-capture.php';
require_once __DIR__ . '/class-wp-mcp-ai-harness-search-engine.php';
require_once __DIR__ . '/class-wp-mcp-ai-harness-population.php';
require_once __DIR__ . '/class-wp-mcp-ai-harness-auto-deploy.php';
require_once __DIR__ . '/class-wp-mcp-ai-output-guardrail.php';
require_once __DIR__ . '/class-wp-mcp-ai-citation-verifier.php';

// Register the chat-client cue injector. Off by default at the profile
// level — this is just the subscriber wiring.
WP_MCP_AI_Harness_Prompt_Injector::register();

// Register the Layer I guardrails subscriber. Off by default at the profile
// level — this is just the subscriber wiring.
WP_MCP_AI_Guardrails::register();

// Register the Layer J necessity gate subscriber. Off by default at the
// profile level — this is just the subscriber wiring.
WP_MCP_AI_Necessity_Gate::register();

// Register the output guardrail subscriber. Off by default at the
// profile level — this is just the subscriber wiring.
WP_MCP_AI_Output_Guardrail::register();

// Register the citation verifier subscriber. Off by default at the
// profile level — this is just the subscriber wiring.
WP_MCP_AI_Citation_Verifier::register();

// Register the Layer G cron. The handler is a no-op until at least one
// assistant has `evals_enabled` populated and a generator is wired up
// via `wp_mcp_ai_harness_eval_generator`.
WP_MCP_AI_Harness_Eval_Scheduler::register();

// Register the trace capture subscriber. Off by default at the profile
// level (gated by `trace_capture.enabled`) — this is just the subscriber wiring.
WP_MCP_AI_Harness_Trace_Capture::register();

// Tools shipped with the harness subsystem.
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-list-prompt-cues.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-select-prompt-cue.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-apply-prompt-cue.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-self-consistency-vote.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-retrieve-with-provenance.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-record-reflection.php';
require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-scope-memory.php';

add_action(
	'wp_mcp_ai_register_tools',
	function ( $registry ) {
		if ( ! $registry instanceof WP_MCP_AI_Tool_Registry ) {
			return;
		}
		$registry->register_tool( new WP_MCP_AI_Tool_List_Prompt_Cues() );
		$registry->register_tool( new WP_MCP_AI_Tool_Select_Prompt_Cue() );
		$registry->register_tool( new WP_MCP_AI_Tool_Apply_Prompt_Cue() );
		$registry->register_tool( new WP_MCP_AI_Tool_Self_Consistency_Vote() );
		$registry->register_tool( new WP_MCP_AI_Tool_Retrieve_With_Provenance() );
		$registry->register_tool( new WP_MCP_AI_Tool_Record_Reflection() );
		$registry->register_tool( new WP_MCP_AI_Tool_Scope_Memory() );
	},
	30
);
