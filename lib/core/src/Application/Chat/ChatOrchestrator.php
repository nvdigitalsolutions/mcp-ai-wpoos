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
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Chat;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Session\SessionLog;
use Nvoos\Core\Application\Session\SessionTelemetry;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
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
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SemanticCompressorInterface;
use Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface;
use Nvoos\Core\Domain\Contract\ContextCompressionInterface;
use Nvoos\Core\Domain\ValueObject\CancellationToken;

class ChatOrchestrator {

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

	public function __construct(
		private readonly ToolRegistry $tools,
		private readonly ProviderRouter $providers,
		private readonly EventDispatcherInterface $events,
		private readonly ErrorFactoryInterface $errors,
		private readonly CostCalculator $costs,
		private readonly SseHandler $sse,
	) {}

	/**
	 * Wire the token-budget manager for tool-definition capping.
	 */
	public function setTokenBudgetManager( TokenBudgetManager $budget ): void {
		$this->tokenBudget = $budget;
	}

	/**
	 * Wire the rate limiter for API call gating.
	 */
	public function setRateLimiter( RateLimiterInterface $rateLimiter ): void {
		$this->rateLimiter = $rateLimiter;
	}

	/**
	 * Configure the per-user chat rate limit.
	 *
	 * Both values are clamped to a minimum of 1 so a misconfigured filter
	 * cannot silently disable the gate or divide the window to zero.
	 */
	public function setChatRateLimit( int $maxRequests, int $windowSeconds = self::DEFAULT_CHAT_RATE_LIMIT_WINDOW ): void {
		$this->chatRateLimitMax    = \max( 1, $maxRequests );
		$this->chatRateLimitWindow = \max( 1, $windowSeconds );
	}

	/**
	 * Wire the semantic compressor for message reduction.
	 */
	public function setSemanticCompressor( SemanticCompressorInterface $compressor ): void {
		$this->compressor = $compressor;
	}

	/**
	 * Wire the byte-budget tracker for tool output accounting.
	 */
	public function setDataBudgetTracker( DataBudgetTrackerInterface $tracker ): void {
		$this->budgetTracker = $tracker;
	}

	/**
	 * Wire the context compressor for token-aware truncation.
	 *
	 * @deprecated 1.3.0 Use setCompactionProvider().
	 */
	public function setContextCompressor( ContextCompressionInterface $compressor ): void {
		$this->contextCompressor = $compressor;
	}

	/**
	 * Wire the auth provider used for per-tool capability checks.
	 */
	public function setAuthProvider( AuthProviderInterface $authProvider ): void {
		$this->authProvider = $authProvider;
	}

	/**
	 * Wire the session log (opt-in) and its optional persistence sink.
	 */
	public function setSessionLog( ?SessionLog $log, ?SessionLogStoreInterface $store = null ): void {
		$this->sessionLog      = $log;
		$this->sessionLogStore = $store;
	}

	/**
	 * Wire a scoped tool resolver for visibility resolution.
	 */
	public function setToolResolver( ToolResolverInterface $resolver ): void {
		$this->toolResolver = $resolver;
	}

	/**
	 * Wire the compaction provider for between-step context compaction.
	 */
	public function setCompactionProvider( CompactionProvider $provider ): void {
		$this->compactionProvider = $provider;
	}

	/**
	 * Wire the session-log telemetry tap (Phase 5, R6).
	 *
	 * Subscribers receive every appended log entry. Pass null to detach.
	 *
	 * @param SessionTelemetry|null $telemetry Telemetry tap.
	 */
	public function setSessionTelemetry( ?SessionTelemetry $telemetry ): void {
		$this->sessionTelemetry = $telemetry;
	}

