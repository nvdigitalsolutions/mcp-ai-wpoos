<?php

/**
 * Delete a document from the corpus.
 *
 * Port of `WP_MCP_AI_Tool_Docs_Hub_Delete_Doc` under Proposal 164 §164.34.
 *
 * `dry_run` defaults to **true**, as it does in the WordPress tool and in every
 * other destructive tool here: a caller has to say "yes, really". A dry run
 * reports whether the path exists without touching it, and a missing file is
 * `not_found` either way, so a dry run and a real run agree about what is there.
 *
 * The WordPress `rebuild` flag is not carried over: there is no separate index to
 * rebuild (§164.34), so accepting it would be a parameter that does nothing.
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
use Nvoos\Core\Domain\Error\NotFoundException;

class DocsHubDeleteDocTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly DocsCorpusInterface $corpus,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'docs_hub_delete_doc';
    }

    public function getName(): string
    {
        return 'Docs Hub: Delete Document';
    }

    public function getDescription(): string
    {
        return 'Delete a document from the corpus. Defaults to a dry run that reports what would be removed without removing it.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'path' => array(
                    'type' => 'string',
                    'description' => 'Corpus-relative path, e.g. "guides/install.md".',
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'When true (the default) nothing is deleted; the result says what would be.',
                    'default' => true,
                ),
            ),
            'required' => array('path'),
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
        return array('requires-capability', 'state-changing', 'destructive', 'write');
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

        // A dry run still reads, so the answer is grounded in what is actually
        // there rather than in the caller's assumption.
        try {
            $document = $this->corpus->read($path);
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                $e->getMessage(),
                array('path' => array('The path is not a valid corpus path.')),
            );
        }

        if ($this->boolParam($arguments, 'dry_run', true)) {
            return $this->dryRunPlan(
                'delete',
                null,
                array('path' => $document['path'], 'size' => $document['size']),
                sprintf('Would delete "%s". Nothing was removed.', $document['path']),
            );
        }

        try {
            $this->corpus->delete($path);
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('docs_delete_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Deleted "%s".', $document['path']),
            array('path' => $document['path'], 'deleted' => true),
        );
    }
}
