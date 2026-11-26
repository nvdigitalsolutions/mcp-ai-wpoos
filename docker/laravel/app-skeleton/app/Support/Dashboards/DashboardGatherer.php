<?php

namespace App\Support\Dashboards;

use App\Support\DashboardCatalogue;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\Classroom\Enrollments;

/**
 * The one dashboard gatherer — Proposal 149 §113.
 *
 * WordPress dispatches each dashboard to that dashboard's wp-admin page class.
 * This runtime owns one of those stores (the classroom/ECA family, 090-e), so
 * exactly one dashboard has a gatherer here and the other six have no data
 * source at all.
 *
 * **Why this class exists rather than two copies.** The ECA aggregation was
 * written inside `Pro\DashboardsController` for the console's REST surface. The
 * admin page needs the same numbers, and a second implementation would drift on
 * the first change — the class of bug this repo has removed repeatedly
 * (`CommandCentreReport`, `RoutingExportStore::backoffState()`,
 * `JitProvisioner::reasonLabel()`). So the page and the API call one method.
 *
 * **`gather()` returns null for a dashboard this runtime cannot feed**, which is
 * a different answer from an empty one. `null` means "no store here"; an empty
 * array would mean "a store here that holds nothing". The controller turns the
 * first into a 501, and the panel leaves the dashboard out of its picker
 * entirely ({@see DashboardCatalogue::servable()}), because a runtime that
 * cannot serve a dashboard should not offer it.
 */
final class DashboardGatherer
{
    public function __construct(private readonly ContentStoreInterface $content)
    {
    }

    /**
     * Gather one dashboard's payload, or null when this runtime has no store.
     *
     * Aliases resolve first, so a retired id reaches the same gatherer its
     * replacement does.
     *
     * @param array<string, string> $filters Whitelisted filter values.
     * @return array<string, mixed>|null
     */
    public function gather(string $id, array $filters = []): ?array
    {
        return match (DashboardCatalogue::resolveAlias($id)) {
            'eca' => $this->eca($filters),
            default => null,
        };
    }

    /**
     * ECA enrolment dashboard.
     *
     * Counts come from the activity-side enrolment map through the shared
     * `Enrollments` normaliser, with `_eca_current_enrollment` preferred where
     * it was written — the same authority order the classroom tools use.
     *
     * @param array<string, string> $filters Whitelisted filter values.
     * @return array<string, mixed>
     */
    public function eca(array $filters): array
    {
        // Seeded so the chart axis is stable on a quiet site.
        $byDay = [
            'Monday' => 0,
            'Tuesday' => 0,
            'Wednesday' => 0,
            'Thursday' => 0,
            'Friday' => 0,
            'Saturday' => 0,
            'Sunday' => 0,
        ];
        $byType = [];
        $byStatus = [];
        $rows = [];

        $activities = 0;
        $enrolled = 0;
        $waitlisted = 0;
        $capacity = 0;

        $page = 1;

        do {
            $collection = $this->content->query(new ContentQuery(
                types: ['mcp_ai_eca'],
                statuses: [],
                perPage: 200,
                page: $page,
            ));

            foreach ($collection->items as $eca) {
                $day = $this->textMeta($eca, '_eca_day');
                $type = $this->textMeta($eca, '_eca_type');
                $status = $this->textMeta($eca, '_eca_status');

                if (isset($filters['day']) && '' !== $filters['day'] && $filters['day'] !== $day) {
                    continue;
                }

                if (isset($filters['eca_type']) && '' !== $filters['eca_type'] && $filters['eca_type'] !== $type) {
                    continue;
                }

                [$confirmed, $waiting] = $this->countEnrolments($eca->meta['_eca_student_enrollments'] ?? []);

                $max = $this->intMeta($eca, '_eca_max_students');

                $current = $this->intMeta($eca, '_eca_current_enrollment');

                // A record whose counter was never written still has a map.
                if ($current <= 0 && $confirmed > 0) {
                    $current = $confirmed;
                }

                ++$activities;
                $enrolled += $current;
                $waitlisted += $waiting;
                $capacity += $max;

                $dayKey = '' !== $day ? $day : 'Unassigned';
                $byDay[$dayKey] = ($byDay[$dayKey] ?? 0) + $current;

                $typeKey = '' !== $type ? $type : 'unspecified';
                $byType[$typeKey] = ($byType[$typeKey] ?? 0) + 1;

                $statusKey = '' !== $status ? $status : 'unknown';
                $byStatus[$statusKey] = ($byStatus[$statusKey] ?? 0) + 1;

                $rows[] = [
                    'id' => $eca->id,
                    'title' => $eca->title,
                    'day' => $day,
                    'type' => $type,
                    'enrolled' => $current,
                    'capacity' => $max,
                    'waitlisted' => $waiting,
                    'status' => $status,
                ];
            }

            ++$page;
        } while ($page <= $collection->totalPages);

        usort($rows, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return [
            'total_activities' => $activities,
            'total_enrolments' => $enrolled,
            'total_waitlisted' => $waitlisted,
            'capacity_usage' => $capacity > 0 ? round(($enrolled / $capacity) * 100, 2) : 0.0,
            'by_day' => $byDay,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'activity_list' => $rows,
        ];
    }

    /**
     * Confirmed and waitlisted counts from the activity-side enrolment map.
     *
     * `preference` and `trial` entries are neither: only a confirmed enrolment
     * occupies a place, which is the rule `enroll_student_eca` applies.
     *
     * @return array{0: int, 1: int}
     */
    private function countEnrolments(mixed $raw): array
    {
        $confirmed = 0;
        $waitlisted = 0;

        foreach (Enrollments::map($raw) as $record) {
            $type = isset($record['enrollment_type']) ? (string) $record['enrollment_type'] : 'confirmed';

            if ('confirmed' === $type) {
                ++$confirmed;
            } elseif ('waitlist' === $type) {
                ++$waitlisted;
            }
        }

        return [$confirmed, $waitlisted];
    }

    private function textMeta(ContentItem $item, string $key): string
    {
        $value = $item->meta[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function intMeta(ContentItem $item, string $key): int
    {
        $value = $item->meta[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
