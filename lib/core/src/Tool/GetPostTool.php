<?php
/**
 * Get Post tool — retrieves a content item by ID.
 *
 * Demonstrates the read-only tool pattern: inject ContentStore,
 * validate params, call store.find(), return canonical envelope.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Get_Post.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ContentStoreInterface;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;

class GetPostTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'get_post';
    }

    public function getName(): string
    {
        return 'Get Post';
    }

    public function getDescription(): string
    {
        return 'Retrieves a content item by ID, including its metadata and taxonomy terms.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'The ID of the post to retrieve.',
                    'minimum'     => 1,
                ],
                'include_meta' => [
                    'type'        => 'boolean',
                    'description' => 'Whether to include metadata. Default: true.',
                    'default'     => true,
                ],
                'include_taxonomies' => [
                    'type'        => 'boolean',
                    'description' => 'Whether to include taxonomy terms. Default: true.',
                    'default'     => true,
                ],
            ],
            'required'             => ['post_id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments = [], array $context = []): mixed
    {
        $postId = $this->intParam($arguments, 'post_id');
        if ($postId <= 0) {
            return $this->errors->validationFailed(
                'post_id is required and must be a positive integer.',
                ['post_id' => ['A valid post ID is required.']],
            );
        }

        $userId = $context['user_id'] ?? null;
        $item   = $this->content->find($postId, $userId);

        if (null === $item) {
            return $this->errors->notFound('The requested post does not exist or you do not have permission to view it.');
        }

        $includeMeta       = $this->boolParam($arguments, 'include_meta', true);
        $includeTaxonomies = $this->boolParam($arguments, 'include_taxonomies', true);

        $data = $item->jsonSerialize();

        if ( ! $includeMeta) {
            unset($data['meta']);
        }

        if ( ! $includeTaxonomies) {
            unset($data['taxonomy']);
        }

        return $this->success('Post retrieved.', $data);
    }
}
