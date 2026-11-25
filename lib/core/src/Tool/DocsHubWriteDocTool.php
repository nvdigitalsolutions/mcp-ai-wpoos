<?php

/**
 * Write a document into the corpus.
 *
 * Port of `WP_MCP_AI_Tool_Docs_Hub_Write_Doc` under Proposal 164 §164.34.
 *
 * Frontmatter is prepended only when the caller supplied a title, slug or order;
 * without them the body is written verbatim so a caller managing its own
 * frontmatter is not given a second, conflicting block. The payload reports
 * `created` so a caller can tell an insert from an overwrite — the WordPress tool
 * cannot, and a tool that silently replaces a document is a data-loss path.
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
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class DocsHubWriteDocTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly DocsCorpusInterface $corpus,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'docs_hub_write_doc';
    }

    public function getName(): string
    {
        return 'Docs Hub: Write Document';
    }

    public function getDescription(): string
    {
        return 'Create or replace a document in the corpus, optionally with YAML frontmatter. Parent directories are created as needed.';
    }

    public function getParametersSchema(): array
    {
        $extensions = $this->corpus->isActive() ? $this->corpus->allowedExtensions() : array('md', 'txt');

        return array(
            'type' => 'object',
            'properties' => array(
                'path' => array(
                    'type' => 'string',
                    'description' => 'Corpus-relative path, e.g. "guides/install.md". Must end in one of: ' . implode(', ', $extensions) . '.',
                ),
                'content' => array(
                    'type' => 'string',
                    'description' => 'The document body. Written verbatim apart from any frontmatter this tool prepends.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Frontmatter title. Omit to leave the body untouched.',
                ),
                'slug' => array(
                    'type' => 'string',
                    'description' => 'Frontmatter slug. Omit for none.',
                ),
                'order' => array(
                    'type' => 'integer',
                    'description' => 'Frontmatter sort order. Omit for none.',
                ),
            ),
            'required' => array('path', 'content'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
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
        $content = $arguments['content'] ?? null;

        if ('' === $path) {
            return $this->errors->validationFailed(
                'The path is required.',
                array('path' => array('Give a corpus-relative path.')),
            );
        }

        if (!is_string($content)) {
            return $this->errors->validationFailed(
                'The content is required.',
                array('content' => array('Give the document body as a string.')),
            );
        }

        $meta = array();

        foreach (array('title', 'slug') as $key) {
            $value = $arguments[$key] ?? '';

            if (is_string($value) && '' !== trim($value)) {
                $meta[$key] = $value;
            }
        }

        if (array_key_exists('order', $arguments) && is_numeric($arguments['order'])) {
            $meta['order'] = (int) $arguments['order'];
        }

        try {
            $result = $this->corpus->write($path, $content, $meta);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('docs_write_failed', $e->getMessage());
        }

        return $this->success(
            $result['created'] ? 'Document created.' : 'Document replaced.',
            $result,
        );
    }
}
