<?php
/**
 * Cancel Job tool — cancels a queued background job.
 *
 * Uses QueueClientInterface — framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\QueueClientInterface;

class CancelJobTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'cancel_job';
	}

	public function getName(): string {
		return 'Cancel Job';
	}

	public function getDescription(): string {
		return 'Cancels a queued or scheduled background job by its job ID. Returns true if the job was found and cancelled.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'job_id' => array(
					'type'        => 'string',
					'description' => 'The job ID to cancel.',
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
			$cancelled = $this->queue->cancel( $jobId );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'cancel_failed', $e->getMessage() );
		}

		return $this->success(
			$cancelled ? sprintf( 'Job "%s" cancelled.', $jobId ) : sprintf( 'Job "%s" not found.', $jobId ),
			array(
				'job_id'    => $jobId,
				'cancelled' => $cancelled,
			),
		);
	}
}
