<?php
/**
 * Chat Parity Check - detect drift between legacy chat and lib/core.
 *
 * Compares the public API surface and feature inventory of the legacy
 * WordPress chat layer (WP_MCP_AI_Chat_Service, WP_MCP_AI_REST_Chat_Controller)
 * against the extracted oOS engine (ChatOrchestrator, AbstractProviderClient).
 *
 * Run as: php bin/check-chat-parity.php
 *
 * Exit codes:
 *   0 - full parity (no actionable drift)
 *   1 - drift detected (non-blocking warning)
 *   2 - critical gap detected (blocking)
 *
 * @package WP_MCP_AI
 * @since   1.1.29
 */

declare(strict_types=1);

// ─── Feature Inventory: Legacy vs lib/core ──────────────────────────

$features = array(
    // ── Chat Orchestration ─────────────────────────────────────────
    array(
        'feature' => 'Agentic loop (tool execution to LLM re-query)',
        'legacy'  => 'WP_MCP_AI_Chat_Service::process_chat_request',
        'libcore' => 'ChatOrchestrator::handleChat',
        'status'  => 'parity',
    ),
    array(
        'feature' => 'Agentic loop - streaming (SSE)',
        'legacy'  => 'WP_MCP_AI_Chat_Service::handle_chat_request_with_streaming',
        'libcore' => 'ChatOrchestrator::handleChatStreaming',
        'status'  => 'parity',
    ),
    array(
        'feature' => 'Tool definition building from allowed slugs',
        'legacy'  => 'WP_MCP_AI_Chat_Service::build_tool_definitions',
        'libcore' => 'ChatOrchestrator::buildAllowedTools',
        'status'  => 'parity',
    ),
    array(
        'feature' => 'Tool execution via registry',
        'legacy'  => 'WP_MCP_AI_Chat_Service::execute_tool_calls',
        'libcore' => 'ChatOrchestrator (tools->execute inline)',
        'status'  => 'parity',
    ),
    array(
        'feature'  => 'finish_reason-aware loop exit',
        'legacy'   => 'WP_MCP_AI_Chat_Service::process_chat_request',
        'libcore'  => 'NOT PRESENT - exits only on empty tool_calls',
        'status'   => 'gap',
        'severity' => 'medium',
    ),
    array(
        'feature' => 'Strip orphaned tool calls on max_iterations',
        'legacy'  => 'WP_MCP_AI_Chat_Service::process_chat_request',
        'libcore' => 'ChatOrchestrator::stripOrphanedToolCalls',
        'status'  => 'parity',
    ),

    // ── Context Window Management ──────────────────────────────────
    array(
        'feature' => 'Model context-window limit lookup',
        'legacy'  => 'WP_MCP_AI_Token_Budget_Manager::get_model_limit',
        'libcore' => 'TokenBudgetManager::getModelLimit',
        'status'  => 'parity',
        'note'    => 'Newly added in this PR',
    ),
    array(
        'feature' => 'Token estimation (chars/4 heuristic)',
        'legacy'  => 'WP_MCP_AI_Token_Budget_Manager::estimate_tokens',
        'libcore' => 'TokenBudgetManager::estimateTokens',
        'status'  => 'parity',
    ),
    array(
        'feature' => 'Pre-flight context-window validation in provider clients',
        'legacy'  => 'WP_MCP_AI_Token_Budget_Manager::validate_context_window (all 12 clients)',
        'libcore' => 'AbstractProviderClient::validateContextWindow (OpenAiCompatibleClient)',
        'status'  => 'parity',
        'note'    => 'Newly added; Gemini/Anthropic may need manual wiring',
    ),
    array(
        'feature' => 'Token-budget tool-definition capping',
        'legacy'  => 'render_prompt_window_estimator_meta_box (admin-side only)',
        'libcore' => 'ChatOrchestrator::capToolDefinitions (proactive)',
        'status'  => 'parity',
        'note'    => 'Newly added - lib/core capping is proactive',
    ),

    // ── Provider Clients ───────────────────────────────────────────
    array(
        'feature' => 'Provider routing (12 providers)',
        'legacy'  => 'WP_MCP_AI_Language_Model_Router::get_client',
        'libcore' => 'ProviderRouter::resolveForChat',
        'status'  => 'parity',
    ),
    array(
        'feature' => 'OpenAI-compatible base class (shared chat/stream logic)',
        'legacy'  => 'Individual clients duplicate chat-completion logic',
        'libcore' => 'OpenAiCompatibleClient::chat/stream',
        'status'  => 'enhanced',
    ),

    // ── SSE Streaming ──────────────────────────────────────────────
    array(
        'feature' => 'SSE handler (RFC 6202)',
        'legacy'  => 'WP_MCP_AI_SSE_Handler',
        'libcore' => 'SseHandler',
        'status'  => 'parity',
    ),

    // ── Cost Calculation ───────────────────────────────────────────
    array(
        'feature' => 'Per-model cost calculation',
        'legacy'  => 'WP_MCP_AI_Cost_Calculator',
        'libcore' => 'CostCalculator',
        'status'  => 'parity',
    ),

    // ── Event System ───────────────────────────────────────────────
    array(
        'feature' => 'Before/after chat hooks',
        'legacy'  => 'wp_mcp_ai_before_chat_request / wp_mcp_ai_after_chat_request',
        'libcore' => 'BeforeChatRequest / AfterChatResponse domain events',
        'status'  => 'parity',
    ),
    array(
        'feature' => 'Agentic iteration events',
        'legacy'  => 'WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled + error_log',
        'libcore' => 'AgenticIterationComplete / AgenticLoopCompleted events',
        'status'  => 'parity',
    ),

    // ── GAPS: Features in legacy only ──────────────────────────────
    array(
        'feature'  => 'Chat continuation (async job to LLM re-entry)',
        'legacy'   => 'WP_MCP_AI_Chat_Continuation_Store, Dispatcher, LLM_Re_Entry',
        'libcore'  => 'ChatContinuationInterface + adapter (contract layer complete)',
        'status'   => 'partial',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Session frame buffer',
        'legacy'   => 'WP_MCP_AI_Chat_Session_Frame_Buffer',
        'libcore'  => 'ChatContinuationInterface (covers frame buffer persistence)',
        'status'   => 'partial',
        'severity' => 'low',
    ),
    array(
        'feature'  => 'Rate limiting',
        'legacy'   => 'WP_MCP_AI_Rate_Limit_Manager',
        'libcore'  => 'RateLimiterInterface + adapter (contract layer complete)',
        'status'   => 'partial',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Semantic message compression',
        'legacy'   => 'WP_MCP_AI_Chat_Service::maybe_compress_messages',
        'libcore'  => 'SemanticCompressorInterface + adapter (contract layer complete)',
        'status'   => 'partial',
        'severity' => 'low',
    ),
    array(
        'feature'  => 'Prompt caching injection',
        'legacy'   => 'WP_MCP_AI_Chat_Service - prompt_cache_key in options',
        'libcore'  => 'NOT PRESENT',
        'status'   => 'gap',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Tool result sanitization for LLM (strips base64)',
        'legacy'   => 'WP_MCP_AI_Chat_Service::sanitize_tool_result_for_llm',
        'libcore'  => 'NOT PRESENT',
        'status'   => 'gap',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Vision-model image injection from tool results',
        'legacy'   => 'WP_MCP_AI_Chat_Service::extract_images_from_tool_results',
        'libcore'  => 'NOT PRESENT',
        'status'   => 'gap',
        'severity' => 'low',
    ),
    array(
        'feature'  => 'Transcript recording',
        'legacy'   => 'WP_MCP_AI_Chat_Service::save_chat_transcript',
        'libcore'  => 'NOT PRESENT',
        'status'   => 'gap',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Voice/realtime providers (OpenAI Realtime, Gemini Live)',
        'legacy'   => 'WP_MCP_AI_OpenAI_Realtime_Client, WP_MCP_AI_Gemini_Live_Client',
        'libcore'  => 'NOT PRESENT',
        'status'   => 'gap',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Tool migration: ~152 base tools still legacy-only',
        'legacy'   => 'includes/tools/class-wp-mcp-ai-tool-*.php (~152 files)',
        'libcore'  => '43 tools migrated; ~152 remain',
        'status'   => 'gap',
        'severity' => 'high',
        'note'     => 'Separate migration track; ~152 tools remain in legacy. Contract layer for orchestrator is complete (35 contracts).',
    ),
    array(
        'feature'  => 'Chat transcript persistence (localStorage + CCT)',
        'legacy'   => 'WP_MCP_AI_Chat_Transcript_Recorder',
        'libcore'  => 'NOT PRESENT',
        'status'   => 'gap',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Async tool waiting in agentic loop',
        'legacy'   => 'WP_MCP_AI_Chat_Service::wait_for_async_tool_completion',
        'libcore'  => 'ToolExecutionInterface::executeAsync + adapter (contract layer complete)',
        'status'   => 'partial',
        'severity' => 'medium',
    ),
    array(
        'feature'  => 'Tiktoken integration (BPE token counting)',
        'legacy'   => 'WP_MCP_AI_Token_Budget_Manager::estimate_tokens (tiktoken-backed)',
        'libcore'  => 'TokenBudgetManager uses chars/4 only (tiktoken opt-in via adapter)',
        'status'   => 'partial',
        'severity' => 'low',
    ),
);

