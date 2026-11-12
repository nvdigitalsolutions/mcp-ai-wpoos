<?php
/**
 * Get Task Dependencies tool — reads a task's dependency edges.
 *
 * Identical slug, parameters and result fields to the WordPress tool. The
 * edges live in `_task_depends_on` / `_task_blocks` meta; both are arrays of
 * task IDs, so the lookup goes through ContentStoreInterface::find().
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class GetTaskDependenciesTool extends AbstractTool {

	private const TASK_TYPE = 'mcp_ai_task';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_task_dependencies';
	}

	public function getName(): string {
		return 'Get Task Dependencies';
	}

	public function getDescription(): string {
		return 'Returns the tasks a task depends on and the tasks it blocks, with a can_start verdict based on whether every dependency is completed.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the task to retrieve dependencies for.',
				),
			),
			'required'             => array( 'task_id' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$task_id = $this->intParam( $arguments, 'task_id' );

		if ( $task_id <= 0 ) {
			return $this->errors->validationFailed(
				'task_id is required.',
				array( 'task_id' => array( 'Provide the task ID.' ) )
			);
		}

		$task = $this->content->find( $task_id );

		if ( null === $task || self::TASK_TYPE !== $task->type ) {
			return $this->errors->notFound( 'Task not found.' );
		}

		$depends_on = $this->enrich( $this->idList( $task->meta['_task_depends_on'] ?? array() ) );
		$blocks     = $this->enrich( $this->idList( $task->meta['_task_blocks'] ?? array() ) );

		$can_start = true;
		foreach ( $depends_on as $dependency ) {
			if ( 'completed' !== $dependency['status'] ) {
				$can_start = false;
				break;
			}
		}

		return $this->success(
			sprintf( 'This task depends on %1$d task(s) and blocks %2$d task(s).', count( $depends_on ), count( $blocks ) ),
			array(
				'task_id'     => $task_id,
				'task_title'  => $task->title,
				'task_status' => (string) ( $task->meta['_task_status'] ?? 'todo' ),
				'can_start'   => $can_start,
				'depends_on'  => $depends_on,
				'blocks'      => $blocks,
				'summary'     => sprintf( 'This task depends on %1$d task(s) and blocks %2$d task(s).', count( $depends_on ), count( $blocks ) ),
			)
		);
	}

	/**
	 * Normalise a stored ID list (int or string entries, duplicates, zeros).
	 *
	 * @param mixed $raw Stored meta value.
	 * @return int[]
	 */
	private function idList( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();

		foreach ( $raw as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Load each dependency and keep only real tasks.
	 *
	 * @param int[] $ids Task IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function enrich( array $ids ): array {
		$result = array();

		foreach ( $ids as $id ) {
			$item = $this->content->find( $id );

			if ( null === $item || self::TASK_TYPE !== $item->type ) {
				continue;
			}

			$result[] = array(
				'task_id'  => $item->id,
				'title'    => $item->title,
				'status'   => (string) ( $item->meta['_task_status'] ?? 'todo' ),
				'due_date' => (string) ( $item->meta['_task_due_date'] ?? '' ),
				'priority' => (string) ( $item->meta['_task_priority'] ?? 'medium' ),
			);
		}

		return $result;
	}
}
