<?php
/**
 * Create Post tool — creates a new content item.
 *
 * Demonstrates the write-tool pattern: inject ContentStore,
 * build a CreateContentCommand, call store.create(), return the result.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Create_Post.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\ValidationException;

class CreatePostTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_post';
	}

	public function getName(): string {
		return 'Create Post';
	}

	public function getDescription(): string {
		return 'Creates a new content item with the given title, content, type, and status.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'   => array(
					'type'        => 'string',
					'description' => 'The title of the new post.',
				),
				'content' => array(
					'type'        => 'string',
					'description' => 'The body content of the post.',
				),
				'type'    => array(
					'type'        => 'string',
					'description' => 'Content type slug. Default: "post".',
					'default'     => 'post',
				),
				'status'  => array(
					'type'        => 'string',
					'description' => 'Publication status. Default: "draft".',
					'enum'        => array( 'publish', 'draft', 'private', 'pending' ),
					'default'     => 'draft',
				),
				'excerpt' => array(
					'type'        => 'string',
					'description' => 'Optional excerpt/summary.',
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$title = $this->stringParam( $arguments, 'title' );
		if ( '' === $title ) {
			return $this->errors->validationFailed(
				'The title parameter is required.',
				array( 'title' => array( 'A post title is required.' ) ),
			);
		}

		$userId = $context['user_id'] ?? 0;
		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in to create content.' );
		}

		$command = new CreateContentCommand(
			title: $title,
			type: $this->stringParam( $arguments, 'type', 'post' ),
			status: $this->stringParam( $arguments, 'status', 'draft' ),
			content: $this->stringParam( $arguments, 'content' ),
			authorId: $userId,
			excerpt: $this->stringParam( $arguments, 'excerpt' ) ?: null,
		);

		try {
			$item = $this->content->create( $command );

			return $this->success( 'Post created successfully.', $item->jsonSerialize() );

		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );

		} catch ( ValidationException $e ) {
			return $this->errors->validationFailed(
				$e->getMessage(),
				$e->hasFieldErrors() ? $e->errors : array(),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'create_failed',
				"Failed to create post: {$e->getMessage()}",
			);
		}
	}
}
