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
 * 090-d cluster 3 — `list_ecas` on the Laravel runtime.
 *
 * Scope is the local mirror: the tool reads rows the store already has and
 * never calls Google Classroom or ISAMS, so the assertions are about the wire
 * shape and the filters rather than about a remote sync.
 *
 * The row keys, derived capacity values and pagination envelope mirror
 * `WP_MCP_AI_Tool_List_ECAs`; the two documented divergences (`sort_by`
 * restricted to name/created_date, `url` always empty) are asserted here so
 * they cannot drift silently.
 */
class ListEcasToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);
    }

    private function callTool(array $args = []): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('list_ecas')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result['data'];
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has('list_ecas'));
    }

    public function test_it_returns_the_seeded_activity_in_the_wordpress_row_shape(): void
    {
        $data = $this->callTool();

        $this->assertSame(1, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(20, $data['per_page']);
        $this->assertSame(1, $data['total_pages']);
        $this->assertFalse($data['has_more']);
        $this->assertCount(1, $data['ecas']);

        $eca = $data['ecas'][0];

        $this->assertSame(
            [
                'eca_id',
                'name',
                'eca_code',
                'description',
                'type',
                'day',
                'start_time',
                'end_time',
                'venue',
                'year_groups',
                'teachers',
                'max_students',
                'current_enrollment',
                'available_spots',
                'is_full',
                'capacity_utilization',
                'is_paid',
                'cost',
                'cost_period',
                'requires_audition',
                'booking_type',
                'status',
                'url',
            ],
            array_keys($eca),
            'Row keys must stay in the WordPress order so consumers can diff responses.'
        );

        $this->assertSame('Robotics Club', $eca['name']);
        $this->assertSame('ROB-01', $eca['eca_code']);
        $this->assertSame('club', $eca['type']);
        $this->assertSame('Wednesday', $eca['day']);
        $this->assertSame('Design Lab', $eca['venue']);
        $this->assertSame(['9', '10'], $eca['year_groups']);

        // Derived capacity values.
        $this->assertSame(16, $eca['max_students']);
        $this->assertSame(2, $eca['current_enrollment']);
        $this->assertSame(14, $eca['available_spots']);
        $this->assertFalse($eca['is_full']);
        $this->assertSame(12.5, $eca['capacity_utilization']);

        // WordPress checkbox encoding is mirrored, not converted.
        $this->assertTrue($eca['is_paid']);
        $this->assertSame(45.0, $eca['cost']);
        $this->assertSame('term', $eca['cost_period']);
        $this->assertFalse($eca['requires_audition']);
        $this->assertSame('active', $eca['status']);

        // Documented divergence: no permalinks outside WordPress.
        $this->assertSame('', $eca['url']);
    }

    public function test_it_filters_by_day_type_venue_and_status(): void
    {
        $this->assertSame(1, $this->callTool(['day' => 'Wednesday'])['total']);
        $this->assertSame(0, $this->callTool(['day' => 'Monday'])['total']);
        $this->assertSame(1, $this->callTool(['eca_type' => 'club'])['total']);
        $this->assertSame(0, $this->callTool(['eca_type' => 'sport_squad'])['total']);
        $this->assertSame(1, $this->callTool(['status' => 'active'])['total']);
        $this->assertSame(0, $this->callTool(['status' => 'cancelled'])['total']);
        $this->assertSame(1, $this->callTool(['venue' => 'Design'])['total']);
        $this->assertSame(0, $this->callTool(['venue' => 'Gym'])['total']);
    }

    public function test_it_matches_list_valued_meta_with_a_like_filter(): void
    {
        $this->assertSame(1, $this->callTool(['year_group' => '10'])['total']);
        $this->assertSame(0, $this->callTool(['year_group' => '7'])['total']);
        $this->assertSame(1, $this->callTool(['teacher' => '1'])['total']);
    }

    public function test_is_paid_distinguishes_absent_from_false(): void
    {
        // Absent filters nothing.
        $this->assertSame(1, $this->callTool()['total']);

        // Present and true: the paid activity.
        $this->assertSame(1, $this->callTool(['is_paid' => true])['total']);

        // Present and false: an explicit free-activity filter. WordPress uses
        // isset(), so `false` must filter rather than be treated as absent.
        $this->assertSame(0, $this->callTool(['is_paid' => false])['total']);
    }

    public function test_has_availability_excludes_full_activities(): void
    {
        // The seeded activity is not full, so it survives the filter.
        $this->assertSame(1, $this->callTool(['has_availability' => true])['total']);

        $this->markFull();

        $this->assertSame(0, $this->callTool(['has_availability' => true])['total']);
        $this->assertSame(1, $this->callTool(['status' => 'full'])['total']);
    }

    public function test_search_matches_the_activity_body(): void
    {
        $this->assertSame(1, $this->callTool(['search' => 'competition robots'])['total']);
        $this->assertSame(0, $this->callTool(['search' => 'debating'])['total']);
    }

    public function test_it_paginates(): void
    {
        $first = $this->callTool(['per_page' => 1]);

        $this->assertSame(1, $first['per_page']);
        $this->assertSame(1, $first['total_pages']);
        $this->assertFalse($first['has_more']);

        $second = $this->callTool(['per_page' => 1, 'page' => 2]);

        $this->assertSame(2, $second['page']);
        $this->assertSame([], $second['ecas']);
    }

    public function test_it_rejects_the_wordpress_only_sort_fields(): void
    {
        foreach (['day', 'type'] as $sortBy) {
            $result = $this->app->make(ToolRegistry::class)
                ->get('list_ecas')
                ->execute(['sort_by' => $sortBy], ['user_id' => 1]);

            // The adapter returns errors as Throwables rather than throwing
            // them, and AssertionFailedError is itself a RuntimeException — so
            // the rejection is asserted on the return value, not with try/catch.
            $this->assertInstanceOf(RuntimeException::class, $result, 'sort_by='.$sortBy.' should be rejected.');
            $this->assertStringContainsString('sort_by', $result->getMessage());
            $this->assertStringContainsString('WordPress-only', $result->getMessage());
        }
    }

    public function test_created_date_sorting_is_supported(): void
    {
        // Stamped after the seed so ASC puts the seeded activity first.
        $this->addActivity('Chess Club', '2030-01-01 09:00:00');

        $asc = $this->callTool(['sort_by' => 'created_date', 'sort_order' => 'ASC']);
        $desc = $this->callTool(['sort_by' => 'created_date', 'sort_order' => 'DESC']);

        $this->assertSame(2, $asc['total']);
        $this->assertSame('Robotics Club', $asc['ecas'][0]['name']);
        $this->assertSame('Chess Club', $desc['ecas'][0]['name']);
    }

    public function test_it_requires_a_logged_in_user(): void
    {
        $this->expectException(\Nvoos\Core\Domain\Error\AccessDeniedException::class);

        $this->app->make(ToolRegistry::class)
            ->get('list_ecas')
            ->execute([], []);
    }

    /**
     * Flip the seeded activity to a full one, the way WordPress stores it.
     */
    private function markFull(): void
    {
        $store = $this->app->make(ContentStoreInterface::class);

        $eca = $store->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))->items[0];

        		$meta = $eca->meta;
        		$meta['_eca_status'] = 'full';
        		$meta['_eca_current_enrollment'] = $meta['_eca_max_students'] ?? 0;

        		$store->update(
        			$eca->id,
        			new \Nvoos\Core\Domain\Entity\UpdateContentCommand(
        				userId: 1,
        				meta: $meta,
        			)
        		);
    }

    private function addActivity(string $title, string $createdAt): void
    {
        $store = $this->app->make(ContentStoreInterface::class);

        $item = $store->create(
            new CreateContentCommand(
                title: $title,
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

        \Illuminate\Support\Facades\DB::table('nvoos_posts')
            ->where('id', $item->id)
            ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
    }
}
