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
 * 090-d cluster 3 — `create_eca`.
 *
 * The tool is dual-purpose: it creates, and it updates in place when `eca_id`
 * is supplied. The assertions that matter are the ones a tidy rewrite would
 * lose — optional meta is only written when non-empty, so an empty string
 * means "not provided" rather than "clear it".
 */
class CreateEcaToolTest extends TestCase
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

    private function callTool(array $args): array
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('create_eca')
            ->execute($args, ['user_id' => 1]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    public function test_the_tool_is_registered_with_the_wordpress_slug(): void
    {
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('create_eca'));
    }

    public function test_it_creates_an_activity_and_reports_the_wordpress_shape(): void
    {
        $result = $this->callTool([
            'name' => 'Astronomy Club',
            'eca_code' => 'AST-01',
            'eca_type' => 'club',
            'day' => 'Monday',
            'start_time' => '4:00 PM',
            'end_time' => '5:30 PM',
            'venue' => 'Observatory',
            'year_groups' => ['Year 9', 'Year 10'],
            'max_students' => 12,
            'teachers' => ['Ms Hubble'],
            'is_paid' => true,
            'cost' => 30,
            'cost_period' => 'term',
            'status' => 'active',
        ]);

        $this->assertSame(
            [
                'eca_id',
                'name',
                'eca_code',
                'type',
                'day',
                'start_time',
                'end_time',
                'venue',
                'max_students',
                'current_enrollment',
                'year_groups',
                'teachers',
                'is_paid',
                'cost',
                'status',
                'url',
                'edit_url',
                'updated',
            ],
            array_keys($result['data'])
        );

        $this->assertFalse($result['data']['updated']);
        $this->assertSame('ECA created successfully.', $result['message']);
        $this->assertSame('Astronomy Club', $result['data']['name']);
        $this->assertSame(0, $result['data']['current_enrollment']);
        $this->assertSame(30.0, $result['data']['cost']);

        $saved = $this->store()->find($result['data']['eca_id']);

        $this->assertSame('mcp_ai_eca', $saved->type);
        $this->assertSame('Astronomy Club', $saved->title);
        $this->assertSame('AST-01', $saved->meta['_eca_code']);
        $this->assertSame('Monday', $saved->meta['_eca_day']);
        $this->assertSame('yes', $saved->meta['_eca_is_paid']);
        $this->assertSame('no', $saved->meta['_eca_requires_audition']);
        $this->assertSame(30.0, (float) $saved->meta['_eca_cost']);
        $this->assertSame(['Year 9', 'Year 10'], $saved->meta['_eca_year_groups']);
        $this->assertSame(0, (int) $saved->meta['_eca_current_enrollment']);
    }

    public function test_optional_meta_is_not_written_when_omitted(): void
    {
        $result = $this->callTool(['name' => 'Quiet Club']);

        $saved = $this->store()->find($result['data']['eca_id']);

        // WordPress only writes optional meta when supplied and non-empty.
        $this->assertArrayNotHasKey('_eca_venue', $saved->meta);
        $this->assertArrayNotHasKey('_eca_day', $saved->meta);
        $this->assertArrayNotHasKey('_eca_max_students', $saved->meta);
        $this->assertArrayNotHasKey('_eca_cost', $saved->meta);

        // The required meta is always written, with defaults applied.
        $this->assertSame('club', $saved->meta['_eca_type']);
        $this->assertSame('active', $saved->meta['_eca_status']);
        $this->assertSame('first_come_first_served', $saved->meta['_eca_booking_type']);
        $this->assertSame('no', $saved->meta['_eca_is_paid']);
    }

    public function test_an_unknown_type_falls_back_to_club(): void
    {
        $result = $this->callTool(['name' => 'Odd Club', 'eca_type' => 'not_a_type']);

        $this->assertSame('club', $result['data']['type']);
        $this->assertSame('club', $this->store()->find($result['data']['eca_id'])->meta['_eca_type']);
    }

    public function test_an_invalid_day_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('create_eca')
            ->execute(['name' => 'Odd Club', 'day' => 'Funday'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Invalid day of week.', $result->getMessage());
    }

    public function test_a_missing_name_is_a_validation_error(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('create_eca')
            ->execute(['venue' => 'Somewhere'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
    }

    public function test_a_description_is_stored_as_sanitised_text(): void
    {
        $result = $this->callTool([
            'name' => 'Marked Club',
            'description' => '<script>alert(1)</script><b>Bold</b> and plain',
        ]);

        $this->assertSame(
            'Bold and plain',
            $this->store()->find($result['data']['eca_id'])->content
        );
    }

    public function test_supplying_an_eca_id_updates_in_place(): void
    {
        $result = $this->callTool([
            'eca_id' => $this->roboticsId,
            'name' => 'Robotics Society',
            'venue' => 'Innovation Lab',
        ]);

        $this->assertTrue($result['data']['updated']);
        $this->assertSame('ECA updated: Robotics Society', $result['message']);
        $this->assertSame($this->roboticsId, $result['data']['eca_id']);

        $saved = $this->store()->find($this->roboticsId);

        $this->assertSame('Robotics Society', $saved->title);
        $this->assertSame('Innovation Lab', $saved->meta['_eca_venue']);

        // No second activity was created.
        $this->assertSame(1, $this->store()->query(new ContentQuery(types: array('mcp_ai_eca')))->total);
    }

    public function test_updating_preserves_the_existing_enrolment_count(): void
    {
        $before = (int) $this->store()->find($this->roboticsId)->meta['_eca_current_enrollment'];

        $result = $this->callTool([
            'eca_id' => $this->roboticsId,
            'name' => 'Robotics Club',
        ]);

        $this->assertSame($before, $result['data']['current_enrollment']);
        $this->assertSame($before, (int) $this->store()->find($this->roboticsId)->meta['_eca_current_enrollment']);
    }

    public function test_an_unknown_eca_id_is_rejected(): void
    {
        $result = $this->app->make(ToolRegistry::class)
            ->get('create_eca')
            ->execute(['eca_id' => 999999, 'name' => 'Ghost Club'], ['user_id' => 1]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('ECA not found.', $result->getMessage());
    }
}
