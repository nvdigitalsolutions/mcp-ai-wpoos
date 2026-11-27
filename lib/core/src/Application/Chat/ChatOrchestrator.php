<?php

/**
 * Chat orchestrator — the agentic loop.
 *
 * Manages the full lifecycle of a chat request:
 *  1. Build tool definitions from the registry
 *  2. Send messages to the AI provider
 *  3. Extract tool calls from the response
 *  4. Execute tools via the registry
 *  5. Feed tool results back into the conversation
 *  6. Repeat until no more tool calls or max iterations reached
 *
 * This is the framework-agnostic equivalent of
 * WP_MCP_AI_REST::handle_chat_request() and
 * handle_chat_request_with_streaming().
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Chat;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Security\InputGuard;
use Nvoos\Core\Application\Security\OutputGuard;
use Nvoos\Core\Application\Session\SessionLog;
use Nvoos\Core\Application\Session\SessionTelemetry;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Domain\Service\Harness\GuardrailPrompt;
use Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue;
use Nvoos\Core\Domain\Service\Harness\ToolRouterHarness;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\SessionLogStoreInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface;
use Nvoos\Core\Domain\Decision\AgentPreStepDecision;
use Nvoos\Core\Domain\Decision\AgentRequestDecision;
use Nvoos\Core\Domain\Decision\AgentRequestErrorDecision;
use Nvoos\Core\Domain\Event\AgentPreStep;
use Nvoos\Core\Domain\Event\AgentRequest;
use Nvoos\Core\Domain\Event\AgentRequestError;
use Nvoos\Core\Domain\Event\AgentTurnStopping;
use Nvoos\Core\Domain\Event\BeforeChatRequest;
use Nvoos\Core\Domain\Event\AfterChatResponse;
use Nvoos\Core\Domain\Event\AgenticIterationComplete;
use Nvoos\Core\Domain\Event\AgenticLoopCompleted;
use Nvoos\Core\Domain\Event\CostCalculated;
use Nvoos\Core\Domain\Event\UnresolvedToolRequested;
use Nvoos\Core\Domain\ValueObject\TurnBudget;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SemanticCompressorInterface;
use Nvoos\Core\Domain\Contract\TraceStoreInterface;
use Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface;
use Nvoos\Core\Domain\Contract\ContextCompressionInterface;
use Nvoos\Core\Domain\ValueObject\CancellationToken;
use Nvoos\Core\Support\Json;

class ChatOrchestrator
{
    /**
     * Maximum agentic loop iterations. Prevents infinite loops.
     */
    private const DEFAULT_MAX_ITERATIONS = 15;

    /**
     * Maximum agent/request_error retries per provider call.
     */
    private const MAX_REQUEST_RETRIES = 3;

    /**
     * Default per-user chat rate limit: requests per window.
     */
    private const DEFAULT_CHAT_RATE_LIMIT = 60;

    /**
     * Default per-user chat rate limit: window length in seconds.
     */
    private const DEFAULT_CHAT_RATE_LIMIT_WINDOW = 60;

    /**
     * Optional token-budget manager for tool-definition capping.
     */
    private ?TokenBudgetManager $tokenBudget = null;

    /**
     * The turn's token ceilings, when a host configures them.
     *
     * Optional: a runtime that never wires it behaves exactly as before, because
     * a zero ceiling is off in both dimensions.
     */
    private ?TurnBudget $turnBudget = null;

    /**
     * Optional rate limiter for API call gating.
     */
    private ?RateLimiterInterface $rateLimiter = null;

    /**
     * Per-user chat rate limit: max requests allowed per window.
     *
     * Configurable via setChatRateLimit(); the WordPress bridge feeds this
     * from the wp_mcp_ai_chat_rate_limit filter.
     */
    private int $chatRateLimitMax = self::DEFAULT_CHAT_RATE_LIMIT;

    /**
     * Per-user chat rate limit: window length in seconds.
     */
    private int $chatRateLimitWindow = self::DEFAULT_CHAT_RATE_LIMIT_WINDOW;

    /**
     * Optional semantic compressor for message reduction.
     */
    private ?SemanticCompressorInterface $compressor = null;

    /**
     * Optional byte-budget tracker for tool output.
     */
    private ?DataBudgetTrackerInterface $budgetTracker = null;

    /**
     * Optional context compressor for token-aware truncation.
     *
     * @deprecated 1.3.0 Superseded by setCompactionProvider() — kept for
     *     API stability only; compaction now flows through the provider.
     */
    private ?ContextCompressionInterface $contextCompressor = null;

    /**
     * Optional auth provider for per-tool capability enforcement.
     *
     * Injected into every tool-execution context so the ToolRegistry can
     * resolve required_capability against the requesting user. Without it,
     * every tool declaring a capability would deny authenticated users.
     */
    private ?AuthProviderInterface $authProvider = null;

    /**
     * Optional event-sourced session log (Phase 3, R1).
     *
     * When wired, the loop appends every model-visible fact to the log;
     * history can then be derived and replayed from it. Null by default —
     * session logging is opt-in until the replay tests prove parity.
     */
    private ?SessionLog $sessionLog = null;

    /**
     * Optional persistence sink for the session log.
     */
    private ?SessionLogStoreInterface $sessionLogStore = null;

    /**
     * Optional scoped tool resolver (Phase 5, R4).
     *
     * When wired, tool VISIBILITY resolves through the scope (shadowing
     * + restriction intersection); execution still happens on the
     * registry, which owns the policy pipeline.
     */
    private ?ToolResolverInterface $toolResolver = null;

    /**
     * Optional compaction provider (Phase 5, R6).
     *
     * Triggers budget-driven context compaction between continuation
     * steps; compaction facts are recorded in the session log.
     */
    private ?CompactionProvider $compactionProvider = null;

    /**
     * Optional telemetry tap on the session log (Phase 5, R6).
     *
     * Every appended log entry fans out to subscribers here — the single
     * path audit/telemetry consumers use instead of re-wrapping the loop.
     */
    private ?SessionTelemetry $sessionTelemetry = null;

    /**
     * Optional input guard (prompt injection / PII).
     *
     * Only wired by runtimes that want orchestrator-level enforcement. WordPress
     * keeps its own harness layer and does not set it, so behaviour there is
     * unchanged.
     */
    private ?InputGuard $inputGuard = null;

    /**
     * Optional output guard (response validation).
     *
     * Only wired by runtimes that want orchestrator-level enforcement, and only
     * once the assistant's profile enables it. See setOutputGuard().
     */
    private ?OutputGuard $outputGuard = null;

    /**
     * Optional harness profile provider, so a per-assistant `output_guard` block
     * can switch the guard on. Not wired by WordPress, which enforces this in
     * its own harness layer.
     */
    private ?HarnessProfileProviderInterface $harnessProfiles = null;

    /**
     * Optional per-user chat profile, for the advisory mode hint.
     *
     * The hint is **not** a control — the guard in `Application/Tool` is — so a
     * runtime that never wires this is still enforced, just chattier: the model
     * discovers the boundary by having a write refused instead of being told.
     */
    private ?ChatProfileResolverInterface $chatProfiles = null;

    /**
     * Optional trace store, so a turn can record what it did.
     *
     * Capture stays opt-in per assistant through `trace_capture.enabled`; a
     * runtime that wires a store but leaves the profile off writes nothing.
     */
    private ?TraceStoreInterface $traceStore = null;

    /**
     * The one reader for the model-facing copy of a tool result.
     *
     * Lazy because it is stateless: constructing one per orchestrator is fine,
     * but keeping it out of the constructor leaves the public signature alone
     * for every runtime that already builds one (Proposal 161 §6).
     */
    private ?ToolResultSanitizer $resultSanitizer = null;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ProviderRouter $providers,
        private readonly EventDispatcherInterface $events,
        private readonly ErrorFactoryInterface $errors,
        private readonly CostCalculator $costs,
        private readonly SseHandler $sse,
    ) {
    }

    /**
     * Wire the token-budget manager for tool-definition capping.
     */
    public function setTokenBudgetManager(TokenBudgetManager $budget): void
    {
        $this->tokenBudget = $budget;
    }

    /**
     * Wire the turn's token ceilings.
     *
     * Enforced before each provider call rather than after it: a ceiling applied
     * afterwards is a report, and the request that overspends has already been
     * billed. The estimate is the only measurement available before the call, so
     * one request can still overshoot by its own estimation error — the same
     * allowance `BudgetGuard` and the tool quotas document.
     */
    public function setTurnBudget(?TurnBudget $budget): void
    {
        $this->turnBudget = $budget;
    }

    /**
     * Wire the rate limiter for API call gating.
     */
    public function setRateLimiter(RateLimiterInterface $rateLimiter): void
    {
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Configure the per-user chat rate limit.
     *
     * Both values are clamped to a minimum of 1 so a misconfigured filter
     * cannot silently disable the gate or divide the window to zero.
     */
    public function setChatRateLimit(int $maxRequests, int $windowSeconds = self::DEFAULT_CHAT_RATE_LIMIT_WINDOW): void
    {
        $this->chatRateLimitMax    = \max(1, $maxRequests);
        $this->chatRateLimitWindow = \max(1, $windowSeconds);
    }

    /**
     * Wire the semantic compressor for message reduction.
     */
    public function setSemanticCompressor(SemanticCompressorInterface $compressor): void
    {
        $this->compressor = $compressor;
    }

    /**
     * Wire the byte-budget tracker for tool output accounting.
     */
    public function setDataBudgetTracker(DataBudgetTrackerInterface $tracker): void
    {
        $this->budgetTracker = $tracker;
    }

    /**
     * Wire the context compressor for token-aware truncation.
     *
     * @deprecated 1.3.0 Use setCompactionProvider().
     */
    public function setContextCompressor(ContextCompressionInterface $compressor): void
    {
        $this->contextCompressor = $compressor;
    }

    /**
     * Wire the auth provider used for per-tool capability checks.
     */
    public function setAuthProvider(AuthProviderInterface $authProvider): void
    {
        $this->authProvider = $authProvider;
    }

    /**
     * Wire the session log (opt-in) and its optional persistence sink.
     */
    public function setSessionLog(?SessionLog $log, ?SessionLogStoreInterface $store = null): void
    {
        $this->sessionLog      = $log;
        $this->sessionLogStore = $store;
    }

    /**
     * Wire a scoped tool resolver for visibility resolution.
     */
    public function setToolResolver(ToolResolverInterface $resolver): void
    {
        $this->toolResolver = $resolver;
    }

    /**
     * Wire the compaction provider for between-step context compaction.
     */
    public function setCompactionProvider(CompactionProvider $provider): void
    {
        $this->compactionProvider = $provider;
    }

    /**
     * Wire the session-log telemetry tap (Phase 5, R6).
     *
     * Subscribers receive every appended log entry. Pass null to detach.
     *
     * @param SessionTelemetry|null $telemetry Telemetry tap.
     */
    public function setSessionTelemetry(?SessionTelemetry $telemetry): void
    {
        $this->sessionTelemetry = $telemetry;
    }

    /**
     * Wire the input guard for prompt-injection blocking and PII redaction.
     *
     * Pass null to detach.
     */
    public function setInputGuard(?InputGuard $guard): void
    {
        $this->inputGuard = $guard;
    }

    /**
     * Wire the output guard for response validation.
     *
     * Pass null to detach. While a guard is wired and enabled the streaming path
     * buffers instead of emitting token deltas: a guard that runs at the end
     * cannot retract text it has already sent, so the only honest choice is to
     * send nothing until the response has been validated.
     */
    /**
     * Wire the output guard for response validation.
     *
     * Pass null to detach. While a guard is wired and enabled the streaming path
     * buffers instead of emitting token deltas: a guard that runs at the end
     * cannot retract text it has already sent, so the only honest choice is to
     * send nothing until the response has been validated.
     */
    public function setOutputGuard(?OutputGuard $guard): void
    {
        $this->outputGuard = $guard;
    }

    /**
     * The guard wired by the runtime, or null.
     *
     * Exposes the configuration, not the resolution: a per-assistant profile can
     * still switch the guard on for a turn. Diagnostics surfaces use this to
     * report whether the runtime has an output guard at all.
     */
    public function outputGuard(): ?OutputGuard
    {
        return $this->outputGuard;
    }

    /**
     * Wire the profile provider so a per-assistant `output_guard` can turn the
     * guard on.
     *
     * Precedence is deliberate: a profile that enables the block wins, and
     * otherwise the guard wired by the runtime stands. A profile can therefore
     * switch the guard *on* per assistant but never *off* — a stale or partial
     * profile must not be able to silently disable a safety control.
     */
    public function setHarnessProfileProvider(?HarnessProfileProviderInterface $provider): void
    {
        $this->harnessProfiles = $provider;
    }

    /**
     * Wire the per-user chat profile so the system prompt can carry its hint.
     *
     * Optional by design. The boundary is enforced at tool dispatch; this only
     * tells the model where it is, so a read-only turn answers rather than
     * spending its iterations on calls the server will refuse.
     */
    public function setChatProfileResolver(?ChatProfileResolverInterface $profiles): void
    {
        $this->chatProfiles = $profiles;
    }

    /**
     * The advisory mode hint for this turn, or an empty string.
     *
     * Resolved from the server-built context only — the orchestrator's own
     * `$userId` — so nothing a client sends can name a profile.
     */
    private function chatProfileHint(int $userId): string
    {
        if (null === $this->chatProfiles) {
            return '';
        }

        return $this->chatProfiles->resolve(array( 'user_id' => $userId ))->promptHint();
    }

    /**
     * Resolve the guard that applies to this turn.
     */
    private function resolveOutputGuard(?array $profile): ?OutputGuard
    {
        if (null !== $profile) {
            $fromProfile = OutputGuard::fromProfile($profile);

            if (null !== $fromProfile) {
                return $fromProfile;
            }
        }

        return $this->outputGuard;
    }

    /**
     * Resolve the harness profile once per turn, or null when none is wired.
     *
     * @return array<string, mixed>|null
     */
    private function resolveProfile(int $assistantId): ?array
    {
        return null === $this->harnessProfiles ? null : $this->harnessProfiles->getProfile($assistantId);
    }

    /**
     * Place the assistant's instructions and the guardrail block in the system turn.
     *
     * WordPress resolves one system prompt server-side: the REST handler reads the
     * assistant's stored instructions and the provider client emits them as the
     * first `system` message. This runtime had no equivalent — the console knew a
     * prompt existed (it warns when one is missing) but never sent it, so every
     * assistant ran on the user's words alone.
     *
     * Composition order matches WordPress: the guardrail block first, then the
     * assistant's instructions, then the chat profile's mode hint, then whatever
     * the conversation itself carried. Each part is added only when absent,
     * because a client may already have injected one and a second copy doubles
     * its weight in the prompt.
     *
     * @param array<int, array<string, mixed>> $messages        Conversation messages.
     * @param array<string, mixed>             $assistantConfig Assistant configuration; `system_prompt` carries the instructions.
     * @param array<string, mixed>|null        $profile         Resolved harness profile, if any.
     * @param int                              $userId          Caller, for the chat-profile hint.
     * @return array<int, array<string, mixed>>
     */
    private function applySystemPrompt(array $messages, array $assistantConfig, ?array $profile, int $userId = 0): array
    {
        $raw          = $assistantConfig['system_prompt'] ?? '';
        $instructions = \is_string($raw) ? \trim($raw) : '';

        $index    = null;
        $existing = '';

        foreach ($messages as $i => $message) {
            if (isset($message['role']) && 'system' === $message['role']) {
                $index    = $i;
                $existing = \is_string($message['content'] ?? null) ? $message['content'] : '';
                break;
            }
        }

        $parts = array();

        if (! \str_contains($existing, GuardrailPrompt::HEADER)) {
            $block = \trim(GuardrailPrompt::apply('', $profile ?? array()));

            if ('' !== $block) {
                $parts[] = $block;
            }
        }

        if ('' !== $instructions && ! \str_contains($existing, $instructions)) {
            $parts[] = $instructions;
        }

        // The mode hint sits with the other rails, before any client text, so a
        // client-supplied block cannot bury it. De-duplicated because a resumed
        // turn may already carry it.
        $hint = $this->chatProfileHint($userId);

        if ('' !== $hint && ! \str_contains($existing, $hint)) {
            $parts[] = $hint;
        }

        if (array() === $parts) {
            return $messages;
        }

        if ('' !== \trim($existing)) {
            $parts[] = $existing;
        }

        $content = \implode("\n\n", $parts);

        if (null !== $index) {
            $messages[ $index ]['content'] = $content;

            return $messages;
        }

        \array_unshift(
            $messages,
            array(
                'role' => 'system',
                'content' => $content,
            )
        );

        return $messages;
    }

    /**
     * Wire the trace store.
     *
     * Pass null to detach. The profile still gates whether anything is written.
     */
    public function setTraceStore(?TraceStoreInterface $store): void
    {
        $this->traceStore = $store;
    }

    /**
     * The trace store wired by the runtime, or null.
     */
    public function traceStore(): ?TraceStoreInterface
    {
        return $this->traceStore;
    }

    /**
     * Begin a run, or null when capture is off.
     *
     * @param int                  $assistantId Assistant the turn belongs to.
     * @param array<string, mixed> $profile     Resolved harness profile.
     * @param array<string, mixed> $options     Request options (model, provider).
     */
    private function startTrace(int $assistantId, ?array $profile, array $options): ?TraceCapture
    {
        if (null === $this->traceStore) {
            return null;
        }

        return TraceCapture::start(
            $this->traceStore,
            $assistantId,
            $profile ?? array(),
            array(
                'model' => (string) ( $options['model'] ?? '' ),
                'provider' => (string) ( $options['provider'] ?? '' ),
            )
        );
    }

    /**
     * Slugs of the definitions that were handed to the provider, in order.
     *
     * @param array<int, array<string, mixed>> $definitions Tool definitions.
     * @return array<int, string>
     */
    private static function toolSlugs(array $definitions): array
    {
        $slugs = array();

        foreach ($definitions as $definition) {
            $name = $definition['function']['name'] ?? null;

            if (\is_string($name) && '' !== $name) {
                $slugs[] = $name;
            }
        }

        return $slugs;
    }

    /**
     * Provider finish reason, when the response is a well-formed payload.
     */
    private function finishReason(mixed $response): string
    {
        if (! \is_array($response)) {
            return '';
        }

        return (string) ( $response['choices'][0]['finish_reason'] ?? '' );
    }

    /**
     * Emit the validated response as a single content chunk.
     *
     * The client renders assistant text from `choices[0].delta.content` frames
     * only — the trailing payload frame is metadata. So a buffered turn still
     * has to send its text as a delta, just once and only after validation;
     * suppressing the chunks outright would leave the bubble empty.
     *
     * @param mixed $response Validated provider response.
     */
    private function emitBufferedContent(mixed $response): void
    {
        if (! \is_array($response)) {
            return;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! \is_string($content) || '' === $content) {
            return;
        }

        $this->sse->sendEvent(
            'message',
            array(
                'choices' => array(
                    array(
                        'delta' => array( 'content' => $content ),
                    ),
                ),
            )
        );
    }

    /**
     * Validate an assembled provider response before it is delivered.
     *
     * Rewrites `choices[0].message.content` in place and returns the verdict for
     * audit. A blocked response is replaced with the withheld notice, never with
     * its own text — `OutputGuard::inspect()` hands back an empty string for
     * exactly that reason.
     *
     * @param mixed $response Provider response payload.
     * @return array{response: mixed, verdict: array<string, mixed>|null}
     */
    private function applyOutputGuard(mixed $response, ?OutputGuard $guard): array
    {
        if (null === $guard || ! $guard->enabled()) {
            return array( 'response' => $response, 'verdict' => null );
        }

        if (! \is_array($response)) {
            return array( 'response' => $response, 'verdict' => null );
        }

        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! \is_string($content) || '' === $content) {
            return array( 'response' => $response, 'verdict' => null );
        }

        $verdict = $guard->inspect($content);

        if (! $verdict['enabled'] || $verdict['safe']) {
            return array( 'response' => $response, 'verdict' => null );
        }

        $response['choices'][0]['message']['content'] = $verdict['blocked']
            ? OutputGuard::WITHHELD_NOTICE
            : $verdict['content'];

        return array( 'response' => $response, 'verdict' => $verdict );
    }

    /**
     * Inspect the latest user message when a guard is wired.
     *
     * Only the newest user turn is screened: earlier turns were already
     * accepted, and re-screening history would block a conversation the guard
     * had previously allowed.
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     * @return array<string, mixed>|null Verdict, or null when there is nothing to screen.
     */
    private function inspectLatestUserMessage(array $messages): ?array
    {
        if (null === $this->inputGuard) {
            return null;
        }

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[ $i ];

            if (! isset($message['role']) || 'user' !== $message['role']) {
                continue;
            }

            $content = $message['content'] ?? '';

            if (! is_string($content)) {
                return null;
            }

            return $this->inputGuard->inspect($content);
        }

        return null;
    }

    /**
     * Handle a chat request — non-streaming (returns full response).
     *
     * @param array<int, array<string, mixed>> $messages        OpenAI-format conversation messages.
     * @param array<string, mixed> $assistantConfig Assistant configuration (provider, model, tools, etc.).
     * @param int   $userId          Authenticated user ID (0 = guest).
     * @param int   $assistantId     Assistant post ID (0 = none).
     * @param array<string, mixed> $options         Additional options (temperature, max_tokens, etc.).
     * @param CancellationToken|null $cancellation Optional cooperative
     *     cancellation token; checked at every provider and tool boundary.
     *
     * @return array{
     *     response: array<string, mixed>,
     *     tool_results: array<int, array<string, mixed>>,
     *     iterations: int,
     *     cost: array<string, mixed>|null,
     *     cancelled: bool,
     *     cancel_reason: string,
     * }
     */
    public function handleChat(
        array $messages,
        array $assistantConfig,
        int $userId = 0,
        int $assistantId = 0,
        array $options = array(),
        ?CancellationToken $cancellation = null,
    ): array {
        $maxIterations = (int) ( $assistantConfig['max_agentic_iterations'] ?? self::DEFAULT_MAX_ITERATIONS );
        $maxIterations = \max(1, \min(50, $maxIterations));

        $iteration          = 0;
        $toolResultMessages = array();
        $budgetExhausted    = false;

        // The harness profile drives both the prompt rails and the response
        // guard, so it is resolved once per turn.
        $profile  = $this->resolveProfile($assistantId);
        $messages = $this->applySystemPrompt($messages, $assistantConfig, $profile, $userId);

        // Build tool definitions for this assistant.
        $allowedToolSlugs = $assistantConfig['tools'] ?? array();
        $toolDefinitions  = $this->buildAllowedTools($allowedToolSlugs, $assistantId, $profile);

        // Always replace tools with the properly-resolved definitions.
        // If no tools resolve (e.g., none are registered in the OOS
        // tool registry), an empty array prevents raw tool slugs from
        // leaking to the provider and causing API errors like
        // "Invalid type for 'tools[0]': expected an object".
        $options['tools'] = $toolDefinitions;

        // Merge assistant config into options.
        $options['provider'] ??= $assistantConfig['provider'] ?? '';
        $options['model']    ??= $assistantConfig['model'] ?? '';

        // Session logging is opt-in; the session id arrives via options.
        $sessionId = (string) ( $options['session_id'] ?? '' );

        $startedAt = \microtime(true);

        $trace = $this->startTrace($assistantId, $profile, $options);

        $trace?->routing((string) ( $profile['tools']['router'] ?? 'fixed' ), self::toolSlugs($toolDefinitions));

        // Before hook.
        $this->events->dispatch(
            new BeforeChatRequest(
                assistantId: $assistantId,
                messages: $messages,
                options: $options,
                authContext: array( 'user_id' => $userId ),
            )
        );

        // Rate limit check — reject if user/exceeded quota.
        if (null !== $this->rateLimiter) {
            $rateLimitKey = 'chat:' . $userId . ':' . $assistantId;
            if (! $this->rateLimiter->isAllowed($rateLimitKey, $this->chatRateLimitMax, $this->chatRateLimitWindow)) {
                return array(
                    'response'      => $this->errors->rateLimited('Too many requests. Please wait before sending another message. If you believe this is an error, contact the site administrator.'),
                    'tool_results'  => array(),
                    'iterations'    => 0,
                    'cost'          => null,
                    'cancelled'     => false,
                    'cancel_reason' => '',
                );
            }
            $this->rateLimiter->record($rateLimitKey, $this->chatRateLimitWindow);
        }

        // Semantic compression — reduce message size when near token limits.
        if (null !== $this->compressor) {
            $modelId = (string) $options['model'];
            $tokenLimit = null !== $this->tokenBudget
                ? $this->tokenBudget->getModelLimit($modelId)
                : 128000;

            // Estimate total message tokens.
            $estimatedTokens = 0;
            foreach ($messages as $msg) {
                $content = \is_array($msg['content'] ?? null)
                    ? Json::encodeOr($msg['content'], '')
                    : (string) ( $msg['content'] ?? '' );
                $estimatedTokens += $this->compressor->estimateTokens($content);
            }

            // Compress if over 80% of limit. The SemanticCompressorInterface
            // contract is compress( string, int aggressiveness, int maxTokens )
            // — pass ints, never the legacy options array.
            if ($estimatedTokens > (int) ( $tokenLimit * 0.8 )) {
                $result = $this->compressor->compress(
                    Json::encodeOr($messages, '[]'),
                    2,
                    0,
                );
                if (! empty($result['compressed']) && \is_string($result['compressed'])) {
                    $decoded = \json_decode($result['compressed'], true);
                    if (\is_array($decoded)) {
                        $messages = $decoded;
                    }
                }
            }
        }

        // Prompt caching — pass cache key if configured.
        if (! empty($assistantConfig['prompt_cache_key'])) {
            $options['prompt_cache_key'] = $assistantConfig['prompt_cache_key'];
        }

        // Input guard — block a jailbreak/injection before anything is spent.
        $guardVerdict = $this->inspectLatestUserMessage($messages);
        if (null !== $guardVerdict && $guardVerdict['blocked']) {
            return array(
                'response'      => $this->errors->validationFailed($guardVerdict['detail'], array( 'analysis' => $guardVerdict )),
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => '',
            );
        }

        // Turn boundary + entering messages are the first log facts.
        $this->recordSessionEvent(
            SessionLog::TYPE_TURN_STARTED,
            array(
                'assistant_id'   => $assistantId,
                'user_id'        => $userId,
                'max_iterations' => $maxIterations,
            ),
            $sessionId,
        );
        foreach ($messages as $msg) {
            if (isset($msg['role']) && 'user' === $msg['role']) {
                $content = is_string($msg['content'] ?? null) ? $msg['content'] : '';

                // Redact PII/secrets before the message reaches durable storage.
                if (null !== $this->inputGuard) {
                    $content = $this->inputGuard->redact($content)['text'];
                }

                $this->recordSessionEvent(
                    SessionLog::TYPE_USER_MESSAGE,
                    array( 'content' => $content ),
                    $sessionId,
                );
            }
        }

        // Cooperative cancellation — checked at every provider and tool boundary.
        if (null !== $cancellation && $cancellation->isCancelled()) {
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'aborted', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );

            return $this->cancelledResult(array(), $cancellation);
        }

        $costAccumulator = self::newCostAccumulator();

        // agent/pre_step policy — listeners may reject the turn or rewrite
        // the entering batch; agent/request may replace the call config.
        [ $messages, $preStepRejected ] = $this->applyPreStepPolicy($messages, $assistantId, 0);
        if ($preStepRejected) {
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'rejected', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );
            $this->notifyTurnStopping($assistantId, 0);

            return array(
                'response'      => array(),
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => '',
            );
        }
        $options = $this->applyRequestPolicy($options, $assistantId, 0);

        // Turn budget — before the first request leaves. A ceiling applied
        // afterwards is a report, and the call that overspent is already billed.
        $budgetVerdict = $this->turnBudgetVerdict($messages, $options, $costAccumulator, $sessionId);

        if (null !== $budgetVerdict && TurnBudget::STATE_EXHAUSTED === $budgetVerdict['state']) {
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'budget_limit', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );
            $this->notifyTurnStopping($assistantId, 0);

            return array(
                'response'      => $this->turnBudgetError($budgetVerdict),
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => 'budget_limit',
            );
        }

        // Initial LLM call with bounded retry via agent/request_error.
        $response = $this->providers->chat($messages, $options, $assistantConfig);
        $retries  = 0;
        while (
            $this->errors->isError($response)
            && $retries < self::MAX_REQUEST_RETRIES
            && $this->shouldRetryAfterError($response, $assistantId, 0)
        ) {
            ++$retries;
            $response = $this->providers->chat($messages, $options, $assistantConfig);
        }
        $this->accumulateUsage($response, $costAccumulator);

        if ($this->errors->isError($response)) {
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'request_error', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );

            return array(
                'response'      => $this->errors->normalize($response),
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => '',
            );
        }

        $trace?->providerCall($this->finishReason($response), \count($this->extractToolCalls($response)));

        // Model-visible facts are logged: the assistant message that just
        // arrived (with any tool_calls it carries).
        $this->recordSessionEvent(
            SessionLog::TYPE_ASSISTANT_MESSAGE,
            array(
                'content'       => $response['choices'][0]['message']['content'] ?? null,
                'tool_calls'    => $this->extractToolCalls($response),
                'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
            ),
            $sessionId,
        );

        // ─── Agentic loop ────────────────────────────────────────────

        $cancelled = false;
        $stepResponseLogged = false; // The initial response is logged above.

        while ($iteration < $maxIterations) {
            if (null !== $cancellation && $cancellation->isCancelled()) {
                $cancelled = true;
                break;
            }

            if ($stepResponseLogged) {
                // The continuation response that opened this step is also a
                // model-visible fact — log it before the loop can exit.
                $this->recordSessionEvent(
                    SessionLog::TYPE_ASSISTANT_MESSAGE,
                    array(
                        'content'       => $response['choices'][0]['message']['content'] ?? null,
                        'tool_calls'    => $this->extractToolCalls($response),
                        'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
                    ),
                    $sessionId,
                );
            }
            $stepResponseLogged = true;

            $toolCalls = $this->extractToolCalls($response);

            if (array() === $toolCalls) {
                break;
            }

            // finish_reason-aware exit.
            $finishReason = $response['choices'][0]['finish_reason'] ?? null;
            if ('stop' === $finishReason) {
                $this->stripOrphanedToolCalls($response);
                break;
            }
            if ('length' === $finishReason) {
                break;
            }

            // Add the assistant message with tool_calls to the conversation.
            $assistantMsg = $this->extractAssistantMessage($response);
            if (null !== $assistantMsg) {
                $messages[] = $assistantMsg;
            }

            // Execute each tool.
            foreach ($toolCalls as $toolCall) {
                if (null !== $cancellation && $cancellation->isCancelled()) {
                    $cancelled = true;
                    break;
                }

                $toolName   = $toolCall['function']['name'] ?? '';
                $toolCallId = $toolCall['id'] ?? '';
                $rawArgs    = $toolCall['function']['arguments'] ?? '{}';
                $arguments  = is_string($rawArgs)
                    ? ( \json_decode($rawArgs, true) ?: array() )
                    : ( is_array($rawArgs) ? $rawArgs : array() );

                // Log the model-visible call before dispatch.
                $this->recordSessionEvent(
                    SessionLog::TYPE_TOOL_CALL,
                    array(
                        'tool_call_id' => (string) $toolCallId,
                        'name'         => (string) $toolName,
                        'arguments'    => $arguments,
                        'iteration'    => $iteration,
                    ),
                    $sessionId,
                );

                $toolStartedAt = \microtime(true);

                $result = $this->tools->execute(
                    $toolName,
                    $arguments,
                    array(
                        'user_id'       => $userId,
                        'assistant_id'  => $assistantId,
                        'agentic_loop'  => true,
                        'iteration'     => $iteration,
                        'auth_provider' => $this->authProvider,
                        'shadow_mode'   => (bool) ( $options['shadow_mode'] ?? false ),
                    )
                );

                $toolDurationMs = \max(0.0, ( \microtime(true) - $toolStartedAt ) * 1000.0);
                $toolOutcome    = $this->errors->isError($result) ? 'error' : 'success';

                // One record per execution, in the shape OpenTelemetry's
                // `execute_tool` span uses. The session log below already carries
                // these four facts; a trace is where a reader can query them, and
                // this is the record that makes per-tool metrics derivable.
                $trace?->toolExecution((string) $toolName, (string) $toolCallId, $toolDurationMs, $toolOutcome);

                // Normalize errors for LLM consumption.
                if ($this->errors->isError($result)) {
                    $normalized    = $this->errors->normalize($result);
                    $resultContent = "Error: {$normalized['message']}";
                } elseif (is_array($result) && isset($result['message'])) {
                    $resultContent = is_string($result['message'])
                        ? $result['message']
                        : Json::encodeOr($result, '(unserializable result)');
                } else {
                    // The model gets a sanitized copy; the tool's own result is
                    // untouched. ToolResultSanitizer is the only place that
                    // decides what "sanitized" means.
                    $resultContent = Json::encodeOr(
                        $this->resultSanitizer()->sanitize($result, $this->tools->get($toolName)),
                        '(unserializable result)'
                    );
                }

                // Track byte budget if wired (accounting only — spill
                // behavior is a later-phase policy).
                if (null !== $this->budgetTracker) {
                    $this->budgetTracker->record(\strlen($resultContent));
                }

                // Log the model-visible result plus the execution facts
                // telemetry needs (Phase 5, R6): outcome, duration, and
                // the acting identity — additive keys, replay-safe.
                $this->recordSessionEvent(
                    SessionLog::TYPE_TOOL_RESULT,
                    array(
                        'tool_call_id' => (string) $toolCallId,
                        'name'         => (string) $toolName,
                        'content'      => (string) $resultContent,
                        'outcome'      => $toolOutcome,
                        'duration_ms'  => $toolDurationMs,
                        'user_id'      => $userId,
                        'assistant_id' => $assistantId,
                    ),
                    $sessionId,
                );

                // Extract images for vision models.
                $imageMessages = $this->extractImagesFromToolResult($result, $toolName);

                // Track byte budget if wired.
                $toolResultMessages[] = array(
                    'role'         => 'tool',
                    'content'      => $resultContent,
                    'tool_call_id' => $toolCallId,
                    'name'         => $toolName,
                );

                // Add tool response to conversation.
                $toolMsg = array(
                    'role'         => 'tool',
                    'content'      => $resultContent,
                    'tool_call_id' => $toolCallId,
                );

                if ('' !== $toolName) {
                    $toolMsg['name'] = $toolName;
                }

                $messages[] = $toolMsg;
            }

            if ($cancelled) {
                break;
            }

            if (null !== $cancellation && $cancellation->isCancelled()) {
                $cancelled = true;
                break;
            }

            // Between-step compaction (Phase 5, R6): budget-driven + logged.
            $messages = $this->maybeCompact($messages, $options, $iteration + 1, $sessionId);

            // Policy for the continuation step.
            [ $messages, $preStepRejected ] = $this->applyPreStepPolicy($messages, $assistantId, $iteration + 1);
            if ($preStepRejected) {
                break;
            }
            $options = $this->applyRequestPolicy($options, $assistantId, $iteration + 1);

            // Turn budget — before each continuation call. The spend so far plus
            // this request is what matters: a loop that has already used its
            // allowance must stop rather than spend one more round-trip.
            $budgetVerdict = $this->turnBudgetVerdict($messages, $options, $costAccumulator, $sessionId);

            if (null !== $budgetVerdict && TurnBudget::STATE_EXHAUSTED === $budgetVerdict['state']) {
                $budgetExhausted = true;
                break;
            }

            // Call LLM again with tool results, with bounded retry.
            $response = $this->providers->chat($messages, $options, $assistantConfig);
            $retries  = 0;
            while (
                $this->errors->isError($response)
                && $retries < self::MAX_REQUEST_RETRIES
                && $this->shouldRetryAfterError($response, $assistantId, $iteration)
            ) {
                ++$retries;
                $response = $this->providers->chat($messages, $options, $assistantConfig);
            }
            $this->accumulateUsage($response, $costAccumulator);

            if ($this->errors->isError($response)) {
                break;
            }

            ++$iteration;

            $this->events->dispatch(
                new AgenticIterationComplete(
                    iteration: $iteration,
                    assistantId: $assistantId,
                )
            );
            $trace?->providerCall($this->finishReason($response), \count($this->extractToolCalls($response)));
        }

        $durationMs = ( \microtime(true) - $startedAt ) * 1000;

        // Strip orphaned tool calls if the loop stopped early — an iteration cap
        // and a spent token ceiling leave the same half-finished step behind.
        $limitReached = $iteration >= $maxIterations;
        if ($limitReached || $cancelled || $budgetExhausted) {
            $this->stripOrphanedToolCalls($response);
        }

        // After hook.
        $this->events->dispatch(
            new AfterChatResponse(
                assistantId: $assistantId,
                response: $response,
                requestContext: array(
                    'user_id' => $userId,
                    'options' => $options,
                ),
                durationMs: $durationMs,
            )
        );

        // Loop completed.
        $this->events->dispatch(
            new AgenticLoopCompleted(
                totalIterations: $iteration,
                assistantId: $assistantId,
                toolResults: $toolResultMessages,
                limitReached: $limitReached || $budgetExhausted,
            )
        );

        // Turn-stopping serial notification — the turn is about to close.
        $this->notifyTurnStopping($assistantId, $iteration);

        // Durable turn boundary with the exit reason.
        $turnEndReason = $cancelled
            ? 'aborted'
            : ( $budgetExhausted ? 'budget_limit' : ( $limitReached ? 'iteration_limit' : 'completed' ) );
        $this->recordSessionEvent(
            SessionLog::TYPE_TURN_ENDED,
            array( 'reason' => $turnEndReason, 'iterations' => $iteration, 'assistant_id' => $assistantId, 'user_id' => $userId ),
            $sessionId,
        );

        // The response guard runs before the cost event so a trace consumer sees
        // what the caller will actually receive. Cost reads `usage`, which the
        // rewrite does not touch, so the ordering is safe.
        $guarded            = $this->applyOutputGuard($response, $this->resolveOutputGuard($profile));
        $response           = $guarded['response'];
        $outputGuardVerdict = $guarded['verdict'];

        // Calculate cost from accumulated per-iteration usage.
        $cost = null;
        if (\is_array($response)) {
            $billedRoute = $this->billedRoute($response, $options);

            $cost = $this->costs->calculateFromResponse(
                $response,
                $billedRoute['provider'],
                $billedRoute['model'],
            );

            if (
                null !== $cost
                && ( $costAccumulator['total_prompt_tokens'] > 0 || $costAccumulator['total_completion_tokens'] > 0 )
            ) {
                $cost['prompt_tokens']            = $costAccumulator['total_prompt_tokens'];
                $cost['completion_tokens']        = $costAccumulator['total_completion_tokens'];
                $cost['agentic_accumulated']      = $costAccumulator;
                $cost['agentic_iterations_count'] = $iteration;
            }
        }

        $this->events->dispatch(
            new CostCalculated(
                costData: $cost ?? array(),
                assistantId: $assistantId,
                userId: $userId,
                response: \is_array($response) ? $response : array(),
            )
        );

        $trace?->finish(
            array(
                'iterations' => $iteration,
                'cost' => $cost,
                'cancelled' => $cancelled,
                'cancel_reason' => $cancelled ? ( $cancellation?->reason() ?? '' ) : '',
                'duration_ms' => (int) \round($durationMs),
            )
        );

        return array(
            'response'      => $response,
            'tool_results'  => $toolResultMessages,
            'iterations'    => $iteration,
            'cost'          => $cost,
            'cancelled'     => $cancelled,
            'cancel_reason' => $cancelled ? ( $cancellation?->reason() ?? '' ) : '',
            'output_guard'  => $outputGuardVerdict,
        );
    }

    /**
     * Handle a chat request with SSE streaming.
     *
     * Sends status updates, tool execution progress, and final response
     * as SSE events. The caller is responsible for calling $sse->finish()
     * after this method returns.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $assistantConfig
     * @param array<string, mixed> $options
     * @return array<string, mixed>  Same as handleChat() — the final response payload.
     */
    public function handleChatStreaming(
        array $messages,
        array $assistantConfig,
        int $userId = 0,
        int $assistantId = 0,
        array $options = array(),
        ?CancellationToken $cancellation = null,
    ): array {
        $this->sse->sendHeaders();

        $maxIterations = (int) ( $assistantConfig['max_agentic_iterations'] ?? self::DEFAULT_MAX_ITERATIONS );
        $maxIterations = \max(1, \min(50, $maxIterations));

        // The harness profile drives both the prompt rails and the response
        // guard, so it is resolved once per turn.
        $profile  = $this->resolveProfile($assistantId);
        $messages = $this->applySystemPrompt($messages, $assistantConfig, $profile, $userId);

        $iteration          = 0;
        $toolResultMessages = array();
        $budgetExhausted    = false;

        // Status: thinking.
        $this->sse->sendEvent(
            'status',
            array(
                'type'         => 'thinking',
                'message'      => 'Processing your request…',
                'assistant_id' => $assistantId,
            )
        );

        $allowedToolSlugs = $assistantConfig['tools'] ?? array();
        $toolDefinitions  = $this->buildAllowedTools($allowedToolSlugs, $assistantId, $profile);

        // Always replace tools with the properly-resolved definitions.
        // If no tools resolve (e.g., none are registered in the OOS
        // tool registry), an empty array prevents raw tool slugs from
        // leaking to the provider and causing API errors like
        // "Invalid type for 'tools[0]': expected an object".
        $options['tools'] = $toolDefinitions;

        $options['provider'] ??= $assistantConfig['provider'] ?? '';
        $options['model']    ??= $assistantConfig['model'] ?? '';

        // Session logging is opt-in; the session id arrives via options.
        $sessionId = (string) ( $options['session_id'] ?? '' );

        $startedAt = \microtime(true);

        $trace = $this->startTrace($assistantId, $profile, $options);

        $trace?->routing((string) ( $profile['tools']['router'] ?? 'fixed' ), self::toolSlugs($toolDefinitions));

        // Status: generating.
        $this->sse->sendEvent(
            'status',
            array(
                'type'    => 'generating',
                'message' => 'Generating response…',
            )
        );

        // A guard that runs at the end cannot retract a token it has already sent,
        // so an enabled output guard turns the stream into a single final message.
        // Nothing else changes on the default path.
        $runtimeOutputGuard   = $this->resolveOutputGuard($profile);
        $bufferForOutputGuard = null !== $runtimeOutputGuard && $runtimeOutputGuard->enabled();

        $onStreamChunk = function (string $token) use ($cancellation, $bufferForOutputGuard): void {
            if ($bufferForOutputGuard) {
                return;
            }

            // Cooperative cancellation: stop emitting chunks once cancelled.
            if (null !== $cancellation && $cancellation->isCancelled()) {
                return;
            }

            $this->sse->sendEvent(
                'message',
                array(
                    'choices' => array(
                        array(
                            'delta' => array( 'content' => $token ),
                        ),
                    ),
                )
            );
        };

        // Input guard — block a jailbreak/injection before anything is spent.
        $guardVerdict = $this->inspectLatestUserMessage($messages);
        if (null !== $guardVerdict && $guardVerdict['blocked']) {
            $this->sse->sendEvent(
                'error',
                array(
                    'code'    => 'guardrail_blocked',
                    'message' => $guardVerdict['detail'],
                )
            );
            $this->sse->sendDone();

            return array(
                'response'      => $this->errors->validationFailed($guardVerdict['detail'], array( 'analysis' => $guardVerdict )),
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => '',
            );
        }

        // Rate limit + compression before streaming call.
        if (null !== $this->rateLimiter) {
            $key = 'chat:' . $userId . ':' . $assistantId;
            if (! $this->rateLimiter->isAllowed($key, $this->chatRateLimitMax, $this->chatRateLimitWindow)) {
                $this->sse->sendEvent('error', [ 'code' => 'rate_limited', 'message' => 'Too many requests. Please wait before sending another message. If you believe this is an error, contact the site administrator.' ]);
                $this->sse->sendDone();
                return [
                    'response'      => $this->errors->rateLimited('Too many requests. Please wait before sending another message. If you believe this is an error, contact the site administrator.'),
                    'tool_results'  => [],
                    'iterations'    => 0,
                    'cost'          => null,
                    'cancelled'     => false,
                    'cancel_reason' => '',
                ];
            }
            $this->rateLimiter->record($key, $this->chatRateLimitWindow);
        }

        if (null !== $cancellation && $cancellation->isCancelled()) {
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_STARTED,
                array(
                    'assistant_id'   => $assistantId,
                    'user_id'        => $userId,
                    'max_iterations' => $maxIterations,
                ),
                $sessionId,
            );
            foreach ($messages as $msg) {
                if (isset($msg['role']) && 'user' === $msg['role']) {
                    $this->recordSessionEvent(
                        SessionLog::TYPE_USER_MESSAGE,
                        array( 'content' => is_string($msg['content'] ?? null) ? $msg['content'] : '' ),
                        $sessionId,
                    );
                }
            }
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'aborted', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );
            $this->sse->sendEvent('error', [ 'code' => 'cancelled', 'message' => 'Request cancelled.' ]);
            $this->sse->sendDone();
            return $this->cancelledResult(array(), $cancellation);
        }

        $costAccumulator = self::newCostAccumulator();

        $this->recordSessionEvent(
            SessionLog::TYPE_TURN_STARTED,
            array(
                'assistant_id'   => $assistantId,
                'user_id'        => $userId,
                'max_iterations' => $maxIterations,
            ),
            $sessionId,
        );
        foreach ($messages as $msg) {
            if (isset($msg['role']) && 'user' === $msg['role']) {
                $content = is_string($msg['content'] ?? null) ? $msg['content'] : '';

                // Redact PII/secrets before the message reaches durable storage.
                if (null !== $this->inputGuard) {
                    $content = $this->inputGuard->redact($content)['text'];
                }

                $this->recordSessionEvent(
                    SessionLog::TYPE_USER_MESSAGE,
                    array( 'content' => $content ),
                    $sessionId,
                );
            }
        }

        // agent/pre_step policy — reject the turn or rewrite the entering
        // batch; agent/request may replace the call config.
        [ $messages, $preStepRejected ] = $this->applyPreStepPolicy($messages, $assistantId, 0);
        if ($preStepRejected) {
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'rejected', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );
            $this->sse->sendEvent('status', array( 'type' => 'rejected', 'message' => 'Request rejected by policy.' ));
            $this->sse->sendDone();
            $this->notifyTurnStopping($assistantId, 0);

            return array(
                'response'      => array(),
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => '',
            );
        }
        $options = $this->applyRequestPolicy($options, $assistantId, 0);

        // Turn budget — before the stream opens. The SSE error event is the same
        // one a provider error uses, so a client that already handles failures
        // needs no new case.
        $budgetVerdict = $this->turnBudgetVerdict($messages, $options, $costAccumulator, $sessionId);

        if (null !== $budgetVerdict && TurnBudget::STATE_EXHAUSTED === $budgetVerdict['state']) {
            $budgetError = $this->errors->normalize($this->turnBudgetError($budgetVerdict));

            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'budget_limit', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );
            $this->sse->sendEvent('error', array( 'code' => $budgetError['code'], 'message' => $budgetError['message'] ));
            $this->sse->sendDone();
            $this->notifyTurnStopping($assistantId, 0);

            return array(
                'response'      => $budgetError,
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => 'budget_limit',
            );
        }

        $response = $this->providers->stream(
            $messages,
            $options,
            $assistantConfig,
            $onStreamChunk,
        );
        $this->accumulateUsage($response, $costAccumulator);

        if ($this->errors->isError($response)) {
            $normalized = $this->errors->normalize($response);
            $this->recordSessionEvent(
                SessionLog::TYPE_TURN_ENDED,
                array( 'reason' => 'request_error', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
                $sessionId,
            );
            $this->sse->sendEvent(
                'error',
                array(
                    'code'    => $normalized['code'],
                    'message' => $normalized['message'],
                )
            );
            $this->sse->sendDone();
            return array(
                'response'      => $normalized,
                'tool_results'  => array(),
                'iterations'    => 0,
                'cost'          => null,
                'cancelled'     => false,
                'cancel_reason' => '',
            );
        }

        $trace?->providerCall($this->finishReason($response), \count($this->extractToolCalls($response)));

        $this->recordSessionEvent(
            SessionLog::TYPE_ASSISTANT_MESSAGE,
            array(
                'content'       => $response['choices'][0]['message']['content'] ?? null,
                'tool_calls'    => $this->extractToolCalls($response),
                'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
            ),
            $sessionId,
        );

        // ─── Streaming agentic loop ──────────────────────────────────

        $cancelled = false;
        $stepResponseLogged = false; // The initial response is logged above.

        while ($iteration < $maxIterations) {
            if (null !== $cancellation && $cancellation->isCancelled()) {
                $cancelled = true;
                break;
            }

            if ($stepResponseLogged) {
                // Log the continuation response that opened this step before
                // the loop can exit.
                $this->recordSessionEvent(
                    SessionLog::TYPE_ASSISTANT_MESSAGE,
                    array(
                        'content'       => $response['choices'][0]['message']['content'] ?? null,
                        'tool_calls'    => $this->extractToolCalls($response),
                        'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
                    ),
                    $sessionId,
                );
            }
            $stepResponseLogged = true;

            $toolCalls = $this->extractToolCalls($response);
            if (array() === $toolCalls) {
                break;
            }

            // Stream tool execution start.
            $this->sse->sendEvent(
                'tool_execution',
                array(
                    'type'       => 'start',
                    'iteration'  => $iteration,
                    'tool_count' => \count($toolCalls),
                    'tools'      => \array_map(static fn($tc) => $tc['function']['name'] ?? 'unknown', $toolCalls),
                )
            );

            $assistantMsg = $this->extractAssistantMessage($response);
            if (null !== $assistantMsg) {
                $messages[] = $assistantMsg;
            }

            foreach ($toolCalls as $toolCall) {
                if (null !== $cancellation && $cancellation->isCancelled()) {
                    $cancelled = true;
                    break;
                }

                $toolName   = $toolCall['function']['name'] ?? '';
                $toolCallId = $toolCall['id'] ?? '';
                $rawArgs    = $toolCall['function']['arguments'] ?? '{}';
                $arguments  = is_string($rawArgs)
                    ? ( \json_decode($rawArgs, true) ?: array() )
                    : ( is_array($rawArgs) ? $rawArgs : array() );

                // Log the model-visible call before dispatch.
                $this->recordSessionEvent(
                    SessionLog::TYPE_TOOL_CALL,
                    array(
                        'tool_call_id' => (string) $toolCallId,
                        'name'         => (string) $toolName,
                        'arguments'    => $arguments,
                        'iteration'    => $iteration,
                    ),
                    $sessionId,
                );

                // Stream: tool started.
                $this->sse->sendEvent(
                    'tool_execution',
                    array(
                        'type'      => 'tool_start',
                        'tool_name' => $toolName,
                        'tool_id'   => $toolCallId,
                    )
                );

                $toolStartedAt = \microtime(true);

                $result = $this->tools->execute(
                    $toolName,
                    $arguments,
                    array(
                        'user_id'       => $userId,
                        'assistant_id'  => $assistantId,
                        'agentic_loop'  => true,
                        'iteration'     => $iteration,
                        'auth_provider' => $this->authProvider,
                        'shadow_mode'   => (bool) ( $options['shadow_mode'] ?? false ),
                    )
                );

                $toolDurationMs = \max(0.0, ( \microtime(true) - $toolStartedAt ) * 1000.0);
                $toolOutcome    = $this->errors->isError($result) ? 'error' : 'success';

                // Same record on the streaming path — one shape for both, so a
                // reader does not have to know which route produced the turn.
                $trace?->toolExecution((string) $toolName, (string) $toolCallId, $toolDurationMs, $toolOutcome);

                if ($this->errors->isError($result)) {
                    $normalized    = $this->errors->normalize($result);
                    $resultContent = "Error: {$normalized['message']}";
                } elseif (is_array($result) && isset($result['message'])) {
                    $resultContent = is_string($result['message'])
                        ? $result['message']
                        : Json::encodeOr($result, '(unserializable result)');
                } else {
                    // Same sanitizer as the non-streaming loop. These two paths
                    // used to disagree — this one encoded the raw result — and
                    // sharing the reader is what prevents that recurring.
                    $resultContent = Json::encodeOr(
                        $this->resultSanitizer()->sanitize($result, $this->tools->get($toolName)),
                        '(unserializable result)'
                    );
                }

                if (null !== $this->budgetTracker) {
                    $this->budgetTracker->record(\strlen($resultContent));
                }

                // Log the model-visible result plus the execution facts
                // telemetry needs (Phase 5, R6): outcome, duration, and
                // the acting identity — additive keys, replay-safe.
                $this->recordSessionEvent(
                    SessionLog::TYPE_TOOL_RESULT,
                    array(
                        'tool_call_id' => (string) $toolCallId,
                        'name'         => (string) $toolName,
                        'content'      => (string) $resultContent,
                        'outcome'      => $toolOutcome,
                        'duration_ms'  => $toolDurationMs,
                        'user_id'      => $userId,
                        'assistant_id' => $assistantId,
                    ),
                    $sessionId,
                );

                // Stream: tool result.
                $this->sse->sendEvent(
                    'tool_execution',
                    array(
                        'type'      => 'tool_result',
                        'tool_name' => $toolName,
                        'tool_id'   => $toolCallId,
                        'result'    => $resultContent,
                    )
                );

                $toolResultMessages[] = array(
                    'role'         => 'tool',
                    'content'      => $resultContent,
                    'tool_call_id' => $toolCallId,
                    'name'         => $toolName,
                );

                $toolMsg = array(
                    'role'         => 'tool',
                    'content'      => $resultContent,
                    'tool_call_id' => $toolCallId,
                );
                if ('' !== $toolName) {
                    $toolMsg['name'] = $toolName;
                }
                $messages[] = $toolMsg;
            }

            if ($cancelled) {
                break;
            }

            if (null !== $cancellation && $cancellation->isCancelled()) {
                $cancelled = true;
                break;
            }

            // Between-step compaction (Phase 5, R6): budget-driven + logged.
            $messages = $this->maybeCompact($messages, $options, $iteration + 1, $sessionId);

            // Policy for the continuation step.
            [ $messages, $preStepRejected ] = $this->applyPreStepPolicy($messages, $assistantId, $iteration + 1);
            if ($preStepRejected) {
                break;
            }
            $options = $this->applyRequestPolicy($options, $assistantId, $iteration + 1);

            // Turn budget — before each continuation call, same rule as the
            // non-streaming path: the spend so far plus this request decides.
            $budgetVerdict = $this->turnBudgetVerdict($messages, $options, $costAccumulator, $sessionId);

            if (null !== $budgetVerdict && TurnBudget::STATE_EXHAUSTED === $budgetVerdict['state']) {
                $budgetExhausted = true;
                break;
            }

            // Status: analyzing results.
            $this->sse->sendEvent(
                'status',
                array(
                    'type'    => 'thinking',
                    'message' => 'Analyzing tool results…',
                )
            );

            $response = $this->providers->stream(
                $messages,
                $options,
                $assistantConfig,
                $onStreamChunk,
            );
            $this->accumulateUsage($response, $costAccumulator);

            if ($this->errors->isError($response)) {
                break;
            }

            ++$iteration;

            $this->events->dispatch(
                new AgenticIterationComplete(
                    iteration: $iteration,
                    assistantId: $assistantId,
                )
            );
            $trace?->providerCall($this->finishReason($response), \count($this->extractToolCalls($response)));
        }

        $limitReached = $iteration >= $maxIterations;
        if ($limitReached || $cancelled || $budgetExhausted) {
            $this->stripOrphanedToolCalls($response);

            if ($budgetExhausted) {
                $this->sse->sendEvent(
                    'status',
                    array(
                        'type'    => 'budget_limit',
                        'message' => 'Reached this turn\'s token ceiling; stopping before the next request.',
                    )
                );
            } elseif ($limitReached) {
                $this->sse->sendEvent(
                    'status',
                    array(
                        'type'    => 'max_iterations',
                        'message' => 'Reached maximum tool execution iterations.',
                    )
                );
            }
        }

        $this->events->dispatch(
            new AgenticLoopCompleted(
                totalIterations: $iteration,
                assistantId: $assistantId,
                toolResults: $toolResultMessages,
                limitReached: $limitReached || $budgetExhausted,
            )
        );

        // Turn-stopping serial notification — the turn is about to close.
        $this->notifyTurnStopping($assistantId, $iteration);

        // Durable turn boundary with the exit reason.
        $turnEndReason = $cancelled
            ? 'aborted'
            : ( $budgetExhausted ? 'budget_limit' : ( $limitReached ? 'iteration_limit' : 'completed' ) );
        $this->recordSessionEvent(
            SessionLog::TYPE_TURN_ENDED,
            array( 'reason' => $turnEndReason, 'iterations' => $iteration, 'assistant_id' => $assistantId, 'user_id' => $userId ),
            $sessionId,
        );

        $cost = null;
        if (\is_array($response)) {
            $billedRoute = $this->billedRoute($response, $options);

            $cost = $this->costs->calculateFromResponse(
                $response,
                $billedRoute['provider'],
                $billedRoute['model'],
            );

            if (
                null !== $cost
                && ( $costAccumulator['total_prompt_tokens'] > 0 || $costAccumulator['total_completion_tokens'] > 0 )
            ) {
                $cost['prompt_tokens']            = $costAccumulator['total_prompt_tokens'];
                $cost['completion_tokens']        = $costAccumulator['total_completion_tokens'];
                $cost['agentic_accumulated']      = $costAccumulator;
                $cost['agentic_iterations_count'] = $iteration;
            }
        }

        // Validate before the payload is built: this is the only point on the
        // streaming path where the whole response exists and nothing has been
        // delivered yet.
        $guarded            = $this->applyOutputGuard($response, $runtimeOutputGuard);
        $response           = $guarded['response'];
        $outputGuardVerdict = $guarded['verdict'];

        if ($bufferForOutputGuard) {
            $this->emitBufferedContent($response);
        }

        $this->events->dispatch(
            new CostCalculated(
                costData: $cost ?? array(),
                assistantId: $assistantId,
                userId: $userId,
                response: \is_array($response) ? $response : array(),
            )
        );

        $payload = array(
            'assistant_id' => $assistantId,
            'data'         => $response,
            'tool_results' => $toolResultMessages,
            'cost'         => $cost,
            'output_guard' => $outputGuardVerdict,
        );

        $this->sse->sendEvent('message', $payload);
        $this->sse->sendDone();

        $trace?->finish(
            array(
                'iterations' => $iteration,
                'cost' => $cost,
                'cancelled' => $cancelled,
                'cancel_reason' => $cancelled ? ( $cancellation?->reason() ?? '' ) : '',
                'duration_ms' => (int) \round(( \microtime(true) - $startedAt ) * 1000),
            )
        );

        return array(
            'response'      => $response,
            'tool_results'  => $toolResultMessages,
            'iterations'    => $iteration,
            'cost'          => $cost,
            'cancelled'     => $cancelled,
            'cancel_reason' => $cancelled ? ( $cancellation?->reason() ?? '' ) : '',
            'output_guard'  => $outputGuardVerdict,
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Build tool definitions for only the allowed tools.
     *
     * Unresolved slugs are never sent to the provider; instead an
     * UnresolvedToolRequested event is dispatched so the platform adapter
     * can surface a fail-loud admin notice.
     *
     * @param string[] $allowedSlugs Assistant-configured tool slugs.
     * @param int      $assistantId  Assistant ID for audit attribution.
     * @return array<int, array<string, mixed>>
     */
    private function buildAllowedTools(array $allowedSlugs, int $assistantId = 0, ?array $profile = null): array
    {
        if (array() === $allowedSlugs) {
            return array();
        }

        $resolver = $this->toolResolver ?? $this->tools;
        $resolved = array();

        foreach ($allowedSlugs as $slug) {
            if (! \is_string($slug) || '' === $slug) {
                continue;
            }

            $tool = $resolver->get($slug);
            if (null === $tool) {
                // Fail loud, never silently: misconfiguration must surface.
                $this->events->dispatch(
                    new UnresolvedToolRequested(
                        slug: $slug,
                        assistantId: $assistantId,
                    )
                );
                continue;
            }

            $resolved[ $slug ] = $tool;
        }

        $definitions = array();

        foreach ($this->orderToolSlugs(\array_keys($resolved), $resolved, $profile) as $slug) {
            $tool          = $resolved[ $slug ];
            $definitions[] = array(
                'type'     => 'function',
                'function' => array(
                    'name'        => $slug,
                    'description' => $tool->getDescription(),
                    'parameters'  => self::normalizeToolSchema($tool->getParametersSchema()),
                ),
            );
        }

        return $definitions;
    }

    /**
     * Order the tool slugs the provider will see.
     *
     * `fixed` keeps the assistant's configured order, which is the default and
     * the behaviour every existing install already has. `scored` asks the
     * shared router to rank by capability flags and per-slug preferences, which
     * matters most when an assistant carries dozens of tools: providers attend
     * to earlier definitions more, and some cap the count.
     *
     * Any failure falls back to the fixed order. A router that cannot score must
     * not be able to drop a tool — that would turn a ranking problem into a
     * capability outage.
     *
     * @param array<int, string>                 $slugs    Resolved slugs, in configured order.
     * @param array<string, \Nvoos\Core\Domain\Contract\ToolInterface> $resolved Slug → tool.
     * @param array<string, mixed>|null          $profile  Resolved profile, if any.
     * @return array<int, string>
     */
    private function orderToolSlugs(array $slugs, array $resolved, ?array $profile): array
    {
        if (null === $profile || 'scored' !== ( $profile['tools']['router'] ?? 'fixed' ) || count($slugs) < 2) {
            return $slugs;
        }

        // The only task class the profile carries today.
        $taskClass = (string) ( $profile['memory']['task_class'] ?? 'general' );

        [ $preferences, $presetWeights ] = ToolRouterHarness::resolveAssistantInputs($profile);

        try {
            $ranked = ToolRouterHarness::rank(
                $resolved,
                $taskClass,
                $preferences,
                $presetWeights,
                array(),
                array( 'preset_index' => ToolPresetCatalogue::index() )
            );
        } catch (\Throwable) {
            return $slugs;
        }

        if (array() === $ranked) {
            return $slugs;
        }

        $ordered = \array_keys($ranked);

        // Anything the router did not score keeps its configured position at
        // the end rather than disappearing.
        foreach ($slugs as $slug) {
            if (! \in_array($slug, $ordered, true)) {
                $ordered[] = $slug;
            }
        }

        return $ordered;
    }

    /**
     * Normalize a tool parameter schema into a valid JSON Schema object.
     *
     * Providers (DeepSeek in particular) validate tool schemas strictly and
     * reject empty arrays with errors like "[] is not of type 'object'".
     * Any schema that is empty, or that lacks the object root, is converted
     * to an open-object schema so the payload always serializes as an object.
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function normalizeToolSchema(array $schema): array
    {
        if (array() === $schema) {
            return array(
                'type'       => 'object',
                'properties' => new \stdClass(),
            );
        }

        if (! isset($schema['type'])) {
            // A bare property map without the object root — wrap it.
            $schema = array(
                'type'       => 'object',
                'properties' => $schema,
            );
        }

        // `properties` must encode as a JSON object (`{}`), never as an empty
        // JSON array (`[]`) — strict providers (DeepSeek) reject
        // "[] is not of type 'object'". Preserve object-valued property maps
        // and convert empty arrays to an empty \stdClass (encodes as `{}`).
        if (! isset($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        } elseif (! \is_array($schema['properties']) && ! \is_object($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        } elseif (\is_array($schema['properties']) && array() === $schema['properties']) {
            $schema['properties'] = new \stdClass();
        }

        // Per-property `required` is not valid JSON Schema. Many base WordPress
        // tools declare it as a boolean inside each property (e.g.
        // `'required' => true`) instead of as a top-level array. DeepSeek
        // rejects this outright: "true is not of type 'array'". Hoist any such
        // illegal `required` flag out of the property and into the top-level
        // `required` array so the payload always serializes as valid schema.
        $schema = self::hoistPropertyRequiredFlags($schema);

        return $schema;
    }

    /**
     * Hoist illegal per-property `required` flags into the top-level array.
     *
     * JSON Schema defines `required` only at the object level (as a list of
     * property names). A boolean `required` inside a property definition is
     * invalid and is rejected by strict providers (DeepSeek). This converts
     * those flags to the canonical form, preserving the author's intent.
     *
     * @param array<string, mixed> $schema A normalized tool parameter schema.
     * @return array<string, mixed> The schema with per-property `required` flags relocated.
     */
    private static function hoistPropertyRequiredFlags(array $schema): array
    {
        if (! isset($schema['properties']) || ! \is_array($schema['properties'])) {
            return $schema;
        }

        $required = isset($schema['required']) && \is_array($schema['required'])
            ? $schema['required']
            : array();

        foreach ($schema['properties'] as $property_name => $property) {
            if (! \is_array($property) || ! \array_key_exists('required', $property)) {
                continue;
            }

            // A nested array `required` belongs to an inner object schema and is
            // valid JSON Schema there — leave it untouched. Only hoist a scalar
            // (boolean) flag, which is the legacy, invalid authoring pattern.
            if (\is_array($property['required'])) {
                continue;
            }

            $flag = $property['required'];
            unset($schema['properties'][ $property_name ]['required']);

            // Only a truthy `required` flag (e.g. `true`) marks the field as
            // mandatory. A `false` flag carries no constraint once removed.
            if ($flag && ! \in_array($property_name, $required, true)) {
                $required[] = $property_name;
            }
        }

        if (array() !== $required) {
            $schema['required'] = \array_values($required);
        }

        return $schema;
    }

    /**
     * Extract tool calls from an LLM response.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>  Array of { id, function: { name, arguments } }
     */
    private function extractToolCalls(array $response): array
    {
        // Direct tool_calls key.
        if (isset($response['tool_calls']) && is_array($response['tool_calls'])) {
            return $response['tool_calls'];
        }

        // OpenAI format: choices[0].message.tool_calls.
        if (isset($response['choices'][0]['message']['tool_calls'])) {
            $calls = $response['choices'][0]['message']['tool_calls'];
            return is_array($calls) ? $calls : array();
        }

        return array();
    }

    /**
     * Extract the assistant message from an LLM response.
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function extractAssistantMessage(array $response): ?array
    {
        if (isset($response['choices'][0]['message'])) {
            return $response['choices'][0]['message'];
        }

        if (isset($response['role']) && 'assistant' === $response['role']) {
            return $response;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function newCostAccumulator(): array
    {
        return array(
            'total_prompt_tokens'     => 0,
            'total_completion_tokens' => 0,
            'cost_usd'                => 0.0,
        );
    }

    /**
     * The provider and model to bill for a response (Proposal 149 §70).
     *
     * Normally the request's own values. When routing deviated — a model
     * fallback, a provider hop or an accepted cascade tier-1 answer — the
     * router annotates the response with what actually answered, and the cost
     * table must be read against that. A response that echoes its own `model`
     * also wins over the request: a client may have applied a provider default.
     *
     * @param mixed                $response Provider response.
     * @param array<string, mixed> $options  Request options.
     * @return array{provider: string, model: string}
     */
    private function billedRoute(mixed $response, array $options): array
    {
        $routing = \is_array($response) && \is_array($response['_nvoos_routing'] ?? null)
            ? $response['_nvoos_routing']
            : array();

        $provider = (string) ( $routing['provider'] ?? $options['provider'] ?? '' );

        if (\is_string($routing['model'] ?? null) && '' !== $routing['model']) {
            $model = $routing['model'];
        } elseif (\is_array($response) && \is_string($response['model'] ?? null) && '' !== $response['model']) {
            $model = $response['model'];
        } else {
            $model = (string) ( $options['model'] ?? '' );
        }

        return array( 'provider' => $provider, 'model' => $model );
    }

    /**
     * Accumulate token usage from one provider response.
     *
     * Error responses (WP_Error, DomainError, etc.) contribute nothing.
     *
     * @param mixed $response    Provider response array or error object.
     * @param array<string, mixed> $accumulator Mutable accumulator from newCostAccumulator().
     */
    private function accumulateUsage(mixed $response, array &$accumulator): void
    {
        if (! is_array($response)) {
            return;
        }

        $usage = $response['usage'] ?? array();
        if (! is_array($usage)) {
            return;
        }

        $accumulator['total_prompt_tokens']     += (int) ( $usage['prompt_tokens'] ?? 0 );
        $accumulator['total_completion_tokens'] += (int) ( $usage['completion_tokens'] ?? 0 );
        // cost_usd is recomputed by CostCalculator when the final cost is built.
    }

    /**
     * Judge the next provider call against the turn's ceilings.
     *
     * Called **before** every request leaves. A ceiling applied afterwards is a
     * report, and the request that overspent is already billed.
     *
     * A `warn` verdict is recorded to the session log and does not stop anything;
     * an `exhausted` one is the caller's to act on. Splitting the two here keeps
     * the four call sites (two per entry point) to a single state check.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     * @param array<string, mixed>             $accumulator
     * @return array{state: string, dimension: string, limit: int, projected: int, spent: int, percent: float|null}|null
     */
    private function turnBudgetVerdict(
        array $messages,
        array $options,
        array $accumulator,
        ?string $sessionId,
    ): ?array {
        if (null === $this->turnBudget || ! $this->turnBudget->enabled()) {
            return null;
        }

        $spent = (int) ( $accumulator['total_prompt_tokens'] ?? 0 )
            + (int) ( $accumulator['total_completion_tokens'] ?? 0 );

        $verdict = $this->turnBudget->assess(
            $spent,
            $this->estimatePromptTokens($messages),
            (int) ( $options['max_tokens'] ?? 0 ),
        );

        if (null !== $verdict && TurnBudget::STATE_WARN === $verdict['state']) {
            $this->recordSessionEvent(
                SessionLog::TYPE_BUDGET_WARNING,
                array(
                    'dimension' => $verdict['dimension'],
                    'limit'     => $verdict['limit'],
                    'projected' => $verdict['projected'],
                    'spent'     => $verdict['spent'],
                    'percent'   => $verdict['percent'],
                ),
                $sessionId,
            );
        }

        return $verdict;
    }

    /**
     * Estimate the tokens in the outgoing prompt.
     *
     * The tokenizer is used when one is wired; the character fallback is the same
     * `strlen / 4` ratio the tool quotas and WordPress's `estimate_tokens()` use,
     * so a ceiling means the same thing on a deployment without tiktoken.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function estimatePromptTokens(array $messages): int
    {
        if (null !== $this->tokenBudget) {
            return $this->tokenBudget->estimateMessageTokens($messages);
        }

        return (int) ( \strlen(Json::encodeOr($messages, '[]')) / 4 );
    }

    /**
     * Build the refusal for an exhausted ceiling.
     *
     * The message names the dimension, because "reduce the conversation" (a call
     * that is too big) and "the turn was stopped" (a loop that spent too much)
     * call for different remedies.
     *
     * @param array{state: string, dimension: string, limit: int, projected: int, spent: int, percent: float|null} $verdict
     */
    private function turnBudgetError(array $verdict): mixed
    {
        $message = TurnBudget::DIMENSION_CALL === $verdict['dimension']
            ? \sprintf(
                'This request is estimated at %d tokens, above the per-call ceiling of %d. Reduce the conversation or deselect tools, or ask an operator to raise the ceiling.',
                $verdict['projected'],
                $verdict['limit'],
            )
            : \sprintf(
                'This turn reached %d tokens of its %d-token ceiling, so the next call was not made. The work done so far is in the transcript.',
                $verdict['spent'],
                $verdict['limit'],
            );

        return $this->errors->create(
            'turn_budget_exceeded',
            $message,
            array(
                'dimension' => $verdict['dimension'],
                'limit'     => $verdict['limit'],
                'projected' => $verdict['projected'],
                'spent'     => $verdict['spent'],
                'percent'   => $verdict['percent'],
            ),
        );
    }

    /**
     * Run the agent/pre_step waterfall (feature-detected).
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{0: array<int, array<string, mixed>>, 1: bool}  [messages, rejected]
     */
    private function applyPreStepPolicy(array $messages, int $assistantId, int $iteration): array
    {
        if (! $this->events instanceof WaterfallEventDispatcherInterface) {
            return array( $messages, false );
        }

        $decision = $this->events->waterfall(
            'agent/pre_step',
            new AgentPreStep(messages: $messages, assistantId: $assistantId, iteration: $iteration),
            static function (AgentPreStep $event): AgentPreStepDecision {
                return AgentPreStepDecision::enter($event->messages);
            },
        );

        if (! $decision instanceof AgentPreStepDecision) {
            // A misbehaving listener returned an unexpected type — keep the batch.
            return array( $messages, false );
        }

        if (AgentPreStepDecision::KIND_REJECT === $decision->kind) {
            return array( $messages, true );
        }

        return array( $decision->messages, false );
    }

    /**
     * Run the agent/request waterfall (feature-detected).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>  The (possibly replaced) call options.
     */
    private function applyRequestPolicy(array $options, int $assistantId, int $iteration): array
    {
        if (! $this->events instanceof WaterfallEventDispatcherInterface) {
            return $options;
        }

        $decision = $this->events->waterfall(
            'agent/request',
            new AgentRequest(options: $options, assistantId: $assistantId, iteration: $iteration),
            static function (object $event): AgentRequestDecision {
                return AgentRequestDecision::keep();
            },
        );

        if (
            $decision instanceof AgentRequestDecision
            && AgentRequestDecision::KIND_REPLACE === $decision->kind
            && is_array($decision->options)
        ) {
            // Merge so listeners can override selectively while the required
            // keys (provider, model, tools) remain present downstream.
            return \array_merge($options, $decision->options);
        }

        return $options;
    }

    /**
     * Whether the agent/request_error waterfall owns recovery via retry.
     */
    private function shouldRetryAfterError(mixed $error, int $assistantId, int $iteration): bool
    {
        if (! $this->events instanceof WaterfallEventDispatcherInterface) {
            return false;
        }

        $decision = $this->events->waterfall(
            'agent/request_error',
            new AgentRequestError(error: $error, assistantId: $assistantId, iteration: $iteration),
            static function (object $event): AgentRequestErrorDecision {
                return AgentRequestErrorDecision::terminal();
            },
        );

        return $decision instanceof AgentRequestErrorDecision
            && AgentRequestErrorDecision::KIND_RETRY === $decision->kind;
    }

    /**
     * Notify serial listeners that the turn is about to close.
     */
    private function notifyTurnStopping(int $assistantId, int $totalIterations): void
    {
        if (! $this->events instanceof WaterfallEventDispatcherInterface) {
            return;
        }

        $this->events->serial(
            'agent/turn_stopping',
            new AgentTurnStopping(assistantId: $assistantId, totalIterations: $totalIterations),
        );
    }

    /**
     * Record one session-log entry when session logging is wired.
     *
     * The appended entry is persisted through the optional store when a
     * session id is known. Persistence failures must never break the chat
     * (the log is observability, not a correctness dependency).
     * @param array<string, mixed> $data
     */
    private function recordSessionEvent(string $type, array $data, string $sessionId = ''): void
    {
        if (null === $this->sessionLog) {
            return;
        }

        $this->sessionLog->append($type, $data);

        $last = $this->sessionLog->lastEvent();

        if (null !== $this->sessionLogStore && '' !== $sessionId && null !== $last) {
            $this->sessionLogStore->append($sessionId, $last->toArray());
        }

        // Telemetry single-path (Phase 5, R6): fan the appended entry out
        // to subscribers instead of letting them re-wrap the loop.
        if (null !== $this->sessionTelemetry && null !== $last) {
            $this->sessionTelemetry->notify($last);
        }
    }

    /**
     * Compact the message list between continuation steps when the
     * wired provider's budget policy triggers.
     *
     * Compaction facts are durable: a context_compacted entry records the
     * message counts before/after so resumed sessions know their history
     * was summarized.
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function maybeCompact(array $messages, array $options, int $iteration, string $sessionId): array
    {
        $provider = $this->compactionProvider;

        // Legacy bridge: the deprecated setContextCompressor() wiring folds
        // into the provider seam so old callers keep working.
        if (null === $provider && null !== $this->contextCompressor) {
            $provider = new CompactionProvider($this->contextCompressor, $this->compressor);
        }

        if (null === $provider || null === $this->tokenBudget) {
            return $messages;
        }

        $model = (string) ( $options['model'] ?? '' );
        $limit = $this->tokenBudget->getModelLimit($model);
        if ($limit <= 0) {
            $limit = 128000;
        }

        if (! $provider->shouldCompact($messages, $limit, $iteration)) {
            return $messages;
        }

        $compacted = $provider->compact($messages, $model);

        $this->recordSessionEvent(
            SessionLog::TYPE_CONTEXT_COMPACTED,
            array(
                'iteration'           => $iteration,
                'message_count_before' => \count($messages),
                'message_count_after'  => \count($compacted),
            ),
            $sessionId,
        );

        return $compacted;
    }

    /**
     * Build the canonical result payload for a cancelled request.
     *
     * @param array<string, mixed> $response
     * @return array{response: array<string, mixed>, tool_results: array<int, array<string, mixed>>, iterations: int, cost: null, cancelled: bool, cancel_reason: string}
     */
    private function cancelledResult(array $response, CancellationToken $cancellation): array
    {
        return array(
            'response'      => $response,
            'tool_results'  => array(),
            'iterations'    => 0,
            'cost'          => null,
            'cancelled'     => true,
            'cancel_reason' => $cancellation->reason(),
        );
    }

    /**
     * Strip unexecuted tool calls from a response in-place.
     *
     * When the agentic loop hits max_iterations, the final response may
     * still contain tool_calls that were never executed. Sending those to
     * the client causes "orphaned tool_call_id" errors on the next turn.
     * @param array<string, mixed> $response
     */
    private function stripOrphanedToolCalls(array &$response): void
    {
        if (isset($response['choices'][0]['message']['tool_calls'])) {
            unset($response['choices'][0]['message']['tool_calls']);
        }

        if (
            isset($response['choices'][0]['finish_reason'])
            && 'tool_calls' === $response['choices'][0]['finish_reason']
        ) {
            $response['choices'][0]['finish_reason'] = 'stop';
        }
    }

    /**
     * The shared sanitizer instance.
     */
    private function resultSanitizer(): ToolResultSanitizer
    {
        return $this->resultSanitizer ??= new ToolResultSanitizer();
    }

    /**
     * Extract image URLs from a tool result for vision-model injection.
     *
     * When a tool returns image URLs, these are injected as user-content
     * images for vision-capable models on the next LLM turn.
     *
     * @param mixed  $result   Raw tool result.
     * @param string $toolName Tool slug for context.
     * @return array<int, array<string, mixed>> Image messages in OpenAI vision format.
     */
    private function extractImagesFromToolResult($result, string $toolName): array
    {
        if (! \is_array($result)) {
            return array();
        }

        $images    = array();
        $imageKeys = array( 'image_url', 'url', 'download_url', 'image' );

        foreach ($imageKeys as $key) {
            if (! isset($result[ $key ]) || ! \is_string($result[ $key ])) {
                continue;
            }

            $url = $result[ $key ];

            if (
                \preg_match('/\.(png|jpg|jpeg|gif|webp)(\?|$)/i', $url)
                || \str_starts_with($url, 'data:image/')
            ) {
                $images[] = array(
                    'role'    => 'user',
                    'content' => array(
                        array( 'type' => 'text', 'text' => "Tool {$toolName} returned an image:" ),
                        array( 'type' => 'image_url', 'image_url' => array( 'url' => $url ) ),
                    ),
                );
            }
        }

        return $images;
    }
}
