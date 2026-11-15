<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `manage_eca_waitlist`.
 *
 * The waitlist has no position column: an entry is waitlisted when its
 * `enrollment_type` says so, and its position is the sort order of
 * `enrollment_date`. These tests pin that derivation, because every action
 * depends on it — including `reorder`, which has to rewrite every date to
 * persist a new order.
 */
class ManageEcaWaitlistToolTest extends TestCase
{
    use RefreshDatabase;

    private int $debateId;

    /** @var array<int, int> Waitlisted, in signup order. */
    private array $waitlisted = [];

    /** @var array<int, int> */
    private array $confirmed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $store = $this->app->make(ContentStoreInterface::class);

        $this->debateId = $store->create(
            new CreateContentCommand(
                title: 'Debate Society',
                type: 'mcp_ai_eca',
                authorId: 1,
                status: 'publish',
                content: '',
                meta: [
                    '_eca_status' => 'active',
                    '_eca_is_paid' => 'no',
                    '_eca_cost' => 0,
                    '_eca_max_students' => 5,
                    '_eca_current_enrollment' => 1,
                    '_eca_student_enrollments' => [],
                ],
            )
        )->id;

        foreach (['Ada Lovelace', 'Ben Silva', 'Cleo Marsh', 'Dev Rao'] as $index => $name) {
            $studentId = $store->create(
                new CreateContentCommand(
                    title: $name,
                    type: 'mcp_ai_student',
                    authorId: 1,
                    status: 'publish',
                    content: '',
                    meta: ['_student_eca_enrollments' => []],
                )
            )->id;

            if (0 === $index) {
                $this->confirmed[] = $studentId;
            } else {
                $this->waitlisted[] = $studentId;
            }
        }

        // Build both sides by hand: the seeder has no waitlist.
        $ecaMap = [];

        $ecaMap[$this->confirmed[0]] = [
            'enrollment_type' => 'confirmed',
            'enrollment_date' => '2026-09-01 09:00:00',
            'payment_status' => 'n/a',
            'amount_due' => 0,
            'notes' => '',
            'enrolled_by' => 1,
        ];

        foreach ($this->waitlisted as $offset => $studentId) {
            $record = [
                'enrollment_type' => 'waitlist',
                'enrollment_date' => sprintf('2026-09-02 09:%02d:00', $offset),
                'payment_status' => 'n/a',
                'amount_due' => 0,
                'notes' => '',
                'enrolled_by' => 1,
            ];

            $ecaMap[$studentId] = $record;

            $map = [$this->debateId => $record];

            $store->update(
                $studentId,
                new UpdateContentCommand(userId: 1, meta: ['_student_eca_enrollments' => $map])
            );
        }

        $store->update(
            $this->debateId,
            new UpdateContentCommand(userId: 1, meta: ['_eca_student_enrollments' => $ecaMap])
        );
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    private function eca(): ContentItem
    {
        return $this->store()->find($this->debateId);
    }

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('manage_eca_waitlist')
            ->execute($args + ['eca_id' => $this->debateId], ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    /**
     * @return array<int, int> Student ids ordered by enrollment date.
     */
    private function waitlistOrder(): array
    {
        $map = $this->eca()->meta['_eca_student_enrollments'];

        $waitlisted = array_filter(
            $map,
            static fn (array $record): bool => 'waitlist' === ($record['enrollment_type'] ?? '')
        );

        uasort(
            $waitlisted,
            static fn (array $a, array $b): int => strcmp($a['enrollment_date'], $b['enrollment_date'])
        );

        return array_map('intval', array_keys($waitlisted));
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('manage_eca_waitlist'));
    }

    public function test_list_returns_positions_in_signup_order(): void
    {
        $result = $this->callTool(['action' => 'list']);

        $this->assertSame(
            ['eca_id', 'eca_name', 'total', 'waitlist'],
            array_keys($result['data'])
        );

        $this->assertSame(3, $result['data']['total']);
        $this->assertSame([1, 2, 3], array_column($result['data']['waitlist'], 'position'));
        $this->assertSame($this->waitlisted, array_column($result['data']['waitlist'], 'student_id'));

        $this->assertSame(
            ['position', 'student_id', 'student_name', 'enrollment_date', 'notes'],
            array_keys($result['data']['waitlist'][0])
        );

        $this->assertSame('Ben Silva', $result['data']['waitlist'][0]['student_name']);
    }

    public function test_list_reports_an_empty_waitlist(): void
    {
        $this->store()->update(
            $this->debateId,
            new UpdateContentCommand(userId: 1, meta: ['_eca_student_enrollments' => []])
        );

        $result = $this->callTool(['action' => 'list']);

        $this->assertSame(0, $result['data']['total']);
        $this->assertSame([], $result['data']['waitlist']);
    }

    public function test_promote_moves_both_sides_and_increments_the_count(): void
    {
        $studentId = $this->waitlisted[1];

        $result = $this->callTool(['action' => 'promote', 'student_id' => $studentId]);

        $this->assertSame(
            ['eca_id', 'eca_name', 'student_id', 'student_name'],
            array_keys($result['data'])
        );

        $this->assertSame($studentId, $result['data']['student_id']);
        $this->assertSame('Cleo Marsh', $result['data']['student_name']);

        $this->assertSame(
            'confirmed',
            $this->eca()->meta['_eca_student_enrollments'][$studentId]['enrollment_type']
        );

        $this->assertSame(
            'confirmed',
            $this->store()->find($studentId)->meta['_student_eca_enrollments'][$this->debateId]['enrollment_type']
        );

        $this->assertSame(2, (int) $this->eca()->meta['_eca_current_enrollment']);
    }

