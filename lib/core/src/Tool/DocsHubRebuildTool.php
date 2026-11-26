<?php

/**
 * Scan the corpus and report what it holds.
 *
 * Port of `WP_MCP_AI_Tool_Docs_Hub_Rebuild` under Proposal 164 §164.34.
 *
 * The WordPress tool queues a job that regenerates the docs-hub plugin's site
 * index, and offers `mode: async|sync` plus `reset`. None of that survives the
 * port, and pretending otherwise would be the "accepted and ignored" defect this
 * cluster keeps fixing:
 *
 *  - **There is no index.** The filesystem is authoritative here, so a scan *is*
 *    the rebuild.
 *  - **`async` has nothing to schedule.** The scan is bounded by the corpus and
 *    runs in the call.
 *  - **`reset` has nothing to clear.** Dropping it means a caller cannot ask for
 *    something that would not happen.
 *
 * What the scan does report is the two things a listing hides: documents that
 * collide on title, and documents that could not be read.
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

class DocsHubRebuildTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly DocsCorpusInterface $corpus,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'docs_hub_rebuild';
    }

    public function getName(): string
    {
        return 'Docs Hub: Scan Corpus';
    }

    public function getDescription(): string
    {
        return 'Scan the corpus and report how many documents it holds, which titles collide, and which files could not be read. There is no separate index to rebuild.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    /**
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

        try {
            $result = $this->corpus->rebuild();
        } catch (\Throwable $e) {
            return $this->errors->create('docs_scan_failed', $e->getMessage());
        }

        $summary = sprintf('Scanned %d document(s).', $result['indexed']);

        if (array() !== $result['duplicates']) {
            $summary .= sprintf(' %d title(s) collide.', \count($result['duplicates']));
        }

        if (array() !== $result['unreadable']) {
            $summary .= sprintf(' %d file(s) could not be read.', \count($result['unreadable']));
        }

        return $this->success($summary, $result);
    }
}
