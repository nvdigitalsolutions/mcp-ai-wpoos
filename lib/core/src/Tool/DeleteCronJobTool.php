<?php
/**
 * Delete Cron Job tool — removes a scheduled background task.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Delete_Cron_Job.
 * Uses QueueClientInterface for cancellation.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class DeleteCronJobTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'delete_cron_job';
	}

	public function getName(): string {
		return 'Delete Cron Job';
	}

	public function getDescription(): string {
		return 'Deletes a scheduled background task and removes it from the system.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'job_id' => array(
					'type'        => 'string',
					'description' => 'The unique identifier of the task to delete.',
					'minLength'   => 1,
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
				'A valid job ID is required.',
				array( 'job_id' => array( 'Job ID is required.' ) ),
			);
		}

		$userId = $context['user_id'] ?? 0;

		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in to manage tasks.' );
		}

		// Check the job exists by fetching its status.
		try {
			$status = $this->queue->getStatus( $jobId );
		} catch ( \Throwable $e ) {
			return $this->errors->notFound( 'The specified task was not found.' );
		}

		$cancelled = $this->queue->cancel( $jobId );

		if ( ! $cancelled ) {
			return $this->errors->create(
				'delete_failed',
				'Failed to delete the task. It may have already completed or been removed.',
			);
		}

		return $this->success(
			'Task deleted successfully.',
			array(
				'job_id' => $jobId,
				'status' => $status->status,
			),
		);
	}
}
