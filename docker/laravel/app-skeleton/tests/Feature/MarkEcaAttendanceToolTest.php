<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use RuntimeException;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `mark_eca_attendance`.
 *
 * The attendance log is a **date-keyed** map of session records, which makes it
 * a different shape from the id-keyed enrolment maps. Re-recording a date
 * replaces that session; a different date adds one.
 */
class MarkEcaAttendanceToolTest extends TestCase
{
    use RefreshDatabase;

    private int $amaraId;

    private int $leoId;

    private int $roboticsId;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);

        $store = $this->app->make(ContentStoreInterface::class);

        foreach ($store->query(new ContentQuery(types: array('mcp_ai_student'), perPage: 10))->items as $student) {
            if ('Amara Okafor' === $student->title) {
                $this->amaraId = $student->id;
            }
            if ('Leo Marchetti' === $student->title) {
                $this->leoId = $student->id;
            }
        }

        $this->roboticsId = $store->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))->items[0]->id;
    }

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('mark_eca_attendance')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    private function log(): array
    {
        return $this->app->make(ContentStoreInterface::class)
            ->find($this->roboticsId)->meta['_eca_attendance_log'] ?? [];
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('mark_eca_attendance'));
    }

    public function test_it_records_a_session_and_returns_the_wordpress_counters(): void
    {
        $result = $this->callTool([
            'eca_id' => $this->roboticsId,
            'session_date' => '2026-11-14',
            'attendees' => [
                ['student_id' => $this->amaraId, 'status' => 'present'],
                ['student_id' => $this->leoId, 'status' => 'late', 'notes' => 'Bus delay'],
            ],
            'session_notes' => 'First session of term.',
        ]);

        $this->assertSame('Attendance recorded for Robotics Club on 2026-11-14.', $result['message']);

        $this->assertSame(
            [
                'eca_id',
                'eca_name',
                'session_date',
                'attendance_count',
                'present_count',
                'absent_count',
                'late_count',
                'excused_count',
            ],
            array_keys($result['data'])
        );

        $this->assertSame(2, $result['data']['attendance_count']);
        $this->assertSame(1, $result['data']['present_count']);
        $this->assertSame(1, $result['data']['late_count']);
        $this->assertSame(0, $result['data']['absent_count']);
        $this->assertSame(0, $result['data']['excused_count']);
    }

    public function test_the_stored_record_matches_the_wordpress_shape(): void
    {
        $this->callTool([
            'eca_id' => $this->roboticsId,
            'session_date' => '2026-11-14',
            'attendees' => [
                ['student_id' => $this->amaraId, 'status' => 'present'],
            ],
            'session_notes' => 'First session of term.',
        ]);

        $session = $this->log()['2026-11-14'];

        $this->assertSame(
            [
                'date',
                'recorded_by',
                'recorded_at',
                'session_notes',
                'attendees',
                'present_count',
                'absent_count',
                'late_count',
                'excused_count',
            ],
            array_keys($session)
        );

        $this->assertSame('2026-11-14', $session['date']);
        $this->assertSame(1, $session['recorded_by']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $session['recorded_at']);
        $this->assertSame('First session of term.', $session['session_notes']);

        $attendee = $session['attendees'][0];

        $this->assertSame(['student_id', 'student_name', 'status', 'notes'], array_keys($attendee));
        $this->assertSame($this->amaraId, $attendee['student_id']);
        $this->assertSame('Amara Okafor', $attendee['student_name']);
        $this->assertSame('present', $attendee['status']);
        $this->assertSame('', $attendee['notes']);
    }

    public function test_a_second_date_adds_a_second_session(): void
    {
        $this->callTool([
            'eca_id' => $this->roboticsId,
            'session_date' => '2026-11-14',
            'attendees' => [['student_id' => $this->amaraId, 'status' => 'present']],
        ]);

        $this->callTool([
            'eca_id' => $this->roboticsId,
            'session_date' => '2026-11-21',
            'attendees' => [['student_id' => $this->amaraId, 'status' => 'absent']],
        ]);

        $this->assertSame(['2026-11-14', '2026-11-21'], array_keys($this->log()));
    }

    public function test_recording_the_same_date_replaces_that_session(): void
    {
        $this->callTool([
            'eca_id' => $this->roboticsId,
            'session_date' => '2026-11-14',
            'attendees' => [['student_id' => $this->amaraId, 'status' => 'absent']],
        ]);

        $this->callTool([
            'eca_id' => $this->roboticsId,
            'session_date' => '2026-11-14',
            'attendees' => [['student_id' => $this->amaraId, 'status' => 'present']],
        ]);

        $log = $this->log();

        $this->assertSame(['2026-11-14'], array_keys($log));
        $this->assertSame('present', $log['2026-11-14']['attendees'][0]['status']);
        $this->assertSame(1, $log['2026-11-14']['present_count']);
        $this->assertSame(0, $log['2026-11-14']['absent_count']);
    }

    public function test_a_date_that_does_not_exist_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('mark_eca_attendance')
            ->execute([
                'eca_id' => $this->roboticsId,
                'session_date' => '2026-02-31',
                'attendees' => [['student_id' => $this->amaraId, 'status' => 'present']],
            ], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('valid session date', $result->getMessage());
    }

    public function test_an_empty_attendee_list_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('mark_eca_attendance')
            ->execute([
                'eca_id' => $this->roboticsId,
                'session_date' => '2026-11-14',
                'attendees' => [],
            ], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('At least one attendee', $result->getMessage());
    }

    public function test_an_unknown_status_names_the_student(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('mark_eca_attendance')
            ->execute([
                'eca_id' => $this->roboticsId,
                'session_date' => '2026-11-14',
                'attendees' => [['student_id' => $this->amaraId, 'status' => 'sleeping']],
            ], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid attendance status', $result->getMessage());
        $this->assertStringContainsString((string) $this->amaraId, $result->getMessage());
    }

    public function test_an_activity_id_is_not_a_student(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('mark_eca_attendance')
            ->execute([
                'eca_id' => $this->roboticsId,
                'session_date' => '2026-11-14',
                'attendees' => [['student_id' => $this->roboticsId, 'status' => 'present']],
            ], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid student ID', $result->getMessage());
    }

    public function test_a_student_id_is_not_an_activity(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('mark_eca_attendance')
            ->execute([
                'eca_id' => $this->amaraId,
                'session_date' => '2026-11-14',
                'attendees' => [['student_id' => $this->leoId, 'status' => 'present']],
            ], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid ECA ID', $result->getMessage());
    }
}
