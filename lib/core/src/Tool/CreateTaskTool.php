<?php
/**
 * Create Task tool — writes the mcp_ai_task family through the content port.
 *
 * Mirrors the WordPress `create_task` contract: one slug that creates, and
 * updates instead when `task_id` is supplied. Same parameters, same result
 * fields, so a model that learned the tool on one runtime keeps working.
 *
 * Like the read path, every field lives in `_task_*` meta; the projection back
 * to the wire shape is `ProjectManagement\TaskRow`, shared with `list_tasks`.
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
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\ProjectManagement\TaskRow;
use Nvoos\Core\Tool\ProjectManagement\Vocabulary;

class CreateTaskTool extends AbstractTool {

	/** Content type this tool writes. */
	private const TYPE = 'mcp_ai_task';


	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_task';
	}

	public function getName(): string {
		return 'Create Task';
	}

	public function getDescription(): string {
		return 'Creates a task, or updates an existing task when task_id is provided.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task_id'          => array(
					'type'        => 'integer',
					'description' => 'Optional task ID. When provided, updates the existing task instead of creating one.',
				),
				'title'            => array(
					'type'        => 'string',
					'description' => 'Task title.',
				),
				'description'      => array(
					'type'        => 'string',
					'description' => 'Task description (optional).',
				),
				'project_id'       => array(
					'type'        => 'integer',
					'description' => 'ID of the project this task belongs to (optional).',
				),
				'status'           => array(
					'type'        => 'string',
					'description' => 'Task status (optional).',
					'enum'        => Vocabulary::TASK_STATUSES,
				),
				'priority'         => array(
					'type'        => 'string',
					'description' => 'Task priority (optional).',
					'enum'        => Vocabulary::TASK_PRIORITIES,
				),
				'due_date'         => array(
					'type'        => 'string',
					'description' => 'Task due date in ISO 8601 format (YYYY-MM-DD) (optional).',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to'      => array(
					'type'        => 'integer',
					'description' => 'User ID this task is assigned to (optional).',
				),
				'category'         => array(
					'type'        => 'string',
					'description' => 'Task category/type (optional).',
					'enum'        => Vocabulary::TASK_CATEGORIES,
				),
				'tags'             => array(
					'type'        => 'string',
					'description' => 'Comma-separated list of tags for flexible categorization (optional).',
				),
				'estimated_effort' => array(
					'type'        => 'number',
					'description' => 'Estimated effort in hours (optional).',
				),
				'actual_effort'    => array(
					'type'        => 'number',
					'description' => 'Actual effort in hours (optional).',
				),
			),
			'required'   => array( 'title' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$title = trim( $this->stringParam( $arguments, 'title' ) );

		if ( '' === $title ) {
			return $this->errors->create( 'missing_title', 'A task title is required.' );
		}

		$status = $this->stringParam( $arguments, 'status' );
		if ( '' !== $status && ! in_array( $status, Vocabulary::TASK_STATUSES, true ) ) {
			// WordPress coerces an unknown status to the default and echoes the
			// stored value back, so the caller still sees what was written.
			// Matching that keeps one model transcript valid on both runtimes
			// (090-e write parity); `pending` is the case that found this.
			$status = 'todo';
		}

		$priority = $this->stringParam( $arguments, 'priority' );
		if ( '' !== $priority && ! in_array( $priority, Vocabulary::TASK_PRIORITIES, true ) ) {
			$priority = 'medium';
		}

		$category = $this->stringParam( $arguments, 'category' );
		if ( '' !== $category && ! in_array( $category, Vocabulary::TASK_CATEGORIES, true ) ) {
			$category = 'general';
		}

		$due_date = $this->stringParam( $arguments, 'due_date' );
		if ( '' !== $due_date && ! $this->isIsoDate( $due_date ) ) {
			return $this->errors->create( 'invalid_argument', 'due_date must be YYYY-MM-DD.' );
		}

		$meta = array();

		$project_id = $this->intParam( $arguments, 'project_id' );
		if ( $project_id > 0 ) {
			$meta['_task_project_id'] = $project_id;
		}

		$assigned_to = $this->intParam( $arguments, 'assigned_to' );
		if ( $assigned_to > 0 ) {
			$meta['_task_assigned_to'] = $assigned_to;
		}

		if ( '' !== $status ) {
			$meta['_task_status'] = $status;
		}

		if ( '' !== $priority ) {
			$meta['_task_priority'] = $priority;
		}

		if ( '' !== $category ) {
			$meta['_task_category'] = $category;
		}

		if ( '' !== $due_date ) {
			$meta['_task_due_date'] = $due_date;
		}

		$tags = $this->stringParam( $arguments, 'tags' );
		if ( '' !== $tags ) {
			$meta['_task_tags'] = $tags;
		}

		$estimated = $this->floatParam( $arguments, 'estimated_effort' );
		if ( $estimated > 0 ) {
			$meta['_task_estimated_effort'] = $estimated;
		}

		$actual = $this->floatParam( $arguments, 'actual_effort' );
		if ( $actual > 0 ) {
			$meta['_task_actual_effort'] = $actual;
		}

		$task_id = $this->intParam( $arguments, 'task_id' );

		return $task_id > 0
			? $this->update( $task_id, $title, $this->stringParam( $arguments, 'description' ), $meta, $context )
			: $this->create( $title, $this->stringParam( $arguments, 'description' ), $meta, $context );
	}

	/**
	 * @param array<string, mixed> $meta   Task meta to set.
	 * @param array<string, mixed> $context Execution context.
	 */
	private function create( string $title, string $description, array $meta, array $context ): mixed {
		$meta += array(
			'_task_status'   => 'todo',
			'_task_priority' => 'medium',
			'_task_category' => 'general',
		);

		try {
			$item = $this->content->create(
				new CreateContentCommand(
					title: $title,
					type: self::TYPE,
					authorId: $this->userId( $context ),
					status: 'publish',
					content: $description,
					meta: $meta,
				)
			);
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'create_failed', $e->getMessage() );
		}

		return $this->success(
			'Task created successfully.',
			array(
				'task_id' => $item->id,
				'task'    => TaskRow::from( $item ),
				'updated' => false,
			)
		);
	}

	/**
	 * @param array<string, mixed> $meta    Task meta to merge.
	 * @param array<string, mixed> $context Execution context.
	 */
	private function update( int $task_id, string $title, string $description, array $meta, array $context ): mixed {
		$existing = $this->content->find( $task_id );

		if ( null === $existing || self::TYPE !== $existing->type ) {
			return $this->errors->notFound( 'Task not found.' );
		}

		try {
			$item = $this->content->update(
				$task_id,
				new UpdateContentCommand(
					userId: $this->userId( $context ),
					title: $title,
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
				'updated' => true,
			)
		);
	}

	/**
	 * Requesting user, defaulting to the item author when the context is bare.
	 *
	 * @param array<string, mixed> $context Execution context.
	 */
	private function userId( array $context ): int {
		return isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
	}
}
