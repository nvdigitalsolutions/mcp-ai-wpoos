<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `update_eca`.
 *
 * This is a patch tool: only supplied fields move, and every move lands in
 * `_eca_change_history`. The interesting cases are the ones the auto-adjust
 * logic can get wrong — a `max_students` change that silently rewrites
 * `_eca_status` in both directions.
 */
class UpdateEcaToolTest extends TestCase
{
    use RefreshDatabase;

    private int $roboticsId;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);

        $this->roboticsId = $this->store()
            ->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))
            ->items[0]->id;
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
            ->get('update_eca')
            ->execute($args + ['eca_id' => $this->roboticsId], ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('update_eca'));
    }

    public function test_it_only_touches_the_supplied_fields(): void
    {
        $before = $this->eca();

        $result = $this->callTool(['venue' => 'New Hall']);

        $this->assertSame(
            ['eca_id', 'fields_changed', 'changes'],
            array_keys($result['data'])
        );

        $this->assertSame(1, $result['data']['fields_changed']);
        $this->assertSame('venue', $result['data']['changes'][0]['field']);
        $this->assertSame('Design Lab', $result['data']['changes'][0]['old_value']);
        $this->assertSame('New Hall', $result['data']['changes'][0]['new_value']);

        $after = $this->eca();

        $this->assertSame('New Hall', $after->meta['_eca_venue']);
        $this->assertSame($before->title, $after->title);
        $this->assertSame($before->meta['_eca_day'], $after->meta['_eca_day']);
        $this->assertSame($before->meta['_eca_max_students'], $after->meta['_eca_max_students']);
    }

    public function test_a_no_op_patch_reports_no_changes_and_writes_no_history(): void
    {
        $result = $this->callTool(['venue' => 'Design Lab']);

        $this->assertSame(0, $result['data']['fields_changed']);
        $this->assertSame([], $result['data']['changes']);

        // No audit row for a write that changed nothing.
        $this->assertArrayNotHasKey('_eca_change_history', $this->eca()->meta);
    }

    public function test_a_loose_comparison_does_not_report_numeric_drift(): void
    {
        // The seeder stores the cost as an integer; the caller sends the same
        // amount as a float. WordPress compares loosely here.
        $result = $this->callTool(['cost' => 45.0]);

        $this->assertSame(0, $result['data']['fields_changed']);
    }

    public function test_a_rename_is_recorded(): void
    {
        $result = $this->callTool(['name' => 'Robotics Society']);

        $this->assertSame('name', $result['data']['changes'][0]['field']);
        $this->assertSame('Robotics Club', $result['data']['changes'][0]['old_value']);
        $this->assertSame('Robotics Society', $result['data']['changes'][0]['new_value']);
        $this->assertSame('Robotics Society', $this->eca()->title);
    }

    public function test_a_capacity_below_the_current_enrolment_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('update_eca')
            ->execute(['eca_id' => $this->roboticsId, 'max_students' => 1], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Cannot set max students below current enrollment count (2).', $result->getMessage());
    }

    public function test_raising_capacity_on_a_full_activity_reopens_it(): void
    {
        $this->store()->update(
            $this->roboticsId,
            new \Nvoos\Core\Domain\Entity\UpdateContentCommand(
                userId: 1,
                meta: ['_eca_status' => 'full', '_eca_max_students' => 2, '_eca_current_enrollment' => 2]
            )
        );

        $this->callTool(['max_students' => 5]);

        $this->assertSame('active', $this->eca()->meta['_eca_status']);
        $this->assertSame(5, (int) $this->eca()->meta['_eca_max_students']);
    }

    public function test_lowering_capacity_onto_the_current_count_marks_it_full(): void
    {
        $this->callTool(['max_students' => 2]);

        // Current enrolment is 2 and the activity starts active.
        $this->assertSame('full', $this->eca()->meta['_eca_status']);
    }

    public function test_every_change_is_appended_to_the_audit_trail(): void
    {
        $this->callTool(['venue' => 'New Hall', 'status' => 'inactive']);

        $history = $this->eca()->meta['_eca_change_history'];

        $this->assertCount(1, $history);
        $this->assertSame(1, $history[0]['user_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $history[0]['timestamp']);
        $this->assertSame(['venue', 'status'], array_column($history[0]['changes'], 'field'));
    }

    public function test_a_second_patch_appends_rather_than_replaces(): void
    {
        $this->callTool(['venue' => 'New Hall']);
        $this->callTool(['eca_code' => 'ROB-02']);

        $history = $this->eca()->meta['_eca_change_history'];

        $this->assertCount(2, $history);
        $this->assertSame('venue', $history[0]['changes'][0]['field']);
        $this->assertSame('eca_code', $history[1]['changes'][0]['field']);
    }

    public function test_the_audit_trail_is_capped_at_one_hundred_entries(): void
    {
        $history = array();

        for ($i = 0; $i < 100; $i++) {
            $history[] = array(
                'timestamp' => '2026-01-01 00:00:00',
                'user_id' => 1,
                'changes' => array(array('field' => 'seed', 'old_value' => '', 'new_value' => (string) $i)),
            );
        }

        $this->store()->update(
            $this->roboticsId,
            new \Nvoos\Core\Domain\Entity\UpdateContentCommand(userId: 1, meta: ['_eca_change_history' => $history])
        );

        $this->callTool(['venue' => 'New Hall']);

        $history = $this->eca()->meta['_eca_change_history'];

        $this->assertCount(100, $history);
        $this->assertSame('venue', $history[99]['changes'][0]['field']);
    }

    public function test_an_out_of_enum_value_is_a_validation_error(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('update_eca')
            ->execute(['eca_id' => $this->roboticsId, 'status' => 'bogus'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());

        // Nothing was written.
        $this->assertSame('active', $this->eca()->meta['_eca_status']);
    }

    public function test_a_currency_is_case_sensitive(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('update_eca')
            ->execute(['eca_id' => $this->roboticsId, 'currency' => 'gbp'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
    }

    public function test_a_boolean_field_is_stored_with_wordpress_encoding(): void
    {
        $this->callTool(['requires_audition' => true]);

        $this->assertSame('yes', $this->eca()->meta['_eca_requires_audition']);

        $this->callTool(['requires_audition' => false]);

        $this->assertSame('no', $this->eca()->meta['_eca_requires_audition']);
    }

    public function test_an_unknown_eca_id_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('update_eca')
            ->execute(['eca_id' => 999999, 'venue' => 'Nowhere'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Invalid ECA ID.', $result->getMessage());
    }

    public function test_a_missing_eca_id_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('update_eca')
            ->execute(['venue' => 'Nowhere'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('ECA ID is required.', $result->getMessage());
    }
}
