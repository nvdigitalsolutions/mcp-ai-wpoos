<?php

/**
 * A compliance dashboard over the registration estate.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Compliance_Report` (Proposal 164 section
 * 164.156). Counts registrations by status and country, counts overdue and
 * soon-due registrations, and averages the submission-to-approval interval.
 *
 * ## `format` promised three formats and delivered one
 *
 * The schema accepted `json`, `pdf` and `excel`. The handler read the value,
 * echoed it back as `format`, and returned the same JSON payload every time — a
 * caller that asked for `pdf` received JSON and a field claiming it was a PDF.
 * This runtime has no PDF writer, so the port accepts `json` and **refuses** the
 * other two, naming `generate_pdf` and `generate_excel`, which are served and
 * take a structured payload. A refusal that names the alternative is a better
 * answer than a document that is not what its own response says.
 *
 * ## `custom` without dates meant "everything"
 *
 * The date filter was applied only when `date_range` was `custom` *and* both
 * dates were present. A caller who asked for a custom range and supplied one
 * date got the whole estate with `date_range: "custom"` in the response. The
 * port requires both dates for `custom`, validates them, and refuses the call
 * otherwise.
 *
 * ## Two arithmetic guards
 *
 * `strtotime` returning `false` made a record look decades overdue, as in
 * sections 164.153 and 164.154; unreadable expiry dates are counted in
 * `unreadable_expiry` instead. And `average_approval_days` averaged every
 * submission/approval pair, including pairs where approval preceded submission,
 * which quietly pulled the average down; non-positive intervals are excluded
 * and counted in `approval_intervals_ignored`.
 *
 * `approval_rate` is the share of registrations whose status is approved or
 * active, not the rate among decided applications. The name is kept for shape
 * compatibility and defined here so a reader does not over-read it.
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

class GenerateComplianceReportTool extends RegulatoryTool
{
    /** The statuses counted as active. */
    private const ACTIVE_STATUSES = array('approved', 'active');

    /** The statuses counted as pending. */
    private const PENDING_STATUSES = array('submitted', 'pending', 'under_review');

    /** The window that counts as expiring soon. */
    private const EXPIRING_SOON_DAYS = 90;

    /** The ranges this tool accepts. */
    private const RANGES = array('all', 'last_30_days', 'last_90_days', 'last_year', 'custom');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'generate_compliance_report';
    }

    public function getName(): string
    {
        return 'Generate Compliance Report';
    }

    public function getDescription(): string
    {
        return 'Builds a compliance dashboard: registrations by status and country, expired and soon-to-expire counts, and the average submission-to-approval interval. Returns JSON only; pdf and excel are refused because this runtime serves those through generate_pdf and generate_excel.';
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
                'date_range' => array(
                    'type'        => 'string',
                    'description' => 'Registrations created inside the window. Default all.',
                    'enum'        => self::RANGES,
                    'default'     => 'all',
                ),
                'start_date' => array(
                    'type'        => 'string',
                    'description' => 'Start of a custom range, YYYY-MM-DD. Required with date_range custom.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'end_date'   => array(
                    'type'        => 'string',
                    'description' => 'End of a custom range, YYYY-MM-DD. Required with date_range custom.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'countries'  => array(
                    'type'        => 'array',
                    'description' => 'Only registrations in these countries.',
                    'items'       => array('type' => 'string'),
                ),
                'format'     => array(
                    'type'        => 'string',
                    'description' => 'Report format. Only json is served here.',
                    'enum'        => array('json', 'pdf', 'excel'),
                    'default'     => 'json',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $range   = $this->stringParam($arguments, 'date_range', 'all');
        $format  = $this->stringParam($arguments, 'format', 'json');
        $countries = $this->countryFilter($arguments['countries'] ?? null);

        $fields = array();

        if (! in_array($range, self::RANGES, true)) {
            $fields['date_range'] = array('One of: ' . implode(', ', self::RANGES) . '.');
        }

        if ('json' !== $format) {
            $fields['format'] = array(
                'Only json is served here. Use generate_pdf or generate_excel for a file.',
            );
        }

        $now   = time();
        $start = 'all' === $range ? null : $this->rangeStart($range, $now);
        $end   = null;

        if ('custom' === $range) {
            $window = $this->customWindow($arguments);

            $start  = $window['start'];
            $end    = $window['end'];
            $fields = array_merge($fields, $window['fields']);
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The compliance report cannot be built.', $fields);
        }

        $metaQuery = array('relation' => 'AND');

        if (array() !== $countries) {
            $metaQuery[] = array('key' => 'country', 'value' => $countries, 'compare' => 'IN');
        }

        $items     = $this->collectContent(array(self::REGISTRATION_TYPE), $metaQuery);
        $truncated = count($items) >= self::MAX_COLLECTED;

        $total         = 0;
        $byStatus      = array();
        $byCountry     = array();
        $expiringSoon  = 0;
        $expired       = 0;
        $active        = 0;
        $pending       = 0;
        $unreadable    = 0;
        $approvalDays  = array();
        $ignoredPairs  = 0;

        foreach ($items as $registration) {
            if (! $this->withinRange($registration, $start, $end)) {
                continue;
            }

            ++$total;

            $statuses = $this->statuses($registration);

            if (array() !== $statuses) {
                $name = $statuses[0];
                $byStatus[ $name ] = ( $byStatus[ $name ] ?? 0 ) + 1;

                $key = $this->statusKey($name);

                if (in_array($key, self::ACTIVE_STATUSES, true)) {
                    ++$active;
                } elseif (in_array($key, self::PENDING_STATUSES, true)) {
                    ++$pending;
                }
            }

            $country = $this->text($registration->meta['country'] ?? null);

            if ('' !== $country) {
                $byCountry[ $country ] = ( $byCountry[ $country ] ?? 0 ) + 1;
            }

            $expiryDate = $this->text($registration->meta['expiry_date'] ?? null);

            if ('' !== $expiryDate) {
                $days = $this->daysUntil($expiryDate, $now);

                if (null === $days) {
                    ++$unreadable;
                } elseif ($days < 0) {
                    ++$expired;
                } elseif ($days <= self::EXPIRING_SOON_DAYS) {
                    ++$expiringSoon;
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

            $approvalDays[] = $interval;
        }

        $metrics = array(
            'total_registrations'   => $total,
            'by_status'             => $byStatus,
            'by_country'            => $byCountry,
            'expiring_soon'         => $expiringSoon,
            'expired'               => $expired,
            'active'                => $active,
            'pending'               => $pending,
            'approval_rate'         => $total > 0 ? round(( $active / $total ) * 100, 2) : 0.0,
            'average_approval_days' => array() !== $approvalDays
                ? round(array_sum($approvalDays) / count($approvalDays), 1)
                : 0.0,
            'approval_intervals_ignored' => $ignoredPairs,
            'unreadable_expiry'     => $unreadable,
            'truncated'             => $truncated,
        );

        return $this->success(
            sprintf(
                '%d registration(s): %d active, %d pending, %d expired, %d expiring within %d days.',
                $total,
                $active,
                $pending,
                $expired,
                $expiringSoon,
                self::EXPIRING_SOON_DAYS
            ),
            array(
                'report_type'      => 'compliance',
                'generated_at'     => $this->nowStamp(),
                'date_range'       => $range,
                'window'           => array('start' => $start, 'end' => $end),
                'filter_countries' => $countries,
                'metrics'          => $metrics,
                'format'           => 'json',
            )
        );
    }

    /**
     * The validated custom window and any field errors.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{start: string|null, end: string|null, fields: array<string, array<int, string>>}
     */
    private function customWindow(array $arguments): array
    {
        $start = trim($this->stringParam($arguments, 'start_date'));
        $end   = trim($this->stringParam($arguments, 'end_date'));

        $fields = array();

        if ('' === $start || ! $this->isIsoDate($start)) {
            $fields['start_date'] = array('A date in YYYY-MM-DD form is required for a custom range.');
        }

        if ('' === $end || ! $this->isIsoDate($end)) {
            $fields['end_date'] = array('A date in YYYY-MM-DD form is required for a custom range.');
        }

        if (array() !== $fields) {
            return array('start' => null, 'end' => null, 'fields' => $fields);
        }

        if ($end < $start) {
            return array(
                'start'  => null,
                'end'    => null,
                'fields' => array('end_date' => array('The end date must not precede the start date.')),
            );
        }

        return array('start' => $start, 'end' => $end, 'fields' => array());
    }

    /**
     * The submission-to-approval interval in whole days, or null when either
     * date is absent or unreadable.
     */
    private function approvalInterval(ContentItem $registration): ?int
    {
        $submitted = $this->text($registration->meta['submission_date'] ?? null);
        $approved  = $this->text($registration->meta['approval_date'] ?? null);

        if ('' === $submitted || '' === $approved) {
            return null;
        }

        $start = date_create_immutable($submitted, new \DateTimeZone('UTC'));
        $end   = date_create_immutable($approved, new \DateTimeZone('UTC'));

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
