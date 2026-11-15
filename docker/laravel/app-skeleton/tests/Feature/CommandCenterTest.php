<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use App\Support\ActivityRecorder;
use App\Support\HealthSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-i — the command-centre overview.
 *
 * The contract that matters is honesty: every KPI is sourced from a real
 * store, `uptime` is `null` rather than `0` before anything has been sampled,
 * and `agent_statuses` is an object because the console iterates
 * `Object.values()`. A number that cannot be measured is reported as unknown,
 * never invented.
 */
final class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        Sanctum::actingAs($this->admin);
    }

    private function assistant(string $status): Assistant
    {
        return Assistant::query()->create(array(
            'title' => 'Agent '.$status,
            'slug' => 'agent-'.strtolower($status),
            'status' => $status,
            'tenant_id' => Assistant::DEFAULT_TENANT,
        ));
    }

    private function overview(): array
    {
        $response = $this->getJson('/api/v1/pro/command-center/overview');

        $response->assertOk()->assertJsonPath('success', true);

        return (array) $response->json('data');
    }

    public function test_it_requires_manage_options(): void
    {
        Sanctum::actingAs(User::factory()->create(array('role' => 'subscriber')));

        $this->getJson('/api/v1/pro/command-center/overview')->assertForbidden();
    }

    public function test_it_reports_the_six_kpis(): void
    {
        $this->assistant('publish');
        $this->assistant('draft');

        $kpis = $this->overview()['kpis'];

        foreach (array(
            'total_agents',
            'agents_online',
            'active_tasks',
            'pending_approvals',
            'tokens_today',
            'uptime',
        ) as $key) {
            $this->assertArrayHasKey($key, $kpis);
        }
    }

    public function test_agent_counts_come_from_the_assistant_catalogue(): void
    {
        $this->assistant('publish');
        $this->assistant('draft');

        $kpis = $this->overview()['kpis'];

        // An "agent" on this runtime is an assistant record.
        $this->assertSame(2, $kpis['total_agents']);
        // There is no presence channel; "online" means "enabled", the same
        // reading `orchestration/stats` uses for active_agents.
        $this->assertSame(1, $kpis['agents_online']);
    }

    public function test_agent_statuses_is_an_object_keyed_by_assistant(): void
    {
        $online = $this->assistant('publish');
        $idle = $this->assistant('draft');

        $response = $this->getJson('/api/v1/pro/command-center/overview');

        // An object, not a list: the console iterates Object.values(). A PHP
        // array of integer keys would encode as a JSON array and render empty.
        $this->assertStringContainsString('"agent_statuses":{', $response->getContent());

        $statuses = $response->json('data.agent_statuses');

        $this->assertSame('online', $statuses[(string) $online->id]['status']);
        $this->assertSame('idle', $statuses[(string) $idle->id]['status']);
        // The timestamp is a unix second, the shape the feed and map render.
        $this->assertIsInt($statuses[(string) $online->id]['last_active']);
        $this->assertGreaterThan(0, $statuses[(string) $online->id]['last_active']);
    }

    public function test_active_tasks_is_the_queue_depth(): void
    {
        DB::table('jobs')->insert(array(
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ));

        $this->assertSame(1, $this->overview()['kpis']['active_tasks']);
    }

    public function test_pending_approvals_counts_only_pending_rows(): void
    {
        DB::table('nvoos_approvals')->insert(array(
            array('status' => 'pending', 'tool' => 'send_email', 'created_at' => now(), 'updated_at' => now()),
            array('status' => 'approved', 'tool' => 'send_email', 'created_at' => now(), 'updated_at' => now()),
        ));

        $this->assertSame(1, $this->overview()['kpis']['pending_approvals']);
    }

    public function test_tokens_today_counts_only_todays_rows(): void
    {
        DB::table('nvoos_token_usage')->insert(array(
            array('user_id' => 1, 'model_id' => 'gpt-test', 'total_tokens' => 100, 'created_at' => now()),
            array('user_id' => 1, 'model_id' => 'gpt-test', 'total_tokens' => 500, 'created_at' => now()->subDays(2)),
        ));

        $this->assertSame(100, $this->overview()['kpis']['tokens_today']);
    }

    public function test_uptime_is_null_until_a_sample_exists(): void
    {
        // A `0%` beside five real tiles reads as an outage; unknown is honest.
        $this->assertNull($this->overview()['kpis']['uptime']);
    }

    public function test_uptime_tracks_the_health_samples(): void
    {
        $this->app->make(HealthSampler::class)->sample();

        // Cast: the JSON encoder may render a whole percentage as `100`.
        $this->assertSame(100.0, (float) $this->overview()['kpis']['uptime']);
    }

    public function test_recent_events_come_from_the_activity_recorder(): void
    {
        $this->app->make(ActivityRecorder::class)->record('workflow.completed', 'Run finished', 3);

        $events = $this->overview()['recent_events'];

        $this->assertCount(1, $events);
        $this->assertSame('workflow.completed', $events[0]['type']);
        $this->assertSame('Run finished', $events[0]['message']);
        $this->assertSame(3, $events[0]['agent_id']);
    }

    public function test_restriction_count_is_a_true_zero(): void
    {
        // No restriction model exists on this runtime, so the banner this
        // drives never shows. If one is added, this is where it gets counted.
        $this->assertSame(0, $this->overview()['restriction_count']);
    }

    public function test_the_capability_manifest_reports_the_endpoint_as_route_supplied(): void
    {
        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.commandCenter', true)
            ->assertJsonPath('data.endpoint_reasons.commandCenter', 'route');
    }
}
