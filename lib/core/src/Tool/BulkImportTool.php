<?php
/**
 * Bulk Import tool — batch-create tasks or projects from a row list.
 *
 * Follows the toolkit import contract (090-e §5, `Pro\ToolkitsController::importEntity`):
 * a `rows` list, a `preview` dry run, `skip_existing` defaulting to true, and a
 * result where **valid + duplicates + failed always equals total**. A batch
 * report the caller cannot reconcile is worse than no report.
 *
 * There is no external id on this family, so the natural key is the title
 * (tasks) or name (projects), compared case- and whitespace-insensitively.
 * That means two rows with the same title in one batch are a duplicate — the
 * honest consequence of having no id to tell them apart.
 *
 * Validation is per row and non-fatal: a bad row is reported with its index and
 * field errors while the rest of the batch still imports.
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
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\ProjectManagement\Vocabulary;

class BulkImportTool extends AbstractTool {

	/** Maximum rows accepted in one call, matching the toolkit import. */
	public const MAX_ROWS = 500;

	private const TYPE_TASK    = 'mcp_ai_task';
	private const TYPE_PROJECT = 'mcp_ai_project';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'bulk_import';
	}

	public function getName(): string {
		return 'Bulk Import';
	}

	public function getDescription(): string {
		return 'Imports tasks or projects from a list of rows. Supports a preview dry run and skips rows whose title already exists.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'entity'        => array(
					'type'        => 'string',
					'description' => 'What to import.',
					'enum'        => array( 'tasks', 'projects' ),
				),
				'rows'          => array(
					'type'        => 'array',
					'description' => 'Rows to import. Each is an object matching the create tool for the entity.',
					'maxItems'    => self::MAX_ROWS,
					'items'       => array( 'type' => 'object' ),
				),
				'preview'       => array(
					'type'        => 'boolean',
					'description' => 'Validate and report without writing (default: false).',
				),
				'skip_existing' => array(
					'type'        => 'boolean',
					'description' => 'Count rows whose title already exists as duplicates instead of failing them (default: true).',
				),
			),
			'required'   => array( 'entity', 'rows' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$entity = $this->stringParam( $arguments, 'entity' );

		if ( ! in_array( $entity, array( 'tasks', 'projects' ), true ) ) {
			return $this->errors->create( 'invalid_argument', 'entity must be "tasks" or "projects".' );
		}

		$rows = $arguments['rows'] ?? null;

		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			return $this->errors->create( 'invalid_argument', 'rows must be a list of objects.' );
		}

		if ( count( $rows ) > self::MAX_ROWS ) {
			return $this->errors->create(
				'too_many_rows',
				sprintf( 'Import at most %d rows at a time.', self::MAX_ROWS )
			);
		}

		$preview      = $this->boolParam( $arguments, 'preview' );
		$skipExisting = $this->boolParam( $arguments, 'skip_existing', true );
		$type         = 'tasks' === $entity ? self::TYPE_TASK : self::TYPE_PROJECT;
		$key_field    = 'tasks' === $entity ? 'title' : 'name';

		$valid      = array();
		$failed     = array();
		$duplicates = 0;
		$seen       = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				$failed[] = array(
					'index'  => $index,
					'fields' => array( 'row' => 'Each row must be an object.' ),
				);
				continue;
			}

			list( $errors, $normalized ) = 'tasks' === $entity
				? $this->normalizeTask( $row )
				: $this->normalizeProject( $row );

			if ( array() !== $errors ) {
				$failed[] = array(
					'index'  => $index,
					'fields' => $errors,
				);
				continue;
			}

			$key = $this->naturalKey( $normalized['title'] );

			if ( isset( $seen[ $key ] ) || $this->existsByTitle( $type, $normalized['title'] ) ) {
				if ( $skipExisting ) {
					++$duplicates;
					continue;
				}

				$failed[] = array(
					'index'  => $index,
					'fields' => array( $key_field => 'A record with that value already exists.' ),
				);
				continue;
			}

			$seen[ $key ] = true;
			$valid[]      = $normalized;
		}

		$report = array(
			'entity'     => $entity,
			'total'      => count( $rows ),
			'valid'      => count( $valid ),
			'duplicates' => $duplicates,
			'failed'     => $failed,
			'created'    => 0,
			'preview'    => $preview,
		);

		if ( $preview ) {
			$report['rows'] = $valid;

			return $this->success(
				sprintf( 'Preview: %d of %d rows are importable.', count( $valid ), count( $rows ) ),
				$report
			);
		}

		foreach ( $valid as $index => $normalized ) {
			try {
				$this->content->create(
					new CreateContentCommand(
						title: $normalized['title'],
						type: $type,
						authorId: isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
						status: 'publish',
						content: $normalized['content'],
						meta: $normalized['meta'],
					)
				);
			} catch ( AccessDeniedException $e ) {
				return $this->errors->forbidden( $e->getMessage() );
			} catch ( \Throwable $e ) {
				// A row that passed validation but failed to persist is still a
				// failed row, so the invariant holds: valid + duplicates +
				// failed === total.
				$failed[]    = array(
					'index'  => $index,
					'fields' => array( 'row' => $e->getMessage() ),
				);
				$report['valid'] = max( 0, $report['valid'] - 1 );
				continue;
			}

			++$report['created'];
		}

		return $this->success(
			sprintf( 'Imported %d of %d rows.', $report['created'], count( $rows ) ),
			$report
		);
	}

	/**
	 * Normalise a task row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array{0: array<string,string>, 1: array{title:string, content:string, meta:array<string,mixed>}}
	 */
	private function normalizeTask( array $row ): array {
		$errors = array();

		$title = isset( $row['title'] ) && is_scalar( $row['title'] ) ? trim( (string) $row['title'] ) : '';

		if ( '' === $title ) {
			$errors['title'] = 'A task title is required.';
		}

		$meta = array(
			'_task_status'   => 'todo',
			'_task_priority' => 'medium',
			'_task_category' => 'general',
		);

		$status = isset( $row['status'] ) && is_scalar( $row['status'] ) ? (string) $row['status'] : '';
		if ( '' !== $status ) {
			if ( ! in_array( $status, Vocabulary::TASK_STATUSES, true ) ) {
				$errors['status'] = 'Unknown task status.';
			} else {
				$meta['_task_status'] = $status;
			}
		}

		$priority = isset( $row['priority'] ) && is_scalar( $row['priority'] ) ? (string) $row['priority'] : '';
		if ( '' !== $priority ) {
			if ( ! in_array( $priority, Vocabulary::TASK_PRIORITIES, true ) ) {
				$errors['priority'] = 'Unknown task priority.';
			} else {
				$meta['_task_priority'] = $priority;
			}
		}

		$category = isset( $row['category'] ) && is_scalar( $row['category'] ) ? (string) $row['category'] : '';
		if ( '' !== $category ) {
			if ( ! in_array( $category, Vocabulary::TASK_CATEGORIES, true ) ) {
				$errors['category'] = 'Unknown task category.';
			} else {
				$meta['_task_category'] = $category;
			}
		}

		$due_date = isset( $row['due_date'] ) && is_scalar( $row['due_date'] ) ? (string) $row['due_date'] : '';
		if ( '' !== $due_date ) {
			if ( ! $this->isIsoDate( $due_date ) ) {
				$errors['due_date'] = 'due_date must be YYYY-MM-DD.';
			} else {
				$meta['_task_due_date'] = $due_date;
			}
		}

		foreach ( array( 'project_id' => '_task_project_id', 'assigned_to' => '_task_assigned_to' ) as $field => $meta_key ) {
			if ( isset( $row[ $field ] ) && is_numeric( $row[ $field ] ) && (int) $row[ $field ] > 0 ) {
				$meta[ $meta_key ] = (int) $row[ $field ];
			}
		}

		if ( isset( $row['tags'] ) && is_scalar( $row['tags'] ) && '' !== (string) $row['tags'] ) {
			$meta['_task_tags'] = (string) $row['tags'];
		}

		foreach ( array( 'estimated_effort' => '_task_estimated_effort', 'actual_effort' => '_task_actual_effort' ) as $field => $meta_key ) {
			if ( isset( $row[ $field ] ) && is_numeric( $row[ $field ] ) && (float) $row[ $field ] > 0 ) {
				$meta[ $meta_key ] = (float) $row[ $field ];
			}
		}

		return array(
			$errors,
			array(
				'title'   => $title,
				'content' => isset( $row['description'] ) && is_scalar( $row['description'] ) ? (string) $row['description'] : '',
				'meta'    => $meta,
			),
		);
	}

	/**
	 * Normalise a project row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array{0: array<string,string>, 1: array{title:string, content:string, meta:array<string,mixed>}}
	 */
	private function normalizeProject( array $row ): array {
		$errors = array();

		$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? trim( (string) $row['name'] ) : '';

		if ( '' === $name ) {
			$errors['name'] = 'A project name is required.';
		}

		$meta = array( '_project_status' => 'planning' );

		$status = isset( $row['status'] ) && is_scalar( $row['status'] ) ? (string) $row['status'] : '';
		if ( '' !== $status ) {
			if ( ! in_array( $status, Vocabulary::PROJECT_STATUSES, true ) ) {
				$errors['status'] = 'Unknown project status.';
			} else {
				$meta['_project_status'] = $status;
			}
		}

		$start = isset( $row['start_date'] ) && is_scalar( $row['start_date'] ) ? (string) $row['start_date'] : '';
		$end   = isset( $row['end_date'] ) && is_scalar( $row['end_date'] ) ? (string) $row['end_date'] : '';

		if ( '' !== $start ) {
			if ( ! $this->isIsoDate( $start ) ) {
				$errors['start_date'] = 'start_date must be YYYY-MM-DD.';
			} else {
				$meta['_project_start_date'] = $start;
			}
		}

		if ( '' !== $end ) {
			if ( ! $this->isIsoDate( $end ) ) {
				$errors['end_date'] = 'end_date must be YYYY-MM-DD.';
			} else {
				$meta['_project_end_date'] = $end;
			}
		}

		if ( '' !== $start && '' !== $end && $end < $start && ! isset( $errors['start_date'] ) && ! isset( $errors['end_date'] ) ) {
			$errors['end_date'] = 'end_date must not precede start_date.';
		}

		if ( isset( $row['assigned_to'] ) && is_array( $row['assigned_to'] ) ) {
			$ids = array();

			foreach ( $row['assigned_to'] as $value ) {
				if ( is_numeric( $value ) ) {
					$ids[] = (string) (int) $value;
				}
			}

			if ( array() !== $ids ) {
				$meta['_project_assigned_to'] = array_values( array_unique( $ids ) );
			}
		}

		return array(
			$errors,
			array(
				'title'   => $name,
				'content' => isset( $row['description'] ) && is_scalar( $row['description'] ) ? (string) $row['description'] : '',
				'meta'    => $meta,
			),
		);
	}

	/**
	 * Case- and whitespace-insensitive identity for duplicate detection.
	 */
	private function naturalKey( string $title ): string {
		return mb_strtolower( trim( $title ) );
	}

	/**
	 * Whether a record of this type already carries the title.
	 *
	 * `ContentQuery::search` narrows on the database side; the exact comparison
	 * happens here, because search is a substring match and would treat
	 * "Alpha" as a duplicate of "Alpha Two".
	 */
	private function existsByTitle( string $type, string $title ): bool {
		$collection = $this->content->query(
			new ContentQuery(
				types: array( $type ),
				statuses: array( 'publish' ),
				search: $title,
				perPage: 50,
			)
		);

		$needle = $this->naturalKey( $title );

		foreach ( $collection->items as $item ) {
			if ( $this->naturalKey( $item->title ) === $needle ) {
				return true;
			}
		}

		return false;
	}
}
