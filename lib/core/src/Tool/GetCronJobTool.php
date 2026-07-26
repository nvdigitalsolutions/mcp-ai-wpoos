<?php
/**
 * Get Cron Job tool — retrieves details of a specific scheduled task.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Get_Cron_Job.
 * Uses QueueClientInterface::getStatus().
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class GetCronJobTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_cron_job';
	}

	public function getName(): string {
		return 'Get Cron Job';
	}

	public function getDescription(): string {
		return 'Retrieves detailed information about a specific scheduled task by its job ID.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'job_id' => array(
					'type'        => 'string',
					'description' => 'The unique identifier of the task to retrieve.',
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
			return $this->errors->forbidden( 'You must be logged in to view tasks.' );
		}

		try {
			$status = $this->queue->getStatus( $jobId );

			return $this->success(
				'Task retrieved.',
				array(
					'job_id'       => $status->jobId,
					'status'       => $status->status,
					'result'       => $status->result,
					'error'        => $status->error,
					'queued_at'    => $status->queuedAt?->format( 'c' ),
					'started_at'   => $status->startedAt?->format( 'c' ),
					'completed_at' => $status->completedAt?->format( 'c' ),
					'attempts'     => $status->attempts,
					'is_terminal'  => $status->isTerminal(),
					'is_running'   => $status->isRunning(),
					'is_successful' => $status->isSuccessful(),
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->notFound( 'The specified task was not found.' );
		}
	}
}
