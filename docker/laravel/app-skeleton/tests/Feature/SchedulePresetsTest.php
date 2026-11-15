<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Schedule Anything presets on the Laravel runtime (090-b §14).
 *
 * The contract is WordPress's: the same catalogue (mirrored from
 * `WP_MCP_AI_SA_Schedule_Presets`, drift-checked in CI), the same filters
 * envelope, and the same idempotent install keyed on the raw overrides.
 */
class SchedulePresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_the_catalogue_is_served_with_facets(): void
    {
        $response = $this->getJson('/api/v1/pro/presets')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total', 12);

        $this->assertCount(12, $response->json('presets'));

        $toolkits = $response->json('filters.toolkits');
        $categories = $response->json('filters.categories');

        $this->assertContains('crm', $toolkits);
        $this->assertContains('calendar-booking', $toolkits);
        $this->assertContains('reporting', $categories);

        // The first preset carries its full WordPress shape.
        $first = $response->json('presets.0');
        $this->assertSame('cal-reminder-followup', $first['id']);
        $this->assertSame('calendar-booking', $first['toolkit']);
        $this->assertSame('daily', $first['schedule_data']['schedule']);
    }

    public function test_filters_narrow_the_catalogue(): void
    {
        $this->getJson('/api/v1/pro/presets?toolkit=crm')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('presets.0.toolkit', 'crm');

        $this->getJson('/api/v1/pro/presets?search=abandoned')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('presets.0.id', 'ecom-abandoned-cart');

        $this->getJson('/api/v1/pro/presets?category=reporting')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_pagination_is_bounded(): void
    {
        $this->getJson('/api/v1/pro/presets?per_page=5&page=2')
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonCount(5, 'presets');
    }

    public function test_show_returns_one_preset_and_404_for_unknown(): void
    {
        $this->getJson('/api/v1/pro/presets/crm-lead-nurture')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preset.id', 'crm-lead-nurture')
            ->assertJsonPath('preset.toolkit', 'crm');

        $this->getJson('/api/v1/pro/presets/not-a-preset')
            ->assertNotFound()
            ->assertJsonPath('code', 'sa_preset_not_found');
    }

    public function test_install_creates_a_disabled_schedule_and_replays(): void
    {
        $first = $this->postJson('/api/v1/pro/presets/cal-reminder-followup/install')
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('preset_id', 'cal-reminder-followup')
            ->assertJsonPath('schedule.enabled', false)
            ->assertJsonPath('schedule.schedule', '0 0 * * *')
            ->assertJsonPath('schedule.handler', 'wp_mcp_ai_pro_schedule_exec');

        $id = $first->json('schedule.id');

        $this->postJson('/api/v1/pro/presets/cal-reminder-followup/install')
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('schedule.id', $id);

        $this->assertSame(1, DB::table('nvoos_schedules')->count());
        $this->assertStringStartsWith('preset:cal-reminder-followup:h', (string) DB::table('nvoos_schedules')->value('preset_key'));
        $this->assertNotNull(DB::table('nvoos_schedules')->value('next_run_at'));
    }

    public function test_overrides_change_the_schedule_and_its_identity(): void
    {
        $this->postJson('/api/v1/pro/presets/cal-reminder-followup/install')
            ->assertCreated();

        $this->postJson('/api/v1/pro/presets/cal-reminder-followup/install', [
            'overrides' => [
                'name' => 'Custom reminder',
                'schedule' => 'weekly',
                'enabled' => true,
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('schedule.name', 'Custom reminder')
            ->assertJsonPath('schedule.schedule', '0 0 * * 1')
            ->assertJsonPath('schedule.enabled', true);

        // Different overrides are a different install key: two rows.
        $this->assertSame(2, DB::table('nvoos_schedules')->count());
    }

    public function test_install_rejects_an_unsupported_interval(): void
    {
        $this->postJson('/api/v1/pro/presets/cal-reminder-followup/install', [
            'overrides' => ['schedule' => 'every-five-minutes'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sa_preset_schedule_unsupported');
    }

    public function test_install_404s_for_an_unknown_preset(): void
    {
        $this->postJson('/api/v1/pro/presets/nope/install')
            ->assertNotFound()
            ->assertJsonPath('code', 'sa_preset_not_found');
    }

    public function test_non_admins_cannot_install(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create());

        $this->postJson('/api/v1/pro/presets/cal-reminder-followup/install')->assertForbidden();
    }
}
