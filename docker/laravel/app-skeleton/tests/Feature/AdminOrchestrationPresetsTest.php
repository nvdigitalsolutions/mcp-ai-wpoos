<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\OrchestrationPresets;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvoos\Core\Domain\ValueObject\TurnBudget;
use Tests\TestCase;

/**
 * Orchestration presets — Proposal 149 §181.
 *
 * The page is a macro over three settings keys, so the assertions are about the
 * joins that could silently break: the presets must write through the one writer,
 * the derived "active" state must agree with what is stored (never a second
 * truth), and an apply must audit itself because Livewire never reaches the
 * request middleware.
 */
final class AdminOrchestrationPresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(array('sanctum.stateful' => array('localhost', '127.0.0.1')));
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_an_unconfigured_estate_reads_as_the_off_preset(): void
    {
        $this->actingAsEstate();

        // 0/0/0.8 is both "everything off" (§179) and the Off preset's values, so
        // the page names it rather than calling a fresh estate Custom.
        $component = Livewire::test(OrchestrationPresets::class)
            ->assertSet('pending', '')
            ->assertSee('Frugal')
            ->assertSee('Balanced')
            ->assertSee('Generous')
            ->assertSee('Off');

        $this->assertSame('off', $component->instance()->activeKey());
    }

    public function test_values_that_match_no_preset_read_as_custom(): void
    {
        $this->actingAsEstate();

        $this->settings()->write(
            array(
                'turn_max_call_tokens' => 12_345,
                'turn_max_turn_tokens' => 67_890,
                'turn_warn_at' => 0.55,
            ),
            SettingsService::SURFACE_ADMIN
        );

        // `activeKey()` rather than assertSee('Custom'): the callout copy also
        // contains the word, so a text assertion would pass regardless.
        $this->assertNull(Livewire::test(OrchestrationPresets::class)->instance()->activeKey());
    }

    public function test_applying_a_preset_writes_all_three_keys_and_audits_itself(): void
    {
        $this->actingAsEstate();

        Livewire::test(OrchestrationPresets::class)
            ->call('confirm', 'frugal')
            ->assertSet('pending', 'frugal')
            ->call('apply')
            ->assertSet('pending', '');

        $values = $this->settings()->values(SettingsService::SURFACE_ADMIN);

        $this->assertSame(16000, (int) $values['turn_max_call_tokens']);
        $this->assertSame(64000, (int) $values['turn_max_turn_tokens']);
        $this->assertSame(0.7, (float) $values['turn_warn_at']);

        $audit = DB::table('nvoos_admin_audit')
            ->where('resource', 'orchestration-presets')
            ->where('record_id', 'frugal')
            ->where('action', 'applied')
            ->first();

        $this->assertNotNull($audit, 'Applying a preset must leave an audit row.');

        // `AdminAuditLogger` redacts any key whose name contains "token", so both
        // ceilings are stored as [redacted] by policy — the warn band is not a
        // "token" key and keeps its value. The preset key is the durable record
        // of what was written, because the catalogue is code-owned.
        $after = (string) $audit->after;

        $this->assertStringContainsString('turn_max_call_tokens', $after);
        $this->assertStringContainsString('turn_warn_at', $after);
        $this->assertStringContainsString('0.7', $after);
        $this->assertStringContainsString('[redacted]', $after);
        $this->assertStringContainsString('turn_max_call_tokens', (string) $audit->before);
    }

    public function test_the_applied_values_reach_the_runtimes_turn_budget(): void
    {
        $this->actingAsEstate();

        Livewire::test(OrchestrationPresets::class)
            ->call('confirm', 'generous')
            ->call('apply');

        // `scoped` is what keeps an Octane worker from serving the previous
        // estate's ceilings; forget it so the container rebuilds from storage.
        $this->app->forgetScopedInstances();

        $budget = $this->app->make(TurnBudget::class);

        $this->assertTrue($budget->enabled());
        $this->assertSame(64000, $budget->perCallTokens);
        $this->assertSame(512000, $budget->perTurnTokens);
        $this->assertSame(0.9, $budget->warnAt);
    }

    public function test_applying_writes_those_three_keys_and_nothing_else(): void
    {
        $this->actingAsEstate();

        $this->settings()->write(
            array('default_provider' => 'anthropic'),
            SettingsService::SURFACE_ADMIN
        );

        $before = $this->settings()->values(SettingsService::SURFACE_ADMIN);

        Livewire::test(OrchestrationPresets::class)
            ->call('confirm', 'frugal')
            ->call('apply');

        $after = $this->settings()->values(SettingsService::SURFACE_ADMIN);

        // Every other value is untouched: a preset is a projection, not a mode.
        foreach ($before as $key => $value) {
            if (in_array($key, array('turn_max_call_tokens', 'turn_max_turn_tokens', 'turn_warn_at'), true)) {
                continue;
            }

            $this->assertSame($value, $after[$key], "Preset apply changed {$key}.");
        }

        $this->assertSame('anthropic', $after['default_provider']);
    }

    public function test_an_unknown_preset_writes_nothing_and_audits_nothing(): void
    {
        $this->actingAsEstate();

        Livewire::test(OrchestrationPresets::class)
            ->call('confirm', 'no-such-preset')
            ->assertSet('pending', '')
            ->set('pending', 'no-such-preset')
            ->call('apply')
            ->assertSet('pending', '');

        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
        $this->assertSame(
            0,
            (int) $this->settings()->values(SettingsService::SURFACE_ADMIN)['turn_max_call_tokens'],
            'An unknown key must not fall back to some default preset.'
        );
    }

    public function test_apply_without_a_pending_preset_does_nothing(): void
    {
        $this->actingAsEstate();

        Livewire::test(OrchestrationPresets::class)
            ->call('apply')
            ->assertSet('pending', '');

        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    public function test_the_page_requires_the_tenant_panel(): void
    {
        $this->actingAs(User::factory()->create(array('role' => 'subscriber')));

        $this->assertFalse(OrchestrationPresets::canAccess());
    }

    private function settings(): SettingsService
    {
        return $this->app->make(SettingsService::class);
    }

    private function actingAsEstate(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            array('id' => Tenant::DEFAULT_ID),
            array(
                'name' => 'Estate '.Tenant::DEFAULT_ID,
                'slug' => Tenant::DEFAULT_ID,
                'is_operator' => false,
            )
        );

        $user = User::factory()->admin()->create();
        $tenant->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        $this->actingAs($user);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }
}
