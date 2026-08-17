<?php
/**
 * Cooperative cancellation token for long-running orchestration work.
 *
 * PHP has no cooperative concurrency primitives, so cancellation is
 * checked — never enforced — at loop and tool boundaries. A token is
 * cancelled either explicitly (cancel()), by a parent token, by a
 * deadline, or by an optional caller-supplied probe (e.g., a closure
 * that returns connection_aborted()).
 *
 * This mirrors the cooperative-abort model of agent harnesses such as
 * deepseek-harness, where AbortSignal threading is advisory and code
 * observes it between steps.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

use Nvoos\Core\Domain\Error\CancelledException;

final class CancellationToken {

	/**
	 * Reason recorded when the deadline elapsed.
	 */
	public const REASON_DEADLINE = 'deadline_exceeded';

	/**
	 * Optional parent token — cancellation propagates downward.
	 */
	private ?CancellationToken $parent;

	/**
	 * Explicit cancellation reason; empty while active.
	 */
	private string $reason = '';

	/**
	 * Optional deadline as microtime(true); null when unbounded.
	 */
	private ?float $deadline = null;

	/**
	 * Optional probe invoked on every isCancelled() check. Must be
	 * side-effect-free and cheap; exceptions are swallowed (a broken
	 * probe must never crash the request it guards).
	 *
	 * @var callable|null
	 */
	private $probe = null;

	public function __construct( ?CancellationToken $parent = null, ?callable $probe = null ) {
		$this->parent = $parent;
		$this->probe  = $probe;
	}

	/**
	 * Create a token that also expires after the given number of seconds.
	 */
	public static function withDeadline( float $seconds, ?CancellationToken $parent = null, ?callable $probe = null ): self {
		$token           = new self( $parent, $probe );
		$token->deadline = \microtime( true ) + \max( 0.0, $seconds );

		return $token;
	}

	/**
	 * Cancel this token (and therefore all of its children).
	 */
	public function cancel( string $reason = 'cancelled' ): void {
		if ( '' === $this->reason ) {
			$this->reason = '' === $reason ? 'cancelled' : $reason;
		}
	}

	/**
	 * Whether the token (or its parent, or its deadline, or its probe)
	 * has cancelled.
	 */
	public function isCancelled(): bool {
		if ( '' !== $this->reason ) {
			return true;
		}

		if ( null !== $this->deadline && \microtime( true ) > $this->deadline ) {
			return true;
		}

		if ( null !== $this->probe ) {
			try {
				$probeResult = ( $this->probe )();
				if ( true === $probeResult ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				// A failing probe never cancels — fail open on the probe itself.
			}
		}

		return null !== $this->parent && $this->parent->isCancelled();
	}

	/**
	 * The effective cancellation reason.
	 *
	 * Returns '' while active. A deadline expiry reports
	 * REASON_DEADLINE; a probe-triggered cancellation reports 'cancelled';
	 * otherwise the explicit (or inherited) reason is returned.
	 */
	public function reason(): string {
		if ( '' !== $this->reason ) {
			return $this->reason;
		}

		if ( null !== $this->deadline && \microtime( true ) > $this->deadline ) {
			return self::REASON_DEADLINE;
		}

		if ( null !== $this->probe ) {
			try {
				$probeResult = ( $this->probe )();
				if ( true === $probeResult ) {
					return 'cancelled';
				}
			} catch ( \Throwable $e ) {
				// See isCancelled(): a failing probe is treated as active.
			}
		}

		if ( null !== $this->parent ) {
			return $this->parent->reason();
		}

		return '';
	}

	/**
	 * Throw a CancelledException when the token has cancelled.
	 *
	 * @throws CancelledException
	 */
	public function throwIfCancelled(): void {
		if ( $this->isCancelled() ) {
			throw new CancelledException( $this->reason() );
		}
	}
}
