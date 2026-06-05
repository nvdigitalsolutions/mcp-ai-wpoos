<?php
/**
 * Generic oOS tool execution job for Craft's Yii Queue.
 *
 * Dispatched by QueueClient::enqueue() — carries a handler class name
 * and serialized payload. The handler is resolved and invoked when
 * the job is processed by the queue runner.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Jobs;

use Craft;
use craft\queue\BaseJob;

class NvoosToolJob extends BaseJob {

	/**
	 * @var string  Fully-qualified handler class name.
	 */
	public string $handler = '';

	/**
	 * @var array<string, mixed>  Serialized payload.
	 */
	public array $payload = array();

	/**
	 * Execute the job.
	 *
	 * Resolves the handler class and invokes it with the payload.
	 * The handler can be any callable, a class with handle(), or __invoke().
	 *
	 * @param \yii\queue\Queue|craft\queue\QueueInterface $queue  The queue instance.
	 */
	public function execute( $queue ): void {
		$handler = $this->handler;
		$payload = $this->payload;

		if ( '' === $handler ) {
			throw new \RuntimeException( 'OOS job requires a handler class name.' );
		}

		if ( ! class_exists( $handler ) ) {
			throw new \RuntimeException( "Handler class [{$handler}] not found." );
		}

		$instance = new $handler();

		if ( is_callable( $instance ) ) {
			call_user_func( $instance, $payload );
			return;
		}

		if ( method_exists( $instance, 'handle' ) ) {
			$instance->handle( $payload );
			return;
		}

		if ( method_exists( $instance, '__invoke' ) ) {
			$instance( $payload );
			return;
		}

		throw new \RuntimeException(
			sprintf(
				'OOS tool handler [%s] must be callable, implement handle(), or __invoke().',
				$handler,
			),
		);
	}

	/**
	 * Default description shown in the Craft control panel queue manager.
	 *
	 * @return string|null
	 */
	protected function defaultDescription(): ?string {
		$handler = $this->handler ?: 'unknown';

		return "oOS Tool: {$handler}";
	}
}
