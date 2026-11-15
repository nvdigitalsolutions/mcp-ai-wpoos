<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use RuntimeException;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `get_student` on the Laravel runtime.
 *
 * The tool reads `_student_eca_enrollments`, the reverse link the enrolment
 * tools maintain. The seeder records enrolment on the activity side only, so
 * each test establishes the reverse link the same way `enroll_student_eca`
 * will — through the store, as an editor.
 */
class GetStudentToolTest extends TestCase
{
    use RefreshDatabase;

    private int $amaraId;

    private int $roboticsId;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);

        $store = $this->app->make(ContentStoreInterface::class);

        $students = $store->query(new ContentQuery(types: array('mcp_ai_student'), perPage: 10));

        foreach ($students->items as $student) {
            if ('Amara Okafor' === $student->title) {
                $this->amaraId = $student->id;
            }
        }

        $this->roboticsId = $store->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))->items[0]->id;
    }

    /**
     * Write the reverse link the way the enrolment tool will.
     *
     * @param array<int, int> $ecaIds
     */
    private function link(array $ecaIds): void
    {
        $map = array();

        foreach ($ecaIds as $ecaId) {
            $map[$ecaId] = array(
                'enrollment_type' => 'confirmed',
                'enrollment_date' => '2026-09-01 09:00:00',
                'payment_status' => 'n/a',
            );
        }

        $this->app->make(ContentStoreInterface::class)->update(
            $this->amaraId,
            new UpdateContentCommand(userId: 1, meta: ['_student_eca_enrollments' => $map])
        );
    }

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('get_student')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result['data'];
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('get_student'));
    }

    public function test_it_returns_the_wordpress_field_order(): void
    {
        $this->link([$this->roboticsId]);

        $data = $this->callTool(['student_id' => $this->amaraId]);

        $this->assertSame(
            [
                'student_id',
                'name',
                'first_name',
                'last_name',
                'year_group',
                'house',
                'email',
                'total_enrollments',
                'enrolled_ecas',
                'isams_id',
                'isams_synced',
                'url',
                'weekly_schedule',
            ],
            array_keys($data)
        );

        $this->assertSame($this->amaraId, $data['student_id']);
        $this->assertSame('Amara Okafor', $data['name']);
        $this->assertSame('Amara', $data['first_name']);
        $this->assertSame('Okafor', $data['last_name']);
        $this->assertSame('10', $data['year_group']);
        $this->assertSame('Falcon', $data['house']);
        $this->assertSame('amara.okafor@example.org', $data['email']);
        $this->assertSame(1, $data['total_enrollments']);

        // ISAMS never populates this runtime; the fields stay present and empty.
        $this->assertSame('', $data['isams_id']);
        $this->assertFalse($data['isams_synced']);
        $this->assertSame('', $data['url']);
    }

    public function test_the_enrolled_activity_row_matches_the_wordpress_shape(): void
    {
        $this->link([$this->roboticsId]);

        $eca = $this->callTool(['student_id' => $this->amaraId])['enrolled_ecas'][0];

        $this->assertSame(
            [
                'eca_id',
                'name',
                'type',
                'day',
                'start_time',
                'end_time',
                'venue',
                'teachers',
                'enrollment_type',
                'enrollment_date',
                'payment_status',
            ],
            array_keys($eca)
        );

        $this->assertSame($this->roboticsId, $eca['eca_id']);
        $this->assertSame('Robotics Club', $eca['name']);
        $this->assertSame('club', $eca['type']);
        $this->assertSame('Wednesday', $eca['day']);
        $this->assertSame('15:30', $eca['start_time']);
        $this->assertSame('17:00', $eca['end_time']);
        $this->assertSame('Design Lab', $eca['venue']);
        $this->assertSame([1], $eca['teachers']);
        $this->assertSame('confirmed', $eca['enrollment_type']);
        $this->assertSame('2026-09-01 09:00:00', $eca['enrollment_date']);
        $this->assertSame('n/a', $eca['payment_status']);
    }

    public function test_the_schedule_groups_by_day_and_is_on_by_default(): void
    {
        $this->link([$this->roboticsId]);

        $schedule = $this->callTool(['student_id' => $this->amaraId])['weekly_schedule'];

        $this->assertSame(['Wednesday'], array_keys($schedule));
        $this->assertSame(
            ['eca_id', 'name', 'start_time', 'end_time', 'venue'],
            array_keys($schedule['Wednesday'][0])
        );
        $this->assertSame('Robotics Club', $schedule['Wednesday'][0]['name']);
        $this->assertSame('Design Lab', $schedule['Wednesday'][0]['venue']);
    }

    public function test_the_schedule_is_monday_first(): void
    {
        $store = $this->app->make(ContentStoreInterface::class);

        $debate = $store->create(
            new CreateContentCommand(
                title: 'Debate Society',
                type: 'mcp_ai_eca',
                authorId: 1,
                status: 'publish',
                content: 'Weekly debate.',
                meta: [
                    '_eca_status' => 'active',
                    '_eca_type' => 'society',
                    '_eca_day' => 'Monday',
                    '_eca_start_time' => '12:30',
                    '_eca_end_time' => '13:30',
                    '_eca_venue' => 'Room 4',
                    '_eca_is_paid' => 'no',
                    '_eca_requires_audition' => 'no',
                ],
            )
        );

        // Link Wednesday first, so insertion order cannot be what passes.
        $this->link([$this->roboticsId, $debate->id]);

        $schedule = $this->callTool(['student_id' => $this->amaraId])['weekly_schedule'];

        $this->assertSame(['Monday', 'Wednesday'], array_keys($schedule));
    }

    public function test_schedule_can_be_omitted(): void
    {
        $this->link([$this->roboticsId]);

        $data = $this->callTool([
            'student_id' => $this->amaraId,
            'include_schedule' => false,
        ]);

        $this->assertArrayNotHasKey('weekly_schedule', $data);
        $this->assertSame(1, $data['total_enrollments']);
    }

    public function test_a_student_with_no_reverse_link_reports_nothing(): void
    {
        // The seeder only writes the activity side, which is where the mirror
        // stops until enroll_student_eca is ported.
        $data = $this->callTool(['student_id' => $this->amaraId]);

        $this->assertSame(0, $data['total_enrollments']);
        $this->assertSame([], $data['enrolled_ecas']);
        $this->assertSame([], $data['weekly_schedule']);
    }

    public function test_an_enrolment_pointing_at_a_missing_activity_is_skipped(): void
    {
        $this->link([$this->roboticsId]);

        $store = $this->app->make(ContentStoreInterface::class);
        $store->update(
            $this->amaraId,
            new UpdateContentCommand(
                userId: 1,
                meta: ['_student_eca_enrollments' => [999999 => ['enrollment_type' => 'confirmed']]]
            )
        );

        $data = $this->callTool(['student_id' => $this->amaraId]);

        $this->assertSame(0, $data['total_enrollments']);
        $this->assertSame([], $data['enrolled_ecas']);
    }

    public function test_a_missing_id_is_a_validation_error(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('get_student')
            ->execute([], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Student ID is required', $result->getMessage());
    }

    public function test_an_unknown_id_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('get_student')
            ->execute(['student_id' => 999999], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid student ID', $result->getMessage());
    }

    public function test_an_activity_id_is_not_a_student(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('get_student')
            ->execute(['student_id' => $this->roboticsId], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid student ID', $result->getMessage());
    }
}
