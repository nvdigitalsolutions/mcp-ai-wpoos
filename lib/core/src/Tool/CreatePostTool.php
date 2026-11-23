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
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\ContentImages;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\ValidationException;

class CreatePostTool extends AbstractTool
{
    /**
     * `$files` is optional: a host with no registry can create posts, it just
     * cannot resolve a numeric `content_images` source.
     */
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ContentStoreInterface $content,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'create_post';
    }

    public function getName(): string
    {
        return 'Create Post';
    }

    public function getDescription(): string
    {
        return 'Creates a new content item with the given title, content, type, and status.';
    }

    public function getParametersSchema(): array
    {
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
                'dry_run' => array(
                    'type'        => 'boolean',
                    'description' => 'Describe the post that would be created without writing it.',
                    'default'     => false,
                ),
            ) + ContentImages::parameters(),
            'required'             => array( 'title' ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $title = $this->stringParam($arguments, 'title');
        if ('' === $title) {
            return $this->errors->validationFailed(
                'The title parameter is required.',
                array( 'title' => array( 'A post title is required.' ) ),
            );
        }

        $userId = $context['user_id'] ?? 0;
        if ($userId <= 0) {
            return $this->errors->forbidden('You must be logged in to create content.');
        }

        // Proposal 162 §162.9.5 — `content_images` is embedded here rather than
        // handed to the store, so a bad source is refused before a post exists.
        try {
            $content = ContentImages::embed($this->stringParam($arguments, 'content'), $arguments, $this->files);
        } catch (ValidationException $e) {
            return $this->errors->validationFailed(
                $e->getMessage(),
                array( 'content_images' => array( $e->getMessage() ) ),
            );
        }

        $command = new CreateContentCommand(
            title: $title,
            type: $this->stringParam($arguments, 'type', 'post'),
            status: $this->stringParam($arguments, 'status', 'draft'),
            content: $content,
            authorId: $userId,
            excerpt: $this->stringParam($arguments, 'excerpt') ?: null,
        );

        if ($this->isDryRun($arguments)) {
            return $this->dryRunPlan(
                'create',
                null,
                array(
                    'title'     => $command->title,
                    'type'      => $command->type,
                    'status'    => $command->status,
                    'content'   => $command->content,
                    'excerpt'   => $command->excerpt,
                    'author_id' => $command->authorId,
                ),
                'Dry run: the post would be created.'
            );
        }

        try {
            $item = $this->content->create($command);

            return $this->success('Post created successfully.', $item->jsonSerialize());
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (ValidationException $e) {
            return $this->errors->validationFailed(
                $e->getMessage(),
                $e->hasFieldErrors() ? $e->errors : array(),
            );
        } catch (\Throwable $e) {
            return $this->errors->create(
                'create_failed',
                "Failed to create post: {$e->getMessage()}",
            );
        }
    }
}
