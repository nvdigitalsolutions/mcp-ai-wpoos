<?php

/**
 * Read one document from the corpus.
 *
 * Port of `WP_MCP_AI_Tool_Docs_Hub_Read_Doc` under Proposal 164 §164.34.
 *
 * A missing document and a refused path are reported differently — `not_found`
 * against `validation_failed` — because a caller that cannot tell them apart will
 * rewrite the path when the real problem is that the file is gone.
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
use Nvoos\Core\Domain\Error\NotFoundException;

class DocsHubReadDocTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly DocsCorpusInterface $corpus,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'docs_hub_read_doc';
    }

    public function getName(): string
    {
        return 'Docs Hub: Read Document';
    }

    public function getDescription(): string
    {
        return 'Read one document from the corpus by its relative path, returning its content, frontmatter title, size and modified time.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'path' => array(
                    'type' => 'string',
                    'description' => 'Corpus-relative path, e.g. "guides/install.md". Must end in one of the served extensions.',
                ),
            ),
            'required' => array('path'),
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

        $path = $this->stringParam($arguments, 'path');

        if ('' === $path) {
            return $this->errors->validationFailed(
                'The path is required.',
                array('path' => array('Give a corpus-relative path.')),
            );
        }

        try {
            return $this->success('Document read.', $this->corpus->read($path));
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                $e->getMessage(),
                array('path' => array('The path is not a valid corpus path.')),
            );
        } catch (\Throwable $e) {
            return $this->errors->create('docs_read_failed', $e->getMessage());
        }
    }
}
