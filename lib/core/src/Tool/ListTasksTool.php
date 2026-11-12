<?php
/**
 * List Tasks tool — reads the mcp_ai_task family through the content port.
 *
 * Identical slug, parameters and result fields to the WordPress tool.
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
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

class ListTasksTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_tasks';
	}

	public function getName(): string {
		return 'List Tasks';
	}

	public function getDescription(): string {
		return 'Lists tasks with optional filtering by project, status, priority, assigned user, or due date range.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id'  => array(
					'type'        => 'integer',
					'description' => 'Filter by project ID (optional)',
				),
				'status'      => array(
					'type'        => 'string',
					'description' => 'Filter by task status (optional)',
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => 'Filter by priority (optional)',
					'enum'        => array( 'low', 'medium', 'high', 'urgent' ),
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => 'Filter by assigned user ID (optional)',
				),
				'due_after'   => array(
					'type'        => 'string',
					'description' => 'Filter tasks due after this date (YYYY-MM-DD) for calendar views (optional)',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'due_before'  => array(
					'type'        => 'string',
					'description' => 'Filter tasks due before this date (YYYY-MM-DD) for calendar views (optional)',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => 'Maximum number of tasks to return (default: 50, max: 200)',
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$meta = array();

		$project_id = $this->intParam( $arguments, 'project_id' );
		if ( $project_id > 0 ) {
			$meta[] = array(
				'key'     => '_task_project_id',
				'value'   => $project_id,
				'compare' => '=',
			);
		}

		$status = $this->stringParam( $arguments, 'status' );
		if ( '' !== $status ) {
			$meta[] = array(
				'key'     => '_task_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		$priority = $this->stringParam( $arguments, 'priority' );
		if ( '' !== $priority ) {
			$meta[] = array(
				'key'     => '_task_priority',
				'value'   => $priority,
				'compare' => '=',
			);
		}

		$assigned = $this->intParam( $arguments, 'assigned_to' );
		if ( $assigned > 0 ) {
			$meta[] = array(
				'key'     => '_task_assigned_to',
				'value'   => $assigned,
				'compare' => '=',
			);
		}

		$due_after = $this->stringParam( $arguments, 'due_after' );
		if ( '' !== $due_after ) {
			$meta[] = array(
				'key'     => '_task_due_date',
				'value'   => $due_after,
				'compare' => '>=',
			);
		}

		$due_before = $this->stringParam( $arguments, 'due_before' );
		if ( '' !== $due_before ) {
			$meta[] = array(
				'key'     => '_task_due_date',
				'value'   => $due_before,
				'compare' => '<=',
			);
		}

		$limit = min( 200, max( 1, $this->intParam( $arguments, 'limit', 50 ) ) );

		$collection = $this->content->query(
			new ContentQuery(
				types: array( 'mcp_ai_task' ),
				statuses: array( 'publish' ),
				metaQuery: $meta,
				orderBy: 'date',
				order: 'DESC',
				perPage: $limit,
			)
		);

		$tasks = array();

		foreach ( $collection->items as $item ) {
			$tasks[] = $this->row( $item );
		}

		return $this->success(
			sprintf( 'Found %d tasks.', count( $tasks ) ),
			array(
				'count' => count( $tasks ),
				'total' => $collection->total,
				'tasks' => $tasks,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( ContentItem $item ): array {
		$meta = $item->meta;

		$project_id = isset( $meta['_task_project_id'] ) && (int) $meta['_task_project_id'] > 0
			? (int) $meta['_task_project_id']
			: null;

		$assigned = isset( $meta['_task_assigned_to'] ) && (int) $meta['_task_assigned_to'] > 0
			? (int) $meta['_task_assigned_to']
			: null;

		$estimated = isset( $meta['_task_estimated_effort'] ) && is_numeric( $meta['_task_estimated_effort'] )
			? (float) $meta['_task_estimated_effort']
			: null;

		$actual = isset( $meta['_task_actual_effort'] ) && is_numeric( $meta['_task_actual_effort'] )
			? (float) $meta['_task_actual_effort']
			: null;

		return array(
			'id'               => $item->id,
			'title'            => $item->title,
			'description'      => $item->content,
			'project_id'       => $project_id,
			'status'           => (string) ( $meta['_task_status'] ?? 'todo' ),
			'priority'         => (string) ( $meta['_task_priority'] ?? 'medium' ),
			'category'         => (string) ( $meta['_task_category'] ?? 'general' ),
			'tags'             => (string) ( $meta['_task_tags'] ?? '' ),
			'due_date'         => (string) ( $meta['_task_due_date'] ?? '' ),
			'assigned_to'      => $assigned,
			'estimated_effort' => $estimated,
			'actual_effort'    => $actual,
			'created_at'       => $item->createdAt->format( \DateTimeInterface::ATOM ),
			'updated_at'       => $item->updatedAt->format( \DateTimeInterface::ATOM ),
		);
	}
}
