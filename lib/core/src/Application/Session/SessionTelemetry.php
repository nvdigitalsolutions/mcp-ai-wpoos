<?php
/**
 * SessionTelemetry — subscriber tap on the append-only session log.
 *
 * The session log is the single source of truth for a conversation
 * (Proposal 029, R6). Telemetry consumers (audit loggers, metric
 * subscribers) subscribe here instead of re-wrapping the chat loop, so
 * tool_result and turn events reach them from the same stream that
 * derives model history — one path, replayable and identical across
 * both chat loops.
 *
 * Listeners are fire-and-forget: a throwing listener is isolated and
 * counted so observability can never break a conversation.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Session;

final class SessionTelemetry {

	/**
	 * @var array<int, callable>
	 */
	private array $listeners = array();

	/**
	 * Number of listener failures isolated so far.
	 */
	private int $failures = 0;

	/**
	 * Subscribe a listener. Signature: function ( SessionEvent $event ): void.
	 */
	public function subscribe( callable $listener ): void {
		$this->listeners[] = $listener;
	}

	/**
	 * Fan out an appended event to every listener.
	 *
	 * Listener exceptions are caught and counted — telemetry must never
	 * break the conversation it observes.
	 */
	public function notify( SessionEvent $event ): void {
		foreach ( $this->listeners as $listener ) {
			try {
				$listener( $event );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Failure isolation is the contract; no rethrow.
				++$this->failures;
			}
		}
	}

	/**
	 * Number of subscribed listeners.
	 */
	public function listenerCount(): int {
		return \count( $this->listeners );
	}

	/**
	 * Number of listener failures isolated so far (tests / health checks).
	 */
	public function failureCount(): int {
		return $this->failures;
	}
}
