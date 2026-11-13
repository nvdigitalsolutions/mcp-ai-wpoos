<?php
/**
 * Project-management vocabulary — the allowed values, in one place.
 *
 * These lists appear in tool schemas (where they become the model's enum), in
 * validation (where they decide whether to write), and now in bulk import. Two
 * copies is how a schema and its validator drift apart.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ProjectManagement;

/**
 * Allowed field values for the project-management family.
 *
 * @since 1.3.0
 */
final class Vocabulary {

	/** @var list<string> */
	public const TASK_STATUSES = array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' );

	/** @var list<string> */
	public const TASK_PRIORITIES = array( 'low', 'medium', 'high', 'urgent' );

	/** @var list<string> */
	public const TASK_CATEGORIES = array( 'general', 'bug', 'feature', 'maintenance', 'research', 'documentation', 'design', 'testing' );

	/** @var list<string> */
	public const PROJECT_STATUSES = array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' );
}
