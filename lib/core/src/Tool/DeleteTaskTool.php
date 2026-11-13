<?php
/**
 * Delete Task tool — removes one mcp_ai_task through the content port.
 *
 * Mirrors the WordPress `delete_task` contract: `task_id` is the only
 * parameter, the result reports the deleted id, and a missing task is an error
 * rather than a silent success — a model told "deleted" for an id that never
 * existed will happily move on.
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

class DeleteTaskTool extends AbstractTool {

	private const TYPE = 'mcp_ai_task';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'delete_task';
	}

	public function getName(): string {
		return 'Delete Task';
	}

	public function getDescription(): string {
		return 'Permanently deletes a task by task_id.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task_id' => array(
					'type'        => 'integer',
					'description' => 'ID of the task to delete.',
				),
			),
			'required'   => array( 'task_id' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$this->authorize( $context, 'delete_posts' );

		$task_id = $this->intParam( $arguments, 'task_id' );

		if ( $task_id <= 0 ) {
			return $this->errors->create( 'missing_id', 'Task ID is required.' );
		}

		$existing = $this->content->find( $task_id );

		if ( null === $existing || self::TYPE !== $existing->type ) {
			return $this->errors->notFound( 'Task not found.' );
		}

		try {
			$this->content->delete( $task_id, isset( $context['user_id'] ) ? (int) $context['user_id'] : 0 );
		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );
		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'delete_failed', $e->getMessage() );
		}

		return $this->success(
			'Task deleted successfully.',
			array( 'task_id' => $task_id )
		);
	}
}
