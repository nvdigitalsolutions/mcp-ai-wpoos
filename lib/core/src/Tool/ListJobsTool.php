<?php
/**
 * List Jobs tool — retrieves queued and scheduled background jobs.
 *
 * Uses QueueClientInterface::listJobs(). Framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class ListJobsTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_jobs';
	}

	public function getName(): string {
		return 'List Jobs';
	}

	public function getDescription(): string {
		return 'Lists queued and scheduled background jobs, optionally filtered by status. Returns job ID, handler, status, and timing information.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status' => array(
					'type'        => 'string',
					'description' => 'Filter by status: queued, running, completed, failed, cancelled. Leave empty for all.',
					'enum'        => array( '', 'queued', 'running', 'completed', 'failed', 'cancelled' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => 'Maximum number of jobs to return. Default: 50.',
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$statusFilter = $this->stringParam( $arguments, 'status' );
		$limit        = $this->intParam( $arguments, 'limit', 50 );

		$filters = array();
		if ( '' !== $statusFilter ) {
			$filters['status'] = $statusFilter;
		}

		try {
			$jobs = $this->queue->listJobs( $filters, $limit );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'list_failed', $e->getMessage() );
		}

		$items = array_map( fn( $job ) => $job->jsonSerialize(), $jobs );

		$counts = array(
			'queued'    => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'cancelled' => 0,
		);
		foreach ( $jobs as $job ) {
			if ( isset( $counts[ $job->status ] ) ) {
				$counts[ $job->status ]++;
			}
		}

		return $this->success(
			sprintf( '%d jobs found.', count( $items ) ),
			array(
				'jobs'   => $items,
				'total'  => count( $items ),
				'counts' => $counts,
			),
		);
	}
}
