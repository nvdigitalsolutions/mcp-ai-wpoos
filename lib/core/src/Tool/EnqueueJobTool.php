<?php
/**
 * Enqueue Job tool — dispatches an async job to the queue.
 *
 * Uses QueueClientInterface — framework-agnostic. Adapters map this
 * to Action Scheduler (WordPress), Laravel Queues, or Yii Queue.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class EnqueueJobTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'enqueue_job';
	}

	public function getName(): string {
		return 'Enqueue Job';
	}

	public function getDescription(): string {
		return 'Dispatches an asynchronous background job. Useful for offloading long-running tasks like content processing, batch operations, or external API calls.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'handler' => array(
					'type'        => 'string',
					'description' => 'The job handler identifier (e.g., wp_mcp_ai_process_batch).',
				),
				'payload' => array(
					'type'        => 'object',
					'description' => 'Arbitrary payload data to pass to the job handler.',
				),
				'group'   => array(
					'type'        => 'string',
					'description' => 'Job group for organizing related jobs. Default: wp_mcp_ai.',
					'default'     => 'wp_mcp_ai',
				),
				'unique'  => array(
					'type'        => 'boolean',
					'description' => 'Ensure only one job with this handler+payload exists at a time. Default: false.',
					'default'     => false,
				),
			),
			'required'             => array( 'handler', 'payload' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$handler = $this->stringParam( $arguments, 'handler' );
		if ( '' === $handler ) {
			return $this->errors->validationFailed(
				'handler is required.',
				array( 'handler' => array( 'A job handler name is required.' ) ),
			);
		}

		$payload = $arguments['payload'] ?? array();
		if ( ! is_array( $payload ) ) {
			return $this->errors->validationFailed(
				'payload must be an object.',
				array( 'payload' => array( 'Payload must be a JSON object.' ) ),
			);
		}

		$group  = $this->stringParam( $arguments, 'group', 'wp_mcp_ai' );
		$unique = $this->boolParam( $arguments, 'unique', false );

		$options = array(
			'group'  => $group,
			'unique' => $unique,
		);

		try {
			$jobId = $this->queue->enqueue( $handler, $payload, $options );
		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'queue_failed',
				'Failed to enqueue job: ' . $e->getMessage(),
			);
		}

		return $this->success(
			sprintf( 'Job "%s" enqueued.', $handler ),
			array(
				'job_id'  => $jobId,
				'handler' => $handler,
				'group'   => $group,
				'unique'  => $unique,
			),
		);
	}
}
