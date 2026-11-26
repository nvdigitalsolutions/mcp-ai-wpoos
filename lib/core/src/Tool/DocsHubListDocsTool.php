<?php

/**
 * List the documents in the corpus.
 *
 * Port of `WP_MCP_AI_Tool_Docs_Hub_List_Docs` under Proposal 164 §164.34. Reads
 * only; the corpus decides what a path may be, so `subdirectory` is passed
 * through and a refused prefix is a validation error rather than an empty page.
 *
 * `total` is always reported, even when `limit` truncates the list, because a
 * caller paging through a corpus cannot otherwise tell "the end" from "the cap".
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\DocsCorpusInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class DocsHubListDocsTool extends AbstractTool
{
    /**
     * The largest page this tool will return.
     */
    private const MAX_LIMIT = 500;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly DocsCorpusInterface $corpus,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'docs_hub_list_docs';
    }

    public function getName(): string
    {
        return 'Docs Hub: List Documents';
    }

    public function getDescription(): string
    {
        return 'List the Markdown and text documents in the configured corpus, optionally under a subdirectory prefix, with their titles, sizes and modified times.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'subdirectory' => array(
                    'type' => 'string',
                    'description' => 'Corpus-relative prefix, e.g. "guides". Empty lists the whole corpus. A prefix that would escape the corpus is refused.',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Maximum documents to return. Default 100, capped at ' . self::MAX_LIMIT . '. The total is reported regardless.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ),
            ),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * Read-only: no capability beyond the corpus being configured.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (!$this->corpus->isActive()) {
            return $this->errors->create(
                'no_docs_corpus',
                'No docs corpus is configured on this host. Set docs.corpus.root to a directory.',
            );
        }

        $limit = $this->intParam($arguments, 'limit', 100);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        try {
            $result = $this->corpus->list($this->stringParam($arguments, 'subdirectory'), $limit);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                $e->getMessage(),
                array('subdirectory' => array('The prefix is not a valid corpus path.')),
            );
        }

        return $this->collection(
            sprintf('Found %d document(s).', $result['total']),
            $result['documents'],
            $result['total'],
        );
    }
}
