<?php
/**
 * List Cron Jobs tool — lists all scheduled background tasks.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_List_Cron_Jobs.
 * Uses QueueClientInterface::listJobs().
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class ListCronJobsTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_cron_jobs';
	}

	public function getName(): string {
		return 'List Cron Jobs';
	}

	public function getDescription(): string {
		return 'Lists all scheduled background tasks with their status and metadata.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum number of jobs to return (1-100). Default: 50.',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 50,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$userId = $context['user_id'] ?? 0;
		$limit  = $this->intParam( $arguments, 'limit', 50 );
		$limit  = \max( 1, \min( 100, $limit ) );

		try {
			$jobs = $this->queue->listJobs( array(), $limit );

			if ( array() === $jobs ) {
				return $this->success(
					'No tasks are currently scheduled.',
					array(
						'jobs'  => array(),
						'count' => 0,
					),
				);
			}

			$formatted = array();

			foreach ( $jobs as $job ) {
				$formatted[] = array(
					'job_id'       => $job->jobId,
					'status'       => $job->status,
					'queued_at'    => $job->queuedAt?->format( 'c' ),
					'started_at'   => $job->startedAt?->format( 'c' ),
					'completed_at' => $job->completedAt?->format( 'c' ),
					'attempts'     => $job->attempts,
					'is_terminal'  => $job->isTerminal(),
				);
			}

			return $this->collection(
				'Found ' . \count( $formatted ) . ' task(s).',
				$formatted,
				\count( $formatted ),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'list_failed',
				"Failed to list tasks: {$e->getMessage()}",
			);
		}
	}
}
