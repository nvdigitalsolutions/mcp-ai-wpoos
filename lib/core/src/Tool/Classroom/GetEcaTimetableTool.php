<?php

/**
 * A week's schedule from four points of view — Proposal 164 §164.57.
 *
 * Ported from `WP_MCP_AI_Tool_Get_ECA_Timetable`: same slug, same four views,
 * same grouping and the same conflict test. It reads only what the family
 * already stores, so the tool adds no state.
 *
 * ### The four views, and how each one matches
 *
 * | view | matches `view_id` against |
 * |---|---|
 * | `student` | the activity's roster (`_eca_student_enrollments`, or the legacy `_eca_enrollments`) |
 * | `teacher` | `_eca_teachers`, which may be a list, a CSV string or one name |
 * | `venue` | `_eca_venue`, exactly (case-insensitively) |
 * | `year_group` | `_eca_year_groups`, with the same list tolerance as teachers |
 *
 * The filters run in PHP over one page of published activities rather than in
 * the store's meta query, because `LIKE` over a serialised list is exactly the
 * thing a meta query cannot express portably. The page is 200, the same ceiling
 * `list_ecas` uses, and `truncated` says when the answer is a lower bound.
 *
 * ### `week_of` labels, it does not filter
 *
 * The schedule is weekly and recurring: every activity has a day and a time, and
 * there is no dated occurrence to filter by. WordPress accepts `week_of`,
 * validates nothing and echoes it; this port validates the format and echoes it
 * as the label it is. Saying so is better than pretending the argument selects a
 * week.
 *
 * ### Conflicts are arithmetic, not a guess
 *
 * Two sessions on the same day conflict when their minute ranges overlap. Times
 * are parsed to minutes rather than through `strtotime`, which would resolve
 * "15:30" against *today* and make the comparison depend on the day the report
 * ran.
 *
 * @package Nvoos\Core
 * @since   2.9.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\AbstractTool;

class GetEcaTimetableTool extends AbstractTool
{
    private const VIEW_TYPES = array('student', 'teacher', 'venue', 'year_group');

    /** The same page ceiling `list_ecas` uses. */
    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_eca_timetable';
    }

    public function getName(): string
    {
        return 'Get ECA Timetable';
    }

    public function getDescription(): string
    {
        return 'Builds a weekly timetable for a student, teacher, venue or year group: activities grouped by day, sorted by start time, with optional overlap detection. week_of labels the view; the schedule itself is the recurring weekly one.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'view_type' => array(
                    'type' => 'string',
                    'enum' => self::VIEW_TYPES,
                    'description' => 'What the timetable is for.',
                ),
                'view_id' => array(
                    'type' => 'string',
                    'description' => 'Student record id, teacher name, venue name or year group, depending on view_type.',
                ),
                'week_of' => array(
                    'type' => 'string',
                    'description' => 'Date (YYYY-MM-DD) naming the week. A label for the view: the schedule is weekly and recurring, so it does not filter.',
                ),
                'include_conflicts' => array(
                    'type' => 'boolean',
                    'description' => 'Report sessions on the same day whose times overlap.',
                    'default' => false,
                ),
            ),
            'required' => array('view_type', 'view_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $view_type = $this->stringParam($arguments, 'view_type');
        $view_id   = \trim($this->stringParam($arguments, 'view_id'));

        if ('' === $view_type || '' === $view_id) {
            return $this->errors->validationFailed(
                'view_type and view_id are required.',
                array(
                    'view_type' => array('Give what the timetable is for.'),
                    'view_id'   => array('Give the student id, teacher, venue or year group.'),
                ),
            );
        }

        if (!\in_array($view_type, self::VIEW_TYPES, true)) {
            return $this->errors->validationFailed(
                'Unknown view_type "' . $view_type . '".',
                array('view_type' => array('Use one of ' . \implode(', ', self::VIEW_TYPES) . '.')),
            );
        }

        $week_of = \trim($this->stringParam($arguments, 'week_of'));

        if ('' === $week_of) {
            $week_of = \gmdate('Y-m-d');
        } elseif (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_of)) {
            return $this->errors->validationFailed(
                'week_of must be a date in YYYY-MM-DD format.',
                array('week_of' => array('Use YYYY-MM-DD.')),
            );
        }

        $include_conflicts = $this->boolParam($arguments, 'include_conflicts');

        try {
            $page = $this->content->query(
                new ContentQuery(
                    types: array('mcp_ai_eca'),
                    perPage: self::SCAN_LIMIT,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('timetable_failed', $e->getMessage());
        }

        $timetable = array();

        foreach (Schedule::DAYS as $day) {
            $timetable[ $day ] = array();
        }

        $sessions = 0;

        foreach ($page->items as $eca) {
            if (!$eca instanceof ContentItem || !$this->matchesView($eca, $view_type, $view_id)) {
                continue;
            }

            $day = \trim((string) ($eca->meta['_eca_day'] ?? ''));

            if (!isset($timetable[ $day ])) {
                // An activity with no day, or a day WordPress does not know, is
                // not a weekly session; leaving it out is what keeps the seven
                // keys honest.
                continue;
            }

            $row = array(
                'eca_id'     => $eca->id,
                'eca_name'   => $eca->title,
                'day'        => $day,
                'start_time' => (string) ($eca->meta['_eca_start_time'] ?? ''),
                'end_time'   => (string) ($eca->meta['_eca_end_time'] ?? ''),
                'venue'      => (string) ($eca->meta['_eca_venue'] ?? ''),
                'type'       => (string) ($eca->meta['_eca_type'] ?? ''),
                'teachers'   => $eca->meta['_eca_teachers'] ?? array(),
            );

            $timetable[ $day ][] = $row;
            ++$sessions;
        }

        // HH:MM compares lexicographically, so a padded time sorts without being
        // parsed against today's date; a malformed value sorts to one end rather
        // than throwing mid-report.
        foreach (Schedule::DAYS as $day) {
            \usort(
                $timetable[ $day ],
                static fn (array $a, array $b): int => \strcmp($a['start_time'], $b['start_time'])
            );

            if (array() === $timetable[ $day ]) {
                unset($timetable[ $day ]);
            }
        }

        $payload = array(
            'view_type'      => $view_type,
            'view_id'        => $view_id,
            'week_of'        => $week_of,
            'total_sessions' => $sessions,
            'timetable'      => $timetable,
            'truncated'      => $page->total > self::SCAN_LIMIT,
        );

        if ($include_conflicts) {
            $conflicts = $this->conflicts($timetable);

            $payload['has_conflicts'] = array() !== $conflicts;
            $payload['conflicts']     = $conflicts;
        }

        return $this->success(
            array() === $timetable
                ? 'No sessions match this view.'
                : $sessions . ' session(s) across ' . \count($timetable) . ' day(s).',
            $payload
        );
    }

    // ─── Matching and overlap ──────────────────────────────────────────────

    private function matchesView(ContentItem $eca, string $view_type, string $view_id): bool
    {
        switch ($view_type) {
                        case 'student':
                            return EcaFilter::enrolled($eca, (int) \preg_replace('/\D+/', '', $view_id));

            case 'teacher':
                return EcaFilter::matchesList($eca->meta['_eca_teachers'] ?? array(), $view_id);

                        case 'venue':
                            return EcaFilter::venue($eca, $view_id);

            case 'year_group':
                return EcaFilter::matchesList($eca->meta['_eca_year_groups'] ?? array(), $view_id);
        }

        return false;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $timetable
     * @return list<array<string, mixed>>
     */
    private function conflicts(array $timetable): array
    {
        $conflicts = array();

        foreach ($timetable as $day => $rows) {
            $count = \count($rows);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a_start = Schedule::minutes((string) $rows[ $i ]['start_time']);
                    $a_end   = Schedule::minutes((string) $rows[ $i ]['end_time']);
                    $b_start = Schedule::minutes((string) $rows[ $j ]['start_time']);
                    $b_end   = Schedule::minutes((string) $rows[ $j ]['end_time']);

                    if (null === $a_start || null === $a_end || null === $b_start || null === $b_end) {
                        continue;
                    }

                    if ($a_start < $b_end && $a_end > $b_start) {
                        $conflicts[] = array(
                            'day'     => $day,
                            'eca_a'   => array(
                                'eca_id'     => $rows[ $i ]['eca_id'],
                                'eca_name'   => $rows[ $i ]['eca_name'],
                                'start_time' => $rows[ $i ]['start_time'],
                                'end_time'   => $rows[ $i ]['end_time'],
                            ),
                            'eca_b'   => array(
                                'eca_id'     => $rows[ $j ]['eca_id'],
                                'eca_name'   => $rows[ $j ]['eca_name'],
                                'start_time' => $rows[ $j ]['start_time'],
                                'end_time'   => $rows[ $j ]['end_time'],
                            ),
                            'message' => \sprintf(
                                '"%s" and "%s" overlap on %s.',
                                $rows[ $i ]['eca_name'],
                                $rows[ $j ]['eca_name'],
                                $day
                            ),
                        );
                    }
                }
            }
        }

        return $conflicts;
    }
}
