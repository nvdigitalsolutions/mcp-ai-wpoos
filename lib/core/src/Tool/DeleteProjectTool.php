<?php
/**
 * Delete Project tool — removes one mcp_ai_project through the content port.
 *
 * Mirrors the WordPress `delete_project` contract: `project_id` is the only
 * parameter, and an unknown id is an error rather than a silent success.
 *
 * Deleting a project does not cascade to its tasks: `_task_project_id` is a
 * plain meta value in both runtimes, not a foreign key, and the WordPress tool
 * behaves the same way. Callers that want the tasks gone must delete them.
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
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class DeleteProjectTool extends AbstractTool {

	private const TYPE = 'mcp_ai_project';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'delete_project';
	}

	public function getName(): string {
		return 'Delete Project';
	}

	public function getDescription(): string {
		return 'Permanently deletes a project by project_id. Tasks that reference it are left untouched.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'project_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the project to delete.',
				),
			),
			'required'   => array( 'project_id' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'delete_posts' );

		$project_id = $this->intParam( $arguments, 'project_id' );

		if ( $project_id <= 0 ) {
			return $this->errors->create( 'missing_id', 'Project ID is required.' );
		}

		$existing = $this->content->find( $project_id );

		if ( null === $existing || self::TYPE !== $existing->type ) {
			return $this->errors->notFound( 'Project not found.' );
		}

		try {
			$this->content->delete( $project_id, isset( $context['user_id'] ) ? (int) $context['user_id'] : 0 );
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'delete_failed', $e->getMessage() );
		}

		return $this->success(
			'Project deleted successfully.',
			array( 'project_id' => $project_id )
		);
	}
}
