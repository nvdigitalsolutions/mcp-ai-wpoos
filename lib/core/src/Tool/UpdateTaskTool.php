<?php
/**
 * Update Task tool — partial update of one mcp_ai_task.
 *
 * Separate from `create_task` on purpose. `create_task` is an upsert that
 * requires a title, matching WordPress; this one changes only the arguments it
 * is given, so a model can move a task to `completed` without re-sending the
 * title, description and every other field.
 *
 * The result carries the updated row (WordPress returns only `task_id`); the
 * richer shape matches `create_task` and saves the caller a follow-up read.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\ProjectManagement\TaskRow;
use Nvoos\Core\Tool\ProjectManagement\Vocabulary;

class UpdateTaskTool extends AbstractTool {

	private const TYPE = 'mcp_ai_task';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'update_task';
	}

	public function getName(): string {
		return 'Update Task';
	}

	public function getDescription(): string {
		return 'Updates specific fields of an existing task. Only the fields provided are changed.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task_id'     => array(
					'type'        => 'integer',
					'description' => 'Task ID to update.',
				),
				'title'       => array(
					'type'        => 'string',
					'description' => 'New task title (optional).',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'New task description (optional).',
				),
				'status'      => array(
					'type'        => 'string',
					'description' => 'New task status (optional).',
					'enum'        => Vocabulary::TASK_STATUSES,
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => 'New task priority (optional).',
					'enum'        => Vocabulary::TASK_PRIORITIES,
				),
				'due_date'    => array(
					'type'        => 'string',
					'description' => 'New due date (YYYY-MM-DD) (optional).',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => 'New assigned user ID (optional).',
				),
			),
			'required'   => array( 'task_id' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$task_id = $this->intParam( $arguments, 'task_id' );

		if ( $task_id <= 0 ) {
			return $this->errors->create( 'missing_id', 'Task ID is required.' );
		}

		$existing = $this->content->find( $task_id );

		if ( null === $existing || self::TYPE !== $existing->type ) {
			return $this->errors->notFound( 'Task not found.' );
		}

		if ( array() === $this->providedParams( $arguments, array( 'title', 'description', 'status', 'priority', 'due_date', 'assigned_to' ) ) ) {
			return $this->errors->create( 'nothing_to_update', 'Provide at least one field to update.' );
		}

		$status = $this->stringParam( $arguments, 'status' );
		if ( '' !== $status && ! in_array( $status, Vocabulary::TASK_STATUSES, true ) ) {
			return $this->errors->create( 'invalid_argument', 'Unknown task status.' );
		}

		$priority = $this->stringParam( $arguments, 'priority' );
		if ( '' !== $priority && ! in_array( $priority, Vocabulary::TASK_PRIORITIES, true ) ) {
			return $this->errors->create( 'invalid_argument', 'Unknown task priority.' );
		}

		$due_date = $this->stringParam( $arguments, 'due_date' );
		if ( '' !== $due_date && ! $this->isIsoDate( $due_date ) ) {
			return $this->errors->create( 'invalid_argument', 'due_date must be YYYY-MM-DD.' );
		}

		$meta = array();

		if ( '' !== $status ) {
			$meta['_task_status'] = $status;
		}

		if ( '' !== $priority ) {
			$meta['_task_priority'] = $priority;
		}

		if ( '' !== $due_date ) {
			$meta['_task_due_date'] = $due_date;
		}

		$assigned_to = $this->intParam( $arguments, 'assigned_to' );
		if ( $assigned_to > 0 ) {
			$meta['_task_assigned_to'] = $assigned_to;
		}

		$title       = $this->stringParam( $arguments, 'title' );
		$description = $this->stringParam( $arguments, 'description' );

		try {
			$item = $this->content->update(
				$task_id,
				new UpdateContentCommand(
					userId: isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
					title: '' !== $title ? $title : null,
					content: '' !== $description ? $description : null,
					meta: $meta,
				)
			);
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'update_failed', $e->getMessage() );
		}

		return $this->success(
			sprintf( 'Task updated: %s', $item->title ),
			array(
				'task_id' => $item->id,
				'task'    => TaskRow::from( $item ),
			)
		);
	}
}
