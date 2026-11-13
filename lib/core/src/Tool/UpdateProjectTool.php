<?php
/**
 * Update Project tool — partial update of one mcp_ai_project.
 *
 * Separate from `create_project` (which is an upsert requiring a name), so a
 * caller can move a project to `completed` without re-sending everything.
 *
 * Date fields are validated against the **merged** result: changing only
 * `end_date` still cannot invert an existing `start_date`, which is the failure
 * a per-field check would miss.
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
use Nvoos\Core\Tool\ProjectManagement\ProjectRow;

class UpdateProjectTool extends AbstractTool {

	private const TYPE = 'mcp_ai_project';

	private const STATUSES = array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' );

	private const FIELDS = array( 'name', 'description', 'status', 'start_date', 'end_date', 'assigned_to' );

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'update_project';
	}

	public function getName(): string {
		return 'Update Project';
	}

	public function getDescription(): string {
		return 'Updates specific fields of an existing project. Only the fields provided are changed.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'project_id'  => array(
					'type'        => 'integer',
					'description' => 'Project ID to update.',
				),
				'name'        => array(
					'type'        => 'string',
					'description' => 'New project name (optional).',
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'New project description (optional).',
					'maxLength'   => 5000,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => 'New project status (optional).',
					'enum'        => self::STATUSES,
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => 'New start date (YYYY-MM-DD) (optional).',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => 'New end date (YYYY-MM-DD) (optional).',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'array',
					'description' => 'New array of assigned user IDs (optional).',
					'items'       => array( 'type' => 'integer' ),
				),
			),
			'required'   => array( 'project_id' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$project_id = $this->intParam( $arguments, 'project_id' );

		if ( $project_id <= 0 ) {
			return $this->errors->create( 'missing_id', 'Project ID is required.' );
		}

		$existing = $this->content->find( $project_id );

		if ( null === $existing || self::TYPE !== $existing->type ) {
			return $this->errors->notFound( 'Project not found.' );
		}

		if ( array() === $this->providedParams( $arguments, self::FIELDS ) ) {
			return $this->errors->create( 'nothing_to_update', 'Provide at least one field to update.' );
		}

		$name = $this->stringParam( $arguments, 'name' );

		if ( '' !== $name && mb_strlen( $name ) > 200 ) {
			return $this->errors->create( 'invalid_argument', 'A project name may not exceed 200 characters.' );
		}

		$status = $this->stringParam( $arguments, 'status' );
		if ( '' !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			return $this->errors->create( 'invalid_argument', 'Unknown project status.' );
		}

		$start_date = $this->stringParam( $arguments, 'start_date' );
		if ( '' !== $start_date && ! $this->isIsoDate( $start_date ) ) {
			return $this->errors->create( 'invalid_argument', 'start_date must be YYYY-MM-DD.' );
		}

		$end_date = $this->stringParam( $arguments, 'end_date' );
		if ( '' !== $end_date && ! $this->isIsoDate( $end_date ) ) {
			return $this->errors->create( 'invalid_argument', 'end_date must be YYYY-MM-DD.' );
		}

		// Compare against the merged result, not just what was sent: a lone
		// end_date must still not precede the stored start_date.
		$stored = $existing->meta;

		$effective_start = '' !== $start_date ? $start_date : (string) ( $stored['_project_start_date'] ?? '' );
		$effective_end   = '' !== $end_date ? $end_date : (string) ( $stored['_project_end_date'] ?? '' );

		if ( '' !== $effective_start && '' !== $effective_end && $effective_end < $effective_start ) {
			return $this->errors->create( 'invalid_argument', 'end_date must not precede start_date.' );
		}

		// Only the changes go to the command — it merges into the stored meta.
		$meta = array();

		if ( '' !== $start_date ) {
			$meta['_project_start_date'] = $start_date;
		}

		if ( '' !== $end_date ) {
			$meta['_project_end_date'] = $end_date;
		}

		if ( '' !== $status ) {
			$meta['_project_status'] = $status;
		}

		$assigned = $this->intListParam( $arguments, 'assigned_to' );
		if ( array() !== $assigned ) {
			$meta['_project_assigned_to'] = array_map( 'strval', $assigned );
		}

		$description = $this->stringParam( $arguments, 'description' );

		try {
			$item = $this->content->update(
				$project_id,
				new UpdateContentCommand(
					userId: isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
					title: '' !== $name ? $name : null,
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
			sprintf( 'Project updated: %s', $item->title ),
			array(
				'project_id' => $item->id,
				'project'    => ProjectRow::from( $item ),
			)
		);
	}
}
