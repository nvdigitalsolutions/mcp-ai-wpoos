<?php
/**
 * Unschedule Job tool — removes a recurring job schedule.
 *
 * Uses QueueClientInterface::unschedule(). Framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class UnscheduleJobTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'unschedule_job';
	}

	public function getName(): string {
		return 'Unschedule Job';
	}

	public function getDescription(): string {
		return 'Removes a recurring job schedule by its schedule ID. The job will no longer run on its scheduled interval.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schedule_id' => array(
					'type'        => 'string',
					'description' => 'The schedule ID returned by schedule_job.',
				),
			),
			'required'             => array( 'schedule_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$scheduleId = $this->stringParam( $arguments, 'schedule_id' );
		if ( '' === $scheduleId ) {
			return $this->errors->validationFailed(
				'schedule_id is required.',
				array( 'schedule_id' => array( 'A schedule ID is required.' ) ),
			);
		}

		try {
			$this->queue->unschedule( $scheduleId );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'unschedule_failed', $e->getMessage() );
		}

		return $this->success(
			sprintf( 'Schedule "%s" removed.', $scheduleId ),
			array(
				'schedule_id' => $scheduleId,
				'removed'     => true,
			),
		);
	}
}
