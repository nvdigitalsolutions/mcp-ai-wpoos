<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use RuntimeException;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `enroll_student_eca`, the first write in the family.
 *
 * The important assertions are the ones a "cleaner" port would get wrong:
 * a full activity becomes a waitlist rather than an error, an unknown
 * enrollment type falls back to confirmed, and a free activity stores
 * `payment_status: n/a`. Those are the WordPress answers for the same call.
 */
class EnrollStudentEcaToolTest extends TestCase
{
    use RefreshDatabase;

    private int $amaraId;

    private int $leoId;

    private int $ninaId;

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

        // The seeder enrols both seeded students on the activity side, so the
        // write path needs someone who is genuinely unenrolled.
        $this->ninaId = $store->create(
            new CreateContentCommand(
                title: 'Nina Patel',
                type: 'mcp_ai_student',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: [
                    '_student_first_name' => 'Nina',
                    '_student_last_name' => 'Patel',
                    '_student_email' => 'nina.patel@example.org',
                    '_student_year_group' => '9',
                    '_student_house' => 'Heron',
                    '_student_eca_enrollments' => [],
                ],
            )
        )->id;

        $this->roboticsId = $store->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))->items[0]->id;
    }

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('enroll_student_eca')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    private function eca(): \Nvoos\Core\Domain\Entity\ContentItem
    {
        return $this->store()->find($this->roboticsId);
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('enroll_student_eca'));
    }

    public function test_it_writes_both_sides_of_the_enrolment_map(): void
    {
        $result = $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
        ]);

        $this->assertSame('Nina Patel has been enrolled in Robotics Club.', $result['message']);

        $this->assertSame(
            [
                'student_id',
                'student_name',
                'eca_id',
                'eca_name',
                'enrollment_type',
                'payment_status',
                'amount_due',
                'enrollment_date',
            ],
            array_keys($result['data'])
        );

        $this->assertSame('confirmed', $result['data']['enrollment_type']);

        // Student side.
        $student = $this->store()->find($this->ninaId);
        $studentMap = $student->meta['_student_eca_enrollments'];

        $this->assertArrayHasKey($this->roboticsId, $studentMap);
        $this->assertSame('confirmed', $studentMap[$this->roboticsId]['enrollment_type']);
        $this->assertSame(1, $studentMap[$this->roboticsId]['enrolled_by']);

        // Activity side, with the record keyed by student id.
        $ecaMap = $this->eca()->meta['_eca_student_enrollments'];

        $this->assertArrayHasKey($this->ninaId, $ecaMap);
        $this->assertSame('confirmed', $ecaMap[ $this->ninaId ]['enrollment_type']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $ecaMap[ $this->ninaId ]['enrollment_date']
        );
    }

    public function test_it_increments_the_enrolment_count_for_a_confirmed_enrolment(): void
    {
        $before = (int) $this->eca()->meta['_eca_current_enrollment'];

        $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
        ]);

        $this->assertSame($before + 1, (int) $this->eca()->meta['_eca_current_enrollment']);
    }

    public function test_a_waitlist_enrolment_does_not_touch_the_count(): void
    {
        $before = (int) $this->eca()->meta['_eca_current_enrollment'];

        $result = $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
            'enrollment_type' => 'waitlist',
        ]);

        $this->assertSame('waitlist', $result['data']['enrollment_type']);
        $this->assertSame($before, (int) $this->eca()->meta['_eca_current_enrollment']);
    }

    public function test_a_full_activity_falls_back_to_the_waitlist(): void
    {
        $this->makeFull();

        $result = $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
        ]);

        // Not an error: WordPress switches the type instead.
        $this->assertSame('waitlist', $result['data']['enrollment_type']);

        $ecaMap = $this->eca()->meta['_eca_student_enrollments'];
        $this->assertSame('waitlist', $ecaMap[$this->ninaId]['enrollment_type']);
    }

    public function test_skip_capacity_check_enrols_on_a_full_activity(): void
    {
        $this->makeFull();

        $result = $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
            'skip_capacity_check' => true,
        ]);

        $this->assertSame('confirmed', $result['data']['enrollment_type']);
        $this->assertSame('full', $this->eca()->meta['_eca_status']);
    }

    public function test_the_activity_flips_to_full_at_capacity(): void
    {
        $store = $this->store();

        $store->update(
            $this->roboticsId,
            new \Nvoos\Core\Domain\Entity\UpdateContentCommand(
                userId: 1,
                meta: [
                    '_eca_max_students' => 3,
                    '_eca_current_enrollment' => 2,
                ]
            )
        );

        $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
        ]);

        $eca = $this->eca();

        $this->assertSame(3, (int) $eca->meta['_eca_current_enrollment']);
        $this->assertSame('full', $eca->meta['_eca_status']);
    }

    public function test_a_paid_activity_records_the_payment_status_and_amount(): void
    {
        $result = $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
            'payment_status' => 'partial',
        ]);

        // The seeded activity costs 45 per term and is paid.
        $this->assertSame('partial', $result['data']['payment_status']);
        $this->assertSame(45.0, $result['data']['amount_due']);
    }

    public function test_a_free_activity_stores_payment_status_as_not_applicable(): void
    {
        $free = $this->store()->create(
            new CreateContentCommand(
                title: 'Chess Club',
                type: 'mcp_ai_eca',
                authorId: 1,
                status: 'publish',
                content: 'Lunchtime chess.',
                meta: [
                    '_eca_status' => 'active',
                    '_eca_type' => 'club',
                    '_eca_day' => 'Friday',
                    '_eca_is_paid' => 'no',
                    '_eca_requires_audition' => 'no',
                    '_eca_max_students' => 0,
                    '_eca_current_enrollment' => 0,
                ],
            )
        );

        $result = $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $free->id,
            'payment_status' => 'paid',
        ]);

        $this->assertSame('n/a', $result['data']['payment_status']);
        $this->assertSame(0.0, $result['data']['amount_due']);
    }

    public function test_an_unknown_enrolment_type_falls_back_to_confirmed(): void
    {
        $result = $this->callTool([
            'student_id' => $this->ninaId,
            'eca_id' => $this->roboticsId,
            'enrollment_type' => 'sorcery',
        ]);

        $this->assertSame('confirmed', $result['data']['enrollment_type']);
    }

    public function test_an_ineligible_year_group_is_rejected_before_any_write(): void
    {
        $store = $this->store();

        $store->update(
            $this->roboticsId,
            new \Nvoos\Core\Domain\Entity\UpdateContentCommand(
                userId: 1,
                meta: ['_eca_year_groups' => ['11', '12']]
            )
        );

        $result = $this->app->make(ToolRegistry::class)
            ->get('enroll_student_eca')
            ->execute(['student_id' => $this->ninaId, 'eca_id' => $this->roboticsId], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('not eligible', $result->getMessage());

        // Nothing written on either side.
        $this->assertArrayNotHasKey(
            $this->roboticsId,
            $this->store()->find($this->ninaId)->meta['_student_eca_enrollments'] ?? []
        );
        $this->assertArrayNotHasKey(
            $this->ninaId,
            $this->eca()->meta['_eca_student_enrollments'] ?? []
        );
    }

    /**
     * The read tools report the enrolment this tool writes — the reverse-link
     * gap `list_students` and `get_student` documented is now closed.
     */
    public function test_the_read_tools_see_the_new_enrolment(): void
    {
        $this->callTool(['student_id' => $this->ninaId, 'eca_id' => $this->roboticsId]);

        $registry = $this->app->make(ToolRegistry::class);

        $student = $registry->get('get_student')->execute(
            ['student_id' => $this->ninaId, 'include_schedule' => true],
            ['user_id' => 1]
        );

        $this->assertSame(1, $student['data']['total_enrollments']);
        $this->assertSame('Robotics Club', $student['data']['enrolled_ecas'][0]['name']);
        $this->assertSame(['Wednesday'], array_keys($student['data']['weekly_schedule']));

        $list = $registry->get('list_students')->execute(
            ['search' => 'Nina Patel'],
            ['user_id' => 1]
        );

        $this->assertSame(1, $list['data']['students'][0]['enrollment_count']);

        $eca = $registry->get('get_eca')->execute(
            ['eca_id' => $this->roboticsId, 'include_enrollments' => true],
            ['user_id' => 1]
        );

        $this->assertSame(3, $eca['data']['eca']['enrollment_breakdown']['confirmed']);
        $this->assertCount(3, $eca['data']['eca']['enrollments']);
    }

    public function test_a_duplicate_on_the_student_side_is_rejected(): void
    {
        $this->callTool(['student_id' => $this->ninaId, 'eca_id' => $this->roboticsId]);

        $result = $this->app->make(ToolRegistry::class)
            ->get('enroll_student_eca')
            ->execute(['student_id' => $this->ninaId, 'eca_id' => $this->roboticsId], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('already enrolled', $result->getMessage());
    }

    public function test_a_duplicate_on_the_activity_side_is_rejected(): void
    {
        // The seeder enrols Leo on the activity side but cannot write the
        // reverse link, so this is exactly the split-brain a second enrolment
        // would otherwise double-count.
        $this->assertArrayHasKey(
            $this->leoId,
            $this->eca()->meta['_eca_student_enrollments']
        );
        $this->assertArrayNotHasKey(
            $this->roboticsId,
            $this->store()->find($this->leoId)->meta['_student_eca_enrollments'] ?? []
        );

        $result = $this->app->make(ToolRegistry::class)
            ->get('enroll_student_eca')
            ->execute(['student_id' => $this->leoId, 'eca_id' => $this->roboticsId], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('already enrolled', $result->getMessage());
    }

    public function test_missing_ids_are_a_validation_error(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('enroll_student_eca')
            ->execute(array( 'student_id' => $this->ninaId ), array( 'user_id' => 1 ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Both student_id and eca_id are required', $result->getMessage());
    }

    public function test_an_activity_id_is_not_a_student(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('enroll_student_eca')
            ->execute(['student_id' => $this->roboticsId, 'eca_id' => $this->roboticsId], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid student ID', $result->getMessage());
    }

    public function test_a_student_id_is_not_an_activity(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('enroll_student_eca')
            ->execute(['student_id' => $this->ninaId, 'eca_id' => $this->amaraId], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid ECA ID', $result->getMessage());
    }

    /**
     * Push the activity to capacity without enrolling anyone through the tool.
     */
    private function makeFull(): void
    {
        $this->store()->update(
            $this->roboticsId,
            new \Nvoos\Core\Domain\Entity\UpdateContentCommand(
                userId: 1,
                meta: [
                    '_eca_max_students' => 2,
                    '_eca_current_enrollment' => 2,
                    '_eca_status' => 'full',
                ]
            )
        );
    }
}
