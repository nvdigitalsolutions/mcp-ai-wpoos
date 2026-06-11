<?php
/**
 * Get Job Status tool — retrieves the status of a queued background job.
 *
 * Uses QueueClientInterface — framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class GetJobStatusTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_job_status';
	}

	public function getName(): string {
		return 'Get Job Status';
	}

	public function getDescription(): string {
		return 'Retrieves the current status of a queued background job by its job ID.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'job_id' => array(
					'type'        => 'string',
					'description' => 'The job ID returned by enqueue_job.',
				),
			),
			'required'             => array( 'job_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$jobId = $this->stringParam( $arguments, 'job_id' );
		if ( '' === $jobId ) {
			return $this->errors->validationFailed(
				'job_id is required.',
				array( 'job_id' => array( 'A job ID is required.' ) ),
			);
		}

		try {
			$status = $this->queue->getStatus( $jobId );
		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'queue_failed',
				'Failed to retrieve job status: ' . $e->getMessage(),
			);
		}

		return $this->success(
			sprintf( 'Job "%s" status: %s.', $jobId, $status->status ),
			$status->jsonSerialize(),
		);
	}
}