	/**
	 * Handle a chat request — non-streaming (returns full response).
	 *
	 * @param array $messages        OpenAI-format conversation messages.
	 * @param array $assistantConfig Assistant configuration (provider, model, tools, etc.).
	 * @param int   $userId          Authenticated user ID (0 = guest).
	 * @param int   $assistantId     Assistant post ID (0 = none).
	 * @param array $options         Additional options (temperature, max_tokens, etc.).
	 * @param CancellationToken|null $cancellation Optional cooperative
	 *     cancellation token; checked at every provider and tool boundary.
	 *
	 * @return array{
	 *     response: array,
	 *     tool_results: array,
	 *     iterations: int,
	 *     cost: array|null,
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
		$maxIterations = \max( 1, \min( 50, $maxIterations ) );

		$iteration          = 0;
		$toolResultMessages = array();

		// Build tool definitions for this assistant.
		$allowedToolSlugs = $assistantConfig['tools'] ?? array();
		$toolDefinitions  = $this->buildAllowedTools( $allowedToolSlugs, $assistantId );

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

		$startedAt = \microtime( true );

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
		if ( null !== $this->rateLimiter ) {
			$rateLimitKey = 'chat:' . $userId . ':' . $assistantId;
			if ( ! $this->rateLimiter->isAllowed( $rateLimitKey, $this->chatRateLimitMax, $this->chatRateLimitWindow ) ) {
				return array(
					'response'      => $this->errors->rateLimited( 'Too many requests. Please wait before sending another message. If you believe this is an error, contact the site administrator.' ),
					'tool_results'  => array(),
					'iterations'    => 0,
					'cost'          => null,
					'cancelled'     => false,
					'cancel_reason' => '',
				);
			}
			$this->rateLimiter->record( $rateLimitKey, $this->chatRateLimitWindow );
		}

		// Semantic compression — reduce message size when near token limits.
		if ( null !== $this->compressor ) {
			$modelId = (string) $options['model'];
			$tokenLimit = null !== $this->tokenBudget
				? $this->tokenBudget->getModelLimit( $modelId )
				: 128000;

			// Estimate total message tokens.
			$estimatedTokens = 0;
			foreach ( $messages as $msg ) {
				$content = \is_array( $msg['content'] ?? null )
					? \json_encode( $msg['content'] )
					: (string) ( $msg['content'] ?? '' );
				$estimatedTokens += $this->compressor->estimateTokens( $content );
			}

			// Compress if over 80% of limit. The SemanticCompressorInterface
			// contract is compress( string, int aggressiveness, int maxTokens )
			// — pass ints, never the legacy options array.
			if ( $estimatedTokens > (int) ( $tokenLimit * 0.8 ) ) {
				$result = $this->compressor->compress(
					\json_encode( $messages ),
					2,
					0,
				);
				if ( ! empty( $result['compressed'] ) && \is_string( $result['compressed'] ) ) {
					$decoded = \json_decode( $result['compressed'], true );
					if ( \is_array( $decoded ) ) {
						$messages = $decoded;
					}
				}
			}
		}

		// Prompt caching — pass cache key if configured.
		if ( ! empty( $assistantConfig['prompt_cache_key'] ) ) {
			$options['prompt_cache_key'] = $assistantConfig['prompt_cache_key'];
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
		foreach ( $messages as $msg ) {
			if ( isset( $msg['role'] ) && 'user' === $msg['role'] ) {
				$this->recordSessionEvent(
					SessionLog::TYPE_USER_MESSAGE,
					array( 'content' => is_string( $msg['content'] ?? null ) ? $msg['content'] : '' ),
					$sessionId,
				);
			}
		}

		// Cooperative cancellation — checked at every provider and tool boundary.
		if ( null !== $cancellation && $cancellation->isCancelled() ) {
			$this->recordSessionEvent(
				SessionLog::TYPE_TURN_ENDED,
				array( 'reason' => 'aborted', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
				$sessionId,
			);

			return $this->cancelledResult( array(), $cancellation );
		}

		$costAccumulator = self::newCostAccumulator();

		// agent/pre_step policy — listeners may reject the turn or rewrite
		// the entering batch; agent/request may replace the call config.
		[ $messages, $preStepRejected ] = $this->applyPreStepPolicy( $messages, $assistantId, 0 );
		if ( $preStepRejected ) {
			$this->recordSessionEvent(
				SessionLog::TYPE_TURN_ENDED,
				array( 'reason' => 'rejected', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
				$sessionId,
			);
			$this->notifyTurnStopping( $assistantId, 0 );

			return array(
				'response'      => array(),
				'tool_results'  => array(),
				'iterations'    => 0,
				'cost'          => null,
				'cancelled'     => false,
				'cancel_reason' => '',
			);
		}
		$options = $this->applyRequestPolicy( $options, $assistantId, 0 );

		// Initial LLM call with bounded retry via agent/request_error.
		$response = $this->providers->chat( $messages, $options, $assistantConfig );
		$retries  = 0;
		while ( $this->errors->isError( $response )
			&& $retries < self::MAX_REQUEST_RETRIES
			&& $this->shouldRetryAfterError( $response, $assistantId, 0 )
		) {
			++$retries;
			$response = $this->providers->chat( $messages, $options, $assistantConfig );
		}
		$this->accumulateUsage( $response, $costAccumulator );

		if ( $this->errors->isError( $response ) ) {
			$this->recordSessionEvent(
				SessionLog::TYPE_TURN_ENDED,
				array( 'reason' => 'request_error', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
				$sessionId,
			);

			return array(
				'response'      => $this->errors->normalize( $response ),
				'tool_results'  => array(),
				'iterations'    => 0,
				'cost'          => null,
				'cancelled'     => false,
				'cancel_reason' => '',
			);
		}

		// Model-visible facts are logged: the assistant message that just
		// arrived (with any tool_calls it carries).
		$this->recordSessionEvent(
			SessionLog::TYPE_ASSISTANT_MESSAGE,
			array(
				'content'       => $response['choices'][0]['message']['content'] ?? null,
				'tool_calls'    => $this->extractToolCalls( $response ),
				'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
			),
			$sessionId,
		);

		// ─── Agentic loop ────────────────────────────────────────────

		$cancelled = false;
		$stepResponseLogged = false; // The initial response is logged above.

		while ( $iteration < $maxIterations ) {
			if ( null !== $cancellation && $cancellation->isCancelled() ) {
				$cancelled = true;
				break;
			}

			if ( $stepResponseLogged ) {
				// The continuation response that opened this step is also a
				// model-visible fact — log it before the loop can exit.
				$this->recordSessionEvent(
					SessionLog::TYPE_ASSISTANT_MESSAGE,
					array(
						'content'       => $response['choices'][0]['message']['content'] ?? null,
						'tool_calls'    => $this->extractToolCalls( $response ),
						'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
					),
					$sessionId,
				);
			}
			$stepResponseLogged = true;

			$toolCalls = $this->extractToolCalls( $response );

			if ( array() === $toolCalls ) {
				break;
			}

			// finish_reason-aware exit.
			$finishReason = $response['choices'][0]['finish_reason'] ?? null;
			if ( 'stop' === $finishReason ) {
				$this->stripOrphanedToolCalls( $response );
				break;
			}
			if ( 'length' === $finishReason ) {
				break;
			}

			// Add the assistant message with tool_calls to the conversation.
			$assistantMsg = $this->extractAssistantMessage( $response );
			if ( null !== $assistantMsg ) {
				$messages[] = $assistantMsg;
			}

			// Execute each tool.
			foreach ( $toolCalls as $toolCall ) {
				if ( null !== $cancellation && $cancellation->isCancelled() ) {
					$cancelled = true;
					break;
				}

				$toolName   = $toolCall['function']['name'] ?? '';
				$toolCallId = $toolCall['id'] ?? '';
				$rawArgs    = $toolCall['function']['arguments'] ?? '{}';
				$arguments  = is_string( $rawArgs )
					? ( \json_decode( $rawArgs, true ) ?: array() )
					: ( is_array( $rawArgs ) ? $rawArgs : array() );

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

				$toolStartedAt = \microtime( true );

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

				$toolDurationMs = \max( 0.0, ( \microtime( true ) - $toolStartedAt ) * 1000.0 );
				$toolOutcome    = $this->errors->isError( $result ) ? 'error' : 'success';

				// Normalize errors for LLM consumption.
				if ( $this->errors->isError( $result ) ) {
					$normalized    = $this->errors->normalize( $result );
					$resultContent = "Error: {$normalized['message']}";
				} elseif ( is_array( $result ) && isset( $result['message'] ) ) {
					$resultContent = is_string( $result['message'] )
						? $result['message']
						: \json_encode( $result );
				} else {
					// Sanitize: strip base64 to save tokens in LLM context.
					$resultContent = \json_encode( $this->sanitizeToolResult( $result ) );
				}

				// Track byte budget if wired (accounting only — spill
				// behavior is a later-phase policy).
				if ( null !== $this->budgetTracker ) {
					$this->budgetTracker->record( \strlen( $resultContent ) );
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
				$imageMessages = $this->extractImagesFromToolResult( $result, $toolName );

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

				if ( '' !== $toolName ) {
					$toolMsg['name'] = $toolName;
				}

				$messages[] = $toolMsg;
			}

			if ( $cancelled ) {
				break;
			}

			if ( null !== $cancellation && $cancellation->isCancelled() ) {
				$cancelled = true;
				break;
			}

			// Between-step compaction (Phase 5, R6): budget-driven + logged.
			$messages = $this->maybeCompact( $messages, $options, $iteration + 1, $sessionId );

			// Policy for the continuation step.
			[ $messages, $preStepRejected ] = $this->applyPreStepPolicy( $messages, $assistantId, $iteration + 1 );
			if ( $preStepRejected ) {
				break;
			}
			$options = $this->applyRequestPolicy( $options, $assistantId, $iteration + 1 );

			// Call LLM again with tool results, with bounded retry.
			$response = $this->providers->chat( $messages, $options, $assistantConfig );
			$retries  = 0;
			while ( $this->errors->isError( $response )
				&& $retries < self::MAX_REQUEST_RETRIES
				&& $this->shouldRetryAfterError( $response, $assistantId, $iteration )
			) {
				++$retries;
				$response = $this->providers->chat( $messages, $options, $assistantConfig );
			}
			$this->accumulateUsage( $response, $costAccumulator );

			if ( $this->errors->isError( $response ) ) {
				break;
			}

			++$iteration;

			$this->events->dispatch(
				new AgenticIterationComplete(
					iteration: $iteration,
					assistantId: $assistantId,
				)
			);
		}

		$durationMs = ( \microtime( true ) - $startedAt ) * 1000;

		// Strip orphaned tool calls if loop hit max iterations.
		$limitReached = $iteration >= $maxIterations;
		if ( $limitReached || $cancelled ) {
			$this->stripOrphanedToolCalls( $response );
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
				limitReached: $limitReached,
			)
		);

		// Turn-stopping serial notification — the turn is about to close.
		$this->notifyTurnStopping( $assistantId, $iteration );

		// Durable turn boundary with the exit reason.
		$turnEndReason = $cancelled
			? 'aborted'
			: ( $limitReached ? 'iteration_limit' : 'completed' );
		$this->recordSessionEvent(
			SessionLog::TYPE_TURN_ENDED,
			array( 'reason' => $turnEndReason, 'iterations' => $iteration, 'assistant_id' => $assistantId, 'user_id' => $userId ),
			$sessionId,
		);

		// Calculate cost from accumulated per-iteration usage.
		$cost = null;
		if ( \is_array( $response ) ) {
			$cost = $this->costs->calculateFromResponse(
				$response,
				$options['provider'],
				$options['model'],
			);

			if ( null !== $cost
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
				response: \is_array( $response ) ? $response : array(),
			)
		);

		return array(
			'response'      => $response,
			'tool_results'  => $toolResultMessages,
			'iterations'    => $iteration,
			'cost'          => $cost,
			'cancelled'     => $cancelled,
			'cancel_reason' => $cancelled ? ( $cancellation?->reason() ?? '' ) : '',
		);
	}

	/**
	 * Handle a chat request with SSE streaming.
	 *
	 * Sends status updates, tool execution progress, and final response
	 * as SSE events. The caller is responsible for calling $sse->finish()
	 * after this method returns.
	 *
	 * @return array  Same as handleChat() — the final response payload.
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
		$maxIterations = \max( 1, \min( 50, $maxIterations ) );

		$iteration          = 0;
		$toolResultMessages = array();

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
		$toolDefinitions  = $this->buildAllowedTools( $allowedToolSlugs, $assistantId );

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

		// Status: generating.
		$this->sse->sendEvent(
			'status',
			array(
				'type'    => 'generating',
				'message' => 'Generating response…',
			)
		);

		$onStreamChunk = function ( string $token ) use ( $cancellation ): void {
			// Cooperative cancellation: stop emitting chunks once cancelled.
			if ( null !== $cancellation && $cancellation->isCancelled() ) {
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

		// Rate limit + compression before streaming call.
		if ( null !== $this->rateLimiter ) {
			$key = 'chat:' . $userId . ':' . $assistantId;
			if ( ! $this->rateLimiter->isAllowed( $key, $this->chatRateLimitMax, $this->chatRateLimitWindow ) ) {
				$this->sse->sendEvent( 'error', [ 'code' => 'rate_limited', 'message' => 'Too many requests. Please wait before sending another message. If you believe this is an error, contact the site administrator.' ] );
				$this->sse->sendDone();
				return [
					'response'      => $this->errors->rateLimited( 'Too many requests. Please wait before sending another message. If you believe this is an error, contact the site administrator.' ),
					'tool_results'  => [],
					'iterations'    => 0,
					'cost'          => null,
					'cancelled'     => false,
					'cancel_reason' => '',
				];
			}
			$this->rateLimiter->record( $key, $this->chatRateLimitWindow );
		}

		if ( null !== $cancellation && $cancellation->isCancelled() ) {
			$this->recordSessionEvent(
				SessionLog::TYPE_TURN_STARTED,
				array(
					'assistant_id'   => $assistantId,
					'user_id'        => $userId,
					'max_iterations' => $maxIterations,
				),
				$sessionId,
			);
			foreach ( $messages as $msg ) {
				if ( isset( $msg['role'] ) && 'user' === $msg['role'] ) {
					$this->recordSessionEvent(
						SessionLog::TYPE_USER_MESSAGE,
						array( 'content' => is_string( $msg['content'] ?? null ) ? $msg['content'] : '' ),
						$sessionId,
					);
				}
			}
			$this->recordSessionEvent(
				SessionLog::TYPE_TURN_ENDED,
				array( 'reason' => 'aborted', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
				$sessionId,
			);
			$this->sse->sendEvent( 'error', [ 'code' => 'cancelled', 'message' => 'Request cancelled.' ] );
			$this->sse->sendDone();
			return $this->cancelledResult( array(), $cancellation );
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
		foreach ( $messages as $msg ) {
			if ( isset( $msg['role'] ) && 'user' === $msg['role'] ) {
				$this->recordSessionEvent(
					SessionLog::TYPE_USER_MESSAGE,
					array( 'content' => is_string( $msg['content'] ?? null ) ? $msg['content'] : '' ),
					$sessionId,
				);
			}
		}

		// agent/pre_step policy — reject the turn or rewrite the entering
		// batch; agent/request may replace the call config.
		[ $messages, $preStepRejected ] = $this->applyPreStepPolicy( $messages, $assistantId, 0 );
		if ( $preStepRejected ) {
			$this->recordSessionEvent(
				SessionLog::TYPE_TURN_ENDED,
				array( 'reason' => 'rejected', 'iterations' => 0, 'assistant_id' => $assistantId, 'user_id' => $userId ),
				$sessionId,
			);
			$this->sse->sendEvent( 'status', array( 'type' => 'rejected', 'message' => 'Request rejected by policy.' ) );
			$this->sse->sendDone();
			$this->notifyTurnStopping( $assistantId, 0 );

			return array(
				'response'      => array(),
				'tool_results'  => array(),
				'iterations'    => 0,
				'cost'          => null,
				'cancelled'     => false,
				'cancel_reason' => '',
			);
		}
		$options = $this->applyRequestPolicy( $options, $assistantId, 0 );

		$response = $this->providers->stream(
			$messages,
			$options,
			$assistantConfig,
			$onStreamChunk,
		);
		$this->accumulateUsage( $response, $costAccumulator );

		if ( $this->errors->isError( $response ) ) {
			$normalized = $this->errors->normalize( $response );
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

		$this->recordSessionEvent(
			SessionLog::TYPE_ASSISTANT_MESSAGE,
			array(
				'content'       => $response['choices'][0]['message']['content'] ?? null,
				'tool_calls'    => $this->extractToolCalls( $response ),
				'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
			),
			$sessionId,
		);

		// ─── Streaming agentic loop ──────────────────────────────────

		$cancelled = false;
		$stepResponseLogged = false; // The initial response is logged above.

		while ( $iteration < $maxIterations ) {
			if ( null !== $cancellation && $cancellation->isCancelled() ) {
				$cancelled = true;
				break;
			}

			if ( $stepResponseLogged ) {
				// Log the continuation response that opened this step before
				// the loop can exit.
				$this->recordSessionEvent(
					SessionLog::TYPE_ASSISTANT_MESSAGE,
					array(
						'content'       => $response['choices'][0]['message']['content'] ?? null,
						'tool_calls'    => $this->extractToolCalls( $response ),
						'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
					),
					$sessionId,
				);
			}
			$stepResponseLogged = true;

			$toolCalls = $this->extractToolCalls( $response );
			if ( array() === $toolCalls ) {
				break;
			}

			// Stream tool execution start.
			$this->sse->sendEvent(
				'tool_execution',
				array(
					'type'       => 'start',
					'iteration'  => $iteration,
					'tool_count' => \count( $toolCalls ),
					'tools'      => \array_map( static fn( $tc ) => $tc['function']['name'] ?? 'unknown', $toolCalls ),
				)
			);

			$assistantMsg = $this->extractAssistantMessage( $response );
			if ( null !== $assistantMsg ) {
				$messages[] = $assistantMsg;
			}

			foreach ( $toolCalls as $toolCall ) {
				if ( null !== $cancellation && $cancellation->isCancelled() ) {
					$cancelled = true;
					break;
				}

				$toolName   = $toolCall['function']['name'] ?? '';
				$toolCallId = $toolCall['id'] ?? '';
				$rawArgs    = $toolCall['function']['arguments'] ?? '{}';
				$arguments  = is_string( $rawArgs )
					? ( \json_decode( $rawArgs, true ) ?: array() )
					: ( is_array( $rawArgs ) ? $rawArgs : array() );

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

				$toolStartedAt = \microtime( true );

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

				$toolDurationMs = \max( 0.0, ( \microtime( true ) - $toolStartedAt ) * 1000.0 );
				$toolOutcome    = $this->errors->isError( $result ) ? 'error' : 'success';

				if ( $this->errors->isError( $result ) ) {
					$normalized    = $this->errors->normalize( $result );
					$resultContent = "Error: {$normalized['message']}";
				} elseif ( is_array( $result ) && isset( $result['message'] ) ) {
					$resultContent = is_string( $result['message'] )
						? $result['message']
						: \json_encode( $result );
				} else {
					$resultContent = \json_encode( $result );
				}

				if ( null !== $this->budgetTracker ) {
					$this->budgetTracker->record( \strlen( $resultContent ) );
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
				if ( '' !== $toolName ) {
					$toolMsg['name'] = $toolName;
				}
				$messages[] = $toolMsg;
			}

			if ( $cancelled ) {
				break;
			}

			if ( null !== $cancellation && $cancellation->isCancelled() ) {
				$cancelled = true;
				break;
			}

			// Between-step compaction (Phase 5, R6): budget-driven + logged.
			$messages = $this->maybeCompact( $messages, $options, $iteration + 1, $sessionId );

			// Policy for the continuation step.
			[ $messages, $preStepRejected ] = $this->applyPreStepPolicy( $messages, $assistantId, $iteration + 1 );
			if ( $preStepRejected ) {
				break;
			}
			$options = $this->applyRequestPolicy( $options, $assistantId, $iteration + 1 );

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
			$this->accumulateUsage( $response, $costAccumulator );

			if ( $this->errors->isError( $response ) ) {
				break;
			}

			++$iteration;

			$this->events->dispatch(
				new AgenticIterationComplete(
					iteration: $iteration,
					assistantId: $assistantId,
				)
			);
		}

		$limitReached = $iteration >= $maxIterations;
		if ( $limitReached || $cancelled ) {
			$this->stripOrphanedToolCalls( $response );
			if ( $limitReached ) {
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
				limitReached: $limitReached,
			)
		);

		// Turn-stopping serial notification — the turn is about to close.
		$this->notifyTurnStopping( $assistantId, $iteration );

		// Durable turn boundary with the exit reason.
		$turnEndReason = $cancelled
			? 'aborted'
			: ( $limitReached ? 'iteration_limit' : 'completed' );
		$this->recordSessionEvent(
			SessionLog::TYPE_TURN_ENDED,
			array( 'reason' => $turnEndReason, 'iterations' => $iteration, 'assistant_id' => $assistantId, 'user_id' => $userId ),
			$sessionId,
		);

		$cost = null;
		if ( \is_array( $response ) ) {
			$cost = $this->costs->calculateFromResponse(
				$response,
				$options['provider'],
				$options['model'],
			);

			if ( null !== $cost
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
				response: \is_array( $response ) ? $response : array(),
			)
		);

		$payload = array(
			'assistant_id' => $assistantId,
			'data'         => $response,
			'tool_results' => $toolResultMessages,
			'cost'         => $cost,
		);

		$this->sse->sendEvent( 'message', $payload );
		$this->sse->sendDone();

		return array(
			'response'      => $response,
			'tool_results'  => $toolResultMessages,
			'iterations'    => $iteration,
			'cost'          => $cost,
			'cancelled'     => $cancelled,
			'cancel_reason' => $cancelled ? ( $cancellation?->reason() ?? '' ) : '',
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
	 */
	private function buildAllowedTools( array $allowedSlugs, int $assistantId = 0 ): array {
		if ( array() === $allowedSlugs ) {
			return array();
		}

		$definitions = array();
		$resolver    = $this->toolResolver ?? $this->tools;

		foreach ( $allowedSlugs as $slug ) {
			if ( ! \is_string( $slug ) || '' === $slug ) {
				continue;
			}

			$tool = $resolver->get( $slug );
			if ( null === $tool ) {
				// Fail loud, never silently: misconfiguration must surface.
				$this->events->dispatch(
					new UnresolvedToolRequested(
						slug: $slug,
						assistantId: $assistantId,
					)
				);
				continue;
			}

			$definitions[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $slug,
					'description' => $tool->getDescription(),
					'parameters'  => self::normalizeToolSchema( $tool->getParametersSchema() ),
				),
			);
		}

