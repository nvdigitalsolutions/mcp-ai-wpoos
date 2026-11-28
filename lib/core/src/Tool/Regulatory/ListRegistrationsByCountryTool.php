<?php

/**
 * Registrations grouped by country, with statistics — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_List_Registrations_By_Country` (Proposal 164 section
 * 164.147). One read for the country-level view: how many registrations sit in
 * each country, how they split by status, and how many are expired or expiring.
 *
 * ## Three corrections
 *
 * - **Statistics survive `group_by_status`.** WordPress computed a country's
 *   statistics from `registrations`, which is empty when the rows were filed
 *   under `by_status` instead — so asking for both returned correct groups with
 *   an all-zero summary. The port computes the statistics from every row in the
 *   country, however it was grouped.
 * - **`include_stats` defaults to true, as declared.** WordPress declared the
 *   default and read it with `! empty()`, so omitting it meant no statistics.
 * - **The country filter is a country name, not a code.** The schema called it a
 *   "country code" while the filter compared it to the `country` meta, which
 *   holds the name ("Sri Lanka"). The port says what it does.
 *
 * ## One addition
 *
 * WordPress performed no capability check. The port requires `read`: this is a
 * read, and the family's other readers already ask for it.
 *
 * The query is capped at 1000 registrations, the WordPress ceiling, read in
 * pages of the store's 200 so a large account still returns everything up to the
 * cap. `total_registrations` is the whole matching set, not the page, so a
 * capped response is still distinguishable from a complete one.
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
use Nvoos\Core\Domain\Entity\ContentQuery;

class ListRegistrationsByCountryTool extends RegulatoryTool
{
    /** The most registrations one call will read, as WordPress capped it. */
    private const MAX_ROWS = 1000;

    /** The store's per-page ceiling. */
    private const PAGE_SIZE = 200;

    /** Statuses counted as approved in the summary. */
    private const APPROVED_STATUSES = array('approved', 'active');

    /** Statuses counted as pending in the summary. */
    private const PENDING_STATUSES = array('draft', 'submitted', 'under review', 'pending');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'list_registrations_by_country';
    }

    public function getName(): string
    {
        return 'List Registrations by Country';
    }

    public function getDescription(): string
    {
        return 'Groups registrations by country with per-country statistics: status distribution, approved and pending counts, and expired or expiring-soon counts.';
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
                'country'         => array(
                    'type'        => 'string',
                    'description' => 'Exact country name to restrict the view to.',
                    'maxLength'   => 100,
                ),
                'include_stats'   => array(
                    'type'        => 'boolean',
                    'description' => 'Include per-country statistics. Default true.',
                    'default'     => true,
                ),
                'group_by_status' => array(
                    'type'        => 'boolean',
                    'description' => 'File each country rows under their status instead of one list. Default false.',
                    'default'     => false,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $country = trim($this->stringParam($arguments, 'country'));
        $meta    = array();

        if ('' !== $country) {
            $meta[] = array('key' => 'country', 'value' => $country, 'compare' => '=');
        }

        $rows  = array();
        $total = null;
        $page  = 1;

        while (count($rows) < self::MAX_ROWS) {
            try {
                $result = $this->content->query(
                    new ContentQuery(
                        types: array(self::REGISTRATION_TYPE),
                        statuses: array('publish'),
                        metaQuery: $meta,
                        orderBy: 'date',
                        order: 'DESC',
                        page: $page,
                        perPage: self::PAGE_SIZE,
                    )
                );
            } catch (\Throwable $e) {
                return $this->errors->create('list_registrations_by_country_failed', $e->getMessage());
            }

            if (null === $total) {
                $total = $result->total;
            }

            $fetched = 0;

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::REGISTRATION_TYPE !== $item->type) {
                    continue;
                }

                $rows[] = $this->countryRow($item);
                ++$fetched;

                if (count($rows) >= self::MAX_ROWS) {
                    break;
                }
            }

            if ($fetched < self::PAGE_SIZE) {
                break;
            }

            ++$page;
        }

        $groupByStatus = $this->boolParam($arguments, 'group_by_status');
        $includeStats  = $this->boolParam($arguments, 'include_stats', true);
        $countries     = array();

        foreach ($rows as $row) {
            $key = $row['country'];

            if (! isset($countries[ $key ])) {
                $countries[ $key ] = array(
                    'country'       => $key,
                    'registrations' => array(),
                    'count'         => 0,
                    'all'           => array(),
                );
            }

            $countries[ $key ]['count']++;
            $countries[ $key ]['all'][] = $row;

            if ($groupByStatus) {
                $countries[ $key ]['by_status'][ $row['status'] ][] = $row;
            } else {
                $countries[ $key ]['registrations'][] = $row;
            }
        }

        ksort($countries);

        foreach ($countries as &$countryData) {
            unset($countryData['all']);

            if ($includeStats) {
                $countryData['stats'] = $this->countryStats($rows, $countryData['country']);
            }
        }
        unset($countryData);

        return $this->success(
            sprintf(
                'Found %1$d registration(s) across %2$d country/countries.',
                $total,
                count($countries)
            ),
            array(
                'countries'           => array_values($countries),
                'total_countries'     => count($countries),
                'total_registrations' => $total,
            )
        );
    }

    /**
     * One registration in the country view.
     *
     * @return array<string, mixed>
     */
    private function countryRow(ContentItem $item): array
    {
        $meta   = $item->meta;
        $expiry = $this->text($meta['expiry_date'] ?? null);

        $statuses = $this->termNames($this->terms($item), self::STATUS_TAX);

        return array(
            'registration_id' => $item->id,
            'title'           => $item->title,
            'product_id'      => $this->intValue($meta['product_id'] ?? null),
            'country'         => $this->text($meta['country'] ?? null),
            'authority'       => $this->text($meta['authority'] ?? null),
            'status'          => array() !== $statuses ? $statuses[0] : 'Unknown',
            'cos_number'      => $this->text($meta['cos_number'] ?? null),
            'submission_date' => $this->text($meta['submission_date'] ?? null),
            'approval_date'   => $this->text($meta['approval_date'] ?? null),
            'expiry_date'     => $expiry,
            'expiry_status'   => $this->expiryStatus($expiry),
        );
    }

    /**
     * `valid`, `expired` or `expiring_soon`, as WordPress classified it.
     */
    private function expiryStatus(string $expiry): string
    {
        if ('' === $expiry) {
            return 'valid';
        }

        $stamp = strtotime($expiry);

        if (false === $stamp) {
            return 'valid';
        }

        $days = (int) floor(( $stamp - time() ) / 86400);

        if ($days < 0) {
            return 'expired';
        }

        return $days <= 90 ? 'expiring_soon' : 'valid';
    }

    /**
     * The summary for one country, over every row it holds.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function countryStats(array $rows, string $country): array
    {
        $stats = array(
            'total'               => 0,
            'approved'            => 0,
            'pending'             => 0,
            'expired'             => 0,
            'expiring_soon'       => 0,
            'status_distribution' => array(),
        );

        foreach ($rows as $row) {
            if ($country !== $row['country']) {
                continue;
            }

            ++$stats['total'];

            $status = strtolower((string) $row['status']);
            $stats['status_distribution'][ $row['status'] ] = ( $stats['status_distribution'][ $row['status'] ] ?? 0 ) + 1;

            if (in_array($status, self::APPROVED_STATUSES, true)) {
                ++$stats['approved'];
            }

            if (in_array($status, self::PENDING_STATUSES, true)) {
                ++$stats['pending'];
            }

            if ('expired' === $row['expiry_status']) {
                ++$stats['expired'];
            } elseif ('expiring_soon' === $row['expiry_status']) {
                ++$stats['expiring_soon'];
            }
        }

        return $stats;
    }

    /**
     * The terms attached to one item, read defensively.
     *
     * @return array<string, array<int, string>>
     */
    private function terms(ContentItem $item): array
    {
        try {
            return $this->content->getTaxonomyTerms($item->id);
        } catch (\Throwable $e) {
            return array();
        }
    }
}
