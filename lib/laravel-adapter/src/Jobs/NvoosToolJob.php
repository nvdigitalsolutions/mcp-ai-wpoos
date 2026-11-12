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

class NvoosToolJob implements ShouldQueue {

	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	/**
	 * Number of attempts before the job is marked failed.
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
	 * Maximum execution time in seconds.
	 *
	 * @var int
	 */
	public int $timeout = 300;

	/**
	 * @param string               $jobId    Stable tracking ID (UUID).
	 * @param string               $handler  Fully-qualified handler class name or registered ID.
	 * @param array<string, mixed> $payload  Serialized payload passed to the handler.
	 */
	public function __construct(
		public readonly string $jobId,
		public readonly string $handler,
		public readonly array $payload,
	) {}

	/**
	 * Execute the job.
	 */
	public function handle( JobStatusStore $statuses ): void {
		$statuses->markRunning( $this->jobId, $this->attempts() );

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
		return now()->addMinutes( 5 );
	}
}
