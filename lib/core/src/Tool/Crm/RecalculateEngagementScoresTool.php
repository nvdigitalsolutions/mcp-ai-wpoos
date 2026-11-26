<?php

/**
 * Recalculate 0–100 engagement scores for CRM leads and customers.
 *
 * Ported from `WP_MCP_AI_Tool_Recalculate_Engagement_Scores`. The formula is
 * WordPress' formula, unchanged:
 *
 * | component  | weight | rule                                              |
 * |------------|--------|---------------------------------------------------|
 * | volume     | 40%    | `min(100, activities × 10)`                        |
 * | recency    | 25%    | `max(0, 100 − days_since_last × 2)`                |
 * | diversity  | 20%    | `min(100, unique_types × 25)`                      |
 * | completion | 15%    | `completed / total × 100`, capped at 100           |
 *
 * The weights are a product choice, not an industry standard — public
 * engagement-score write-ups agree on recency decay plus weighted signals and
 * disagree on everything else — so they are ported as declared rather than
 * improved. `days_since_last` is computed against an injectable clock so the
 * arithmetic is testable.
 *
 * ## Three WordPress defects, all on the scoring path
 *
 * 1. **`score_model` is a label, not a switch.** `calculate_engagement_score()`
 *    accepts the argument and never reads it; `custom` produces the same score
 *    as `default`. The port keeps that, including storing the label in
 *    `_engagement_score_model`, because inventing a second model would invent
 *    a contract the schema never defined.
 * 2. **Activities are linked through a key nothing writes.** The WordPress
 *    query filters on `_activity_contact_id`; `create_crm_activity` stamps
 *    `related_type` / `related_id`. The port uses the shared linkage clauses,
 *    so the score actually sees the toolkit's activities.
 * 3. **Completion is structurally zero.** WordPress counts
 *    `_activity_outcome === 'completed'`; no tool writes that key, and the live
 *    `disposition` vocabulary has no `completed` value. The only completion
 *    marker the toolkit writes is `completed = '1'` from
 *    `complete_crm_activity`, which the port accepts. Without this, 15% of
 *    every score is unreachable.
 *
 * The score and the breakdown are computed from one activity fetch. WordPress
 * fetches the same 200 activities twice per contact (once for the score, once
 * for the breakdown); the port's output is identical.
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

class RecalculateEngagementScoresTool extends CrmTool
{
    /** The activity window WordPress scores over. */
    public const MAX_ACTIVITIES = 200;

    /** Rows per store page while collecting "all contacts". */
    private const PAGE_CHUNK = 200;

    /** The weights, as WordPress applies them. */
    private const WEIGHTS = array(
        'volume'     => 0.40,
        'recency'    => 0.25,
        'diversity'  => 0.20,
        'completion' => 0.15,
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
        return 'recalculate_engagement_scores';
    }

    public function getName(): string
    {
        return 'Recalculate Engagement Scores';
    }

    public function getDescription(): string
    {
        return 'Recalculates engagement scores for CRM contacts based on recent activity metrics. Supports standard and custom scoring models, targeting specific contacts or all, with dry_run mode.';
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
                'contact_ids' => array(
                    'type'        => 'array',
                    'description' => 'Array of contact IDs to recalculate. If empty, recalculates all contacts.',
                    'items'       => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                ),
                'score_model' => array(
                    'type'        => 'string',
                    'description' => 'Scoring model to use. Default: default.',
                    'enum'        => array('default', 'custom'),
                    'default'     => 'default',
                ),
                'dry_run'     => array(
                    'type'        => 'boolean',
                    'description' => 'If true, preview scores without saving. Default: true.',
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

        $model  = isset($arguments['score_model'])
            ? $this->text($this->stringParam($arguments, 'score_model'))
            : 'default';
        $dryRun = isset($arguments['dry_run']) ? (bool) $arguments['dry_run'] : true;
        $now    = $this->now();

        $contactIds = $this->requestedIds($arguments);

        if (array() === $contactIds) {
            $contactIds = $this->allContactIds();
        }

        $scored = array();
        $failed = array();

        foreach ($contactIds as $contactId) {
            $item = $this->content->find($contactId);

            if (! $item instanceof ContentItem || ! in_array($item->type, array(self::LEAD_TYPE, self::CUSTOMER_TYPE), true)) {
                $failed[] = array(
                    'id'     => $contactId,
                    'reason' => 'Contact not found or invalid type.',
                );
                continue;
            }

            $activities = $this->activitiesFor($contactId);
            $breakdown  = $this->components($activities, $now);
            $score      = $this->score($breakdown);

            $row = array(
                'id'               => $contactId,
                'type'             => $item->type,
                'title'            => $item->title,
                'engagement_score' => $score,
                'score_breakdown'  => array(
                    'volume'           => (int) round($breakdown['volume']),
                    'recency'          => (int) round($breakdown['recency']),
                    'diversity'        => (int) round($breakdown['diversity']),
                    'completion'       => (int) round($breakdown['completion']),
                    'total_activities' => $breakdown['total_activities'],
                    'days_since_last'  => (int) round($breakdown['days_since_last']),
                ),
            );

            if (! $dryRun) {
                $encoded = json_encode($row['score_breakdown']);

                try {
                    $this->content->update(
                        $contactId,
                        new UpdateContentCommand(
                            userId: (int) ( $context['user_id'] ?? 0 ),
                            meta: array(
                                '_engagement_score'           => $score,
                                '_engagement_score_breakdown' => false !== $encoded ? $encoded : '{}',
                                '_engagement_score_date'      => gmdate('Y-m-d H:i:s', $now),
                                '_engagement_score_model'     => $model,
                            ),
                        )
                    );
                } catch (\Throwable $e) {
                    $failed[] = array(
                        'id'     => $contactId,
                        'reason' => $e->getMessage(),
                    );
                    continue;
                }
            }

            $scored[] = $row;
        }

        $action = $dryRun
            ? 'Dry run completed. No scores were saved.'
            : 'Engagement scores recalculated successfully.';

        return $this->success(
            $action,
            array(
                'dry_run'      => $dryRun,
                'score_model'  => $model,
                'action'       => $action,
                'scored_count' => count($scored),
                'failed_count' => count($failed),
                'contacts'     => array(
                    'scored' => $scored,
                    'failed' => $failed,
                ),
            )
        );
    }

    /**
     * The ids the caller named, mapped the way `array_map( 'absint', … )` does.
     *
     * @param array<string, mixed> $arguments
     * @return list<int>
     */
    private function requestedIds(array $arguments): array
    {
        if (! isset($arguments['contact_ids'])) {
            return array();
        }

        $values = is_array($arguments['contact_ids'])
            ? $arguments['contact_ids']
            : array($arguments['contact_ids']);

        $ids = array();

        foreach ($values as $value) {
            $ids[] = abs($this->intValue($value));
        }

        return $ids;
    }

    /**
     * Every lead then every customer, newest first within each type.
     *
     * @return list<int>
     */
    private function allContactIds(): array
    {
        $ids = array();

        foreach (array(self::LEAD_TYPE, self::CUSTOMER_TYPE) as $type) {
            $page = 1;

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
                    if ($item instanceof ContentItem && $type === $item->type) {
                        $ids[] = $item->id;
                    }
                }

                $exhausted = count($result->items) < self::PAGE_CHUNK || $page >= $result->totalPages;
                ++$page;
            } while (! $exhausted);
        }

        return $ids;
    }

    /**
     * The contact's activities in the scoring window, newest first.
     *
     * @return list<ContentItem>
     */
    private function activitiesFor(int $contactId): array
    {
        $result = $this->content->query(
            new ContentQuery(
                types: array(self::ACTIVITY_TYPE),
                statuses: array('publish'),
                metaQuery: $this->contactActivityClauses($contactId),
                orderBy: 'date',
                order: 'DESC',
                page: 1,
                perPage: self::MAX_ACTIVITIES,
            )
        );

        $activities = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::ACTIVITY_TYPE === $item->type) {
                $activities[] = $item;
            }
        }

        return $activities;
    }

    /**
     * The four components, unrounded.
     *
     * @param list<ContentItem> $activities
     * @return array{volume: float, recency: float, diversity: float, completion: float, total_activities: int, days_since_last: float}
     */
    private function components(array $activities, int $now): array
    {
        $total = count($activities);

        if (0 === $total) {
            return array(
                'volume'           => 0.0,
                'recency'          => 0.0,
                'diversity'        => 0.0,
                'completion'       => 0.0,
                'total_activities' => 0,
                'days_since_last'  => 0.0,
            );
        }

        $volume = min(100, $total * 10);

        $newest    = $activities[0];
        $daysSince = max(0, ( $now - $newest->createdAt->getTimestamp() ) / 86400);
        $recency   = max(0, 100 - ( $daysSince * 2 ));

        $types     = array();
        $completed = 0;

        foreach ($activities as $activity) {
            $types[] = $this->activityType($activity->meta);

            if ($this->activityCompleted($activity->meta)) {
                ++$completed;
            }
        }

        $diversity  = min(100, count(array_unique($types)) * 25);
        $completion = min(100, ( $completed / $total ) * 100);

        return array(
            'volume'           => (float) $volume,
            'recency'          => (float) $recency,
            'diversity'        => (float) $diversity,
            'completion'       => (float) $completion,
            'total_activities' => $total,
            'days_since_last'  => (float) $daysSince,
        );
    }

    /**
     * The weighted total, rounded and clamped to 0–100.
     *
     * @param array<string, mixed> $breakdown
     */
    private function score(array $breakdown): int
    {
        $total = 0.0;

        foreach (self::WEIGHTS as $component => $weight) {
            $total += (float) $breakdown[ $component ] * $weight;
        }

        return max(0, min(100, (int) round($total)));
    }
}