		return $definitions;
	}

	/**
	 * Normalize a tool parameter schema into a valid JSON Schema object.
	 *
	 * Providers (DeepSeek in particular) validate tool schemas strictly and
	 * reject empty arrays with errors like "[] is not of type 'object'".
	 * Any schema that is empty, or that lacks the object root, is converted
	 * to an open-object schema so the payload always serializes as an object.
	 */
	private static function normalizeToolSchema( array $schema ): array {
		if ( array() === $schema ) {
			return array(
				'type'       => 'object',
				'properties' => array(),
			);
		}

		if ( ! isset( $schema['type'] ) ) {
			// A bare property map without the object root — wrap it.
			$schema = array(
				'type'       => 'object',
				'properties' => $schema,
			);
		}

		if ( ! isset( $schema['properties'] ) || ! \is_array( $schema['properties'] ) ) {
			$schema['properties'] = array();
		}

		return $schema;
	}

	/**
	 * Extract tool calls from an LLM response.
	 *
	 * @return array  Array of { id, function: { name, arguments } }
	 */
	private function extractToolCalls( array $response ): array {
		// Direct tool_calls key.
		if ( isset( $response['tool_calls'] ) && is_array( $response['tool_calls'] ) ) {
			return $response['tool_calls'];
		}

		// OpenAI format: choices[0].message.tool_calls.
		if ( isset( $response['choices'][0]['message']['tool_calls'] ) ) {
			$calls = $response['choices'][0]['message']['tool_calls'];
			return is_array( $calls ) ? $calls : array();
		}

		return array();
	}

	/**
	 * Extract the assistant message from an LLM response.
	 */
	private function extractAssistantMessage( array $response ): ?array {
		if ( isset( $response['choices'][0]['message'] ) ) {
			return $response['choices'][0]['message'];
		}

		if ( isset( $response['role'] ) && 'assistant' === $response['role'] ) {
			return $response;
		}

		return null;
	}

	private static function newCostAccumulator(): array {
		return array(
			'total_prompt_tokens'     => 0,
			'total_completion_tokens' => 0,
			'cost_usd'                => 0.0,
		);
	}

	/**
	 * Accumulate token usage from one provider response.
	 *
	 * Error responses (WP_Error, DomainError, etc.) contribute nothing.
	 *
	 * @param mixed $response    Provider response array or error object.
	 * @param array $accumulator Mutable accumulator from newCostAccumulator().
	 */
	private function accumulateUsage( mixed $response, array &$accumulator ): void {
		if ( ! is_array( $response ) ) {
			return;
		}

		$usage = $response['usage'] ?? array();
		if ( ! is_array( $usage ) ) {
			return;
		}

		$accumulator['total_prompt_tokens']     += (int) ( $usage['prompt_tokens'] ?? 0 );
		$accumulator['total_completion_tokens'] += (int) ( $usage['completion_tokens'] ?? 0 );
		// cost_usd is recomputed by CostCalculator when the final cost is built.
	}

	/**
	 * Run the agent/pre_step waterfall (feature-detected).
	 *
	 * @return array{0: array, 1: bool}  [messages, rejected]
	 */
	private function applyPreStepPolicy( array $messages, int $assistantId, int $iteration ): array {
		if ( ! $this->events instanceof WaterfallEventDispatcherInterface ) {
			return array( $messages, false );
		}

		$decision = $this->events->waterfall(
			'agent/pre_step',
			new AgentPreStep( messages: $messages, assistantId: $assistantId, iteration: $iteration ),
			static function ( object $event ): AgentPreStepDecision {
				return AgentPreStepDecision::enter( $event->messages );
			},
		);

		if ( ! $decision instanceof AgentPreStepDecision ) {
			// A misbehaving listener returned an unexpected type — keep the batch.
			return array( $messages, false );
		}

		if ( AgentPreStepDecision::KIND_REJECT === $decision->kind ) {
			return array( $messages, true );
		}

		return array( $decision->messages, false );
	}

	/**
	 * Run the agent/request waterfall (feature-detected).
	 *
	 * @return array  The (possibly replaced) call options.
	 */
	private function applyRequestPolicy( array $options, int $assistantId, int $iteration ): array {
		if ( ! $this->events instanceof WaterfallEventDispatcherInterface ) {
			return $options;
		}

		$decision = $this->events->waterfall(
			'agent/request',
			new AgentRequest( options: $options, assistantId: $assistantId, iteration: $iteration ),
			static function ( object $event ): AgentRequestDecision {
				return AgentRequestDecision::keep();
			},
		);

		if ( $decision instanceof AgentRequestDecision
			&& AgentRequestDecision::KIND_REPLACE === $decision->kind
			&& is_array( $decision->options )
		) {
			// Merge so listeners can override selectively while the required
			// keys (provider, model, tools) remain present downstream.
			return \array_merge( $options, $decision->options );
		}

		return $options;
	}

	/**
	 * Whether the agent/request_error waterfall owns recovery via retry.
	 */
	private function shouldRetryAfterError( mixed $error, int $assistantId, int $iteration ): bool {
		if ( ! $this->events instanceof WaterfallEventDispatcherInterface ) {
			return false;
		}

		$decision = $this->events->waterfall(
			'agent/request_error',
			new AgentRequestError( error: $error, assistantId: $assistantId, iteration: $iteration ),
			static function ( object $event ): AgentRequestErrorDecision {
				return AgentRequestErrorDecision::terminal();
			},
		);

		return $decision instanceof AgentRequestErrorDecision
			&& AgentRequestErrorDecision::KIND_RETRY === $decision->kind;
	}

	/**
	 * Notify serial listeners that the turn is about to close.
	 */
	private function notifyTurnStopping( int $assistantId, int $totalIterations ): void {
		if ( ! $this->events instanceof WaterfallEventDispatcherInterface ) {
			return;
		}

		$this->events->serial(
			'agent/turn_stopping',
			new AgentTurnStopping( assistantId: $assistantId, totalIterations: $totalIterations ),
		);
	}

	/**
	 * Record one session-log entry when session logging is wired.
	 *
	 * The appended entry is persisted through the optional store when a
	 * session id is known. Persistence failures must never break the chat
	 * (the log is observability, not a correctness dependency).
	 */
	private function recordSessionEvent( string $type, array $data, string $sessionId = '' ): void {
		if ( null === $this->sessionLog ) {
			return;
		}

		$this->sessionLog->append( $type, $data );

		$last = $this->sessionLog->lastEvent();

		if ( null !== $this->sessionLogStore && '' !== $sessionId && null !== $last ) {
			$this->sessionLogStore->append( $sessionId, $last->toArray() );
		}

		// Telemetry single-path (Phase 5, R6): fan the appended entry out
		// to subscribers instead of letting them re-wrap the loop.
		if ( null !== $this->sessionTelemetry && null !== $last ) {
			$this->sessionTelemetry->notify( $last );
		}
	}

	/**
	 * Compact the message list between continuation steps when the
	 * wired provider's budget policy triggers.
	 *
	 * Compaction facts are durable: a context_compacted entry records the
	 * message counts before/after so resumed sessions know their history
	 * was summarized.
	 */
	private function maybeCompact( array $messages, array $options, int $iteration, string $sessionId ): array {
		$provider = $this->compactionProvider;

		// Legacy bridge: the deprecated setContextCompressor() wiring folds
		// into the provider seam so old callers keep working.
		if ( null === $provider && null !== $this->contextCompressor ) {
			$provider = new CompactionProvider( $this->contextCompressor, $this->compressor );
		}

		if ( null === $provider || null === $this->tokenBudget ) {
			return $messages;
		}

		$model = (string) ( $options['model'] ?? '' );
		$limit = $this->tokenBudget->getModelLimit( $model );
		if ( $limit <= 0 ) {
			$limit = 128000;
		}

		if ( ! $provider->shouldCompact( $messages, $limit, $iteration ) ) {
			return $messages;
		}

		$compacted = $provider->compact( $messages, $model );

		$this->recordSessionEvent(
			SessionLog::TYPE_CONTEXT_COMPACTED,
			array(
				'iteration'           => $iteration,
				'message_count_before' => \count( $messages ),
				'message_count_after'  => \count( $compacted ),
			),
			$sessionId,
		);

		return $compacted;
	}

	/**
	 * Build the canonical result payload for a cancelled request.
	 *
	 * @return array{response: array, tool_results: array, iterations: int, cost: null, cancelled: bool, cancel_reason: string}
	 */
	private function cancelledResult( array $response, CancellationToken $cancellation ): array {
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
	 */
	private function stripOrphanedToolCalls( array &$response ): void {
		if ( isset( $response['choices'][0]['message']['tool_calls'] ) ) {
			unset( $response['choices'][0]['message']['tool_calls'] );
		}

		if ( isset( $response['choices'][0]['finish_reason'] )
			&& 'tool_calls' === $response['choices'][0]['finish_reason']
		) {
			$response['choices'][0]['finish_reason'] = 'stop';
		}
	}

	/**
	 * Sanitize a tool result for LLM consumption.
	 *
	 * Strips base64 data and truncates very long string values
	 * to prevent token waste from inline binary content.
	 *
	 * @param mixed $result Raw tool result.
	 * @return mixed Sanitized result.
	 */
	private function sanitizeToolResult( $result ) {
		if ( ! \is_array( $result ) ) {
			return $result;
		}

		$stripKeys = array( 'b64_json', 'b64_image', 'base64', 'data', 'inline_data', 'inlineData' );
		foreach ( $stripKeys as $key ) {
			if ( isset( $result[ $key ] ) ) {
				unset( $result[ $key ] );
			}
		}

		foreach ( $result as $key => $value ) {
			if ( \is_string( $value ) && \strlen( $value ) > 10000 ) {
				$result[ $key ] = \substr( $value, 0, 200 ) . '…[truncated ' . \strlen( $value ) . ' bytes]';
			}
		}

		return $result;
	}

	/**
	 * Extract image URLs from a tool result for vision-model injection.
	 *
	 * When a tool returns image URLs, these are injected as user-content
	 * images for vision-capable models on the next LLM turn.
	 *
	 * @param mixed  $result   Raw tool result.
	 * @param string $toolName Tool slug for context.
	 * @return array<int, array> Image messages in OpenAI vision format.
	 */
	private function extractImagesFromToolResult( $result, string $toolName ): array {
		if ( ! \is_array( $result ) ) {
			return array();
		}

		$images    = array();
		$imageKeys = array( 'image_url', 'url', 'download_url', 'image' );

		foreach ( $imageKeys as $key ) {
			if ( ! isset( $result[ $key ] ) || ! \is_string( $result[ $key ] ) ) {
				continue;
			}

			$url = $result[ $key ];

			if ( \preg_match( '/\.(png|jpg|jpeg|gif|webp)(\?|$)/i', $url )
				|| \str_starts_with( $url, 'data:image/' )
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
