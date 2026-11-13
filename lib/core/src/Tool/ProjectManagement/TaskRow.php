<?php
/**
 * Task row assembler — the `mcp_ai_task` wire shape.
 *
 * Reads and writes must present a task identically, so the projection lives in
 * one place: `list_tasks`, `create_task` and any future task tool all render
 * through here. Duplicating it per tool is how the two runtimes drifted before.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ProjectManagement;

use Nvoos\Core\Domain\Entity\ContentItem;

/**
 * Projects a `ContentItem` of type `mcp_ai_task` into the tool-facing row.
 *
 * @since 1.3.0
 */
final class TaskRow {

	/**
	 * @return array<string, mixed>
	 */
	public static function from( ContentItem $item ): array {
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
