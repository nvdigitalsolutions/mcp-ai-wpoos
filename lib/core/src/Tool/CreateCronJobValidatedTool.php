<?php
/**
 * Create Cron Job (Validated) tool — validated variant.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Create_Cron_Job_Validated.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class CreateCronJobValidatedTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly QueueClientInterface $queue,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_cron_job_validated';
	}

	public function getName(): string {
		return 'Create Cron Job (Validated)';
	}

	public function getDescription(): string {
		return 'Schedules a background task with validated arguments.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'hook'      => array(
					'type'        => 'string',
					'description' => 'The action hook or handler ID to schedule.',
					'minLength'   => 1,
				),
				'timestamp' => array(
					'type'        => 'integer',
					'description' => 'Unix timestamp for when the task should first run. Defaults to 20 seconds from now.',
					'minimum'     => 0,
				),
				'schedule'  => array(
					'type'        => 'string',
					'description' => 'Recurrence schedule. Use "single" for a one-off task, or an interval like "hourly", "daily", "twicedaily", or a cron expression.',
				),
				'args'      => array(
					'type'        => 'array',
					'description' => 'Optional arguments passed to the handler when it runs.',
					'default'     => array(),
				),
			),
			'required'             => array( 'hook' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$hook = $this->stringParam( $arguments, 'hook' );

		if ( '' === $hook ) {
			return $this->errors->validationFailed(
				'A valid hook name is required.',
				array( 'hook' => array( 'A hook name is required to schedule a task.' ) ),
			);
		}

		$userId = $context['user_id'] ?? 0;

		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in to schedule tasks.' );
		}

		$timestamp = $this->intParam( $arguments, 'timestamp' );
		$schedule  = $this->stringParam( $arguments, 'schedule', 'single' );
		$args      = $this->arrayParam( $arguments, 'args' );

		if ( '' === $schedule ) {
			$schedule = 'single';
		}

		$payload = array(
			'hook'    => $hook,
			'args'    => $args,
			'user_id' => $userId,
		);

		try {
			if ( 'single' === $schedule ) {
				$delaySeconds = 0;
				if ( $timestamp > 0 ) {
					$now          = \time();
					$delaySeconds = \max( 0, $timestamp - $now );
				}

				$options = array();
				if ( $delaySeconds > 0 ) {
					$options['delay_seconds'] = $delaySeconds;
				}

				$jobId = $this->queue->enqueue( $hook, $payload, $options );

				return $this->success(
					'Task scheduled successfully.',
					array(
						'job_id'    => $jobId,
						'hook'      => $hook,
						'schedule'  => 'single',
						'timestamp' => $timestamp > 0 ? $timestamp : \time(),
						'args'      => $args,
					),
				);
			}

			$scheduleId = $this->queue->schedule( $hook, $payload, $schedule );

			return $this->success(
				'Recurring task scheduled successfully.',
				array(
					'schedule_id' => $scheduleId,
					'hook'        => $hook,
					'schedule'    => $schedule,
					'args'        => $args,
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'schedule_failed',
				"Failed to schedule task: {$e->getMessage()}",
			);
		}
	}
}
