<?php

/** @package Nvoos\Core @since 1.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class UpdatePostTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(ErrorFactoryInterface $e, private readonly ContentStoreInterface $c)
    {
        parent::__construct($e);
    }
    public function getSlug(): string
    {
        return 'update_post';
    }
    public function getName(): string
    {
        return 'Update Post';
    }
    public function getDescription(): string
    {
        return 'Updates an existing content item. Only provided fields are changed.';
    }
    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'ID of the post to update',
                    'minimum'     => 1,
                ),
                'title'   => array(
                    'type'        => 'string',
                    'description' => 'New title',
                ),
                'content' => array(
                    'type'        => 'string',
                    'description' => 'New body content',
                ),
                'status'  => array(
                    'type'        => 'string',
                    'description' => 'New status',
                    'enum'        => array( 'publish', 'draft', 'private', 'pending' ),
                ),
                'excerpt' => array(
                    'type'        => 'string',
                    'description' => 'New excerpt',
                ),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'description' => 'Describe the update that would be applied without writing it.',
                    'default'     => false,
                ),
            ),
            'required'             => array( 'post_id' ),
            'additionalProperties' => false,
        );
    }
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $postId = $this->intParam($arguments, 'post_id');
        if ($postId <= 0) {
            return $this->errors->validationFailed('post_id is required.', array( 'post_id' => array( 'Post ID is required.' ) ));
        }
        $userId = $context['user_id'] ?? 0;
        if ($userId <= 0) {
            return $this->errors->forbidden('You must be logged in.');
        }
        $cmd = new UpdateContentCommand(
            userId: $userId,
            title: $this->stringParam($arguments, 'title') ?: null,
            content: $this->stringParam($arguments, 'content') ?: null,
            status: $this->stringParam($arguments, 'status') ?: null,
            excerpt: $this->stringParam($arguments, 'excerpt') ?: null,
            meta: array(),
            taxonomyInput: array(),
        );
        try {
            if ($this->isDryRun($arguments)) {
                // Resolve the target first: a dry run must refuse exactly what
                // the real call refuses, including a missing post.
                if (null === $this->c->find($postId, $userId)) {
                    return $this->errors->notFound('The requested post does not exist or you do not have permission to view it.');
                }

                return $this->dryRunPlan(
                    'update',
                    $postId,
                    array(
                        'title'   => $cmd->title,
                        'content' => $cmd->content,
                        'status'  => $cmd->status,
                        'excerpt' => $cmd->excerpt,
                    ),
                    'Dry run: the post would be updated.'
                );
            }

            $item = $this->c->update($postId, $cmd);
            return $this->success('Post updated.', $item->jsonSerialize());
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
                return $this->errors->create('update_failed', $e->getMessage());
        }
    }

    /**
     * Capability flags — Proposal 149 §154.
     *
     * Writes to a local store and persists information. Re-settable in
     * principle, but the previous value is gone, so not `reversible`.
     *
     * @return list<string>
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }
}
