<?php
/**
 * Generic oOS tool execution job for Laravel queues.
 *
 * Dispatched by QueueClient::enqueue() — carries a handler class name
 * and serialized payload. The handler is resolved from the container
 * and invoked with the deserialized payload.
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

class NvoosToolJob implements ShouldQueue {

	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	/**
	 * @param string               $handler  Fully-qualified handler class name or registered ID.
	 * @param array<string, mixed> $payload  Serialized payload passed to the handler.
	 */
	public function __construct(
		public readonly string $handler,
		public readonly array $payload,
	) {}

	/**
	 * Execute the job.
	 *
	 * Resolves the handler from the container and invokes it. The handler
	 * is expected to implement NvoosToolHandlerInterface or be callable.
	 */
	public function handle(): void {
		$handlerInstance = app( $this->handler );

		if ( is_callable( $handlerInstance ) ) {
			call_user_func( $handlerInstance, $this->payload );
			return;
		}

		if ( method_exists( $handlerInstance, 'handle' ) ) {
			$handlerInstance->handle( $this->payload );
			return;
		}

		if ( method_exists( $handlerInstance, '__invoke' ) ) {
			$handlerInstance( $this->payload );
			return;
		}

		throw new \RuntimeException(
			sprintf(
				'OOS tool handler [%s] must be callable, implement handle(), or implement __invoke().',
				$this->handler,
			),
		);
	}

	/**
	 * Number of seconds the job can run before timing out.
	 *
	 * Tool executions may take a while — default 5 minutes.
	 */
	public function retryUntil(): \DateTime {
		return now()->addMinutes( 5 );
	}
}
