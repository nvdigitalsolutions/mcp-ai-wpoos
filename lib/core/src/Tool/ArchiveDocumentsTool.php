<?php

/**
 * Withdraw documents from current use without deleting them.
 *
 * Port of `WP_MCP_AI_Tool_Archive_Documents` under Proposal 164 §164.35.
 *
 * ## What the standard asks for, and why this is not a delete
 *
 * ISO 9001 §7.5.3 requires obsolete documented information to be **clearly
 * marked** as no longer current, **prevented from unintended use**, and
 * **retained** so it can still answer an audit or a dispute. Deleting a
 * superseded revision fails all three. So archiving is a state transition: the
 * file stays, and its frontmatter records that it is withdrawn, when, by whom,
 * and why.
 *
 * Those four fields are the shape 21 CFR Part 11 asks of an audit record —
 * attributable, timestamped, and carrying the meaning of the action — though see
 * §164.35: this runtime has no re-authentication ceremony, so the record is an
 * *attributed* action, not a verified e-signature.
 *
 * ## What changed from the WordPress tool
 *
 * The original takes `document_ids` — WordPress attachment/post ids. There is no
 * post type here, so this takes corpus `paths`, which is what the document store
 * from §164.34 keys on. It keeps the `dry_run` default of **true**, because a
 * tool that withdraws documents by default is a data-loss path.
 *
 * Archiving an already-archived document changes nothing and says so. Re-stamping
 * `archived_at` would destroy the only thing the record exists to prove.
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

class ArchiveDocumentsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The largest batch this tool will process in one call.
     */
    private const MAX_PATHS = 100;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly DocsCorpusInterface $corpus,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'archive_documents';
    }

    public function getName(): string
    {
        return 'Archive Documents';
    }

    public function getDescription(): string
    {
        return 'Withdraw one or more documents from current use by marking them archived in place, recording when, by whom and why. The body is never deleted, so a superseded revision remains readable for audit. Defaults to a dry run.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'paths' => array(
                    'type' => 'array',
                    'description' => 'Corpus-relative paths to withdraw, e.g. ["guides/install.md"]. Up to ' . self::MAX_PATHS . ' per call.',
                    'items' => array('type' => 'string'),
                    'minItems' => 1,
                    'maxItems' => self::MAX_PATHS,
                ),
                'archive_reason' => array(
                    'type' => 'string',
                    'description' => 'Why the documents are being withdrawn. Recorded in the frontmatter so the reason survives with the document.',
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'When true (the default) nothing is written; the result says which documents would be withdrawn.',
                    'default' => true,
                ),
            ),
            'required' => array('paths'),
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

        $paths = $this->stringListParam($arguments, 'paths');

        if (array() === $paths) {
            return $this->errors->validationFailed(
                'At least one path is required.',
                array('paths' => array('Give one or more corpus-relative paths.')),
            );
        }

        if (\count($paths) > self::MAX_PATHS) {
            return $this->errors->validationFailed(
                sprintf('At most %d paths can be archived in one call.', self::MAX_PATHS),
                array('paths' => array('Split the batch.')),
            );
        }

        $reason = $this->stringParam($arguments, 'archive_reason');
        $dryRun = $this->boolParam($arguments, 'dry_run', true);
        $userId = isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : 0;

        $archived = array();
        $withdrawn = array();
        $refused = array();
        $missing = array();

        foreach ($paths as $path) {
            try {
                if ($dryRun) {
                    // Read first so the dry run reports what is actually there,
                    // including whether the document is already withdrawn.
                    $document = $this->corpus->read($path);
                    $withdrawn[] = array('path' => $document['path'], 'title' => $document['title']);

                    continue;
                }

                $result = $this->corpus->archive($path, array('reason' => $reason, 'user_id' => $userId));
                $archived[] = $result;
            } catch (NotFoundException $e) {
                $missing[] = $path;
            } catch (\InvalidArgumentException $e) {
                $refused[] = array('path' => $path, 'reason' => $e->getMessage());
            } catch (\Throwable $e) {
                $refused[] = array('path' => $path, 'reason' => $e->getMessage());
            }
        }

        $payload = array(
            'dry_run' => $dryRun,
            'reason' => $reason,
            'archived' => $archived,
            'already_archived' => $this->filterAlready($archived),
            'not_found' => $missing,
            'refused' => $refused,
        );

        if ($dryRun) {
            return $this->success(
                sprintf('Would withdraw %d document(s). Nothing was written.', \count($withdrawn)),
                array(
                    'dry_run' => true,
                    'action' => 'archive',
                    'id' => null,
                    'would_change' => array('paths' => array_column($withdrawn, 'path'), 'reason' => $reason),
                    'writes' => false,
                    'would_archive' => $withdrawn,
                    'refused' => $refused,
                    'not_found' => $missing,
                ),
            );
        }

        return $this->success(
            sprintf(
                'Withdrew %d document(s)%s.',
                \count($archived),
                array() === $missing && array() === $refused
                    ? ''
                    : sprintf('; %d not found, %d refused', \count($missing), \count($refused)),
            ),
            $payload,
        );
    }

    /**
     * The documents this call left untouched because they were already withdrawn.
     *
     * @param array<int, array<string, mixed>> $archived Result rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterAlready(array $archived): array
    {
        return array_values(array_filter(
            $archived,
            static fn (array $row): bool => true === ($row['already_archived'] ?? false),
        ));
    }
}
