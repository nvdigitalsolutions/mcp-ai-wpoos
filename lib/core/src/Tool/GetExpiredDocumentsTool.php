<?php

/**
 * Report documents whose retention has run out.
 *
 * Port of `WP_MCP_AI_Tool_Get_Expired_Documents` under Proposal 164 §164.38.
 *
 * ## Detection is not disposal
 *
 * ISO 15489 keeps **disposal approval, execution and verification** apart, and
 * this tool is the first of the three: it reports. It does not delete, does not
 * archive, and does not change any frontmatter. A query that destroyed records as
 * a side effect would collapse three reviewable steps into one nobody can audit,
 * and the whole point of a retention schedule is that disposal is a decision
 * someone makes rather than something a report does.
 *
 * Feeding the result to `archive_documents` or `qms_mark_obsolete` is a deliberate
 * second call, by a person.
 *
 * ## Retention runs from a trigger, not from creation
 *
 * ISO 15489 counts a retention period from the event that starts the clock — so a
 * superseded procedure is kept for N months **from supersession**, not from the
 * day it was written. The corpus understands both shapes: a stated `expires_at`,
 * and `retention_months` measured from the record's own lifecycle stamp. The
 * result says which it used, because an auditor has to be able to reconstruct a
 * derived date.
 *
 * ## Unmanaged is not "current"
 *
 * A document with no retention rule is excluded rather than reported as unexpired,
 * because "we have no rule for this" and "this is still in date" are different
 * answers and only one of them is defensible. Excluded documents do not appear in
 * either set, which is why the corpus skips them instead of inventing a date.
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

class GetExpiredDocumentsTool extends AbstractTool
{
    private const MAX_LIMIT = 500;

    /**
     * Disposition actions a schedule may name.
     *
     * ISO 15489's third column: what happens when the period runs out. `review` is
     * the default because a schedule that says nothing should not imply destruction.
     *
     * @var list<string>
     */
    private const DISPOSITIONS = array('review', 'archive', 'destroy', 'retain');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly DocsCorpusInterface $corpus,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_expired_documents';
    }

    public function getName(): string
    {
        return 'Get Expired Documents';
    }

    public function getDescription(): string
    {
        return 'Report documents whose retention period has run out as of a date, with the trigger the period was counted from and the disposition the schedule names. Read-only: this never disposes of anything.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'as_of' => array(
                    'type' => 'string',
                    'description' => 'ISO 8601 date to ask the question at. Defaults to today (UTC).',
                ),
                'disposition' => array(
                    'type' => 'string',
                    'description' => 'Only reports whose schedule names this disposition.',
                    'enum' => self::DISPOSITIONS,
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Maximum documents to return. Default 100, capped at ' . self::MAX_LIMIT . '.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ),
            ),
            'required' => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * Read-only by design: detection and disposal are separate steps.
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

        $asOf = $this->stringParam($arguments, 'as_of');

        if ('' === $asOf) {
            $asOf = gmdate('Y-m-d');
        }

        $limit = max(1, min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', 100)));

        try {
            $result = $this->corpus->expiring($asOf, $limit);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                $e->getMessage(),
                array('as_of' => array('Give an ISO 8601 date.')),
            );
        } catch (\Throwable $e) {
            return $this->errors->create('retention_report_failed', $e->getMessage());
        }

        $disposition = strtolower($this->stringParam($arguments, 'disposition'));
        $documents = $result['documents'];

        if ('' !== $disposition) {
            $documents = array_values(array_filter(
                $documents,
                static fn (array $row): bool => $disposition === strtolower((string) $row['disposition']),
            ));
        }

        return $this->collection(
            sprintf(
                '%d document(s) are past their retention period as of %s. Nothing was disposed of.',
                count($documents),
                $asOf,
            ),
            $documents,
            count($documents),
        );
    }
}
