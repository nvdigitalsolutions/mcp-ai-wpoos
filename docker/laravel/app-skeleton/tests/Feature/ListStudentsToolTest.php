<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Tests\TestCase;

/**
 * 090-d cluster 3 — `list_students` on the Laravel runtime.
 *
 * Row keys and the pagination envelope mirror `WP_MCP_AI_Tool_List_Students`.
 * `enrollment_count` reads the reverse link (`_student_eca_enrollments`), which
 * nothing writes on this runtime until `enroll_student_eca` is ported, so the
 * tests pin both the empty case and the populated one.
 */
class ListStudentsToolTest extends TestCase
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
            ->get('list_students')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result['data'];
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('list_students'));
    }

    public function test_it_returns_the_wordpress_row_shape_ordered_by_name(): void
    {
        $data = $this->callTool();

        $this->assertSame(2, $data['pagination']['total']);
        $this->assertSame(1, $data['pagination']['total_pages']);
        $this->assertSame(1, $data['pagination']['current_page']);
        $this->assertSame(20, $data['pagination']['per_page']);
        $this->assertCount(2, $data['students']);

        $this->assertSame(
            ['id', 'name', 'first_name', 'last_name', 'year_group', 'house', 'email', 'enrollment_count'],
            array_keys($data['students'][0])
        );

        // Alphabetical by title: Amara before Leo.
        $amara = $data['students'][0];

        $this->assertSame('Amara Okafor', $amara['name']);
        $this->assertSame('Amara', $amara['first_name']);
        $this->assertSame('Okafor', $amara['last_name']);
        $this->assertSame('10', $amara['year_group']);
        $this->assertSame('Falcon', $amara['house']);
        $this->assertSame('amara.okafor@example.org', $amara['email']);
    }

    public function test_enrolment_count_is_zero_until_the_reverse_link_is_written(): void
    {
        // The seeder records enrolment on the activity; no tool maintains the
        // student side yet, and inventing a count from the other table would
        // not be the number the WordPress tool reports.
        $this->assertSame(0, $this->callTool()['students'][0]['enrollment_count']);
    }

    public function test_enrolment_count_follows_the_reverse_link(): void
    {
        $store = $this->app->make(ContentStoreInterface::class);

        $student = $store->query(new ContentQuery(types: array('mcp_ai_student'), perPage: 1))->items[0];
        $eca = $store->query(new ContentQuery(types: array('mcp_ai_eca'), perPage: 1))->items[0];

        $store->update(
            $student->id,
            new UpdateContentCommand(
                userId: 1,
                meta: ['_student_eca_enrollments' => [$eca->id]],
            )
        );

        $students = $this->callTool()['students'];

        $byName = array_column($students, null, 'name');

        $this->assertSame(1, $byName[$student->title]['enrollment_count']);
        $this->assertSame(0, $byName['Leo Marchetti']['enrollment_count']);
    }

    public function test_it_filters_by_year_group_and_house(): void
    {
        $this->assertSame(1, $this->callTool(['year_group' => '10'])['pagination']['total']);
        $this->assertSame(0, $this->callTool(['year_group' => '7'])['pagination']['total']);
        $this->assertSame(1, $this->callTool(['house' => 'Heron'])['pagination']['total']);
        $this->assertSame(0, $this->callTool(['house' => 'Falcon', 'year_group' => '9'])['pagination']['total']);
    }

    public function test_search_matches_the_student_name(): void
    {
        $this->assertSame(1, $this->callTool(['search' => 'Marchetti'])['pagination']['total']);
        $this->assertSame(0, $this->callTool(['search' => 'Bianchi'])['pagination']['total']);
    }

    public function test_an_out_of_range_per_page_falls_back_to_the_default(): void
    {
        // WordPress resets rather than clamps; 500 must not become 100.
        $this->assertSame(20, $this->callTool(['per_page' => 500])['pagination']['per_page']);
        $this->assertSame(20, $this->callTool(['per_page' => 0])['pagination']['per_page']);
        $this->assertSame(1, $this->callTool(['per_page' => 1])['pagination']['per_page']);
    }

    public function test_it_paginates(): void
    {
        $first = $this->callTool(['per_page' => 1]);

        $this->assertSame(2, $first['pagination']['total_pages']);
        $this->assertSame('Amara Okafor', $first['students'][0]['name']);

        $second = $this->callTool(['per_page' => 1, 'page' => 2]);

        $this->assertSame(2, $second['pagination']['current_page']);
        $this->assertSame('Leo Marchetti', $second['students'][0]['name']);
    }
}