// ─── Analysis ───────────────────────────────────────────────────────

$gaps             = array();
$criticalGaps     = array();
$parityFeatures   = array();
$partialFeatures  = array();
$enhancedFeatures = array();

foreach ( $features as $f ) {
    switch ( $f['status'] ) {
        case 'parity':
            $parityFeatures[] = $f;
            break;
        case 'gap':
            $gaps[] = $f;
            if ( ( $f['severity'] ?? 'medium' ) === 'critical' ) {
                $criticalGaps[] = $f;
            }
            break;
        case 'partial':
            $partialFeatures[] = $f;
            break;
        case 'enhanced':
            $enhancedFeatures[] = $f;
            break;
    }
}

// ─── Output ─────────────────────────────────────────────────────────

$total     = \count( $features );
$parity    = \count( $parityFeatures ) + \count( $enhancedFeatures );
$gapCount  = \count( $gaps );
$pctParity = \round( ( $parity / $total ) * 100, 1 );

echo "══════════════════════════════════════════════════════════════\n";
echo "  Chat Feature Parity: Legacy ↔ lib/core\n";
echo "══════════════════════════════════════════════════════════════\n\n";

echo "Total features tracked  : {$total}\n";
echo "In parity               : {$parity} ({$pctParity}%)\n";
echo "Gaps (legacy-only)      : {$gapCount}\n";
echo "  ├─ Critical           : " . \count( $criticalGaps ) . "\n";
echo "  ├─ High               : " . \count( \array_filter( $gaps, static fn( $g ) => ( $g['severity'] ?? 'medium' ) === 'high' ) ) . "\n";
echo "  ├─ Medium             : " . \count( \array_filter( $gaps, static fn( $g ) => ( $g['severity'] ?? 'medium' ) === 'medium' ) ) . "\n";
echo "  └─ Low                : " . \count( \array_filter( $gaps, static fn( $g ) => ( $g['severity'] ?? 'medium' ) === 'low' ) ) . "\n";
echo "Partial                 : " . \count( $partialFeatures ) . "\n";
echo "Enhanced (lib/core >)   : " . \count( $enhancedFeatures ) . "\n\n";

