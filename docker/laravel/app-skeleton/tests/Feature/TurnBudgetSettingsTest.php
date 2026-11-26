<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\ValueObject\TurnBudget;
use Tests\TestCase;

/**
 * Turn-budget settings — Proposal 149 §179.
 *
 * The ceilings live in `SettingsSchema`, so there is one reader
 * (`SettingsService::value()`) and one writer. These assertions pin the two
 * joins that could silently break: the schema keys must be writable on the admin
 * surface, and the container's `TurnBudget` must reflect what was stored — an
 * unreadable setting would leave the page rendering a ceiling nothing enforces.
 */
class TurnBudgetSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_estate_with_no_settings_has_both_ceilings_off(): void
    {
        $budget = $this->app->make(TurnBudget::class);

        $this->assertFalse($budget->enabled(), 'Zero in both dimensions is off, so nothing changes for an unconfigured estate.');
        $this->assertSame(0.8, $budget->warnAt, 'The default band is 80%.');
    }

    public function test_the_ceilings_round_trip_through_the_admin_surface(): void
    {
        $applied = $this->app->make(SettingsService::class)->write(
            array(
                'turn_max_call_tokens' => 40_000,
                'turn_max_turn_tokens' => 150_000,
                'turn_warn_at' => 0.65,
            ),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame(
            array('turn_max_call_tokens', 'turn_max_turn_tokens', 'turn_warn_at'),
            $applied,
            'All three keys must be writable on the admin surface, or the page would silently drop them.'
        );

        // A fresh scope reads the stored values; `scoped` is what keeps an Octane
        // worker from serving the previous estate's ceilings.
        $this->app->forgetScopedInstances();

        $budget = $this->app->make(TurnBudget::class);

        $this->assertTrue($budget->enabled());
        $this->assertSame(40_000, $budget->perCallTokens);
        $this->assertSame(150_000, $budget->perTurnTokens);
        $this->assertSame(0.65, $budget->warnAt);
    }

    public function test_the_warn_band_reaches_the_verdict(): void
    {
        $this->app->make(SettingsService::class)->write(
            array('turn_max_turn_tokens' => 1000, 'turn_warn_at' => 0.5),
            SettingsService::SURFACE_ADMIN
        );

        $this->app->forgetScopedInstances();

        $verdict = $this->app->make(TurnBudget::class)->assess(500, 10, 0);

        $this->assertSame(TurnBudget::STATE_WARN, $verdict['state']);
    }

    public function test_the_settings_page_renders_the_turn_budget_section(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/settings')
            ->assertOk()
            ->assertSee('Turn budget')
            ->assertSee('Per-call token ceiling')
            ->assertSee('Per-turn token ceiling');
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}
