<?php

/**
 * A bounded paged read over `ContentStoreInterface`.
 *
 * Proposal 164 section 164.85. The store cannot order by meta, so any reader
 * that must return "the earliest" or "the latest" rows has to see a window and
 * sort it itself. This helper owns the window: 200-row pages up to a ceiling,
 * with the collection total alongside so a caller can report whether the window
 * was the whole set.
 *
 * Shared by the event readers and the appointment triage tools so the two
 * families cannot disagree about what "scanned" means.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

final class CalendarScan
{
    /** The store's page ceiling, from `ContentQuery`. */
    public const PAGE_SIZE = 200;

    /** The read ceiling: how many matching rows one call will collect. */
    public const MAX_SCAN = 1000;

    /**
     * Read up to `$max` matching items, page by page.
     *
     * @param ContentQuery $query Base query; page and perPage are replaced.
     * @param int          $max   Items to collect, at most `MAX_SCAN`.
     *
     * @return array{items: list<ContentItem>, total: int, scanned: int}
     */
    public static function scan(ContentStoreInterface $content, ContentQuery $query, int $max = self::MAX_SCAN): array
    {
        $max = max(0, min(self::MAX_SCAN, $max));

        if (0 === $max) {
            return array('items' => array(), 'total' => 0, 'scanned' => 0);
        }

        $items   = array();
        $total   = 0;
        $scanned = 0;
        $page    = 1;

        do {
            $collection = $content->query(
                new ContentQuery(
                    types: $query->types,
                    statuses: $query->statuses,
                    search: $query->search,
                    authorId: $query->authorId,
                    include: $query->include,
                    exclude: $query->exclude,
                    metaQuery: $query->metaQuery,
                    taxQuery: $query->taxQuery,
                    orderBy: $query->orderBy,
                    order: $query->order,
                    page: $page,
                    perPage: self::PAGE_SIZE,
                    userId: $query->userId,
                )
            );

            $total = $collection->total;

            foreach ($collection->items as $item) {
                if (! $item instanceof ContentItem || $scanned >= $max) {
                    continue;
                }

                $items[] = $item;
                ++$scanned;
            }

            ++$page;
        } while ($collection->hasItems() && $collection->hasMorePages() && $scanned < $max && $scanned < $total);

        return array(
            'items'   => $items,
            'total'   => $total,
            'scanned' => $scanned,
        );
    }
}
