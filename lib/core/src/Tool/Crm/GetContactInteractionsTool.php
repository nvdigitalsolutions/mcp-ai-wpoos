<?php

/**
 * The interaction timeline for one CRM contact — `mcp_ai_crm_activity`.
 *
 * Ported from `WP_MCP_AI_Tool_Get_Contact_Interactions`: the same five
 * arguments, the same `all` default, the same 50/500 limit clamp, newest
 * first, and the same payload keys. It is a `read` tool, as WordPress gates it.
 *
 * ## Two activity vocabularies
 *
 * Reading the toolkit line by line found that the activities are written and
 * read through **different meta families**:
 *
 * - `create_crm_activity` (the toolkit's only activity writer) stamps
 *   `activity_type`, `related_type` and `related_id`.
 * - This tool's WordPress original filters on `_activity_contact_id` and reads
 *   `_activity_type`, `_activity_outcome` and `_activity_duration`.
 *
 * Nothing in the toolkit writes an `_activity_*` key (checked across
 * `addons/pro/includes/tools/crm/`: three readers, zero writers). Ported
 * literally, the tool could never return a row the toolkit itself created.
 *
 * The port therefore reads **both** families: the legacy underscore keys
 * first, so an already-migrated export keeps working, then the live keys the
 * writer stamps. The query links on either `_activity_contact_id` or a
 * `related_id` whose `related_type` is a contact-bearing kind (`lead` or
 * `contact` — the dual-store means a legacy contact is a lead). This is a
 * documented deviation from the WordPress original and an upstream bug
 * candidate; see Proposal 164 §164.64.
 *
 * ## Date filtering
 *
 * `ContentQuery` has no date-range clause, and filtering after a single page
 * would silently drop in-range rows whenever the newest page is out of range.
 * The tool therefore walks the query in 200-row pages, keeps the items in
 * range, and stops once `limit` rows are collected or the results are
 * exhausted (bounded by `MAX_PAGES`). `date_from` / `date_to` are inclusive,
 * as WordPress' `date_query` is.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

class GetContactInteractionsTool extends CrmTool
{
    /** The WordPress clamp: 50 by default, never more than 500. */
    public const MAX_LIMIT = 500;

    /** Rows per store page when a date range forces an in-memory walk. */
    private const PAGE_CHUNK = 200;

    /** A hard stop for the walk, so a sparse range cannot scan forever. */
    private const MAX_PAGES = 100;

    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?SettingsStoreInterface $settings = null,
    ) {
        parent::__construct($e, $content, $settings);
    }

    public function getSlug(): string
    {
        return 'get_contact_interactions';
    }

    public function getName(): string
    {
        return 'Get Contact Interactions';
    }

    public function getDescription(): string
    {
        return 'Retrieves all interactions (emails, calls, meetings, notes) for a CRM contact, optionally filtered by type or date range. Returns interaction details sorted by date.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress gates this read on `read`, not the family default.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'contact_id'       => array(
                    'type'        => 'integer',
                    'description' => 'CRM contact ID. Required.',
                    'minimum'     => 1,
                    'maximum'     => 9007199254740991,
                ),
                'interaction_type' => array(
                    'type'        => 'string',
                    'description' => 'Filter by interaction type. Default: all.',
                    'enum'        => array('email', 'call', 'meeting', 'note', 'all'),
                    'default'     => 'all',
                ),
                'date_from'        => array(
                    'type'        => 'string',
                    'description' => 'Filter interactions from this date (YYYY-MM-DD).',
                ),
                'date_to'          => array(
                    'type'        => 'string',
                    'description' => 'Filter interactions up to this date (YYYY-MM-DD).',
                ),
                'limit'            => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of interactions to return. Default: 50.',
                    'default'     => 50,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                ),
            ),
            'required'             => array('contact_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $contactId = $this->intParam($arguments, 'contact_id');

        if ($contactId <= 0) {
            return $this->errors->validationFailed(
                'A valid contact_id is required.',
                array('contact_id' => array('Give the contact to read interactions for.')),
            );
        }

        $type = $this->key($this->stringParam($arguments, 'interaction_type', 'all'));

        if ('' === $type) {
            $type = 'all';
        }

        $dateFrom = trim($this->stringParam($arguments, 'date_from'));
        $dateTo   = trim($this->stringParam($arguments, 'date_to'));

        foreach (array('date_from' => $dateFrom, 'date_to' => $dateTo) as $field => $value) {
            if ('' !== $value && ! $this->isIsoDate($value)) {
                return $this->errors->validationFailed(
                    $field . ' must be a date in YYYY-MM-DD format.',
                    array($field => array('Use YYYY-MM-DD.')),
                );
            }
        }

        $limit = min(max($this->intParam($arguments, 'limit', 50), 1), self::MAX_LIMIT);

        try {
            $interactions = $this->collect($contactId, $type, $dateFrom, $dateTo, $limit);
        } catch (\Throwable $e) {
            return $this->errors->create('get_contact_interactions_failed', $e->getMessage());
        }

        return $this->success(
            sprintf('Found %d interactions for contact #%d.', count($interactions), $contactId),
            array(
                'contact_id'       => $contactId,
                'total_count'      => count($interactions),
                'interaction_type' => $type,
                'interactions'     => $interactions,
            )
        );
    }

    /**
     * Walk the activity query and project the rows.
     *
     * @return list<array<string, mixed>>
     */
    private function collect(int $contactId, string $type, string $dateFrom, string $dateTo, int $limit): array
    {
        $clauses = array(
            'relation' => 'AND',
            $this->contactActivityClauses($contactId),
        );

        if ('all' !== $type) {
            $clauses[] = array(
                'relation' => 'OR',
                array('key' => '_activity_type', 'value' => $type),
                array('key' => 'activity_type', 'value' => $type),
            );
        }

        $rows = array();
        $page = 1;

        do {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::ACTIVITY_TYPE),
                    statuses: array('publish'),
                    metaQuery: $clauses,
                    orderBy: 'date',
                    order: 'DESC',
                    page: $page,
                    perPage: self::PAGE_CHUNK,
                )
            );

            $reachedOlder = false;

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::ACTIVITY_TYPE !== $item->type) {
                    continue;
                }

                $day = $item->createdAt->format('Y-m-d');

                // Newest first: once we are past `date_from`, nothing later in
                // the walk can be in range.
                if ('' !== $dateFrom && $day < $dateFrom) {
                    $reachedOlder = true;
                    break;
                }

                if ('' !== $dateTo && $day > $dateTo) {
                    continue;
                }

                $rows[] = $this->row($item);

                if (count($rows) >= $limit) {
                    $reachedOlder = true;
                    break;
                }
            }

            $exhausted = count($result->items) < self::PAGE_CHUNK || $page >= $result->totalPages;
            ++$page;
        } while (! $reachedOlder && ! $exhausted && $page <= self::MAX_PAGES);

        return array_slice($rows, 0, $limit);
    }

    /**
     * One interaction row, legacy keys first and live keys as the fallback.
     *
     * @return array<string, mixed>
     */
    private function row(ContentItem $item): array
    {
        $meta = $item->meta;

        return array(
            'id'               => $item->id,
            'type'             => $this->activityType($meta),
            'subject'          => $item->title,
            'date'             => $item->createdAt->format('Y-m-d H:i:s'),
            'outcome'          => $this->activityOutcome($meta),
            'duration_minutes' => $this->intValue($meta['_activity_duration'] ?? 0),
            'summary'          => (string) ( $item->excerpt ?? '' ),
        );
    }
}
