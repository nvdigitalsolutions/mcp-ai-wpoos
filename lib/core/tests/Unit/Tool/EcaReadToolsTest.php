<?php

/**
 * The three ECA reads: timetable, attendance report, participation summary.
 *
 * They share one fixture because they read the same rows — an activity with a
 * day, a venue, teachers and a `_eca_attendance_log` — and the point of the
 * slice is that all three read what the family already writes rather than a new
 * model. The store is the same stateful double the delete tests use, minus the
 * writes: a read tool that called `update()` would fail here rather than pass
 * silently.
 *
 * The rate assertions use logs with **more than one attendee per session**, so
 * a formula that divides attendee counts by session counts cannot pass by
 * accident (the two are equal when there is one student).
 *
 * @package Nvoos\Core\Tests
 * @since   2.9.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Classroom\GetEcaAttendanceReportTool;
use Nvoos\Core\Tool\Classroom\GetEcaTimetableTool;
use Nvoos\Core\Tool\Classroom\GetStudentParticipationSummaryTool;
use PHPUnit\Framework\TestCase;

final class EcaReadToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    // ─── Timetable ─────────────────────────────────────────────────────────

    public function test_the_student_view_groups_by_day_sorts_by_time_and_drops_empty_days(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_day' => 'Monday', '_eca_start_time' => '15:30', '_eca_end_time' => '16:30',
                '_eca_student_enrollments' => array(30 => array('student_id' => 30)),
            )),
            11 => $this->item(11, 'mcp_ai_eca', array(
                '_eca_day' => 'Monday', '_eca_start_time' => '09:00', '_eca_end_time' => '10:00',
                '_eca_student_enrollments' => array(30 => array('student_id' => 30)),
            )),
            12 => $this->item(12, 'mcp_ai_eca', array(
                '_eca_day' => 'Wednesday', '_eca_start_time' => '10:00', '_eca_end_time' => '11:00',
                '_eca_student_enrollments' => array(30 => array('student_id' => 30)),
            )),
            13 => $this->item(13, 'mcp_ai_eca', array(
                '_eca_day' => 'Tuesday', '_eca_start_time' => '10:00', '_eca_end_time' => '11:00',
                '_eca_student_enrollments' => array(31 => array('student_id' => 31)),
            )),
        ));

        $result = $this->timetable($store)->execute(
            array('view_type' => 'student', 'view_id' => '30'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['total_sessions']);
        $this->assertSame(array('Monday', 'Wednesday'), \array_keys($result['data']['timetable']));

        $monday = $result['data']['timetable']['Monday'];
        $this->assertSame(array(11, 10), array_column($monday, 'eca_id'));
    }

    public function test_the_teacher_view_matches_a_list_a_csv_string_and_one_name(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_day' => 'Monday', '_eca_start_time' => '09:00', '_eca_end_time' => '10:00',
                '_eca_teachers' => array('Mr Smith', 'Ms Lee'),
            )),
            11 => $this->item(11, 'mcp_ai_eca', array(
                '_eca_day' => 'Tuesday', '_eca_start_time' => '09:00', '_eca_end_time' => '10:00',
                '_eca_teachers' => 'Mr Smith, Ms Jones',
            )),
            12 => $this->item(12, 'mcp_ai_eca', array(
                '_eca_day' => 'Wednesday', '_eca_start_time' => '09:00', '_eca_end_time' => '10:00',
                '_eca_teachers' => 'Dr Who',
            )),
        ));

        $result = $this->timetable($store)->execute(
            array('view_type' => 'teacher', 'view_id' => 'mr smith'),
            array('user_id' => 7)
        );

        $this->assertSame(2, $result['data']['total_sessions']);
    }

    public function test_the_venue_view_is_an_exact_case_insensitive_match(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array('_eca_day' => 'Monday', '_eca_venue' => 'Main Hall')),
            11 => $this->item(11, 'mcp_ai_eca', array('_eca_day' => 'Monday', '_eca_venue' => 'Main Hall 2')),
        ));

        $result = $this->timetable($store)->execute(
            array('view_type' => 'venue', 'view_id' => 'main hall'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total_sessions']);
        $this->assertSame(10, $result['data']['timetable']['Monday'][0]['eca_id']);
    }

    public function test_a_request_without_a_view_or_with_an_unknown_one_is_refused(): void
    {
        $store = $this->store();

        $this->assertSame(
            'validation_failed',
            $this->timetable($store)->execute(array('view_type' => 'student'), array('user_id' => 7))['error']['code']
        );

        $this->assertSame(
            'validation_failed',
            $this->timetable($store)->execute(
                array('view_type' => 'class', 'view_id' => 'A'),
                array('user_id' => 7)
            )['error']['code']
        );
    }

    public function test_week_of_is_validated_and_otherwise_labels_the_view(): void
    {
        $store = $this->store();

        $bad = $this->timetable($store)->execute(
            array('view_type' => 'venue', 'view_id' => 'Hall', 'week_of' => '2026-11'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $bad['error']['code']);

        $ok = $this->timetable($store)->execute(
            array('view_type' => 'venue', 'view_id' => 'Hall'),
            array('user_id' => 7)
        );

        $this->assertSame(\gmdate('Y-m-d'), $ok['data']['week_of']);
    }

    /**
     * Overlap is `start < other_end && end > other_start`: touching sessions
     * (one ends exactly when the next begins) are not a conflict.
     */
    public function test_overlapping_sessions_are_reported_and_touching_ones_are_not(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_day' => 'Monday', '_eca_start_time' => '09:00', '_eca_end_time' => '10:00', '_eca_venue' => 'Hall',
            )),
            11 => $this->item(11, 'mcp_ai_eca', array(
                '_eca_day' => 'Monday', '_eca_start_time' => '09:30', '_eca_end_time' => '10:30', '_eca_venue' => 'Hall',
            )),
            12 => $this->item(12, 'mcp_ai_eca', array(
                '_eca_day' => 'Monday', '_eca_start_time' => '10:30', '_eca_end_time' => '11:30', '_eca_venue' => 'Hall',
            )),
        ));

        $result = $this->timetable($store)->execute(
            array('view_type' => 'venue', 'view_id' => 'Hall', 'include_conflicts' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['has_conflicts']);
        $this->assertCount(1, $result['data']['conflicts']);
        $this->assertSame(array(10, 11), array(
            $result['data']['conflicts'][0]['eca_a']['eca_id'],
            $result['data']['conflicts'][0]['eca_b']['eca_id'],
        ));
    }

    public function test_a_truncated_scan_is_flagged(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array('_eca_day' => 'Monday', '_eca_venue' => 'Hall')),
        ), 260);

        $result = $this->timetable($store)->execute(
            array('view_type' => 'venue', 'view_id' => 'Hall'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['truncated']);
    }

    // ─── Attendance report ─────────────────────────────────────────────────

    public function test_the_summary_counts_records_and_rates_present_plus_late(): void
    {
        $store = $this->store(array(10 => $this->item(10, 'mcp_ai_eca', array('_eca_attendance_log' => $this->log()))));

        $result = $this->report($store)->execute(array('eca_id' => 10), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total_sessions']);
        $this->assertSame(4, $result['data']['summary']['total_records']);
        $this->assertSame(2, $result['data']['summary']['present']);
        $this->assertSame(1, $result['data']['summary']['absent']);
        $this->assertSame(1, $result['data']['summary']['late']);
        $this->assertSame(75.0, $result['data']['attendance_rate']);

        $rates = array_column($result['data']['student_rates'], null, 'student_id');
        $this->assertSame(100.0, $rates[30]['rate']);
        $this->assertSame(50.0, $rates[31]['rate']);

        // Chart data is one label per session, oldest first.
        $this->assertSame(array('2026-11-10', '2026-11-17'), $result['data']['chart_data']['labels']);
    }

    public function test_the_date_range_and_student_filters_narrow_the_report(): void
    {
        $store = $this->store(array(10 => $this->item(10, 'mcp_ai_eca', array('_eca_attendance_log' => $this->log()))));

        $range = $this->report($store)->execute(
            array('eca_id' => 10, 'date_from' => '2026-11-17'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $range['data']['total_sessions']);
        $this->assertSame(2, $range['data']['summary']['total_records']);
        $this->assertSame(100.0, $range['data']['attendance_rate']);

        $store2 = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array('_eca_attendance_log' => $this->log())),
            30 => $this->item(30, 'mcp_ai_student'),
        ));

        $one = $this->report($store2)->execute(
            array('eca_id' => 10, 'student_id' => 30),
            array('user_id' => 7)
        );

        $this->assertSame(2, $one['data']['summary']['total_records']);
        $this->assertSame(100.0, $one['data']['attendance_rate']);
        $this->assertSame(30, $one['data']['student_filter']['student_id']);
    }

    public function test_an_empty_log_is_a_success_with_zero_sessions(): void
    {
        $store = $this->store(array(10 => $this->item(10, 'mcp_ai_eca')));

        $result = $this->report($store)->execute(array('eca_id' => 10), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total_sessions']);
        $this->assertSame(0, $result['data']['summary']['total_records']);
        $this->assertStringContainsString('No attendance records', $result['message']);
    }

    public function test_the_detailed_format_adds_one_entry_per_session(): void
    {
        $store = $this->store(array(10 => $this->item(10, 'mcp_ai_eca', array('_eca_attendance_log' => $this->log()))));

        $result = $this->report($store)->execute(
            array('eca_id' => 10, 'format' => 'detailed'),
            array('user_id' => 7)
        );

        $this->assertCount(2, $result['data']['sessions']);
        $this->assertSame('2026-11-10', $result['data']['sessions'][0]['date']);
        $this->assertSame('First session', $result['data']['sessions'][0]['session_notes']);
        $this->assertCount(2, $result['data']['sessions'][0]['attendees']);
    }

    public function test_a_bad_date_or_an_unknown_student_is_refused(): void
    {
        $store = $this->store(array(
            10 => $this->item(10, 'mcp_ai_eca', array('_eca_attendance_log' => $this->log())),
        ));

        $this->assertSame(
            'validation_failed',
            $this->report($store)->execute(array('eca_id' => 10, 'date_from' => '17/11/2026'), array('user_id' => 7))['error']['code']
        );

        $this->assertSame(
            'not_found',
            $this->report($store)->execute(array('eca_id' => 10, 'student_id' => 404), array('user_id' => 7))['error']['code']
        );
    }

    // ─── Participation summary ─────────────────────────────────────────────

    /**
     * The two rates answer different questions and must not collapse into one:
     * the overall figure is records-based, the engagement score is the mean of
     * per-activity rates, which weights a one-session club like a three-session
     * one.
     */
    public function test_the_summary_reports_the_overall_rate_and_the_engagement_score_separately(): void
    {
        $store = $this->store(array(
            30 => $this->item(30, 'mcp_ai_student', array(
                '_student_eca_enrollments' => array(
                    10 => array('eca_id' => 10, 'enrollment_type' => 'confirmed', 'enrollment_date' => '2026-09-01'),
                    11 => array('eca_id' => 11, 'enrollment_type' => 'confirmed'),
                ),
            )),
            10 => $this->item(10, 'mcp_ai_eca', array(
                '_eca_status' => 'active',
                '_eca_term' => 'Autumn',
                '_eca_day' => 'Monday',
                '_eca_venue' => 'Hall',
                '_eca_type' => 'club',
                '_eca_attendance_log' => array(
                    '2026-11-10' => $this->session('2026-11-10', array(array(30, 'present'), array(31, 'present'))),
                ),
            )),
            11 => $this->item(11, 'mcp_ai_eca', array(
                '_eca_status' => 'full',
                '_eca_term' => 'Autumn',
                '_eca_day' => 'Tuesday',
                '_eca_venue' => 'Gym',
                '_eca_type' => 'sport_squad',
                '_eca_attendance_log' => array(
                    '2026-11-11' => $this->session('2026-11-11', array(array(30, 'present'), array(31, 'absent'))),
                    '2026-11-18' => $this->session('2026-11-18', array(array(30, 'absent'), array(31, 'present'))),
                    '2026-11-25' => $this->session('2026-11-25', array(array(30, 'absent'), array(31, 'present'))),
                ),
            )),
        ));

        $result = $this->participation($store)->execute(array('student_id' => 30), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['overall_stats']['total_ecas']);
        $this->assertSame(2, $result['data']['overall_stats']['active_ecas']);
        $this->assertSame(4, $result['data']['overall_stats']['total_sessions_attended']);

        // attended records: 1 of 1 in the club, 1 of 3 in the squad.
        $this->assertSame(50.0, $result['data']['overall_stats']['overall_attendance_rate']);

        // mean of 100.0 and 33.3, which is a different number on purpose.
        $this->assertSame(66.7, $result['data']['overall_stats']['engagement_score']);

        $rates = array();

        foreach ($result['data']['ecas_enrolled'] as $entry) {
            $rates[ $entry['eca_id'] ] = $entry['attendance']['attendance_rate'];
        }

        $this->assertSame(100.0, $rates[10]);
        $this->assertSame(33.3, $rates[11]);
    }

    public function test_the_term_filter_skips_and_says_how_many_it_skipped(): void
    {
        $store = $this->store(array(
            30 => $this->item(30, 'mcp_ai_student', array(
                '_student_eca_enrollments' => array(
                    10 => array('eca_id' => 10),
                    11 => array('eca_id' => 11),
                    99 => array('eca_id' => 99),
                ),
            )),
            10 => $this->item(10, 'mcp_ai_eca', array('_eca_term' => 'Spring')),
            11 => $this->item(11, 'mcp_ai_eca', array('_eca_term' => 'Autumn')),
        ));

        $result = $this->participation($store)->execute(
            array('student_id' => 30, 'term' => 'Spring'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['overall_stats']['total_ecas']);
        $this->assertSame(10, $result['data']['ecas_enrolled'][0]['eca_id']);
        $this->assertSame(2, $result['data']['overall_stats']['skipped']);
    }

    public function test_a_missing_or_unknown_student_is_refused(): void
    {
        $store = $this->store(array(10 => $this->item(10, 'mcp_ai_eca')));

        $this->assertSame(
            'validation_failed',
            $this->participation($store)->execute(array(), array('user_id' => 7))['error']['code']
        );

        $this->assertSame(
            'not_found',
            $this->participation($store)->execute(array('student_id' => 404), array('user_id' => 7))['error']['code']
        );
    }

    // ─── Doubles ──────────────────────────────────────────────────────────

    private function timetable(ContentStoreInterface $store): GetEcaTimetableTool
    {
        return new GetEcaTimetableTool($this->errors, $store);
    }

    private function report(ContentStoreInterface $store): GetEcaAttendanceReportTool
    {
        return new GetEcaAttendanceReportTool($this->errors, $store);
    }

    private function participation(ContentStoreInterface $store): GetStudentParticipationSummaryTool
    {
        return new GetStudentParticipationSummaryTool($this->errors, $store);
    }

    /**
     * Two sessions, two attendees each — so an attendance rate cannot pass by
     * dividing a per-student count by a per-session count.
     *
     * @return array<string, array<string, mixed>>
     */
    private function log(): array
    {
        return array(
            '2026-11-10' => $this->session('2026-11-10', array(array(30, 'present'), array(31, 'absent')), 'First session'),
            '2026-11-17' => $this->session('2026-11-17', array(array(30, 'late'), array(31, 'present'))),
        );
    }

    /**
     * @param list<array{0: int, 1: string}> $attendees Pairs of id and status.
     * @return array<string, mixed>
     */
    private function session(string $date, array $attendees, string $notes = ''): array
    {
        $counts = array('present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0);
        $rows   = array();

        foreach ($attendees as $pair) {
            $rows[] = array('student_id' => $pair[0], 'student_name' => 'Student ' . $pair[0], 'status' => $pair[1]);

            if (isset($counts[ $pair[1] ])) {
                ++$counts[ $pair[1] ];
            }
        }

        return array(
            'date'          => $date,
            'recorded_by'   => 7,
            'recorded_at'   => $date . ' 16:00:00',
            'session_notes' => $notes,
            'attendees'     => $rows,
            'present_count' => $counts['present'],
            'absent_count'  => $counts['absent'],
            'late_count'    => $counts['late'],
            'excused_count' => $counts['excused'],
        );
    }

    private function item(int $id, string $type, array $meta = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: ('mcp_ai_eca' === $type ? 'Chess Club ' : 'Student ') . $id,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }

    /**
     * Reads only: every write method throws, so a read tool that touched one
     * would fail here instead of passing quietly.
     *
     * @param array<int, ContentItem> $items
     * @param int|null                $forceTotal Report a larger total, as a truncated page would.
     */
    private function store(array $items = array(), ?int $forceTotal = null): ContentStoreInterface
    {
        return new class ($items, $forceTotal) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items;

            /**
             * @param array<int, ContentItem> $items
             */
            public function __construct(array $items, private readonly ?int $forceTotal)
            {
                $this->items = $items;
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[ $id ] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $type = (string) ( $query->types[0] ?? '' );
                $rows = array_values(array_filter(
                    $this->items,
                    static fn (ContentItem $item): bool => '' === $type || $item->type === $type
                ));

                $total = $this->forceTotal ?? count($rows);

                return new ContentCollection(
                    array_slice($rows, 0, max(1, $query->perPage)),
                    $total,
                    1,
                    $query->perPage,
                    (int) ceil($total / max(1, $query->perPage))
                );
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('a read tool must not create.');
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                throw new \LogicException('a read tool must not update.');
            }

            public function delete(int $id, int $userId): void
            {
                throw new \LogicException('a read tool must not delete.');
            }

            public function getMeta(int $id): array
            {
                return $this->items[ $id ]->meta ?? array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return true;
            }
        };
    }
}
