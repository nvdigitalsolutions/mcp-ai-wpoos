<?php

/**
 * Per-country registration performance.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Country_Performance` (Proposal 164 section
 * 164.157). Groups the estate by country and reports volume, active and pending
 * counts, expired count, approval rate, average approval days and a weighted
 * compliance score, with a cross-country comparison.
 *
 * ## The comparison block was mostly null
 *
 * `generate_comparison()` built three named facts and two averages. Two of the
 * three facts could never be produced:
 *
 * ```php
 * 'highest_approval_rate' => array_keys( $metrics, max( array_column( $metrics, 'approval_rate' ) ) )[0],
 * ```
 *
 * `array_keys( $array, $value )` searches the array's **values**, and the values
 * here are metric rows (arrays), never a float. The search therefore returned an
 * empty array and `[0]` on it produced `null` plus a warning. `fastest_processing`
 * had the identical fault. And `highest_volume` was `array_keys( $metrics )[0]` —
 * simply the first row *after sorting*, so it named whichever country led the
 * caller's chosen sort, not the largest estate.
 *
 * The port computes all three by key comparison over the rows. It also guards
 * the average: `count( array_filter( $approval_days ) )` is zero when every
 * country's average is `0.0`, and dividing by it is a fatal error, so an
 * unmeasured average is reported as `null` rather than as a number.
 *
 * ## One authority per country was whichever came first
 *
 * The authority was read from the first registration seen for a country and
 * reused for all of them, so a country with two authorities reported one, and a
 * first registration with an empty authority made the whole country report `''`.
 * The port keeps `authority` as the first non-empty value seen and adds
 * `authorities`, the distinct list.
 *
 * ## Three arithmetic guards
 *
 * An unreadable expiry is counted as expired — the safe direction — and named in
 * `unreadable_expiry`. An approval interval that precedes its submission is
 * excluded from the average and counted, as in §164.156. `sort_by` is validated
 * against the documented set instead of being used as an array key.
 *
 * The compliance score keeps WordPress's weights: 60% approval rate, 40% expiry
 * compliance. `approval_rate` is the share of registrations that are approved or
 * active, not the share of decided applications.
 *
 * Gated on `read`; WordPress declared `edit_posts` and checked `read`.
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

class GenerateCountryPerformanceTool extends RegulatoryTool
{
    /** The statuses counted as active. */
    private const ACTIVE_STATUSES = array('approved', 'active');

    /** The statuses counted as pending. */
    private const PENDING_STATUSES = array('submitted', 'pending', 'under_review');

    /** The sort keys this tool accepts. */
    private const SORT_KEYS = array('total', 'approval_rate', 'avg_approval_days', 'active');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'generate_country_performance';
    }

    public function getName(): string
    {
        return 'Generate Country Performance Report';
    }

    public function getDescription(): string
    {
        return 'Reports per-country volume, active and pending counts, expired count, approval rate, average approval days and a compliance score weighted 60% approval and 40% expiry, plus a comparison naming the largest estate, the highest approval rate and the fastest authority. Sorted descending by total, approval_rate, avg_approval_days or active.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress checks `read` in the handler and declares `edit_posts`.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'countries'          => array(
                    'type'        => 'array',
                    'description' => 'Only these countries. All countries when omitted.',
                    'items'       => array('type' => 'string'),
                ),
                'include_comparison' => array(
                    'type'        => 'boolean',
                    'description' => 'Include the cross-country comparison. Default true.',
                    'default'     => true,
                ),
                'sort_by'            => array(
                    'type'        => 'string',
                    'description' => 'Sort descending by this metric. Default total.',
                    'enum'        => self::SORT_KEYS,
                    'default'     => 'total',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $countries       = $this->countryFilter($arguments['countries'] ?? null);
        $includeComparison = $this->boolParam($arguments, 'include_comparison', true);
        $sortBy          = $this->stringParam($arguments, 'sort_by', 'total');

        if (! in_array($sortBy, self::SORT_KEYS, true)) {
            return $this->errors->validationFailed(
                'The country report cannot be built.',
                array('sort_by' => array('One of: ' . implode(', ', self::SORT_KEYS) . '.'))
            );
        }

        $metaQuery = array('relation' => 'AND');

        if (array() !== $countries) {
            $metaQuery[] = array('key' => 'country', 'value' => $countries, 'compare' => 'IN');
        }

        $items     = $this->collectContent(array(self::REGISTRATION_TYPE), $metaQuery);
        $truncated = count($items) >= self::MAX_COLLECTED;

        $now     = time();
        $byCountry = array();
        $unreadable = 0;
        $ignoredPairs = 0;

        foreach ($items as $registration) {
            $country = $this->text($registration->meta['country'] ?? null);

            if ('' === $country) {
                $country = 'Unknown';
            }

            if (! isset($byCountry[ $country ])) {
                $byCountry[ $country ] = array(
                    'total'         => 0,
                    'active'        => 0,
                    'pending'       => 0,
                    'expired'       => 0,
                    'approval_days' => array(),
                    'authority'     => '',
                    'authorities'   => array(),
                );
            }

            ++$byCountry[ $country ]['total'];

            $authority = $this->text($registration->meta['authority'] ?? null);

            if ('' !== $authority) {
                if ('' === $byCountry[ $country ]['authority']) {
                    $byCountry[ $country ]['authority'] = $authority;
                }

                if (! in_array($authority, $byCountry[ $country ]['authorities'], true)) {
                    $byCountry[ $country ]['authorities'][] = $authority;
                }
            }

            $statuses = $this->statuses($registration);

            if (array() !== $statuses) {
                $key = $this->statusKey($statuses[0]);

                if (in_array($key, self::ACTIVE_STATUSES, true)) {
                    ++$byCountry[ $country ]['active'];
                } elseif (in_array($key, self::PENDING_STATUSES, true)) {
                    ++$byCountry[ $country ]['pending'];
                }
            }

            $expiryDate = $this->text($registration->meta['expiry_date'] ?? null);

            if ('' !== $expiryDate) {
                $days = $this->daysUntil($expiryDate, $now);

                if (null === $days) {
                    ++$unreadable;
                    ++$byCountry[ $country ]['expired'];
                } elseif ($days < 0) {
                    ++$byCountry[ $country ]['expired'];
                }
            }

            $interval = $this->approvalInterval($registration);

            if (null === $interval) {
                continue;
            }

            if ($interval < 0) {
                ++$ignoredPairs;

                continue;
            }

            $byCountry[ $country ]['approval_days'][] = $interval;
        }

        $metrics = array();

        foreach ($byCountry as $country => $data) {
            // A row exists only because a registration was counted into it, so
            // `total` is at least one; the guard keeps a future change to the
            // loop from turning that invariant into a division by zero.
            $approvalRate = round(( $data['active'] / max(1, $data['total']) ) * 100, 2);

            $average = array() !== $data['approval_days']
                ? round(array_sum($data['approval_days']) / count($data['approval_days']), 1)
                : 0.0;

            $metrics[ $country ] = array(
                'country'             => $country,
                'authority'           => $data['authority'],
                'authorities'         => $data['authorities'],
                'total_registrations' => $data['total'],
                'active'              => $data['active'],
                'pending'             => $data['pending'],
                'expired'             => $data['expired'],
                'approval_rate'       => $approvalRate,
                'avg_approval_days'   => $average,
                'approval_samples'    => count($data['approval_days']),
                'compliance_score'    => $this->complianceScore($data, $approvalRate),
            );
        }

        uasort(
            $metrics,
            static fn (array $a, array $b): int => array($b[ self::sortKey($sortBy) ], $a['country'])
                <=> array($a[ self::sortKey($sortBy) ], $b['country'])
        );

        return $this->success(
            sprintf(
                '%d countr(ies), %d registration(s)%s.',
                count($metrics),
                count($items),
                $truncated ? ' (capped at ' . self::MAX_COLLECTED . ')' : ''
            ),
            array(
                'report_type'         => 'country_performance',
                'generated_at'        => $this->nowStamp(),
                'total_countries'     => count($metrics),
                'total_registrations' => count($items),
                'truncated'           => $truncated,
                'metrics'             => $metrics,
                'comparison'          => $includeComparison && count($metrics) > 1
                    ? $this->comparison($metrics)
                    : array(),
                'unreadable_expiry'        => $unreadable,
                'approval_intervals_ignored' => $ignoredPairs,
                'sorted_by'           => $sortBy,
            )
        );
    }

    /**
     * The metric key a `sort_by` argument names.
     */
    private static function sortKey(string $sortBy): string
    {
        return match ($sortBy) {
            'approval_rate'     => 'approval_rate',
            'avg_approval_days' => 'avg_approval_days',
            'active'            => 'active',
            default             => 'total_registrations',
        };
    }

    /**
     * WordPress's weighted score: 60% approval rate, 40% expiry compliance.
     *
     * @param array<string, mixed> $data
     */
    private function complianceScore(array $data, float $approvalRate): float
    {
        $score = $approvalRate * 0.6;

        $total = (int) ( $data['total'] ?? 0 );

        if ($total > 0) {
            $expiredRatio = ( (int) ( $data['expired'] ?? 0 ) ) / $total;

            $score += ( 1 - $expiredRatio ) * 40;
        }

        return round($score, 2);
    }

    /**
     * The three named countries and two averages.
     *
     * Every value is found by comparing the metric rows, and an average nobody
     * measured is `null` rather than a division by zero.
     *
     * @param array<string, array<string, mixed>> $metrics
     *
     * @return array<string, mixed>
     */
    private function comparison(array $metrics): array
    {
        $largest  = null;
        $bestRate = null;
        $fastest  = null;

        $rates    = array();
        $measured = array();

        foreach ($metrics as $country => $row) {
            $name = (string) $country;

            if (null === $largest || $row['total_registrations'] > $metrics[ $largest ]['total_registrations']) {
                $largest = $name;
            }

            if (null === $bestRate || $row['approval_rate'] > $metrics[ $bestRate ]['approval_rate']) {
                $bestRate = $name;
            }

            $rates[] = (float) $row['approval_rate'];

            // A country with no measured interval has an average of 0.0 that
            // means "unknown", not "instant", so it cannot win fastest.
            if ((int) $row['approval_samples'] > 0) {
                $measured[] = (float) $row['avg_approval_days'];

                if (null === $fastest || $row['avg_approval_days'] < $metrics[ $fastest ]['avg_approval_days']) {
                    $fastest = $name;
                }
            }
        }

        return array(
            'highest_volume'        => $largest,
            'highest_approval_rate' => $bestRate,
            'fastest_processing'    => $fastest,
            'avg_approval_rate'     => array() !== $rates
                ? round(array_sum($rates) / count($rates), 2)
                : null,
            'avg_processing_days'   => array() !== $measured
                ? round(array_sum($measured) / count($measured), 1)
                : null,
            'countries_compared'    => count($metrics),
        );
    }

    /**
     * The submission-to-approval interval in whole days, or null.
     */
    private function approvalInterval(ContentItem $registration): ?int
    {
        $submitted = $this->text($registration->meta['submission_date'] ?? null);
        $approved  = $this->text($registration->meta['approval_date'] ?? null);

        if ('' === $submitted || '' === $approved) {
            return null;
        }

        $utc   = new \DateTimeZone('UTC');
        $start = date_create_immutable($submitted, $utc);
        $end   = date_create_immutable($approved, $utc);

        if (false === $start || false === $end) {
            return null;
        }

        return (int) $start->diff($end)->format('%r%a');
    }

    /**
     * The status term names attached to a registration.
     *
     * @return list<string>
     */
    private function statuses(ContentItem $registration): array
    {
        try {
            return $this->termNames(
                $this->content->getTaxonomyTerms($registration->id),
                self::STATUS_TAX
            );
        } catch (\Throwable $e) {
            return array();
        }
    }

    /**
     * The requested country filter, trimmed and de-duplicated.
     *
     * @return list<string>
     */
    private function countryFilter(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $countries = array();

        foreach ($raw as $country) {
            $value = $this->text($country);

            if ('' !== $value && ! in_array($value, $countries, true)) {
                $countries[] = $value;
            }
        }

        return $countries;
    }
}
