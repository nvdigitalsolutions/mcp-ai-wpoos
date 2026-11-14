<?php

namespace App\Http\Controllers\Pro;

use App\Support\DashboardCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\Classroom\Enrollments;

/**
 * Pro dashboards (`/api/v1/pro/dashboards/schema` and `/{id}/data`).
 *
 * Shapes are deliberately **unwrapped** rather than the `{success, data}`
 * envelope the other Laravel controllers use: `DashboardsClient.catalogue()`
 * reads `dashboards` / `disabled` off the top level, exactly as WordPress's
 * `rest_ensure_response()` returns them, and this client is shared by both
 * runtimes.
 *
 * WordPress dispatches each dashboard to that dashboard's wp-admin page class
 * (`gather()`), which does not exist here. So this controller owns one
 * gatherer per served dashboard, and a dashboard is only listed as available
 * when its store exists on this runtime. See {@see DashboardCatalogue}.
 */
class DashboardsController
{
    public function __construct(private readonly ContentStoreInterface $content)
    {
    }

    /**
     * Dashboard catalogue: served dashboards plus metadata for the rest.
     */
    public function schema(): JsonResponse
    {
        $dashboards = [];
        $disabled = [];

        foreach (DashboardCatalogue::all() as $id => $config) {
            if (DashboardCatalogue::isAvailable($id)) {
                $dashboards[$id] = $config + ['id' => $id];

                continue;
            }

            $disabled[$id] = [
                'label' => $config['label'],
                'description' => $config['description'],
                'icon' => $config['icon'],
                'toolkit_flag' => DashboardCatalogue::flag($id),
            ];
        }

        return response()->json([
            'dashboards' => $dashboards,
            'disabled' => $disabled,
        ]);
    }

    /**
     * Filtered metric payload for one dashboard.
     */
    public function data(Request $request, string $id): JsonResponse
    {
        if (! array_key_exists($id, DashboardCatalogue::all())) {
            return response()->json(['message' => 'Unknown dashboard.'], 400);
        }

        if (! DashboardCatalogue::isAvailable($id)) {
            return response()->json(
                ['message' => 'This dashboard requires a toolkit that is not enabled on this site.'],
                404
            );
        }

        $payload = match ($id) {
            'eca' => $this->gatherEca($this->filters($request, $id)),
            default => null,
        };

        if (null === $payload) {
            // A dashboard marked available with no gatherer is a wiring bug,
            // and saying so beats an empty page that looks like zero data.
            return response()->json(['message' => 'This dashboard has no gatherer on this runtime.'], 501);
        }

        return response()->json([
            'dashboard' => $id,
            'data' => $payload,
        ]);
    }

    /**
     * Whitelist the caller's filters against the dashboard's declared keys.
     *
     * The console sends `filters` as a JSON string; decode defensively so a
     * direct handler call behaves like the REST pipeline.
     *
     * @return array<string, string>
     */
    private function filters(Request $request, string $id): array
    {
        $raw = $request->query('filters');

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $declared = DashboardCatalogue::all()[$id]['filters'] ?? [];
        $filters = [];

        foreach ($declared as $filter) {
            $key = (string) $filter['key'];

            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $filters[$key] = trim((string) $raw[$key]);
            }
        }

        return $filters;
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
    private function gatherEca(array $filters): array
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
