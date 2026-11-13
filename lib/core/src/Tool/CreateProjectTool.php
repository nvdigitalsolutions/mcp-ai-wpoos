<?php
/**
 * Create Project tool — writes the mcp_ai_project family through the content port.
 *
 * Mirrors the WordPress `create_project` contract: one slug that creates, and
 * updates instead when `project_id` is supplied. Same parameters, same result
 * fields, so a model that learned the tool on one runtime keeps working.
 *
 * `assigned_to` is persisted as an array of **string** user IDs, matching the
 * encoding the read path filters on (`090-e` §7). Casting the ids once here is
 * what keeps "projects assigned to user N" answerable with a JSON LIKE match on
 * both runtimes.
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
use Nvoos\Core\Tool\ProjectManagement\ProjectRow;
use Nvoos\Core\Tool\ProjectManagement\Vocabulary;

class CreateProjectTool extends AbstractTool {

	private const TYPE = 'mcp_ai_project';


	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_project';
	}

	public function getName(): string {
		return 'Create Project';
	}

	public function getDescription(): string {
		return 'Creates a project, or updates an existing project when project_id is provided.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id'  => array(
					'type'        => 'integer',
					'description' => 'Optional project ID. When provided, updates the existing project instead of creating one.',
				),
				'name'        => array(
					'type'        => 'string',
					'description' => 'Project name.',
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Project description (optional).',
					'maxLength'   => 5000,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => 'Project status (optional).',
					'enum'        => Vocabulary::PROJECT_STATUSES,
					'default'     => 'planning',
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => 'Project start date in ISO 8601 format (YYYY-MM-DD) (optional).',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => 'Project end date in ISO 8601 format (YYYY-MM-DD) (optional).',
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'array',
					'description' => 'Array of user IDs assigned to this project (optional).',
					'items'       => array( 'type' => 'integer' ),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'edit_posts' );

		$name = trim( $this->stringParam( $arguments, 'name' ) );

		if ( '' === $name ) {
			return $this->errors->create( 'missing_name', 'A project name is required.' );
		}

		if ( mb_strlen( $name ) > 200 ) {
			return $this->errors->create( 'invalid_argument', 'A project name may not exceed 200 characters.' );
		}

		$status = $this->stringParam( $arguments, 'status' );
		if ( '' !== $status && ! in_array( $status, Vocabulary::PROJECT_STATUSES, true ) ) {
			// The WordPress tool falls back to the default rather than failing,
			// and the response echoes the stored status, so the caller still
			// sees what was written. Coercing keeps one model transcript valid
			// on both runtimes (090-e write parity).
			$status = 'planning';
		}

		$start_date = $this->stringParam( $arguments, 'start_date' );
		if ( '' !== $start_date && ! $this->isIsoDate( $start_date ) ) {
			return $this->errors->create( 'invalid_argument', 'start_date must be YYYY-MM-DD.' );
		}

		$end_date = $this->stringParam( $arguments, 'end_date' );
		if ( '' !== $end_date && ! $this->isIsoDate( $end_date ) ) {
			return $this->errors->create( 'invalid_argument', 'end_date must be YYYY-MM-DD.' );
		}

		if ( '' !== $start_date && '' !== $end_date && $end_date < $start_date ) {
			return $this->errors->create( 'invalid_argument', 'end_date must not precede start_date.' );
		}

		$meta = array();

		if ( '' !== $status ) {
			$meta['_project_status'] = $status;
		}

		if ( '' !== $start_date ) {
			$meta['_project_start_date'] = $start_date;
		}

		if ( '' !== $end_date ) {
			$meta['_project_end_date'] = $end_date;
		}

		$assigned = $this->intListParam( $arguments, 'assigned_to' );
		if ( array() !== $assigned ) {
			$meta['_project_assigned_to'] = array_map( 'strval', $assigned );
		}

		$project_id = $this->intParam( $arguments, 'project_id' );

		return $project_id > 0
			? $this->update( $project_id, $name, $this->stringParam( $arguments, 'description' ), $meta, $context )
			: $this->create( $name, $this->stringParam( $arguments, 'description' ), $meta, $context );
	}

	/**
	 * @param array<string, mixed> $meta    Project meta to set.
	 * @param array<string, mixed> $context Execution context.
	 */
	private function create( string $name, string $description, array $meta, array $context ): mixed {
		$meta += array( '_project_status' => 'planning' );

		try {
			$item = $this->content->create(
				new CreateContentCommand(
					title: $name,
					type: self::TYPE,
					authorId: isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
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
			'Project created successfully.',
			array(
				'project_id' => $item->id,
				'project'    => ProjectRow::from( $item ),
				'updated'    => false,
			)
		);
	}

	/**
	 * @param array<string, mixed> $meta    Project meta to merge.
	 * @param array<string, mixed> $context Execution context.
	 */
	private function update( int $project_id, string $name, string $description, array $meta, array $context ): mixed {
		$existing = $this->content->find( $project_id );

		if ( null === $existing || self::TYPE !== $existing->type ) {
			return $this->errors->notFound( 'Project not found.' );
		}

		try {
			$item = $this->content->update(
				$project_id,
				new UpdateContentCommand(
					userId: isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
					title: $name,
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
				'updated'    => true,
			)
		);
	}
}
