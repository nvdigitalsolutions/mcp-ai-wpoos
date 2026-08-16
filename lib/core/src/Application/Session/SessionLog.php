<?php
/**
 * SessionLog — append-only, event-sourced conversation log.
 *
 * The log is the single source of truth for a conversation: the LLM
 * message history is DERIVED via deriveMessages(), never stored
 * separately. This enables fork/resume, deterministic replay, audit
 * projections, and the model-visible-implies-logged invariant.
 *
 * Logged entry types (mirroring deepseek-harness SessionEventMap):
 *   turn_started, turn_ended, user_message, assistant_message,
 *   tool_call, tool_result, steering_message.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Session;

final class SessionLog {

	public const TYPE_TURN_STARTED     = 'turn_started';
	public const TYPE_TURN_ENDED       = 'turn_ended';
	public const TYPE_USER_MESSAGE     = 'user_message';
	public const TYPE_ASSISTANT_MESSAGE = 'assistant_message';
	public const TYPE_TOOL_CALL        = 'tool_call';
	public const TYPE_TOOL_RESULT      = 'tool_result';
	public const TYPE_STEERING_MESSAGE = 'steering_message';
	public const TYPE_CONTEXT_COMPACTED = 'context_compacted';

	/** @var SessionEvent[] */
	private array $events = array();

	private int $seq = 0;

	/**
	 * Append a typed entry and return its sequence number.
	 *
	 * @param array $data Type-specific payload.
	 * @return int  The entry's monotonic seq (1-based).
	 */
	public function append( string $type, array $data, array $sourceEventSeqs = array() ): int {
		$this->events[] = new SessionEvent(
			seq: ++$this->seq,
			time: \microtime( true ),
			type: $type,
			data: $data,
			sourceEventSeqs: $sourceEventSeqs,
		);

		return $this->seq;
	}

	/**
	 * All entries in append order.
	 *
	 * @return SessionEvent[]
	 */
	public function events(): array {
		return $this->events;
	}

	public function count(): int {
		return \count( $this->events );
	}

	/**
	 * The most recently appended entry, if any.
	 */
	public function lastEvent(): ?SessionEvent {
		return \count( $this->events ) > 0
			? $this->events[ \count( $this->events ) - 1 ]
			: null;
	}

	/**
	 * Derive the model-facing message history from the log.
	 *
	 * Projection rules:
	 *  - an optional system prompt is emitted first when present;
	 *  - user_message → user-role message;
	 *  - assistant_message → assistant-role message; when the entry
	 *    carries tool_calls they are attached verbatim;
	 *  - tool_result → tool-role message keyed by tool_call_id;
	 *  - steering_message → user-role message (steering is model-visible);
	 *  - turn/tool-call bookkeeping entries produce no messages.
	 *
	 * @param string[] $systemPrompt Optional system prompt lines.
	 * @return array<int, array>  OpenAI-format message list.
	 */
	public function deriveMessages( array $systemPrompt = array() ): array {
		$messages = array();

		if ( array() !== $systemPrompt ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => \implode( "\n", $systemPrompt ),
			);
		}

		foreach ( $this->events as $event ) {
			switch ( $event->type ) {
				case self::TYPE_USER_MESSAGE:
					$messages[] = array(
						'role'    => 'user',
						'content' => (string) ( $event->data['content'] ?? '' ),
					);
					break;

				case self::TYPE_ASSISTANT_MESSAGE:
					$message = array(
						'role'    => 'assistant',
						'content' => $event->data['content'] ?? null,
					);

					if ( ! empty( $event->data['tool_calls'] ) && \is_array( $event->data['tool_calls'] ) ) {
						$message['tool_calls'] = $event->data['tool_calls'];
					}

					$messages[] = $message;
					break;

				case self::TYPE_TOOL_RESULT:
					$toolMessage = array(
						'role'         => 'tool',
						'content'      => (string) ( $event->data['content'] ?? '' ),
						'tool_call_id' => (string) ( $event->data['tool_call_id'] ?? '' ),
					);

					if ( ! empty( $event->data['name'] ) ) {
						$toolMessage['name'] = (string) $event->data['name'];
					}

					$messages[] = $toolMessage;
					break;

				case self::TYPE_STEERING_MESSAGE:
					$messages[] = array(
						'role'    => 'user',
						'content' => (string) ( $event->data['content'] ?? '' ),
					);
					break;

				default:
					// Bookkeeping entries (turn boundaries, tool calls)
					// produce no model-visible messages.
					break;
			}
		}

		return $messages;
	}

	/**
	 * Export every entry as plain arrays (JSON-safe).
	 *
	 * @return array<int, array>
	 */
	public function export(): array {
		return \array_map(
			static function ( SessionEvent $event ): array {
				return $event->toArray();
			},
			$this->events,
		);
	}

	/**
	 * Rebuild a log from exported arrays (resume/replay).
	 */
	public static function fromExported( array $exported ): self {
		$log = new self();

		foreach ( $exported as $raw ) {
			if ( ! \is_array( $raw ) ) {
				continue;
			}

			$log->events[] = SessionEvent::fromArray( $raw );
			$log->seq      = \max( $log->seq, (int) ( $raw['seq'] ?? 0 ) );
		}

		return $log;
	}
}
