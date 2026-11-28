<?php

/**
 * Registration pipeline analytics.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Pipeline_Report` (Proposal 164 section
 * 164.157). Groups the estate by status, country, product or creation month,
 * reports the stage each group represents, the average days a registration has
 * spent since submission, the three slowest stages, and a six-month volume
 * trend.
 *
 * ## The bottleneck list reordered the map it was built from
 *
 * WordPress sorted `$average_stage_times` in place with `arsort()` and then
 * sliced the top three. The same array was also returned as
 * `average_stage_times`, so the response carried a map ordered by *value* —
 * which reads as arbitrary unless the caller knows why. The port sorts a copy
 * for the bottleneck list and returns `average_stage_times` keyed by stage, so
 * the two fields mean what their names say. `bottlenecks` is a list of
 * `{stage, days, samples}` rather than a map slice, because the sample count is
 * what tells a reader whether a slow stage is slow or just measured once.
 *
 * ## The trend window could skip or duplicate a month
 *
 * The six buckets were built with `strtotime( "-{$i} months" )`, which is month
 * arithmetic on today's day number and overflows from the 29th to the 31st — the
 * fault §164.154 fixed in the forecast. Here the consequence is a *duplicate*
 * month key (the later count overwrites the earlier) or a missing one, so a
 * six-month trend could show five buckets. The port builds the keys from the
 * first day of the current month.
 *
 * ## `total_registrations` did not describe the pipeline
 *
 * It was the query's `found_posts` (every match), while `pipeline` was built
 * from the first 1000 rows. A caller comparing the two could not tell whether
 * the estate was larger or the read was capped. The port reports the count it
 * actually grouped and sets `truncated`.
 *
 * ## Stage time is time since submission, not time in the stage
 *
 * The name says "stage", the arithmetic is `approval_date ?? now` minus
 * `submission_date`, so a registration approved six months after submission
 * contributes six months to whichever stage it currently sits in. That is what
 * WordPress measured and the field is kept, with the definition stated here:
 * it is age since submission, used as a proxy for pipeline friction. A negative
 * interval (approval preceding submission) is nonsense and is excluded and
 * counted rather than averaged in.
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

class GeneratePipelineReportTool extends RegulatoryTool
{
    /** The groupings this tool accepts. */
    private const GROUPINGS = array('status', 'country', 'product', 'month');

    /** The months the volume trend covers. */
    private const TREND_MONTHS = 6;

    /** How many slow stages the bottleneck list names. */
    private const BOTTLENECKS = 3;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'generate_pipeline_report';
    }

    public function getName(): string
    {
        return 'Generate Pipeline Report';
    }

    public function getDescription(): string
    {
        return 'Groups registrations by status, country, product or creation month and reports each group, the average days since submission per stage, the three slowest stages with their sample counts, and a six-month volume trend by creation date.';
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
                'grouping'            => array(
                    'type'        => 'string',
                    'description' => 'Group registrations by. Default status.',
                    'enum'        => self::GROUPINGS,
                    'default'     => 'status',
                ),
                'include_trends'      => array(
                    'type'        => 'boolean',
                    'description' => 'Include the six-month volume trend. Default true.',
                    'default'     => true,
                ),
                'include_bottlenecks' => array(
                    'type'        => 'boolean',
                    'description' => 'Include the slowest stages. Default true.',
                    'default'     => true,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $grouping = $this->stringParam($arguments, 'grouping', 'status');

        if (! in_array($grouping, self::GROUPINGS, true)) {
            return $this->errors->validationFailed(
                'The pipeline report cannot be built.',
                array('grouping' => array('One of: ' . implode(', ', self::GROUPINGS) . '.'))
            );
        }

        $includeTrends      = $this->boolParam($arguments, 'include_trends', true);
        $includeBottlenecks = $this->boolParam($arguments, 'include_bottlenecks', true);

        $items     = $this->collectContent(array(self::REGISTRATION_TYPE));
        $truncated = count($items) >= self::MAX_COLLECTED;

        $now    = time();
        $groups = array();

        /** @var array<string, list<int>> $stageTimes */
        $stageTimes   = array();
        $unreadable   = 0;
        $ignoredPairs = 0;

        foreach ($items as $registration) {
            $key = $this->groupKey($registration, $grouping);

            if (! isset($groups[ $key ])) {
                $groups[ $key ] = array('count' => 0, 'registrations' => array());
            }

            ++$groups[ $key ]['count'];
            $groups[ $key ]['registrations'][] = $registration->id;

            $submitted = $this->text($registration->meta['submission_date'] ?? null);

            if ('' === $submitted) {
                continue;
            }

            $start = date_create_immutable($submitted, new \DateTimeZone('UTC'));

            if (false === $start) {
                ++$unreadable;

                continue;
            }

            $approved = $this->text($registration->meta['approval_date'] ?? null);
            $end      = '' !== $approved
                ? date_create_immutable($approved, new \DateTimeZone('UTC'))
                : date_create_immutable(gmdate('Y-m-d', $now), new \DateTimeZone('UTC'));

            if (false === $end) {
                ++$unreadable;

                continue;
            }

            $days = (int) $start->diff($end)->format('%r%a');

            if ($days < 0) {
                ++$ignoredPairs;

                continue;
            }

            $statuses = $this->statuses($registration);

            if (array() === $statuses) {
                continue;
            }

            $stage = $statuses[0];

            $stageTimes[ $stage ]   = $stageTimes[ $stage ] ?? array();
            $stageTimes[ $stage ][] = $days;
        }

        ksort($groups);

        $averageStageTimes = array();

        foreach ($stageTimes as $stage => $times) {
            $averageStageTimes[ (string) $stage ] = round(array_sum($times) / count($times), 1);
        }

        ksort($averageStageTimes);

        $bottlenecks = $includeBottlenecks
            ? $this->bottlenecks($averageStageTimes, $stageTimes)
            : array();

        $trends = $includeTrends ? $this->trends($items, $now) : array();

        return $this->success(
            sprintf(
                '%d registration(s) in %d %s group(s)%s.',
                count($items),
                count($groups),
                $grouping,
                $truncated ? ' (capped at ' . self::MAX_COLLECTED . ')' : ''
            ),
            array(
                'report_type'                => 'pipeline',
                'generated_at'               => $this->nowStamp(),
                'grouping'                   => $grouping,
                'total_registrations'        => count($items),
                'truncated'                  => $truncated,
                'pipeline'                   => $groups,
                'average_stage_times'        => $averageStageTimes,
                'bottlenecks'                => $bottlenecks,
                'trends'                     => $trends,
                'unreadable_dates'           => $unreadable,
                'negative_intervals_ignored' => $ignoredPairs,
            )
        );
    }

    /**
     * The three slowest stages, with the sample count behind each average.
     *
     * @param array<string, float>      $averages
     * @param array<string, list<int>>  $samples
     *
     * @return list<array{stage: string, days: float, samples: int}>
     */
    private function bottlenecks(array $averages, array $samples): array
    {
        $sorted = $averages;

        arsort($sorted);

        $rows = array();

        foreach (array_slice($sorted, 0, self::BOTTLENECKS, true) as $stage => $days) {
            $rows[] = array(
                'stage'   => (string) $stage,
                'days'    => $days,
                'samples' => count($samples[ $stage ] ?? array()),
            );
        }

        return $rows;
    }

    /**
     * Volume by creation month over the last six months.
     *
     * The bucket keys come from month arithmetic anchored to the first of the
     * month, so no month is skipped or counted twice.
     *
     * @param list<ContentItem> $items
     *
     * @return array<string, int>
     */
    private function trends(array $items, int $now): array
    {
        $utc   = new \DateTimeZone('UTC');
        $first = date_create_immutable(gmdate('Y-m-01', $now), $utc);

        if (false === $first) {
            return array();
        }

        $months = array();

        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $months[] = $first->modify('-' . $i . ' months')->format('Y-m');
        }

        $counts = array_fill_keys($months, 0);

        foreach ($items as $item) {
            $month = $item->createdAt->format('Y-m');

            if (array_key_exists($month, $counts)) {
                ++$counts[ $month ];
            }
        }

        return $counts;
    }

    /**
     * The grouping key for one registration.
     */
    private function groupKey(ContentItem $registration, string $grouping): string
    {
        switch ($grouping) {
            case 'month':
                return $registration->createdAt->format('Y-m');

            case 'country':
                $country = $this->text($registration->meta['country'] ?? null);

                return '' !== $country ? $country : 'Unknown';

            case 'product':
                $productId = $this->intValue($registration->meta['product_id'] ?? null);

                if ($productId < 1) {
                    return 'Unknown';
                }

                $product = $this->content->find($productId);

                return $product instanceof ContentItem && self::PRODUCT_TYPE === $product->type
                    ? $product->title
                    : 'Unknown';
        }

        $statuses = $this->statuses($registration);

        return array() !== $statuses ? $statuses[0] : 'Unknown';
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
}