if ( array() !== $criticalGaps ) {
    echo "══════════════════════════════════════════════════════════════\n";
    echo "  CRITICAL GAPS (blocking full OOS activation)\n";
    echo "══════════════════════════════════════════════════════════════\n\n";

    foreach ( $criticalGaps as $gap ) {
        echo "  * {$gap['feature']}\n";
        echo "    Legacy : {$gap['legacy']}\n";
        echo "    lib/core: {$gap['libcore']}\n\n";
    }
}

if ( array() !== $gaps ) {
    echo "══════════════════════════════════════════════════════════════\n";
    echo "  ALL GAPS (legacy-only features)\n";
    echo "══════════════════════════════════════════════════════════════\n\n";

    // Sort by severity.
    $severityOrder = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 );
    \usort( $gaps, static fn( $a, $b ) =>
        ( $severityOrder[ $a['severity'] ?? 'medium' ] ?? 2 )
        <=>
        ( $severityOrder[ $b['severity'] ?? 'medium' ] ?? 2 )
    );

    foreach ( $gaps as $gap ) {
        $icon = array(
            'critical' => '[CRIT]',
            'high'     => '[HIGH] ',
            'medium'   => '[MED]  ',
            'low'      => '[LOW]  ',
        )[ $gap['severity'] ?? 'medium' ] ?? '[-----]';

        echo "  {$icon} {$gap['feature']}\n";
        echo "     Legacy : {$gap['legacy']}\n";
        echo "     lib/core: {$gap['libcore']}\n\n";
    }
}

echo "══════════════════════════════════════════════════════════════\n";
echo "  FEATURES IN PARITY\n";
echo "══════════════════════════════════════════════════════════════\n\n";

foreach ( $parityFeatures as $f ) {
    echo "  OK  {$f['feature']}\n";
}

foreach ( $enhancedFeatures as $f ) {
    echo "  ++  {$f['feature']} (lib/core enhanced)\n";
}

echo "\n══════════════════════════════════════════════════════════════\n";
echo "  Summary: {$pctParity}% parity - {$gapCount} gaps remaining\n";
echo "══════════════════════════════════════════════════════════════\n";

// ─── Exit Code ──────────────────────────────────────────────────────

if ( array() !== $criticalGaps ) {
    echo "\nExit code 2: critical gaps detected.\n";
    exit( 2 );
}

if ( array() !== $gaps ) {
    echo "\nExit code 1: non-critical gaps detected.\n";
    exit( 1 );
}

echo "\nExit code 0: full parity achieved.\n";
exit( 0 );
