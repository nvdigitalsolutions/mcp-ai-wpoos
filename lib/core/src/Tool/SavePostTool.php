<?php
/**
 * Save Post tool — creates or updates a content item (upsert).
 *
 * Detects whether post_id is provided to determine create vs. update,
 * then delegates to ContentStoreInterface. Framework-agnostic equivalent
 * of WP_MCP_AI_Tool_Save_Post.
 *
 * Block-editor formatting and Elementor-specific handling belong to
 * the platform adapter layer, not the core tool.
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;

class SavePostTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'save_post';
	}

	public function getName(): string {
		return 'Create or Update Post';
	}

	public function getDescription(): string {
		return 'Creates a new content item or updates an existing one. Provide post_id to update; omit to create.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'           => array(
					'type'        => 'integer',
					'description' => 'Existing post ID to update. Leave empty to create a new post.',
					'minimum'     => 1,
				),
				'post_type'         => array(
					'type'        => 'string',
					'description' => 'Content type slug. Default: "post".',
					'default'     => 'post',
				),
				'title'             => array(
					'type'        => 'string',
					'description' => 'Title of the post.',
				),
				'content'           => array(
					'type'        => 'string',
					'description' => 'Main body content for the post.',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => 'Publication status. Default: "draft".',
					'enum'        => array( 'publish', 'draft', 'private', 'pending' ),
					'default'     => 'draft',
				),
				'excerpt'           => array(
					'type'        => 'string',
					'description' => 'Optional excerpt or summary.',
				),
				'slug'              => array(
					'type'        => 'string',
					'description' => 'Optional URL slug for the permalink.',
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => 'Array of category IDs or names to assign.',
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'integer', 'minimum' => 1 ),
							array( 'type' => 'string' ),
						),
					),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => 'Array of tag IDs or names to assign.',
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'integer', 'minimum' => 1 ),
							array( 'type' => 'string' ),
						),
					),
				),
				'meta_input'        => array(
					'type'        => 'object',
					'description' => 'Key-value pairs of custom fields to set.',
				),
				'comment_status'    => array(
					'type'        => 'string',
					'description' => 'Comment status: "open" or "closed".',
					'enum'        => array( 'open', 'closed' ),
				),
			),
			'required'             => array( 'content' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$userId = $context['user_id'] ?? 0;

		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in to manage content.' );
		}

		$postId   = $this->intParam( $arguments, 'post_id' );
		$postType = $this->stringParam( $arguments, 'post_type', 'post' );
		$status   = $this->stringParam( $arguments, 'status', 'draft' );
		$title    = $this->stringParam( $arguments, 'title' );
		$body     = $this->stringParam( $arguments, 'content' );

		if ( '' === $body ) {
			return $this->errors->validationFailed(
				'Post content is required.',
				array( 'content' => array( 'Content is required.' ) ),
			);
		}

		// Build taxonomy input from categories and tags.
		$taxonomyInput = array();
		$categories    = $this->arrayParam( $arguments, 'categories' );
		$tags          = $this->arrayParam( $arguments, 'tags' );

		if ( array() !== $categories ) {
			$taxonomyInput['category'] = $this->normalizeTerms( $categories );
		}
		if ( array() !== $tags ) {
			$taxonomyInput['post_tag'] = $this->normalizeTerms( $tags );
		}

		// Build meta input.
		$meta = $this->arrayParam( $arguments, 'meta_input' );

		try {
			if ( $postId > 0 ) {
				// Update existing.
				$existing = $this->content->find( $postId, $userId );

				if ( null === $existing ) {
					return $this->errors->notFound( 'The specified post does not exist.' );
				}

				$command = new UpdateContentCommand(
					userId: $userId,
					title: '' !== $title ? $title : null,
					content: $body,
					status: $status,
					excerpt: $this->stringParam( $arguments, 'excerpt' ) ?: null,
					meta: $meta,
					taxonomyInput: $taxonomyInput,
				);

				$item = $this->content->update( $postId, $command );

				return $this->success( 'Post updated successfully.', $item->jsonSerialize() );
			}

			// Create new.
			if ( '' === $title ) {
				return $this->errors->validationFailed(
					'A title is required when creating a new post.',
					array( 'title' => array( 'Title is required for new posts.' ) ),
				);
			}

			$command = new CreateContentCommand(
				title: $title,
				type: $postType,
				authorId: $userId,
				status: $status,
				content: $body,
				excerpt: $this->stringParam( $arguments, 'excerpt' ) ?: null,
				meta: $meta,
				taxonomyInput: $taxonomyInput,
			);

			$item = $this->content->create( $command );

			return $this->success( 'Post created successfully.', $item->jsonSerialize() );

		} catch ( NotFoundException $e ) {
			return $this->errors->notFound( $e->getMessage() );

		} catch ( AccessDeniedException $e ) {
			return $this->errors->forbidden( $e->getMessage() );

		} catch ( ValidationException $e ) {
			return $this->errors->validationFailed(
				$e->getMessage(),
				$e->hasFieldErrors() ? $e->errors : array(),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create(
				'save_failed',
				"Failed to save post: {$e->getMessage()}",
			);
		}
	}

	/**
	 * Normalize mixed term values (IDs or names) to string array for
	 * the taxonomy input map.
	 *
	 * @param array $terms Mixed integers and strings.
	 * @return string[] Normalized string array.
	 */
	private function normalizeTerms( array $terms ): array {
		$result = array();

		foreach ( $terms as $term ) {
			if ( \is_int( $term ) || \is_string( $term ) ) {
				$result[] = (string) $term;
			}
		}

		return $result;
	}
}
