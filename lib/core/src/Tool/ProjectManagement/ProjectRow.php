<?php
/**
 * Project row assembler — the `mcp_ai_project` wire shape.
 *
 * Shared by `list_projects` and the project write tools, for the same reason
 * `TaskRow` exists: one projection means a read and a write cannot disagree
 * about what a project looks like.
 *
 * `assigned_to` is stored as an array of **string** user IDs so it can be
 * filtered with a JSON LIKE match that works on both runtimes (see 090-e §7).
 * The row passes those values through unchanged rather than re-casting them —
 * the filter and the projection have to agree on the encoding.
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
 * Projects a `ContentItem` of type `mcp_ai_project` into the tool-facing row.
 *
 * @since 1.3.0
 */
final class ProjectRow {

	/**
	 * @return array<string, mixed>
	 */
	public static function from( ContentItem $item ): array {
		$meta     = $item->meta;
		$assigned = $meta['_project_assigned_to'] ?? array();

		return array(
			'id'          => $item->id,
			'name'        => $item->title,
			'description' => $item->content,
			'status'      => (string) ( $meta['_project_status'] ?? 'planning' ),
			'start_date'  => (string) ( $meta['_project_start_date'] ?? '' ),
			'end_date'    => (string) ( $meta['_project_end_date'] ?? '' ),
			'assigned_to' => is_array( $assigned ) ? array_values( $assigned ) : array(),
			'created_at'  => $item->createdAt->format( \DateTimeInterface::ATOM ),
			'updated_at'  => $item->updatedAt->format( \DateTimeInterface::ATOM ),
		);
	}
}
