<?php
/**
 * Generic oOS tool execution job for Laravel queues.
 *
 * Dispatched by QueueClient::enqueue() — carries a stable job ID, a
 * handler class name and a serialized payload. The handler is resolved
 * from the container and invoked with the deserialized payload, while
 * the job lifecycle writes durable status rows.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Core\Domain\Error\NonRetryableJobException;

class NvoosToolJob implements ShouldQueue {

	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	/**
	 * Number of attempts before the job is marked failed.
	 *
	 * Overridable per dispatch: a job that polls a slow provider needs many
	 * short attempts, where an ordinary tool needs three.
	 *
	 * @var int
	 */
	public int $tries = 3;

	/**
	 * Backoff seconds between attempts.
	 *
	 * @var array<int, int>
	 */
	public array $backoff = array( 5, 15, 30 );

	/**
	 * Maximum execution time in seconds for one attempt.
	 *
	 * @var int
	 */
	public int $timeout = 300;

	/**
	 * How long the job may keep retrying, in minutes.
	 *
	 * @var int
	 */
	public int $retryUntilMinutes = 5;

	/**
	 * @param string               $jobId    Stable tracking ID (UUID).
	 * @param string               $handler  Fully-qualified handler class name or registered ID.
	 * @param array<string, mixed> $payload  Serialized payload passed to the handler.
	 * @param int|null             $tries    Attempt budget; null keeps the default.
	 * @param array<int, int>|null $backoff  Seconds between attempts; null keeps the default.
	 * @param int|null             $timeout  Per-attempt ceiling in seconds; null keeps the default.
	 * @param int|null             $retryUntilMinutes Retry window in minutes; null keeps the default.
	 */
	public function __construct(
		public readonly string $jobId,
		public readonly string $handler,
		public readonly array $payload,
		?int $tries = null,
		?array $backoff = null,
		?int $timeout = null,
		?int $retryUntilMinutes = null,
	) {
		if ( null !== $tries ) {
			$this->tries = max( 1, $tries );
		}

		if ( null !== $backoff && array() !== $backoff ) {
			$this->backoff = array_map( 'intval', $backoff );
		}

		if ( null !== $timeout ) {
			$this->timeout = max( 1, $timeout );
		}

		if ( null !== $retryUntilMinutes ) {
			$this->retryUntilMinutes = max( 1, $retryUntilMinutes );
		}
	}

	/**
	 * Execute the job.
	 */
	public function handle( JobStatusStore $statuses ): void {
		$statuses->markRunning( $this->jobId, $this->attempts() );

		try {
			$handlerInstance = app( $this->handler );

			if ( is_callable( $handlerInstance ) ) {
				$result = $handlerInstance( $this->payload );
			} elseif ( method_exists( $handlerInstance, 'handle' ) ) {
				$result = $handlerInstance->handle( $this->payload );
			} elseif ( method_exists( $handlerInstance, '__invoke' ) ) {
				$result = $handlerInstance( $this->payload );
			} else {
				throw new \RuntimeException(
					sprintf(
						'OOS tool handler [%s] must be callable, implement handle(), or implement __invoke().',
						$this->handler,
					),
				);
			}
		} catch ( NonRetryableJobException $e ) {
			// The handler reported a terminal failure, so another attempt would
			// repeat work that already finished (and may have cost money).
			// `fail()` marks the job and invokes `failed()`, which records why.
			$this->fail( $e );

			return;
		}

		$statuses->markCompleted( $this->jobId, is_array( $result ) ? $result : null );
	}

	/**
	 * Handle a terminal job failure.
	 */
	public function failed( \Throwable $exception ): void {
		app( JobStatusStore::class )->markFailed( $this->jobId, $exception );
	}

	/**
	 * Number of seconds the job can run before timing out.
	 */
	public function retryUntil(): \DateTime {
		return now()->addMinutes( $this->retryUntilMinutes );
	}
}
