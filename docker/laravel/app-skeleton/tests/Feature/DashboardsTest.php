<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Tests\TestCase;

/**
 * 090-b — the Pro dashboards surface.
 *
 * WordPress builds ten dashboards and dispatches each to that dashboard's
 * wp-admin page gatherer. This runtime owns one of those stores, so the
 * catalogue serves `eca` and reports the other nine as `disabled` with the
 * toolkit flag that would enable them — the same shape WordPress uses for a
 * switched-off toolkit.
 *
 * The response shapes are asserted unwrapped, because `DashboardsClient` reads
 * `dashboards` / `disabled` / `data` off the top level on both runtimes.
 */
class DashboardsTest extends TestCase
{
    use RefreshDatabase;

    private int $roboticsId;

    /** @var array<int, int> */
    private array $studentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->seed(\Database\Seeders\ClassroomSeeder::class);

        $store = $this->app->make(ContentStoreInterface::class);

        $robotics = $store->query(new ContentQuery(types: ['mcp_ai_eca'], perPage: 1))->items[0];

        $this->roboticsId = $robotics->id;

        $this->studentIds = array_map(
            static fn ($student) => $student->id,
            $store->query(new ContentQuery(types: ['mcp_ai_student'], perPage: 10))->items
        );
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    public function test_schema_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/pro/dashboards/schema')->assertStatus(401);
    }

    public function test_schema_requires_the_admin_capability(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create());

        $this->getJson('/api/v1/pro/dashboards/schema')->assertForbidden();
    }

    public function test_schema_serves_the_eca_dashboard_with_its_render_spec(): void
    {
        $response = $this->getJson('/api/v1/pro/dashboards/schema')->assertOk();

        $response->assertJsonPath('dashboards.eca.id', 'eca');
        $response->assertJsonPath('dashboards.eca.label', 'ECA');
        $response->assertJsonPath('dashboards.eca.icon', '🏫');

        $config = $response->json('dashboards.eca');

        $this->assertSame(
            ['total_activities', 'total_enrolments', 'total_waitlisted', 'capacity_usage'],
            array_column($config['metrics'], 'key')
        );

        $this->assertSame(['by_day', 'by_type'], array_column($config['charts'], 'key'));
        $this->assertSame(['activity_list'], array_column($config['lists'], 'key'));
        $this->assertSame(['day', 'eca_type'], array_column($config['filters'], 'key'));
    }

    public function test_schema_reports_the_unserved_dashboards_as_disabled(): void
    {
        $response = $this->getJson('/api/v1/pro/dashboards/schema')->assertOk();

        $disabled = $response->json('disabled');

        // Nine of the ten WordPress dashboards, each with the flag that would
        // switch it on — that metadata is what the console's empty state reads.
        $this->assertCount(9, $disabled);
        $this->assertArrayNotHasKey('eca', $disabled);
        $this->assertSame('🏦', $disabled['cre']['icon']);
        $this->assertSame('enable_cre_debt_toolkit', $disabled['cre']['toolkit_flag']);

        // `observability` is ungated on WordPress, so it carries no flag.
        $this->assertSame('', $disabled['observability']['toolkit_flag']);
    }

    public function test_data_returns_the_metric_chart_and_list_keys_the_config_declares(): void
    {
        $response = $this->getJson('/api/v1/pro/dashboards/eca/data')->assertOk();

        $this->assertSame('eca', $response->json('dashboard'));

        $data = $response->json('data');

        $this->assertSame(
            [
                'total_activities',
                'total_enrolments',
                'total_waitlisted',
                'capacity_usage',
                'by_day',
                'by_type',
                'by_status',
                'activity_list',
            ],
            array_keys($data)
        );
    }

    public function test_data_counts_the_seeded_activity(): void
    {
        $data = $this->getJson('/api/v1/pro/dashboards/eca/data')->json('data');

        // ClassroomSeeder: one activity, two confirmed enrolments, capacity 16.
        $this->assertSame(1, $data['total_activities']);
        $this->assertSame(2, $data['total_enrolments']);
        $this->assertSame(0, $data['total_waitlisted']);
        $this->assertSame(12.5, $data['capacity_usage']);

        $this->assertSame(2, $data['by_day']['Wednesday']);
        $this->assertSame(1, $data['by_type']['club']);
        $this->assertSame(1, $data['by_status']['active']);
    }

    public function test_data_lists_the_activity_with_its_capacity(): void
    {
        $rows = $this->getJson('/api/v1/pro/dashboards/eca/data')->json('data.activity_list');

        $this->assertCount(1, $rows);
        $this->assertSame($this->roboticsId, $rows[0]['id']);
        $this->assertSame('Robotics Club', $rows[0]['title']);
        $this->assertSame('Wednesday', $rows[0]['day']);
        $this->assertSame(2, $rows[0]['enrolled']);
        $this->assertSame(16, $rows[0]['capacity']);
        $this->assertSame(0, $rows[0]['waitlisted']);
    }

    public function test_a_waitlisted_enrolment_is_counted_separately(): void
    {
        $store = $this->store();

        $activity = $store->create(new CreateContentCommand(
            title: 'Chess Club',
            type: 'mcp_ai_eca',
            authorId: 1,
            status: 'publish',
            content: '',
            meta: [
                '_eca_day' => 'Monday',
                '_eca_type' => 'club',
                '_eca_status' => 'active',
                '_eca_max_students' => 1,
                '_eca_current_enrollment' => 1,
                '_eca_student_enrollments' => [
                    $this->studentIds[0] => ['enrollment_type' => 'confirmed'],
                    $this->studentIds[1] => ['enrollment_type' => 'waitlist'],
                ],
            ],
        ));

        $data = $this->getJson('/api/v1/pro/dashboards/eca/data')->json('data');

        $this->assertSame(2, $data['total_activities']);
        $this->assertSame(3, $data['total_enrolments']);
        $this->assertSame(1, $data['total_waitlisted']);
        $this->assertSame(1, $data['by_day']['Monday']);

        $chess = $data['activity_list'][0];

        $this->assertSame('Chess Club', $chess['title']);
        $this->assertSame(1, $chess['waitlisted']);
        $this->assertSame(1, $chess['enrolled']);
        $this->assertSame($activity->id, $chess['id']);
    }

    public function test_the_day_filter_narrows_the_payload(): void
    {
        $monday = $this->getJson('/api/v1/pro/dashboards/eca/data?filters='.urlencode('{"day":"Monday"}'))
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $monday['total_activities']);
        $this->assertSame([], $monday['activity_list']);

        $wednesday = $this->getJson('/api/v1/pro/dashboards/eca/data?filters='.urlencode('{"day":"Wednesday"}'))
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $wednesday['total_activities']);
    }

    public function test_an_undeclared_filter_key_is_ignored(): void
    {
        $data = $this->getJson('/api/v1/pro/dashboards/eca/data?filters='.urlencode('{"status":"full"}'))
            ->assertOk()
            ->json('data');

        // `status` is not a declared ECA filter, so the full catalogue is
        // returned rather than an empty page.
        $this->assertSame(1, $data['total_activities']);
    }

    public function test_an_unknown_dashboard_is_a_bad_request(): void
    {
        $this->getJson('/api/v1/pro/dashboards/nope/data')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Unknown dashboard.');
    }

    public function test_a_disabled_dashboard_is_not_found(): void
    {
        $this->getJson('/api/v1/pro/dashboards/cre/data')
            ->assertStatus(404)
            ->assertJsonPath(
                'message',
                'This dashboard requires a toolkit that is not enabled on this site.'
            );
    }
}
