<?php

/**
 * Read stored regulatory updates.
 *
 * Port of `WP_MCP_AI_Tool_Get_Regulatory_Updates` (Proposal 164 section
 * 164.160). Reads `mcp_ai_reg_update` items published since a date, optionally
 * filtered by country, authority and update type.
 *
 * ## The store had no writer and the fallback was fiction
 *
 * WordPress queried the post type `mcp_ai_reg_update`, which is registered
 * nowhere in the plugin, so the query could never return a row. When it returned
 * nothing the tool called `get_sample_updates()` and returned **five hard-coded
 * examples** — a 2024 NMRA GMP certificate change, a MOHAP form version, an SFDA
 * microplastic restriction, a GCC harmonisation, a CDSCO portal launch — with
 * `success: true`, `total: 5` and a message saying five updates were found. A
 * caller could not tell a live feed from a constant, and the dates made the
 * constants look current for as long as nobody checked.
 *
 * The port serves what is stored. An empty store returns no updates and says so;
 * `store_total` separates "nothing is stored" from "the filters matched nothing",
 * which the WordPress response could not express.
 *
 * ## WordPress enforced no capability at all
 *
 * Unlike every sibling in this family, `execute()` never read `$context`. It
 * declared `edit_posts` and checked nothing, so any authenticated caller could
 * run it. The port gates on `read`: the declaration was never enforced, the tool
 * only reads, and every listing in this family gates on `read`.
 *
 * ## Dates and paging
 *
 * `since_date` defaults to 30 days ago and is inclusive, which is what
 * WordPress's `date_query` with `inclusive => true` did. It is validated rather
 * than passed through: an unreadable date produced an empty result there, which
 * reads as "no updates" rather than "bad input". `page` and `per_page` are
 * clamped to the ranges the schema declares, where WordPress passed `absint`
 * straight to `WP_Query` and accepted `per_page` beyond its own documented 100.
 *
 * Rows are ordered newest first by creation date, with the content id breaking
 * ties, so paging is deterministic. The family's 1000-row collection ceiling
 * applies; a store larger than that reports the first 1000 matches.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;

class GetRegulatoryUpdatesTool extends RegulatoryTool
{
    /** The largest page this tool will return. */
    protected const MAX_PER_PAGE = 100;

    /** The page size used when none is given. */
    protected const DEFAULT_PER_PAGE = 20;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_regulatory_updates';
    }

    public function getName(): string
    {
        return 'Get Regulatory Updates';
    }

    public function getDescription(): string
    {
        return 'Reads regulatory updates stored as mcp_ai_reg_update items, newest first, optionally filtered by country, authority and update type. An empty store returns no updates: no sample or example records are substituted.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'country'     => array(
                    'type'        => 'string',
                    'description' => 'Only updates for this country code.',
                    'maxLength'   => 10,
                ),
                'authority'   => array(
                    'type'        => 'string',
                    'description' => 'Only updates from this authority.',
                    'maxLength'   => 100,
                ),
                'since_date'  => array(
                    'type'        => 'string',
                    'description' => 'Earliest publication date, inclusive (YYYY-MM-DD). Default 30 days ago.',
                    'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
                ),
                'update_type' => array(
                    'type'        => 'string',
                    'description' => 'Only updates of this kind.',
                    'enum'        => self::UPDATE_TYPES,
                ),
                'page'        => array(
                    'type'        => 'integer',
                    'description' => 'Page number, 1-based. Default 1.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
                'per_page'    => array(
                    'type'        => 'integer',
                    'description' => 'Updates per page. Default 20, maximum 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => self::DEFAULT_PER_PAGE,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $country    = trim($this->stringParam($arguments, 'country'));
        $authority  = trim($this->stringParam($arguments, 'authority'));
        $updateType = trim($this->stringParam($arguments, 'update_type'));
        $sinceDate  = trim($this->stringParam($arguments, 'since_date'));

        $fields = array();

        if ('' !== $updateType && ! in_array($updateType, self::UPDATE_TYPES, true)) {
            $fields['update_type'] = array(
                'One of: ' . implode(', ', self::UPDATE_TYPES) . '.',
            );
        }

        if ('' !== $sinceDate && ! $this->isIsoDate($sinceDate)) {
            $fields['since_date'] = array('A date in YYYY-MM-DD form.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The updates cannot be read.', $fields);
        }

        if ('' === $sinceDate) {
            $sinceDate = gmdate('Y-m-d', time() - ( 30 * 86400 ));
        }

        $page    = max(1, $this->intParam($arguments, 'page', 1));
        $perPage = $this->intParam($arguments, 'per_page', self::DEFAULT_PER_PAGE);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $stored = $this->collectContent(array(self::UPDATE_TYPE));

        $total   = 0;
        $matched = array();

        foreach ($stored as $item) {
            $meta = $item->meta;

            if ('' !== $country && $this->text($meta['country'] ?? null) !== $country) {
                continue;
            }

            if ('' !== $authority && $this->text($meta['authority'] ?? null) !== $authority) {
                continue;
            }

            if ('' !== $updateType && $this->text($meta['update_type'] ?? null) !== $updateType) {
                continue;
            }

            if (! $this->withinRange($item, $sinceDate, null)) {
                continue;
            }

            ++$total;
            $matched[] = $item;
        }

        usort(
            $matched,
            static function (ContentItem $a, ContentItem $b): int {
                $left  = $a->createdAt->getTimestamp();
                $right = $b->createdAt->getTimestamp();

                if ($left !== $right) {
                    return $right <=> $left;
                }

                return $b->id <=> $a->id;
            }
        );

        $offset = ( $page - 1 ) * $perPage;
        $rows   = array();

        foreach (array_slice($matched, $offset, $perPage) as $item) {
            $rows[] = $this->updateRow($item);
        }

        return $this->success(
            $this->messageFor($total, count($stored), count($rows)),
            array(
                'updates'     => $rows,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'pages'       => (int) ceil($total / $perPage),
                'since_date'  => $sinceDate,
                'store_total' => count($stored),
                'filters'     => array(
                    'country'     => $country,
                    'authority'   => $authority,
                    'update_type' => $updateType,
                ),
            )
        );
    }

    /**
     * The sentence the caller reads, which distinguishes empty from filtered.
     */
    private function messageFor(int $total, int $stored, int $returned): string
    {
        if (0 === $total && 0 === $stored) {
            return 'No regulatory updates are stored. No sample or example records were substituted.';
        }

        if (0 === $total) {
            return sprintf('No regulatory updates matched the filters. %d are stored.', $stored);
        }

        return sprintf('Found %d regulatory update(s), returning %d.', $total, $returned);
    }

    /**
     * One returned update row.
     *
     * @return array<string, mixed>
     */
    private function updateRow(ContentItem $item): array
    {
        $meta = $item->meta;

        return array(
            'update_id'      => $item->id,
            'title'          => $item->title,
            'description'    => $item->content,
            'country'        => $this->text($meta['country'] ?? null),
            'authority'      => $this->text($meta['authority'] ?? null),
            'update_type'    => $this->text($meta['update_type'] ?? null),
            'effective_date' => $this->text($meta['effective_date'] ?? null),
            'reference_url'  => $this->text($meta['reference_url'] ?? null),
            'published_at'   => $item->createdAt->format('Y-m-d H:i:s'),
        );
    }
}
