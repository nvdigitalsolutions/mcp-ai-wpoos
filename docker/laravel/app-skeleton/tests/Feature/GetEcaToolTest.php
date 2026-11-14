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
 * 090-d cluster 3 — `get_eca` on the Laravel runtime.
 *
 * Field order, derived capacity values and the optional enrolment list mirror
 * `WP_MCP_AI_Tool_Get_ECA`. The envelope nests the row under `data.eca`, which
 * is the canonical core shape.
 */
class GetEcaToolTest extends TestCase
{
    use RefreshDatabase;

    private int $ecaId;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);

        $this->ecaId = $this->app->make(ContentStoreInterface::class)
            ->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))
            ->items[0]->id;
    }

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('get_eca')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result['data']['eca'];
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('get_eca'));
    }

    public function test_it_returns_the_wordpress_detail_shape(): void
    {
        $eca = $this->callTool(['eca_id' => $this->ecaId]);

        $this->assertSame(
            [
                'eca_id',
                'name',
                'description',
                'eca_code',
                'type',
                'day',
                'start_time',
                'end_time',
                'venue',
                'year_groups',
                'max_students',
                'current_enrollment',
                'available_spots',
                'is_full',
                'enrollment_breakdown',
                'teachers',
                'is_paid',
                'cost',
                'cost_period',
                'currency',
                'term',
                'requires_audition',
                'booking_type',
                'status',
                'isams_sync_id',
                'sessions',
                'url',
                'created_at',
                'modified_at',
            ],
            array_keys($eca),
            'Detail keys must stay in the WordPress order.'
        );

        $this->assertSame($this->ecaId, $eca['eca_id']);
        $this->assertSame('Robotics Club', $eca['name']);
        $this->assertSame('Build and program competition robots.', $eca['description']);
        $this->assertSame('ROB-01', $eca['eca_code']);
        $this->assertSame(16, $eca['max_students']);
        $this->assertSame(2, $eca['current_enrollment']);
        $this->assertSame(14, $eca['available_spots']);
        $this->assertFalse($eca['is_full']);
        $this->assertTrue($eca['is_paid']);
        $this->assertSame(45.0, $eca['cost']);
        $this->assertSame('term', $eca['cost_period']);
        $this->assertSame('', $eca['url']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $eca['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $eca['modified_at']);
    }

    public function test_the_breakdown_counts_the_authoritative_enrolment_list(): void
    {
        // WordPress reads `_eca_enrollments`, which nothing writes, so its
        // breakdown is zero on real data. The port falls back to the id list
        // the enrolment tools actually maintain.
        $eca = $this->callTool(['eca_id' => $this->ecaId]);

        $this->assertSame(
            ['confirmed' => 2, 'waitlist' => 0, 'trial' => 0, 'preference' => 0],
            $eca['enrollment_breakdown']
        );
    }

    public function test_it_omits_enrolments_unless_asked(): void
    {
        $this->assertArrayNotHasKey('enrollments', $this->callTool(['eca_id' => $this->ecaId]));
    }

    public function test_it_resolves_enrolled_student_names_when_asked(): void
    {
        $eca = $this->callTool([
            'eca_id' => $this->ecaId,
            'include_enrollments' => true,
        ]);

        $this->assertArrayHasKey('enrollments', $eca);
        $this->assertCount(2, $eca['enrollments']);

        $first = $eca['enrollments'][0];

        $this->assertSame(
            ['student_id', 'student_name', 'enrollment_type', 'enrollment_date', 'payment_status'],
            array_keys($first)
        );
        $this->assertSame('Amara Okafor', $first['student_name']);
        $this->assertSame('confirmed', $first['enrollment_type']);
        $this->assertSame('', $first['enrollment_date']);
        $this->assertSame('', $first['payment_status']);
    }

    public function test_per_enrolment_records_win_when_present(): void
    {
        $store = $this->app->make(ContentStoreInterface::class);

        $student = $store->query(new ContentQuery(types: array('mcp_ai_student'), perPage: 1))->items[0];

        $store->update(
            $this->ecaId,
            new \Nvoos\Core\Domain\Entity\UpdateContentCommand(
                userId: 1,
                meta: [
                    '_eca_enrollments' => [
                        [
                            'student_id' => $student->id,
                            'enrollment_type' => 'waitlist',
                            'enrollment_date' => '2026-09-01',
                            'payment_status' => 'pending',
                        ],
                    ],
                ],
            )
        );

        $detail = $this->callTool([
            'eca_id' => $this->ecaId,
            'include_enrollments' => true,
        ]);

        $this->assertSame(
            ['confirmed' => 0, 'waitlist' => 1, 'trial' => 0, 'preference' => 0],
            $detail['enrollment_breakdown']
        );

        $this->assertCount(1, $detail['enrollments']);
        $this->assertSame('waitlist', $detail['enrollments'][0]['enrollment_type']);
        $this->assertSame('2026-09-01', $detail['enrollments'][0]['enrollment_date']);
        $this->assertSame('pending', $detail['enrollments'][0]['payment_status']);
    }

    public function test_a_missing_id_is_a_validation_error(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('get_eca')
            ->execute([], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('ECA ID is required', $result->getMessage());
    }

    public function test_an_unknown_id_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('get_eca')
            ->execute(['eca_id' => 999999], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid ECA ID', $result->getMessage());
    }

    public function test_a_row_of_another_type_is_not_an_eca(): void
    {
        $student = $this->app->make(ContentStoreInterface::class)
            ->query(new ContentQuery(types: array('mcp_ai_student'), perPage: 1))
            ->items[0];

        $result = $this->app->make(ToolRegistry::class)
            ->get('get_eca')
            ->execute(['eca_id' => $student->id], ['user_id' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Invalid ECA ID', $result->getMessage());
    }
}