    public function test_promote_rejects_a_student_who_is_not_waitlisted(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('manage_eca_waitlist')
            ->execute(
                ['eca_id' => $this->debateId, 'action' => 'promote', 'student_id' => $this->confirmed[0]],
                ['user_id' => 1]
            );

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Student is not on the waitlist for this ECA.', $result->getMessage());
    }

    public function test_promote_next_promotes_the_earliest_signup(): void
    {
        $result = $this->callTool(['action' => 'promote_next']);

        $this->assertSame($this->waitlisted[0], $result['data']['student_id']);
        $this->assertSame(
            'confirmed',
            $this->eca()->meta['_eca_student_enrollments'][$this->waitlisted[0]]['enrollment_type']
        );
    }

    public function test_promote_next_on_an_empty_waitlist_is_an_error(): void
    {
        $this->store()->update(
            $this->debateId,
            new UpdateContentCommand(userId: 1, meta: ['_eca_student_enrollments' => []])
        );

        $result = $this->app->make(ToolRegistry::class)
            ->get('manage_eca_waitlist')
            ->execute(['eca_id' => $this->debateId, 'action' => 'promote_next'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('The waitlist is empty.', $result->getMessage());
    }

    public function test_promote_does_not_check_capacity(): void
    {
        $this->store()->update(
            $this->debateId,
            new UpdateContentCommand(
                userId: 1,
                meta: ['_eca_max_students' => 1, '_eca_current_enrollment' => 1, '_eca_status' => 'active']
            )
        );

        $this->callTool(['action' => 'promote', 'student_id' => $this->waitlisted[0]]);

        // WordPress pushes past the maximum rather than refusing.
        $this->assertSame(2, (int) $this->eca()->meta['_eca_current_enrollment']);
        $this->assertSame('full', $this->eca()->meta['_eca_status']);
    }

    public function test_remove_deletes_both_sides_and_leaves_the_count_alone(): void
    {
        $studentId = $this->waitlisted[0];
        $before = (int) $this->eca()->meta['_eca_current_enrollment'];

        $result = $this->callTool(['action' => 'remove', 'student_id' => $studentId]);

        $this->assertSame($studentId, $result['data']['student_id']);

        $this->assertArrayNotHasKey(
            $studentId,
            $this->eca()->meta['_eca_student_enrollments']
        );

        $this->assertArrayNotHasKey(
            $this->debateId,
            $this->store()->find($studentId)->meta['_student_eca_enrollments']
        );

        // A waitlisted student was never counted, so nothing moves.
        $this->assertSame($before, (int) $this->eca()->meta['_eca_current_enrollment']);
    }

    public function test_remove_leaves_a_full_status_alone(): void
    {
        $this->store()->update(
            $this->debateId,
            new UpdateContentCommand(userId: 1, meta: ['_eca_status' => 'full'])
        );

        $this->callTool(['action' => 'remove', 'student_id' => $this->waitlisted[0]]);

        // WordPress leaves the status alone; the console gates on capacity.
        $this->assertSame('full', $this->eca()->meta['_eca_status']);
    }

    public function test_reorder_moves_the_student_to_the_requested_position(): void
    {
        $moved = $this->waitlisted[2];

        $result = $this->callTool([
            'action' => 'reorder',
            'student_id' => $moved,
            'position' => 1,
        ]);

        $this->assertSame(1, $result['data']['new_position']);

        $expected = array_merge([$moved], array_slice($this->waitlisted, 0, 2));

        $this->assertSame($expected, $this->waitlistOrder());
    }

    public function test_reorder_rewrites_every_waitlist_date(): void
    {
        $this->callTool([
            'action' => 'reorder',
            'student_id' => $this->waitlisted[0],
            'position' => 3,
        ]);

        $map = $this->eca()->meta['_eca_student_enrollments'];

        $dates = array_values(
            array_map(
                static fn (array $record): string => $record['enrollment_date'],
                array_filter($map, static fn (array $record): bool => 'waitlist' === $record['enrollment_type'])
            )
        );

        // All three moved, and they are distinct to the second — otherwise the
        // order would fall back to map iteration.
        $this->assertCount(3, array_unique($dates));
        $this->assertNotContains('2026-09-02 09:00:00', $dates);
    }

    public function test_reorder_clamps_a_position_past_the_end(): void
    {
        $result = $this->callTool([
            'action' => 'reorder',
            'student_id' => $this->waitlisted[0],
            'position' => 99,
        ]);

        $this->assertSame(3, $result['data']['new_position']);
        $this->assertSame($this->waitlisted[0], $this->waitlistOrder()[2]);
    }

    public function test_reorder_rejects_a_student_who_is_not_waitlisted(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('manage_eca_waitlist')
            ->execute(
                [
                    'eca_id' => $this->debateId,
                    'action' => 'reorder',
                    'student_id' => $this->confirmed[0],
                    'position' => 1,
                ],
                ['user_id' => 1]
            );

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Student is not on the waitlist for this ECA.', $result->getMessage());
    }

    public function test_an_invalid_action_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('manage_eca_waitlist')
            ->execute(['eca_id' => $this->debateId, 'action' => 'teleport'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Invalid waitlist action.', $result->getMessage());
    }

    public function test_promote_requires_a_student_id(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('manage_eca_waitlist')
            ->execute(['eca_id' => $this->debateId, 'action' => 'promote'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
    }
}
