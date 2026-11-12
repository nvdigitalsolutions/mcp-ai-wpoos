<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Laravel\Adapter\ProEntitlement;
use Tests\TestCase;

/**
 * Phase 3c — Pro Schedule Manager, Records schema and the wp/v2 content shim.
 *
 * Every assertion follows the console client that consumes the surface:
 * `schedule-manager.ts` and `records.ts` in `addons/pro/assets/spa-v2`.
 */
class Phase3cTest extends TestCase
{
    use RefreshDatabase;

    private function actAsUser(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createSchedule(array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/pro/schedules', array_merge([
            'name' => 'Nightly digest',
            'handler' => 'color_convert',
            'schedule' => '0 2 * * *',
            'payload' => ['color' => '#ff0000', 'to_format' => 'hex'],
        ], $overrides));

        $response->assertCreated()->assertJsonPath('ok', true);

        return $response->json('schedule');
    }

    // ------------------------------------------------------------ schedule CRUD

    public function test_schedule_crud_round_trip(): void
    {
        $this->actAsUser();

        $schedule = $this->createSchedule([
            'description' => 'Summarise the day',
            'priority' => 5,
            'tags' => ['ops', 'digest'],
        ]);

        $this->assertSame('nightly-digest', $schedule['id']);
        $this->assertTrue($schedule['enabled']);
        $this->assertSame(['ops', 'digest'], $schedule['tags']);

        $this->getJson('/api/v1/pro/schedules')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('schedules.0.handler', 'color_convert')
            ->assertJsonStructure([
                'schedules' => [[
                    'id', 'name', 'description', 'schedule_type', 'schedule',
                    'enabled', 'next_run', 'last_run', 'priority', 'tags',
                    'run_count', 'handler', 'payload',
                ]],
                'total',
            ]);

        $this->getJson('/api/v1/pro/schedules/nightly-digest')
            ->assertOk()
            ->assertJsonPath('schedule.name', 'Nightly digest');

        $this->postJson('/api/v1/pro/schedules/nightly-digest', [
            'name' => 'Nightly digest v2',
            'priority' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('schedule.name', 'Nightly digest v2')
            ->assertJsonPath('schedule.priority', 1)
            // Untouched fields survive a partial update.
            ->assertJsonPath('schedule.schedule', '0 2 * * *');

        $this->postJson('/api/v1/pro/schedules/nightly-digest/toggle')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('schedule.enabled', false);

        $this->deleteJson('/api/v1/pro/schedules/nightly-digest')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted', true);

        $this->getJson('/api/v1/pro/schedules/nightly-digest')
            ->assertNotFound()
            ->assertJsonPath('ok', false);
    }

    public function test_schedule_creation_requires_name_handler_and_schedule(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/pro/schedules', ['name' => 'Incomplete'])
            ->assertStatus(422);
    }

    public function test_schedule_ids_are_uniquified(): void
    {
        $this->actAsUser();

        $this->createSchedule(['name' => 'Same Name']);
        $second = $this->createSchedule(['name' => 'Same Name']);

        $this->assertSame('same-name-2', $second['id']);
    }

    // --------------------------------------------------------------- execution

    public function test_trigger_executes_the_handler_and_records_a_run(): void
    {
        $this->actAsUser();
        $this->createSchedule();

        $this->postJson('/api/v1/pro/schedules/nightly-digest/trigger')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('run.status', 'success')
            ->assertJsonPath('run.schedule_id', 'nightly-digest')
            ->assertJsonPath('schedule.run_count', 1)
            ->assertJsonStructure(['run' => ['id', 'status', 'started_at', 'finished_at', 'duration']]);

        $this->getJson('/api/v1/pro/schedules/nightly-digest/history')
            ->assertOk()
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.status', 'success')
            ->assertJsonStructure(['history' => [['status', 'start_time', 'duration', 'error', 'action_log']]]);
    }

    public function test_trigger_reports_an_unknown_handler_as_a_failed_run(): void
    {
        $this->actAsUser();
        $this->createSchedule(['name' => 'Broken', 'handler' => 'not_a_registered_tool']);

        $failed = $this->postJson('/api/v1/pro/schedules/broken/trigger')
            ->assertStatus(422)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('run.status', 'failure');

        $this->assertNotEmpty($failed->json('run.error'));

        $this->getJson('/api/v1/pro/schedules/fleet-stats')
            ->assertOk()
            ->assertJsonPath('stats.failure_runs', 1)
            ->assertJsonPath('stats.success_runs', 0)
            ->assertJsonPath('stats.success_rate', 0);
    }

    public function test_results_surface_the_captured_tool_output(): void
    {
        $this->actAsUser();
        $this->createSchedule();

        $this->postJson('/api/v1/pro/schedules/nightly-digest/trigger')->assertOk();

        $this->getJson('/api/v1/pro/schedules/nightly-digest/results?limit=5')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.status', 'success')
            ->assertJsonPath('results.0.render', 'json')
            ->assertJsonStructure(['results' => [['summary', 'data', 'render', 'status', 'generated_at']]]);
    }

    public function test_fleet_stats_aggregate_the_ledger(): void
    {
        $this->actAsUser();
        $this->createSchedule(['name' => 'One']);
        $this->createSchedule(['name' => 'Two']);
        $this->postJson('/api/v1/pro/schedules/one/trigger')->assertOk();

        $this->getJson('/api/v1/pro/schedules/fleet-stats')
            ->assertOk()
            ->assertJsonPath('stats.total_schedules', 2)
            ->assertJsonPath('stats.enabled_schedules', 2)
            ->assertJsonPath('stats.total_runs', 1)
            ->assertJsonPath('stats.success_runs', 1)
            ->assertJsonPath('stats.success_rate', 100);
    }

    public function test_presets_are_empty_and_install_fails_closed(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/presets')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('presets', [])
            ->assertJsonPath('total', 0);

        $this->postJson('/api/v1/pro/presets/none/install')
            ->assertNotFound()
            ->assertJsonPath('ok', false);
    }

    // ------------------------------------------------------------- entitlement

    public function test_pro_surfaces_fail_closed_when_the_entitlement_denies(): void
    {
        $this->actAsUser();

        $this->app->instance(
            ProEntitlementInterface::class,
            new ProEntitlement('deny', 'base', []),
        );

        $this->getJson('/api/v1/pro/schedules')
            ->assertStatus(402)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error.code', 'pro_entitlement_required')
            ->assertJsonPath('error.feature', 'schedules');

        $this->getJson('/api/v1/pro/records/schema')
            ->assertStatus(402)
            ->assertJsonPath('error.feature', 'records');
    }

    // ----------------------------------------------------------------- records

    public function test_records_schema_lists_the_content_entities(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/records/schema')
            ->assertOk()
            ->assertJsonPath('entities.post.restBase', 'posts')
            ->assertJsonPath('entities.page.restBase', 'pages')
            ->assertJsonPath('entities.post.group', 'content')
            ->assertJsonStructure([
                'entities' => [
                    'post' => ['key', 'postType', 'restBase', 'label', 'singular', 'icon', 'group', 'columns'],
                ],
            ]);
    }

    public function test_unported_record_paths_answer_501_not_404(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/pro/records/remote/post')
            ->assertStatus(501)
            ->assertJsonPath('error.code', 'not_implemented');

        $this->getJson('/api/v1/pro/records/remote/post/5')
            ->assertStatus(501);

        $this->postJson('/api/v1/pro/records/post/research', ['query' => 'acme'])
            ->assertStatus(501)
            ->assertJsonPath('ok', false);
    }

    // ------------------------------------------------------------ content shim

    public function test_content_list_paginates_with_wordpress_total_headers(): void
    {
        $this->actAsUser();
        $this->seedPost('Alpha');
        $this->seedPost('Bravo');
        $this->seedPost('Charlie');
        $this->seedPost('Delta', 'page');

        $response = $this->getJson('/api/v1/wp/posts?per_page=2&page=1&orderby=date&order=desc')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([[
                'id', 'date', 'date_gmt', 'slug', 'status', 'type',
                'title' => ['rendered', 'raw'],
                'meta',
            ]]);

        $this->assertSame('3', $response->headers->get('X-WP-Total'));
        $this->assertSame('2', $response->headers->get('X-WP-TotalPages'));

        // Pages are a separate collection.
        $this->getJson('/api/v1/wp/pages')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title.rendered', 'Delta');
    }

    public function test_content_list_supports_search(): void
    {
        $this->actAsUser();
        $this->seedPost('Quarterly report');
        $this->seedPost('Holiday photos');

        $this->getJson('/api/v1/wp/posts?search=Quarterly')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title.raw', 'Quarterly report');
    }

    public function test_content_create_then_show(): void
    {
        $this->actAsUser();

        $created = $this->postJson('/api/v1/wp/posts', [
            'title' => 'Fresh Post',
            'status' => 'publish',
            'content' => '<p>Body</p>',
            'meta' => ['source' => 'console'],
        ])
            ->assertCreated()
            ->assertJsonPath('title.rendered', 'Fresh Post')
            ->assertJsonPath('status', 'publish')
            ->assertJsonPath('meta.source', 'console');

        $id = $created->json('id');

        $this->getJson('/api/v1/wp/posts/'.$id)
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('slug', 'fresh-post');
    }

    public function test_content_rejects_an_unknown_rest_base_with_a_wp_style_error(): void
    {
        $this->actAsUser();

        $this->getJson('/api/v1/wp/widgets')
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_no_route');

        $this->getJson('/api/v1/wp/posts/999999')
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_post_invalid_id');
    }

    // ------------------------------------------------------------------- guards

    public function test_console_trigger_path_is_reachable(): void
    {
        $this->actAsUser();

        // runtime-config maps `triggers` to `${core}/orchestration/triggers`.
        $this->getJson('/api/v1/orchestration/triggers')
            ->assertOk()
            ->assertJsonStructure(['triggers']);

        $this->getJson('/api/v1/triggers')
            ->assertOk()
            ->assertJsonStructure(['triggers']);
    }

    public function test_pro_surfaces_require_authentication(): void
    {
        $this->getJson('/api/v1/pro/schedules')->assertUnauthorized();
        $this->getJson('/api/v1/pro/records/schema')->assertUnauthorized();
        $this->getJson('/api/v1/wp/posts')->assertUnauthorized();
    }

    private function seedPost(string $title, string $type = 'post'): void
    {
        DB::table('nvoos_posts')->insert([
            'title' => $title,
            'content' => 'Body of '.$title,
            'status' => 'publish',
            'type' => $type,
            'slug' => 'slug-'.md5($title),
            'meta' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
