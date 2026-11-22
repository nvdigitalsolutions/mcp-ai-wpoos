<?php

/**
 * File header.
 *
 * @package WP_MCP_AI
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class CountPostsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(ErrorFactoryInterface $errors, private readonly ContentStoreInterface $content)
    {
        parent::__construct($errors);
    }
    public function getSlug(): string
    {
        return 'count_posts';
    }
    public function getName(): string
    {
        return 'Count Posts';
    }
    public function getDescription(): string
    {
        return 'Counts content items matching optional filters: type, status, author, search term.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => array('type' => array('type' => 'string','description' => 'Post type filter'),'status' => array('type' => 'string','description' => 'Status filter'),'author_id' => array('type' => 'integer','description' => 'Author ID filter'),'search' => array('type' => 'string','description' => 'Search term filter')),'additionalProperties' => false);
    }
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }
    public function execute(array $arguments = [], array $context = []): mixed
    {
        $type = $this->stringParam($arguments, 'type');
        $status = $this->stringParam($arguments, 'status');
        $authorId = $arguments['author_id'] ?? null;
        $search = $this->stringParam($arguments, 'search');
        $q = new ContentQuery(types:'' !== $type ? array($type) : [], statuses:'' !== $status ? array($status) : array('publish'), search:'' !== $search ? $search : null, authorId:is_numeric($authorId) ? (int)$authorId : null, perPage:1);
        $result = $this->content->query($q);
        return $this->success("$result->total items found.", array('total' => $result->total,'filters' => array_filter(array('type' => $type ?: null,'status' => $status ?: null,'author_id' => $authorId,'search' => $search ?: null))));
    }

    /**
     * Capability flags — Proposal 149 §154.
     *
     * Reads a local store. Side-effect free, but not cacheable: the store
     * can change between two identical calls.
     *
     * @return list<string>
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_READ;
    }
}
