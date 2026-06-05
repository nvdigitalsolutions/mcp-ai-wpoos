<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Chat;

use NvoosGraphifyAi\Plugin;
use NvoosGraphifyAi\Contracts\ProviderClient;

/**
 * Chat orchestration service.
 *
 * Manages the tool-calling loop: sends user messages to the AI provider,
 * handles tool-call responses, executes tools via the core registry,
 * and assembles the final response.
 *
 * @since 1.0.0
 */
class ChatService {

	/** Maximum tool-calling iterations to prevent infinite loops. */
	private const MAX_ITERATIONS = 5;

	/**
	 * Process a chat request through the tool-calling loop.
	 *
	 * @param array                $messages  The conversation history.
	 * @param string|null          $provider  Optional provider slug override.
	 * @param callable|string|null $callback Optional streaming callback or SSE output function.
	 * @return array|\WP_Error The final response array.
	 */
	public static function process( array $messages, ?string $provider = null, $callback = null ) {
		$registry = Plugin::instance()->getProviderRegistry();
		$client   = $provider
			? $registry->get( $provider )
			: $registry->getDefault();

		if ( ! $client instanceof ProviderClient ) {
			return new \WP_Error(
				'nvoos_graphify_ai_no_provider',
				__( 'No AI provider is available. Please configure an API key in Settings.', 'nvoos-graphify-ai' )
			);
		}

		$coreRegistry = nvoos_graphify_get_tool_registry();
		$tools        = self::buildToolDefinitions( $coreRegistry );
		$options      = array(
			'tools' => $tools,
		);

		$iteration  = 0;
		$finalUsage = array();

		while ( $iteration < self::MAX_ITERATIONS ) {
			++$iteration;

			// Choose streaming or non-streaming based on callback presence.
			if ( is_callable( $callback ) ) {
				$result = $client->stream( $messages, $options, $callback );
			} elseif ( is_string( $callback ) ) {
				// SSE output function name — call it per chunk.
				$streamCb = static function ( string $chunk, bool $done ) use ( $callback ): void {
					if ( function_exists( $callback ) ) {
						$callback( $chunk, $done );
					}
				};
				$result   = $client->stream( $messages, $options, $streamCb );
			} else {
				$result = $client->chat( $messages, $options );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Accumulate usage.
			if ( ! empty( $result['usage'] ) ) {
				$finalUsage = $result['usage'];
			}

			// If the model returned a final text response, we're done.
			$hasContent   = ! empty( $result['content'] );
			$hasToolCalls = ! empty( $result['tool_calls'] );

			if ( ! $hasToolCalls ) {
				return array(
					'content' => $result['content'] ?? '',
					'usage'   => $finalUsage,
					'model'   => $result['model'] ?? '',
				);
			}

			// ─── Tool-calling loop ──────────────────────────────────
			$assistantMsg = array(
				'role'       => 'assistant',
				'content'    => $result['content'] ?? null,
				'tool_calls' => $result['tool_calls'],
			);
			$messages[]   = $assistantMsg;

			// Execute each tool call and append the result.
			foreach ( $result['tool_calls'] as $toolCall ) {
				$toolName = $toolCall['function']['name'] ?? '';
				$toolArgs = array();

				if ( ! empty( $toolCall['function']['arguments'] ) && is_string( $toolCall['function']['arguments'] ) ) {
					$decoded  = json_decode( $toolCall['function']['arguments'], true );
					$toolArgs = is_array( $decoded ) ? $decoded : array();
				}

				$tool = $coreRegistry->get( $toolName );

				if ( null === $tool ) {
					$toolResult = sprintf(
						/* translators: %s: tool name */
						__( 'Tool not found: %s', 'nvoos-graphify-ai' ),
						$toolName
					);
				} else {
					$execResult = $tool->execute( $toolArgs, array() );
					if ( is_wp_error( $execResult ) ) {
						$toolResult = $execResult->get_error_message();
					} elseif ( is_array( $execResult ) ) {
						$toolResult = wp_json_encode( $execResult );
					} else {
						$toolResult = (string) $execResult;
					}
				}

				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $toolCall['id'] ?? '',
					'content'      => $toolResult,
				);
			}
		}

		// Max iterations reached — return the last content we have.
		return array(
			'content' => $result['content'] ?? '',
			'usage'   => $finalUsage,
			'model'   => $result['model'] ?? '',
			'warning' => __( 'Max tool-calling iterations reached.', 'nvoos-graphify-ai' ),
		);
	}

	/**
	 * Continue a chat via Action Scheduler (async).
	 *
	 * Hooked to `nvoos_graphify_ai/continue_chat`.
	 *
	 * @param array  $messages Conversation history.
	 * @param string $provider Provider slug.
	 * @return void
	 */
	public static function continueChat( array $messages, string $provider = '' ): void {
		self::process( $messages, $provider ?: null );
	}

	/**
	 * Build OpenAI-compatible tool definitions from the core tool registry.
	 *
	 * @param \NvoosGraphify\ToolRegistry $registry The core tool registry.
	 * @return array Tool definition arrays.
	 */
	private static function buildToolDefinitions( \NvoosGraphify\ToolRegistry $registry ): array {
		$definitions = array();

		foreach ( $registry->all() as $tool ) {
			$schema = $tool->getParametersSchema();

			$definitions[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool->getSlug(),
					'description' => $tool->getDescription(),
					'parameters'  => ! empty( $schema ) ? $schema : array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
				),
			);
		}

		return $definitions;
	}
}
