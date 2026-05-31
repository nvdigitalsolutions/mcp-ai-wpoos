<?php
/**
 * Create Post tool — creates a new content item.
 *
 * Demonstrates the write-tool pattern: inject ContentStore,
 * build a CreateContentCommand, call store.create(), return the result.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Create_Post.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ContentStoreInterface;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Entity\CreateContentCommand;
use Oos\Core\Domain\Error\AccessDeniedException;
use Oos\Core\Domain\Error\ValidationException;

class CreatePostTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ContentStoreInterface $content,
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
        return [
            'type'       => 'object',
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => 'The title of the new post.',
                ],
                'content' => [
                    'type'        => 'string',
                    'description' => 'The body content of the post.',
                ],
                'type' => [
                    'type'        => 'string',
                    'description' => 'Content type slug. Default: "post".',
                    'default'     => 'post',
                ],
                'status' => [
                    'type'        => 'string',
                    'description' => 'Publication status. Default: "draft".',
                    'enum'        => ['publish', 'draft', 'private', 'pending'],
                    'default'     => 'draft',
                ],
                'excerpt' => [
                    'type'        => 'string',
                    'description' => 'Optional excerpt/summary.',
                ],
            ],
            'required'             => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments = [], array $context = []): mixed
    {
        $title = $this->stringParam($arguments, 'title');
        if ('' === $title) {
            return $this->errors->validationFailed(
                'The title parameter is required.',
                ['title' => ['A post title is required.']],
            );
        }

        $userId = $context['user_id'] ?? 0;
        if ($userId <= 0) {
            return $this->errors->forbidden('You must be logged in to create content.');
        }

        $command = new CreateContentCommand(
            title: $title,
            type: $this->stringParam($arguments, 'type', 'post'),
            status: $this->stringParam($arguments, 'status', 'draft'),
            content: $this->stringParam($arguments, 'content'),
            authorId: $userId,
            excerpt: $this->stringParam($arguments, 'excerpt') ?: null,
        );

        try {
            $item = $this->content->create($command);

            return $this->success('Post created successfully.', $item->jsonSerialize());

        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());

        } catch (ValidationException $e) {
            return $this->errors->validationFailed(
                $e->getMessage(),
                $e->hasFieldErrors() ? $e->errors : [],
            );

        } catch (\Throwable $e) {
            return $this->errors->create(
                'create_failed',
                "Failed to create post: {$e->getMessage()}",
            );
        }
    }
}
