<?php

/**
 * Archive CRM leads and customers with no recent activity.
 *
 * Ported from `WP_MCP_AI_Tool_Archive_Stale_Contacts`. The candidate rule is
 * the WordPress one, and it has two parts that are easy to miss:
 *
 * 1. Only contacts **created** at or before the cutoff are candidates
 *    (`date_query` with `before`, inclusive). A brand-new contact is never
 *    archived even with no activity.
 * 2. A candidate is stale when its newest activity is absent or older than the
 *    cutoff. The activity's own date — not its modified date — is the clock.
 *
 * `dry_run` defaults to **true**, so a caller must opt in to the write.
 *
 * ## The activity link, bridged
 *
 * WordPress looks for `_activity_contact_id`, which nothing in the toolkit
 * writes. The port uses the shared `contactActivityClauses()`, so an activity
 * linked the way `create_crm_activity` links it (`related_type` + `related_id`)
 * counts as activity too. Without that, every contact would read as stale.
 *
 * ## What "archive" means
 *
 * Not a delete and not a custom status: the item's status becomes `draft`, and
 * three meta keys record the decision — `_archived_date`, `_archive_reason`,
 * `_archived_by`. The core has one store, so the same three keys are written.
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;

class ArchiveStaleContactsTool extends CrmTool
{
    /** WordPress' inactivity default, in days. */
    public const DEFAULT_DAYS = 365;

    /** WordPress' lower bound. */
    public const MIN_DAYS = 30;

    /** WordPress' upper bound. */
    public const MAX_DAYS = 3650;

    /** Rows per store page while collecting candidates. */
    private const PAGE_CHUNK = 200;

    /** The `contact_type` values, mapped to content types. */
    private const CONTACT_TYPES = array(
        'lead'     => self::LEAD_TYPE,
        'customer' => self::CUSTOMER_TYPE,
    );

    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?SettingsStoreInterface $settings = null,
    ) {
        parent::__construct($e, $content, $settings);
    }

    public function getSlug(): string
    {
        return 'archive_stale_contacts';
    }

    public function getName(): string
    {
        return 'Archive Stale Contacts';
    }

    public function getDescription(): string
    {
        return 'Archives CRM contacts (leads and/or customers) that have had no activity for a specified period. Supports dry_run mode to preview which contacts would be archived.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'days_inactive' => array(
                    'type'        => 'integer',
                    'description' => 'Number of days without activity before a contact is considered stale. Default: 365.',
                    'default'     => self::DEFAULT_DAYS,
                    'minimum'     => self::MIN_DAYS,
                    'maximum'     => self::MAX_DAYS,
                ),
                'contact_type'  => array(
                    'type'        => 'string',
                    'description' => 'Type of contacts to archive. Default: all.',
                    'enum'        => array('lead', 'customer', 'all'),
                    'default'     => 'all',
                ),
                'dry_run'       => array(
                    'type'        => 'boolean',
                    'description' => 'If true, preview which contacts would be archived without making changes. Default: true.',
                    'default'     => true,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $days        = min(max($this->intParam($arguments, 'days_inactive', self::DEFAULT_DAYS), self::MIN_DAYS), self::MAX_DAYS);
        $contactType = $this->key($this->stringParam($arguments, 'contact_type', 'all'));
        $dryRun      = isset($arguments['dry_run']) ? (bool) $arguments['dry_run'] : true;
        $userId      = (int) ( $context['user_id'] ?? 0 );
        $now         = $this->now();
        $cutoff      = gmdate('Y-m-d H:i:s', $now - ( $days * 86400 ));

        $types = array();

        if ('all' === $contactType || 'lead' === $contactType) {
            $types[] = self::LEAD_TYPE;
        }

        if ('all' === $contactType || 'customer' === $contactType) {
            $types[] = self::CUSTOMER_TYPE;
        }

        $archived = array();
        $skipped  = array();

        foreach ($types as $type) {
            foreach ($this->candidates($type, $cutoff) as $item) {
                $lastActivity = $this->latestActivityDate($item->id);
                $isStale      = null === $lastActivity || $lastActivity < $cutoff;

                if (! $isStale) {
                    $skipped[] = array(
                        'id'            => $item->id,
                        'type'          => $type,
                        'title'         => $item->title,
                        'last_activity' => $lastActivity,
                        'reason'        => 'Has recent activity.',
                    );
                    continue;
                }

                $row = array(
                    'id'            => $item->id,
                    'type'          => $type,
                    'title'         => $item->title,
                    'last_activity' => null === $lastActivity ? 'Never' : $lastActivity,
                );

                if (! $dryRun) {
                    try {
                        $this->content->update(
                            $item->id,
                            new UpdateContentCommand(
                                userId: $userId,
                                status: 'draft',
                                meta: array(
                                    '_archived_date'   => gmdate('Y-m-d H:i:s', $now),
                                    '_archive_reason'  => 'Stale contact (no recent activity).',
                                    '_archived_by'     => $userId,
                                ),
                            )
                        );
                    } catch (\Throwable $e) {
                        $row['reason'] = $e->getMessage();
                        $skipped[]     = $row;
                        continue;
                    }

                    $row['new_status'] = 'draft';
                }

                $archived[] = $row;
            }
        }

        $action = $dryRun
            ? 'Dry run completed. No contacts were modified.'
            : 'Stale contacts archived successfully.';

        return $this->success(
            $action,
            array(
                'dry_run'        => $dryRun,
                'days_inactive'  => $days,
                'cutoff_date'    => $cutoff,
                'action'         => $action,
                'archived_count' => count($archived),
                'skipped_count'  => count($skipped),
                'contacts'       => array(
                    'archived' => $archived,
                    'skipped'  => $skipped,
                ),
            )
        );
    }

    /**
     * Contacts of one type created at or before the cutoff, newest first.
     *
     * @return list<ContentItem>
     */
    private function candidates(string $type, string $cutoff): array
    {
        $candidates = array();
        $page       = 1;

        do {
            $result = $this->content->query(
                new ContentQuery(
                    types: array($type),
                    statuses: array('publish'),
                    orderBy: 'date',
                    order: 'DESC',
                    page: $page,
                    perPage: self::PAGE_CHUNK,
                )
            );

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || $type !== $item->type) {
                    continue;
                }

                // `date_query` with `before` is inclusive: the cutoff itself
                // qualifies.
                if ($item->createdAt->format('Y-m-d H:i:s') <= $cutoff) {
                    $candidates[] = $item;
                }
            }

            $exhausted = count($result->items) < self::PAGE_CHUNK || $page >= $result->totalPages;
            ++$page;
        } while (! $exhausted);

        return $candidates;
    }

    /**
     * The newest activity's date for a contact, or null when it has none.
     */
    private function latestActivityDate(int $contactId): ?string
    {
        $result = $this->content->query(
            new ContentQuery(
                types: array(self::ACTIVITY_TYPE),
                statuses: array('publish'),
                metaQuery: $this->contactActivityClauses($contactId),
                orderBy: 'date',
                order: 'DESC',
                page: 1,
                perPage: 1,
            )
        );

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::ACTIVITY_TYPE === $item->type) {
                return $item->createdAt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
