<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `bulk_enroll_students`.
 *
 * The batch is partial-success by contract: one bad row must not lose the
 * rest. The assertions that matter are the ones a naive port would get wrong —
 * a full activity spills to the waitlist rather than failing, a duplicate id
 * inside the payload is caught on its second occurrence, and a row whose write
 * fails is not claimed in the activity map.
 */
class BulkEnrollStudentsToolTest extends TestCase
{
    use RefreshDatabase;

    private int $roboticsId;

    private int $freeEcaId;

    /** @var array<int, int> */
    private array $studentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);

        $store = $this->app->make(ContentStoreInterface::class);

        $this->roboticsId = $store->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))->items[0]->id;

        $this->freeEcaId = $store->create(
            new CreateContentCommand(
                title: 'Chess Club',
                type: 'mcp_ai_eca',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: [
                    '_eca_status' => 'active',
                    '_eca_is_paid' => 'no',
                    '_eca_cost' => 0,
                    '_eca_max_students' => 30,
                    '_eca_current_enrollment' => 0,
                    '_eca_student_enrollments' => [],
                ],
            )
        )->id;

        foreach (['Nina Patel', 'Omar Haddad', 'Priya Shah'] as $index => $name) {
            $this->studentIds[] = $store->create(
                new CreateContentCommand(
                    title: $name,
                    type: 'mcp_ai_student',
                    authorId: 1,
                    status: 'publish',
                    content: '',
                    meta: [
                        '_student_year_group' => '9',
                        '_student_house' => 'Heron',
                        '_student_eca_enrollments' => [],
                    ],
                )
            )->id;
        }
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    private function eca(int $id): ContentItem
    {
        return $this->store()->find($id);
    }

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('bulk_enroll_students')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('bulk_enroll_students'));
    }

    public function test_it_reports_the_wordpress_summary_shape(): void
    {
        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0]],
            ],
        ]);

        $this->assertSame(
            [
                'eca_id',
                'eca_name',
                'total_processed',
                'enrolled_count',
                'waitlisted_count',
                'failed_count',
                'results',
            ],
            array_keys($result['data'])
        );

        $this->assertSame(
            ['student_id', 'student_name', 'status'],
            array_keys($result['data']['results'][0])
        );

        $this->assertSame('enrolled', $result['data']['results'][0]['status']);
    }

    public function test_it_enrols_every_valid_student_and_increments_the_count(): void
    {
        $before = (int) $this->eca($this->freeEcaId)->meta['_eca_current_enrollment'];

        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0]],
                ['student_id' => $this->studentIds[1]],
                ['student_id' => $this->studentIds[2]],
            ],
        ]);

        $this->assertSame(3, $result['data']['enrolled_count']);
        $this->assertSame(0, $result['data']['failed_count']);
        $this->assertSame($before + 3, (int) $this->eca($this->freeEcaId)->meta['_eca_current_enrollment']);

        foreach ($this->studentIds as $studentId) {
            $student = $this->store()->find($studentId);

            $this->assertArrayHasKey($this->freeEcaId, $student->meta['_student_eca_enrollments']);
            $this->assertSame('confirmed', $student->meta['_student_eca_enrollments'][$this->freeEcaId]['enrollment_type']);
        }
    }

    public function test_a_bad_row_does_not_abort_the_batch(): void
    {
        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0]],
                ['student_id' => 999999],
                ['student_id' => $this->studentIds[1]],
            ],
        ]);

        $this->assertSame(2, $result['data']['enrolled_count']);
        $this->assertSame(1, $result['data']['failed_count']);
        $this->assertSame(3, $result['data']['total_processed']);

        $this->assertSame('failed', $result['data']['results'][1]['status']);
        $this->assertSame('Invalid student ID.', $result['data']['results'][1]['reason']);

        // The valid rows on either side of the failure landed.
        $this->assertArrayHasKey($this->freeEcaId, $this->store()->find($this->studentIds[0])->meta['_student_eca_enrollments']);
        $this->assertArrayHasKey($this->freeEcaId, $this->store()->find($this->studentIds[1])->meta['_student_eca_enrollments']);
    }

    public function test_a_missing_student_id_is_reported_as_row_zero(): void
    {
        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['enrollment_type' => 'confirmed'],
            ],
        ]);

        $this->assertSame(0, $result['data']['results'][0]['student_id']);
        $this->assertSame('Missing student_id.', $result['data']['results'][0]['reason']);
        $this->assertSame(1, $result['data']['failed_count']);
    }

    public function test_a_duplicate_inside_the_payload_fails_the_second_occurrence(): void
    {
        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0]],
                ['student_id' => $this->studentIds[0]],
            ],
        ]);

        $this->assertSame(1, $result['data']['enrolled_count']);
        $this->assertSame(1, $result['data']['failed_count']);
        $this->assertSame('Already enrolled.', $result['data']['results'][1]['reason']);

        // One enrolment, one count.
        $this->assertSame(1, (int) $this->eca($this->freeEcaId)->meta['_eca_current_enrollment']);
    }

    public function test_an_existing_enrolment_is_not_rewritten(): void
    {
        $seeded = $this->eca($this->roboticsId)->meta['_eca_student_enrollments'];
        $enrolledId = (int) array_key_first($seeded);

        $before = (int) $this->eca($this->roboticsId)->meta['_eca_current_enrollment'];

        $result = $this->callTool([
            'eca_id' => $this->roboticsId,
            'students' => [
                ['student_id' => $enrolledId],
            ],
        ]);

        $this->assertSame(1, $result['data']['failed_count']);
        $this->assertSame('Already enrolled.', $result['data']['results'][0]['reason']);
        $this->assertSame($before, (int) $this->eca($this->roboticsId)->meta['_eca_current_enrollment']);
    }

    public function test_a_full_activity_spills_confirmed_rows_to_the_waitlist(): void
    {
        $this->store()->update(
            $this->freeEcaId,
            new UpdateContentCommand(
                userId: 1,
                meta: ['_eca_max_students' => 1, '_eca_current_enrollment' => 1, '_eca_status' => 'full']
            )
        );

        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0]],
            ],
        ]);

        $this->assertSame(0, $result['data']['enrolled_count']);
        $this->assertSame(1, $result['data']['waitlisted_count']);
        $this->assertSame('waitlisted', $result['data']['results'][0]['status']);

        // Not an error, and the count does not move.
        $this->assertSame(1, (int) $this->eca($this->freeEcaId)->meta['_eca_current_enrollment']);
        $this->assertSame(
            'waitlist',
            $this->store()->find($this->studentIds[0])->meta['_student_eca_enrollments'][$this->freeEcaId]['enrollment_type']
        );
    }

    public function test_skip_capacity_check_enrols_confirmed_on_a_full_activity(): void
    {
        $this->store()->update(
            $this->freeEcaId,
            new UpdateContentCommand(
                userId: 1,
                meta: ['_eca_max_students' => 1, '_eca_current_enrollment' => 1, '_eca_status' => 'full']
            )
        );

        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0]],
            ],
            'skip_capacity_check' => true,
        ]);

        $this->assertSame(1, $result['data']['enrolled_count']);
        $this->assertSame(2, (int) $this->eca($this->freeEcaId)->meta['_eca_current_enrollment']);
    }

    public function test_reaching_capacity_sets_the_activity_status_to_full(): void
    {
        $this->store()->update(
            $this->freeEcaId,
            new UpdateContentCommand(
                userId: 1,
                meta: ['_eca_max_students' => 1, '_eca_current_enrollment' => 0, '_eca_status' => 'active']
            )
        );

        $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0]],
            ],
        ]);

        $this->assertSame('full', $this->eca($this->freeEcaId)->meta['_eca_status']);
    }

    public function test_a_free_activity_stores_payment_status_as_na(): void
    {
        $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0], 'payment_status' => 'paid'],
            ],
        ]);

        $this->assertSame(
            'n/a',
            $this->store()->find($this->studentIds[0])->meta['_student_eca_enrollments'][$this->freeEcaId]['payment_status']
        );
    }

    public function test_a_paid_activity_keeps_the_requested_payment_status_and_cost(): void
    {
        $this->callTool([
            'eca_id' => $this->roboticsId,
            'students' => [
                ['student_id' => $this->studentIds[0], 'payment_status' => 'partial'],
            ],
        ]);

        $record = $this->store()->find($this->studentIds[0])->meta['_student_eca_enrollments'][$this->roboticsId];

        $this->assertSame('partial', $record['payment_status']);
        $this->assertSame(45.0, (float) $record['amount_due']);
    }

    public function test_an_unknown_enrollment_type_falls_back_to_confirmed(): void
    {
        $result = $this->callTool([
            'eca_id' => $this->freeEcaId,
            'students' => [
                ['student_id' => $this->studentIds[0], 'enrollment_type' => 'bogus'],
            ],
        ]);

        $this->assertSame('enrolled', $result['data']['results'][0]['status']);
        $this->assertSame(
            'confirmed',
            $this->store()->find($this->studentIds[0])->meta['_student_eca_enrollments'][$this->freeEcaId]['enrollment_type']
        );
    }

    public function test_an_invalid_eca_is_rejected_before_anything_is_written(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('bulk_enroll_students')
            ->execute(
                ['eca_id' => 999999, 'students' => [['student_id' => $this->studentIds[0]]]],
                ['user_id' => 1]
            );

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Invalid ECA ID.', $result->getMessage());
    }

    public function test_an_empty_students_array_is_a_validation_error(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('bulk_enroll_students')
            ->execute(['eca_id' => $this->freeEcaId, 'students' => []], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
    }
}
