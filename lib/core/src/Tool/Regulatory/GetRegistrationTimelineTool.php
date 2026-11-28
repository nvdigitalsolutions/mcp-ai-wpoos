<?php

/**
 * The expected timeline and current phase of a registration.
 *
 * Port of `WP_MCP_AI_Tool_Get_Registration_Timeline` (Proposal 164 section
 * 164.163). Turns a registration's dates into three milestone rows, a progress
 * percentage and a time analysis against a fixed expectation table.
 *
 * ## The expectations are a table, not a fetched schedule
 *
 * Nothing in the WordPress tool contacts an authority. The numbers are
 * hard-coded per registration type and, for three countries, per country. The
 * port keeps them, computes `total` as the sum of the five phases instead of
 * trusting the literal, and returns `basis` so a caller can see which row was
 * chosen. That last field answers a real question: a Sri Lankan `variation` has
 * no country row, so it falls back to **LK/new** — 210 days — rather than to the
 * 81-day default variation schedule, because WordPress checked the country table
 * before the type table. The precedence is kept and named rather than silently
 * corrected, because changing it would change every estimate for those
 * countries.
 *
 * ## An approved registration could never read as complete
 *
 * The review milestone was marked `in_progress` whenever a submission date
 * existed, with no condition on approval:
 *
 * ```php
 * 'status' => ! empty( $submission_date ) ? 'in_progress' : 'pending',
 * ```
 *
 * So once submitted, the review phase never ended. An approved registration
 * scored one completed milestone out of three (66.67%) and reported its current
 * phase as "Under Review" permanently, while the approval row beside it said
 * `completed`. The port completes the review when an approval date exists, so an
 * approved registration reaches 100% and "Completed".
 *
 * The review row's `actual_date` stays null. WordPress never populated it either,
 * and inventing one would claim to know when review ended rather than when
 * approval was recorded.
 *
 * ## Dates are parsed once, and unreadable ones are reported
 *
 * `strtotime()` returns `false` for anything it cannot read, and `false` is `0`,
 * so an unreadable submission date put every target date in 1970. The port parses
 * each date with `date_create_immutable()`, returns `null` for an unreadable
 * value, names it in `unreadable_dates`, and omits the dependent figures rather
 * than computing them from a false epoch.
 *
 * Day counts use calendar dates in UTC, as the rest of this family does, so a
 * value does not drift through the day.
 *
 * ## Gating
 *
 * The WordPress handler never read `$context` — the fifth tool in this family
 * with that gap. The port gates on `read`: it only reads.
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

class GetRegistrationTimelineTool extends RegulatoryTool
{
    /**
     * The expectation table, in days.
     *
     * `submission` is the day the application is lodged; it is counted in the
     * total but carries no target of its own.
     */
    private const TIMELINES = array(
        'default' => array(
            'new'       => array(
                'preparation' => 30,
                'submission'  => 1,
                'review'      => 90,
                'queries'     => 30,
                'approval'    => 14,
            ),
            'renewal'   => array(
                'preparation' => 14,
                'submission'  => 1,
                'review'      => 60,
                'queries'     => 14,
                'approval'    => 7,
            ),
            'variation' => array(
                'preparation' => 14,
                'submission'  => 1,
                'review'      => 45,
                'queries'     => 14,
                'approval'    => 7,
            ),
        ),
        'LK'      => array(
            'new'     => array(
                'preparation' => 45,
                'submission'  => 1,
                'review'      => 120,
                'queries'     => 30,
                'approval'    => 14,
            ),
            'renewal' => array(
                'preparation' => 21,
                'submission'  => 1,
                'review'      => 90,
                'queries'     => 14,
                'approval'    => 7,
            ),
        ),
        'AE'      => array(
            'new' => array(
                'preparation' => 30,
                'submission'  => 1,
                'review'      => 60,
                'queries'     => 21,
                'approval'    => 7,
            ),
        ),
        'SA'      => array(
            'new' => array(
                'preparation' => 30,
                'submission'  => 1,
                'review'      => 90,
                'queries'     => 30,
                'approval'    => 14,
            ),
        ),
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_registration_timeline';
    }

    public function getName(): string
    {
        return 'Get Registration Timeline';
    }

    public function getDescription(): string
    {
        return 'Reports a registration against a fixed expectation table: three milestones with target dates, a progress percentage and a time analysis. The expectations are stored values, not a fetched schedule, and basis names the row used. An approval completes the review phase, so approved registrations reach 100%. Unreadable dates are named rather than computed as 1970.';
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
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'The registration to report on.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('registration_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $registrationId = $this->intValue($arguments['registration_id'] ?? null);

        if ($registrationId < 1) {
            return $this->errors->validationFailed(
                'The timeline cannot be built.',
                array('registration_id' => array('The registration id.'))
            );
        }

        $registration = $this->registration($registrationId);

        if (null === $registration) {
            return $this->errors->notFound('Registration not found.');
        }

        $meta = $registration->meta;

        $country = $this->text($meta['country'] ?? null);
        $type    = $this->text($meta['registration_type'] ?? null);

        $rawSubmission = $this->text($meta['submission_date'] ?? null);
        $rawApproval   = $this->text($meta['approval_date'] ?? null);
        $rawExpiry     = $this->text($meta['expiry_date'] ?? null);

        $submission = '' !== $rawSubmission ? $this->stampDate($rawSubmission) : '';
        $approval   = '' !== $rawApproval ? $this->stampDate($rawApproval) : '';
        $expiry     = '' !== $rawExpiry ? $this->stampDate($rawExpiry) : '';

        $unreadable = array();

        if ('' !== $rawSubmission && '' === $submission) {
            $unreadable[] = 'submission_date';
        }

        if ('' !== $rawApproval && '' === $approval) {
            $unreadable[] = 'approval_date';
        }

        if ('' !== $rawExpiry && '' === $expiry) {
            $unreadable[] = 'expiry_date';
        }

        $timeline = $this->expectedTimeline($country, $type);

        $milestones = $this->milestones(
            $registration,
            '' !== $submission ? $submission : null,
            '' !== $approval ? $approval : null,
            $timeline
        );

        return $this->success(
            sprintf('Timeline for registration %d: %s.', $registrationId, $this->progress($milestones)['current_phase']),
            array(
                'registration_id'   => $registrationId,
                'country'           => $country,
                'registration_type' => $type,
                'expected_timeline' => $timeline,
                'milestones'        => $milestones,
                'progress'          => $this->progress($milestones),
                'time_analysis'     => $this->timeAnalysis(
                    '' !== $submission ? $submission : null,
                    '' !== $approval ? $approval : null,
                    '' !== $expiry ? $expiry : null,
                    $timeline
                ),
                'dates'             => array(
                    'created'    => $registration->createdAt->format('Y-m-d H:i:s'),
                    'submission' => $rawSubmission,
                    'approval'   => $rawApproval,
                    'expiry'     => $rawExpiry,
                ),
                'unreadable_dates'  => $unreadable,
            )
        );
    }

    /**
     * The expectation row for a country and type, with `total` and `basis`.
     *
     * Precedence is the WordPress precedence: the country's row for the type,
     * else the country's `new` row, else the default row for the type, else the
     * default `new` row.
     *
     * @return array<string, mixed>
     */
    private function expectedTimeline(string $country, string $type): array
    {
        $type   = '' !== $type ? strtolower($type) : 'new';
        $code   = strtoupper(trim($country));
        $rows   = self::TIMELINES[ $code ] ?? array();
        $bounds = self::TIMELINES['default'];

        $timeline = null;
        $basis    = '';

        if (isset($rows[ $type ])) {
            $timeline = $rows[ $type ];
            $basis    = $code . '/' . $type;
        } elseif (isset($rows['new'])) {
            $timeline = $rows['new'];
            $basis    = $code . '/new';
        } elseif (isset($bounds[ $type ])) {
            $timeline = $bounds[ $type ];
            $basis    = 'default/' . $type;
        } else {
            $timeline = $bounds['new'];
            $basis    = 'default/new';
        }

        $timeline['total'] = array_sum($timeline);
        $timeline['basis'] = $basis;

        return $timeline;
    }

    /**
     * The three milestone rows.
     *
     * @param array<string, mixed> $timeline
     *
     * @return list<array<string, mixed>>
     */
    private function milestones(
        ContentItem $registration,
        ?string $submission,
        ?string $approval,
        array $timeline
    ): array {
        $created = $registration->createdAt->format('Y-m-d');

        // An approval completes the review. WordPress tested only the
        // submission date, so the phase never ended and a fully approved
        // registration reported "Under Review" forever.
        if (null !== $approval) {
            $reviewStatus = 'completed';
        } elseif (null !== $submission) {
            $reviewStatus = 'in_progress';
        } else {
            $reviewStatus = 'pending';
        }

        return array(
            array(
                'name'        => 'Preparation',
                'status'      => null !== $submission ? 'completed' : 'in_progress',
                'start_date'  => $created,
                'target_date' => $this->addDays($created, (int) $timeline['preparation']),
                'actual_date' => $submission,
                'days'        => (int) $timeline['preparation'],
            ),
            array(
                'name'        => 'Under Review',
                'status'      => $reviewStatus,
                'start_date'  => $submission,
                'target_date' => null !== $submission
                    ? $this->addDays($submission, (int) $timeline['review'])
                    : null,
                'actual_date' => null,
                'days'        => (int) $timeline['review'],
            ),
            array(
                'name'        => 'Approval',
                'status'      => null !== $approval ? 'completed' : 'pending',
                'start_date'  => null,
                'target_date' => null !== $submission
                    ? $this->addDays($submission, (int) $timeline['review'] + (int) $timeline['approval'])
                    : null,
                'actual_date' => $approval,
                'days'        => (int) $timeline['approval'],
            ),
        );
    }

    /**
     * Completed count, percentage and current phase.
     *
     * @param list<array<string, mixed>> $milestones
     *
     * @return array<string, mixed>
     */
    private function progress(array $milestones): array
    {
        $completed = 0;
        $total     = count($milestones);
        $phase     = '';
        $allDone   = true;

        foreach ($milestones as $milestone) {
            if ('completed' === $milestone['status']) {
                ++$completed;
            } else {
                $allDone = false;
            }

            if ('' === $phase && 'in_progress' === $milestone['status']) {
                $phase = (string) $milestone['name'];
            }
        }

        if ($allDone) {
            $phase = 'Completed';
        } elseif ('' === $phase) {
            $phase = 'Pending Start';
        }

        return array(
            'completed_milestones' => $completed,
            'total_milestones'     => $total,
            'percentage'           => $total > 0 ? round(( $completed / $total ) * 100, 2) : 0.0,
            'current_phase'        => $phase,
        );
    }

    /**
     * Day counts, expected approval and expiry status.
     *
     * @param array<string, mixed> $timeline
     *
     * @return array<string, mixed>
     */
    private function timeAnalysis(
        ?string $submission,
        ?string $approval,
        ?string $expiry,
        array $timeline
    ): array {
        $analysis = array();

        if (null !== $submission) {
            $since = $this->daysUntil($submission);

            $analysis['days_since_submission'] = null !== $since ? -$since : null;

            if (null === $approval) {
                $expected = $this->addDays($submission, (int) $timeline['total']);

                if (null !== $expected) {
                    $analysis['expected_approval_date']    = $expected;
                    $analysis['days_to_expected_approval'] = $this->daysUntil($expected);
                }
            }
        }

        if (null !== $approval) {
            $since = $this->daysUntil($approval);

            $analysis['days_since_approval'] = null !== $since ? -$since : null;

            if (null !== $submission) {
                $actual = $this->daysBetween($submission, $approval);

                $analysis['actual_processing_time']   = $actual;
                $analysis['expected_processing_time'] = (int) $timeline['total'];
                $analysis['processing_time_variance'] = null !== $actual
                    ? $actual - (int) $timeline['total']
                    : null;
            }
        }

        if (null !== $expiry) {
            $days = $this->daysUntil($expiry);

            $analysis['days_to_expiry'] = $days;
            $analysis['expiry_date']    = $expiry;

            if (null !== $days) {
                if ($days < 0) {
                    $analysis['expiry_status'] = 'expired';
                } elseif ($days <= 90) {
                    $analysis['expiry_status'] = 'expiring_soon';
                } else {
                    $analysis['expiry_status'] = 'valid';
                }
            }
        }

        return $analysis;
    }

    /**
     * A date plus whole days, or null when the base cannot be read.
     */
    private function addDays(string $date, int $days): ?string
    {
        $utc  = new \DateTimeZone('UTC');
        $base = date_create_immutable($date, $utc);

        if (false === $base) {
            return null;
        }

        return $base->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Whole calendar days from one date to another, or null.
     */
    private function daysBetween(string $from, string $to): ?int
    {
        $utc = new \DateTimeZone('UTC');

        $start = date_create_immutable($from, $utc);
        $end   = date_create_immutable($to, $utc);

        if (false === $start || false === $end) {
            return null;
        }

        return (int) $start->diff($end)->format('%r%a');
    }

    /**
     * A registration by content id, or null.
     */
    private function registration(int $id): ?ContentItem
    {
        try {
            $item = $this->content->find($id);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $item instanceof ContentItem || self::REGISTRATION_TYPE !== $item->type) {
            return null;
        }

        return $item;
    }
}
