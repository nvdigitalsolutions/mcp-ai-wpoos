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
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Event\BeforeChatRequest;
use Nvoos\Core\Domain\Event\AfterChatResponse;
use Nvoos\Core\Domain\Event\AgenticIterationComplete;
use Nvoos\Core\Domain\Event\AgenticLoopCompleted;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;

class ChatOrchestrator {

	/**
	 * Maximum agentic loop iterations. Prevents infinite loops.
	 */
	private const DEFAULT_MAX_ITERATIONS = 15;

	public function __construct(
		private readonly ToolRegistry $tools,
		private readonly ProviderRouter $providers,
		private readonly EventDispatcherInterface $events,
		private readonly ErrorFactoryInterface $errors,
		private readonly CostCalculator $costs,
		private readonly SseHandler $sse,
	) {}

	/**
	 * Handle a chat request — non-streaming (returns full response).
	 *
	 * @param array $messages        OpenAI-format conversation messages.
	 * @param array $assistantConfig Assistant configuration (provider, model, tools, etc.).
	 * @param int   $userId          Authenticated user ID (0 = guest).
	 * @param int   $assistantId     Assistant post ID (0 = none).
	 * @param array $options         Additional options (temperature, max_tokens, etc.).
	 *
	 * @return array{
	 *     response: array,
	 *     tool_results: array,
	 *     iterations: int,
	 *     cost: array|null,
	 * }
	 */
	public function handleChat(
		array $messages,
		array $assistantConfig,
		int $userId = 0,
		int $assistantId = 0,
		array $options = array(),
	): array {
		$maxIterations = (int) ( $assistantConfig['max_agentic_iterations'] ?? self::DEFAULT_MAX_ITERATIONS );
		$maxIterations = \max( 1, \min( 50, $maxIterations ) );

		$iteration          = 0;
		$toolResultMessages = array();

		// Build tool definitions for this assistant.
		$allowedToolSlugs = $assistantConfig['tools'] ?? array();
		$toolDefinitions  = $this->buildAllowedTools( $allowedToolSlugs );

		// Always replace tools with the properly-resolved definitions.
		// If no tools resolve (e.g., none are registered in the OOS
		// tool registry), an empty array prevents raw tool slugs from
		// leaking to the provider and causing API errors like
		// "Invalid type for 'tools[0]': expected an object".
		$options['tools'] = $toolDefinitions;

		// Merge assistant config into options.
		$options['provider'] ??= $assistantConfig['provider'] ?? '';
		$options['model']    ??= $assistantConfig['model'] ?? '';

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

		// Initial LLM call.
		$response = $this->providers->chat( $messages, $options, $assistantConfig );

		if ( $this->errors->isError( $response ) ) {
			return array(
				'response'     => $this->errors->normalize( $response ),
				'tool_results' => array(),
				'iterations'   => 0,
				'cost'         => null,
			);
		}

		// ─── Agentic loop ────────────────────────────────────────────

		while ( $iteration < $maxIterations ) {
			$toolCalls = $this->extractToolCalls( $response );

			if ( array() === $toolCalls ) {
				break;
			}

			// Add the assistant message with tool_calls to the conversation.
			$assistantMsg = $this->extractAssistantMessage( $response );
			if ( null !== $assistantMsg ) {
				$messages[] = $assistantMsg;
			}

			// Execute each tool.
			foreach ( $toolCalls as $toolCall ) {
				$toolName   = $toolCall['function']['name'] ?? '';
				$toolCallId = $toolCall['id'] ?? '';
				$rawArgs    = $toolCall['function']['arguments'] ?? '{}';
				$arguments  = is_string( $rawArgs )
					? ( \json_decode( $rawArgs, true ) ?: array() )
					: ( is_array( $rawArgs ) ? $rawArgs : array() );

				$result = $this->tools->execute(
					$toolName,
					$arguments,
					array(
						'user_id'      => $userId,
						'assistant_id' => $assistantId,
						'agentic_loop' => true,
						'iteration'    => $iteration,
					)
				);

				// Normalize errors for LLM consumption.
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

				// Store for frontend display.
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

			// Call LLM again with tool results.
			$response = $this->providers->chat( $messages, $options, $assistantConfig );

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
		if ( $limitReached ) {
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

		// Calculate cost.
		$cost = $this->costs->calculateFromResponse(
			$response,
			$options['provider'],
			$options['model'],
		);

		return array(
			'response'     => $response,
			'tool_results' => $toolResultMessages,
			'iterations'   => $iteration,
			'cost'         => $cost,
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
		$toolDefinitions  = $this->buildAllowedTools( $allowedToolSlugs );

		// Always replace tools with the properly-resolved definitions.
		// If no tools resolve (e.g., none are registered in the OOS
		// tool registry), an empty array prevents raw tool slugs from
		// leaking to the provider and causing API errors like
		// "Invalid type for 'tools[0]': expected an object".
		$options['tools'] = $toolDefinitions;

		$options['provider'] ??= $assistantConfig['provider'] ?? '';
		$options['model']    ??= $assistantConfig['model'] ?? '';

		// Status: generating.
		$this->sse->sendEvent(
			'status',
			array(
				'type'    => 'generating',
				'message' => 'Generating response…',
			)
		);

		$response = $this->providers->chat( $messages, $options, $assistantConfig );

		if ( $this->errors->isError( $response ) ) {
			$normalized = $this->errors->normalize( $response );
			$this->sse->sendEvent(
				'error',
				array(
					'code'    => $normalized['code'],
					'message' => $normalized['message'],
				)
			);
			$this->sse->sendDone();
			return array(
				'response'     => $normalized,
				'tool_results' => array(),
				'iterations'   => 0,
				'cost'         => null,
			);
		}

		// ─── Streaming agentic loop ──────────────────────────────────

		while ( $iteration < $maxIterations ) {
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
				$toolName   = $toolCall['function']['name'] ?? '';
				$toolCallId = $toolCall['id'] ?? '';
				$rawArgs    = $toolCall['function']['arguments'] ?? '{}';
				$arguments  = is_string( $rawArgs )
					? ( \json_decode( $rawArgs, true ) ?: array() )
					: ( is_array( $rawArgs ) ? $rawArgs : array() );

				// Stream: tool started.
				$this->sse->sendEvent(
					'tool_execution',
					array(
						'type'      => 'tool_start',
						'tool_name' => $toolName,
						'tool_id'   => $toolCallId,
					)
				);

				$result = $this->tools->execute(
					$toolName,
					$arguments,
					array(
						'user_id'      => $userId,
						'assistant_id' => $assistantId,
						'agentic_loop' => true,
						'iteration'    => $iteration,
					)
				);

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

			// Status: analyzing results.
			$this->sse->sendEvent(
				'status',
				array(
					'type'    => 'thinking',
					'message' => 'Analyzing tool results…',
				)
			);

			$response = $this->providers->chat( $messages, $options, $assistantConfig );

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
		if ( $limitReached ) {
			$this->stripOrphanedToolCalls( $response );
			$this->sse->sendEvent(
				'status',
				array(
					'type'    => 'max_iterations',
					'message' => 'Reached maximum tool execution iterations.',
				)
			);
		}

		$this->events->dispatch(
			new AgenticLoopCompleted(
				totalIterations: $iteration,
				assistantId: $assistantId,
				toolResults: $toolResultMessages,
				limitReached: $limitReached,
			)
		);

		$cost = $this->costs->calculateFromResponse(
			$response,
			$options['provider'],
			$options['model'],
		);

		$payload = array(
			'assistant_id' => $assistantId,
			'data'         => $response,
			'tool_results' => $toolResultMessages,
			'cost'         => $cost,
		);

		// Simulate streaming text chunks.
		$text = $this->extractTextContent( $response );
		if ( '' !== $text ) {
			$this->sse->streamChunks(
				$text,
				function ( string $chunk ) {
					return array( 'choices' => array( array( 'delta' => array( 'content' => $chunk ) ) ) );
				}
			);
		}

		$this->sse->sendEvent( 'message', $payload );
		$this->sse->sendDone();

		return array(
			'response'     => $response,
			'tool_results' => $toolResultMessages,
			'iterations'   => $iteration,
			'cost'         => $cost,
		);
	}

	// ─── Helpers ──────────────────────────────────────────────────────

	/**
	 * Build tool definitions for only the allowed tools.
	 */
	private function buildAllowedTools( array $allowedSlugs ): array {
		if ( array() === $allowedSlugs ) {
			return array();
		}

		$definitions = array();

		foreach ( $allowedSlugs as $slug ) {
			$tool = $this->tools->get( $slug );
			if ( null === $tool ) {
				continue;
			}

			$definitions[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $slug,
					'description' => $tool->getDescription(),
					'parameters'  => $tool->getParametersSchema(),
				),
			);
		}

		return $definitions;
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

	/**
	 * Extract plain text content from a response.
	 */
	private function extractTextContent( array $response ): string {
		$content = $response['choices'][0]['message']['content'] ?? $response['content'] ?? '';

		return is_string( $content ) ? $content : '';
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
}
