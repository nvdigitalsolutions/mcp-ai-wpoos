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
 * 090-d cluster 3 — `withdraw_student_eca`.
 *
 * The counterpart to `enroll_student_eca`. The assertions that matter are the
 * ones that make withdrawal more than a delete: the count only moves for a
 * confirmed enrolment, `full` returns to `active`, and a confirmed withdrawal
 * auto-promotes the longest-waiting student.
 */
class WithdrawStudentEcaToolTest extends TestCase
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

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    private function eca(): \Nvoos\Core\Domain\Entity\ContentItem
    {
        return $this->store()->find($this->roboticsId);
    }

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('withdraw_student_eca')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    private function callExpectingError(array $args): RuntimeException
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('withdraw_student_eca')
            ->execute($args, ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);

        return $result;
    }

    /**
     * Write the reverse link the way `enroll_student_eca` does.
     */
    private function link(int $studentId, int $ecaId, string $type = 'confirmed', string $date = '2026-09-01 09:00:00'): void
    {
        $student = $this->store()->find($studentId);
        $map     = $student?->meta['_student_eca_enrollments'] ?? [];

        if (! is_array($map)) {
            $map = [];
        }

        $map[$ecaId] = array(
            'enrollment_type' => $type,
            'enrollment_date' => $date,
            'payment_status' => 'n/a',
        );

        $this->store()->update(
            $studentId,
            new UpdateContentCommand(userId: 1, meta: ['_student_eca_enrollments' => $map])
        );
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('withdraw_student_eca'));
    }

    public function test_it_removes_both_sides_and_returns_the_wordpress_fields(): void
    {
        $this->link($this->amaraId, $this->roboticsId);

        $result = $this->callTool([
            'student_id' => $this->amaraId,
            'eca_id' => $this->roboticsId,
            'reason' => 'Timetable clash',
        ]);

        $this->assertSame('Amara Okafor has been withdrawn from Robotics Club.', $result['message']);

        $this->assertSame(
            ['student_id', 'student_name', 'eca_id', 'eca_name', 'reason', 'effective_date', 'process_refund'],
            array_keys($result['data'])
        );

        $this->assertSame('Timetable clash', $result['data']['reason']);
        $this->assertSame(date('Y-m-d'), $result['data']['effective_date']);
        $this->assertFalse($result['data']['process_refund']);

        $this->assertArrayNotHasKey($this->amaraId, $this->eca()->meta['_eca_student_enrollments']);
        $this->assertArrayNotHasKey($this->roboticsId, $this->store()->find($this->amaraId)->meta['_student_eca_enrollments']);
    }

    public function test_it_decrements_the_count_for_a_confirmed_enrolment(): void
    {
        $this->link($this->amaraId, $this->roboticsId);

        $before = (int) $this->eca()->meta['_eca_current_enrollment'];

        $this->callTool(['student_id' => $this->amaraId, 'eca_id' => $this->roboticsId]);

        $this->assertSame($before - 1, (int) $this->eca()->meta['_eca_current_enrollment']);
    }

    public function test_a_waitlist_withdrawal_leaves_the_count_alone(): void
    {
        // Park Amara as waitlisted on the activity side.
        $map = $this->eca()->meta['_eca_student_enrollments'];
        $map[$this->amaraId]['enrollment_type'] = 'waitlist';

        $this->store()->update(
            $this->roboticsId,
            new UpdateContentCommand(userId: 1, meta: ['_eca_student_enrollments' => $map])
        );

        $before = (int) $this->eca()->meta['_eca_current_enrollment'];

        $result = $this->callTool(['student_id' => $this->amaraId, 'eca_id' => $this->roboticsId]);

        $this->assertSame($before, (int) $this->eca()->meta['_eca_current_enrollment']);
        $this->assertArrayNotHasKey('waitlist_promoted', $result['data']);
    }

    public function test_a_full_activity_returns_to_active_when_a_place_frees(): void
    {
        $map = $this->eca()->meta['_eca_student_enrollments'];

        $this->store()->update(
            $this->roboticsId,
            new UpdateContentCommand(
                userId: 1,
                meta: [
                    '_eca_max_students' => 2,
                    '_eca_current_enrollment' => 2,
                    '_eca_status' => 'full',
                    '_eca_student_enrollments' => $map,
                ]
            )
        );

        $this->callTool(['student_id' => $this->amaraId, 'eca_id' => $this->roboticsId]);

        $eca = $this->eca();

        $this->assertSame(1, (int) $eca->meta['_eca_current_enrollment']);
        $this->assertSame('active', $eca->meta['_eca_status']);
    }

    public function test_a_confirmed_withdrawal_promotes_the_longest_waiting_student(): void
    {
        $store = $this->store();

        // Nina waits longer than Amara's replacement, and was added later, so
        // date order and insertion order disagree on purpose.
        $nina = $store->create(
            new CreateContentCommand(
                title: 'Nina Patel',
                type: 'mcp_ai_student',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: [
                    '_student_year_group' => '9',
                    '_student_eca_enrollments' => [],
                ],
            )
        );

        $store->create(
            new CreateContentCommand(
                title: 'Owen Reid',
                type: 'mcp_ai_student',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: [
                    '_student_year_group' => '9',
                    '_student_eca_enrollments' => [],
                ],
            )
        );

        $map = $this->eca()->meta['_eca_student_enrollments'];

        $map[$this->leoId] = [
            'enrollment_type' => 'waitlist',
            'enrollment_date' => '2026-10-01 09:00:00',
            'payment_status' => 'n/a',
        ];
        $map[$nina->id] = [
            'enrollment_type' => 'waitlist',
            'enrollment_date' => '2026-09-15 09:00:00',
            'payment_status' => 'n/a',
        ];

        $store->update(
            $this->roboticsId,
            new UpdateContentCommand(
                userId: 1,
                meta: [
                    // Only Amara is confirmed in this fixture.
                    '_eca_current_enrollment' => 1,
                    '_eca_student_enrollments' => $map,
                ]
            )
        );

        // Both students need the reverse link for promotion to mirror onto it.
        $this->link($this->leoId, $this->roboticsId, 'waitlist', '2026-10-01 09:00:00');
        $this->link($nina->id, $this->roboticsId, 'waitlist', '2026-09-15 09:00:00');

        $result = $this->callTool(['student_id' => $this->amaraId, 'eca_id' => $this->roboticsId]);

        $this->assertArrayHasKey('waitlist_promoted', $result['data']);
        $this->assertSame($nina->id, $result['data']['waitlist_promoted']['student_id']);
        $this->assertSame('Nina Patel', $result['data']['waitlist_promoted']['student_name']);

        $after = $this->eca();

        $this->assertSame('confirmed', $after->meta['_eca_student_enrollments'][$nina->id]['enrollment_type']);
        $this->assertSame('waitlist', $after->meta['_eca_student_enrollments'][$this->leoId]['enrollment_type']);
        $this->assertSame(
            'confirmed',
            $this->store()->find($nina->id)->meta['_student_eca_enrollments'][$this->roboticsId]['enrollment_type']
        );

        // Withdraw confirmed, promote waitlisted: the count is unchanged.
        $this->assertSame(1, (int) $after->meta['_eca_current_enrollment']);
    }

    public function test_a_promotion_that_fills_the_activity_marks_it_full(): void
    {
        $store = $this->store();

        $nina = $store->create(
            new CreateContentCommand(
                title: 'Nina Patel',
                type: 'mcp_ai_student',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: ['_student_year_group' => '9', '_student_eca_enrollments' => []],
            )
        );

        // Seed state: Amara + Leo confirmed and full at capacity 2, Nina waiting.
        $map = $this->eca()->meta['_eca_student_enrollments'];

        $map[$nina->id] = [
            'enrollment_type' => 'waitlist',
            'enrollment_date' => '2026-09-20 09:00:00',
            'payment_status' => 'n/a',
        ];

        $store->update(
            $this->roboticsId,
            new UpdateContentCommand(
                userId: 1,
                meta: [
                    '_eca_max_students' => 2,
                    '_eca_current_enrollment' => 2,
                    '_eca_status' => 'full',
                    '_eca_student_enrollments' => $map,
                ]
            )
        );

        $result = $this->callTool(['student_id' => $this->amaraId, 'eca_id' => $this->roboticsId]);

        $this->assertSame($nina->id, $result['data']['waitlist_promoted']['student_id']);

        $eca = $this->eca();

        $this->assertSame(2, (int) $eca->meta['_eca_current_enrollment']);
        $this->assertSame('confirmed', $eca->meta['_eca_student_enrollments'][$nina->id]['enrollment_type']);

        // WordPress leaves this reading `active`; the port marks it full.
        $this->assertSame('full', $eca->meta['_eca_status']);
    }

    public function test_it_appends_to_the_change_history(): void
    {
        $this->link($this->amaraId, $this->roboticsId);

        $this->callTool([
            'student_id' => $this->amaraId,
            'eca_id' => $this->roboticsId,
            'reason' => 'Moved school',
            'effective_date' => '2026-12-01',
            'process_refund' => true,
        ]);

        $history = $this->eca()->meta['_eca_change_history'];

        $this->assertCount(1, $history);
        $this->assertSame(
            ['action', 'student_id', 'student_name', 'reason', 'effective_date', 'process_refund', 'performed_by', 'timestamp'],
            array_keys($history[0])
        );
        $this->assertSame('withdrawal', $history[0]['action']);
        $this->assertSame($this->amaraId, $history[0]['student_id']);
        $this->assertSame('Amara Okafor', $history[0]['student_name']);
        $this->assertSame('Moved school', $history[0]['reason']);
        $this->assertSame('2026-12-01', $history[0]['effective_date']);
        $this->assertTrue($history[0]['process_refund']);
        $this->assertSame(1, $history[0]['performed_by']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $history[0]['timestamp']);
    }

    public function test_history_accumulates_across_withdrawals(): void
    {
        $this->link($this->amaraId, $this->roboticsId);

        $this->callTool(['student_id' => $this->amaraId, 'eca_id' => $this->roboticsId]);
        $this->callTool(['student_id' => $this->leoId, 'eca_id' => $this->roboticsId]);

        $this->assertCount(2, $this->eca()->meta['_eca_change_history']);
    }

    public function test_the_cycle_closes_enrol_withdraw_and_read_back(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $nina = $this->store()->create(
            new CreateContentCommand(
                title: 'Nina Patel',
                type: 'mcp_ai_student',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: ['_student_year_group' => '9', '_student_eca_enrollments' => []],
            )
        );

        $registry->get('enroll_student_eca')->execute(
            ['student_id' => $nina->id, 'eca_id' => $this->roboticsId],
            ['user_id' => 1]
        );

        $listed = $registry->get('list_students')->execute(['search' => 'Nina Patel'], ['user_id' => 1]);
        $this->assertSame(1, $listed['data']['students'][0]['enrollment_count']);

        $this->callTool(['student_id' => $nina->id, 'eca_id' => $this->roboticsId]);

        $listed = $registry->get('list_students')->execute(['search' => 'Nina Patel'], ['user_id' => 1]);
        $this->assertSame(0, $listed['data']['students'][0]['enrollment_count']);

        $student = $registry->get('get_student')->execute(['student_id' => $nina->id], ['user_id' => 1]);
        $this->assertSame(0, $student['data']['total_enrollments']);
    }

    public function test_a_student_who_is_not_enrolled_is_rejected(): void
    {
        // The seeder enrols Amara on the activity side; Owen is not enrolled.
        $owen = $this->store()->create(
            new CreateContentCommand(
                title: 'Owen Reid',
                type: 'mcp_ai_student',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: ['_student_eca_enrollments' => []],
            )
        );

        $error = $this->callExpectingError([
            'student_id' => $owen->id,
            'eca_id' => $this->roboticsId,
        ]);

        $this->assertStringContainsString('not enrolled', $error->getMessage());
    }

    public function test_a_missing_reverse_link_is_still_withdrawn(): void
    {
        // The seeder never writes the reverse link; withdrawal must still work
        // because the activity side is authoritative.
        $this->assertArrayNotHasKey(
            $this->roboticsId,
            $this->store()->find($this->amaraId)->meta['_student_eca_enrollments'] ?? []
        );

        $this->callTool(['student_id' => $this->amaraId, 'eca_id' => $this->roboticsId]);

        $this->assertArrayNotHasKey($this->amaraId, $this->eca()->meta['_eca_student_enrollments']);
    }

    public function test_an_impossible_effective_date_is_rejected(): void
    {
        $this->link($this->amaraId, $this->roboticsId);

        $error = $this->callExpectingError([
            'student_id' => $this->amaraId,
            'eca_id' => $this->roboticsId,
            'effective_date' => '2026-02-31',
        ]);

        $this->assertStringContainsString('real date', $error->getMessage());

        // Nothing was withdrawn.
        $this->assertArrayHasKey($this->amaraId, $this->eca()->meta['_eca_student_enrollments']);
    }

    public function test_missing_ids_are_a_validation_error(): void
    {
        $error = $this->callExpectingError(['student_id' => $this->amaraId]);

        $this->assertStringContainsString('Both student_id and eca_id are required', $error->getMessage());
    }

    public function test_an_activity_id_is_not_a_student(): void
    {
        $error = $this->callExpectingError([
            'student_id' => $this->roboticsId,
            'eca_id' => $this->roboticsId,
        ]);

        $this->assertStringContainsString('Invalid student ID', $error->getMessage());
    }

    public function test_a_student_id_is_not_an_activity(): void
    {
        $error = $this->callExpectingError([
            'student_id' => $this->amaraId,
            'eca_id' => $this->amaraId,
        ]);

        $this->assertStringContainsString('Invalid ECA ID', $error->getMessage());
    }
}
