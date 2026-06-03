<?php
/**
 * Schedule Job tool — creates a recurring background job.
 *
 * Uses QueueClientInterface::schedule(). Framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\QueueClientInterface;

class ScheduleJobTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'schedule_job';
	}

	public function getName(): string {
		return 'Schedule Job';
	}

	public function getDescription(): string {
		return 'Schedules a recurring background job using a cron expression (e.g., */5 * * * * for every 5 minutes) or interval keyword (hourly, daily). Returns a schedule ID for tracking.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'handler' => array(
					'type'        => 'string',
					'description' => 'The job handler identifier.',
				),
				'payload' => array(
					'type'        => 'object',
					'description' => 'Arbitrary payload data.',
				),
				'schedule' => array(
					'type'        => 'string',
					'description' => 'Cron expression (e.g., */5 * * * *) or interval keyword (hourly, daily, twicedaily).',
				),
			),
			'required'             => array( 'handler', 'payload', 'schedule' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$handler  = $this->stringParam( $arguments, 'handler' );
		$schedule = $this->stringParam( $arguments, 'schedule' );
		$payload  = $arguments['payload'] ?? array();

		if ( '' === $handler ) {
			return $this->errors->validationFailed(
				'handler is required.',
				array( 'handler' => array( 'A job handler name is required.' ) ),
			);
		}

		if ( '' === $schedule ) {
			return $this->errors->validationFailed(
				'schedule is required.',
				array( 'schedule' => array( 'A cron expression or interval is required.' ) ),
			);
		}

		if ( ! is_array( $payload ) ) {
			return $this->errors->validationFailed(
				'payload must be an object.',
				array( 'payload' => array( 'Payload must be a JSON object.' ) ),
			);
		}

		try {
			$scheduleId = $this->queue->schedule( $handler, $payload, $schedule );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'schedule_failed', $e->getMessage() );
		}

		return $this->success(
			sprintf( 'Job "%s" scheduled (%s).', $handler, $schedule ),
			array(
				'schedule_id' => $scheduleId,
				'handler'     => $handler,
				'schedule'    => $schedule,
			),
		);
	}
}
