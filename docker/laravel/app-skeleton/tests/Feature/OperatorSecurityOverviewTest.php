<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\SecurityOverview;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Security\SecuritySnapshotOverview;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/ops/security` — Proposal 149 §171.
 *
 * The last `planned` surface in the ownership contract. Three properties carry
 * the weight:
 *
 *  - **Access is the operator predicate, not a role.** A customer's estate owner
 *    must not reach a cross-estate configuration view; operator status belongs to
 *    the estate (`is_operator`), never to a person.
 *  - **The list is cross-estate on purpose.** That is the difference between this
 *    page and `/admin/security`: the operator asks "which estates saved a
 *    snapshot", which an estate-scoped reader cannot answer.
 *  - **A stored secret never reaches the screen.** Masking is applied at write
 *    time *and* again at render, and the test asserts the rendered output rather
 *    than the array, because a page can hold a value it does not print.
 */
final class OperatorSecurityOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_administrator_cannot_open_the_operator_overview(): void
    {
        $user = User::factory()->admin()->create();
        $user->tenants()->syncWithoutDetaching(array(Tenant::DEFAULT_ID => array('role' => 'owner')));

        $this->actingAs($user);

        $this->assertFalse(SecurityOverview::canAccess());
    }

    public function test_an_operator_can_open_it(): void
    {
        $this->actingAsOperator();

        $this->assertTrue(SecurityOverview::canAccess());
    }

    public function test_the_list_spans_estates(): void
    {
        $this->snapshot('estate-a', 'Alpha snapshot');
        $this->snapshot('estate-b', 'Beta snapshot');

        $this->actingAsOperator();

        $rows = app(SecuritySnapshotOverview::class)->rows();
        $estates = array_column($rows, 'estate');

        sort($estates);

        $this->assertSame(array('estate-a', 'estate-b'), $estates);
        $this->assertSame(2, app(SecuritySnapshotOverview::class)->total());
        $this->assertSame(
            array('estate-a' => 1, 'estate-b' => 1),
            app(SecuritySnapshotOverview::class)->estates()
        );
    }

    public function test_opening_a_snapshot_masks_sensitive_keys(): void
    {
        $id = $this->snapshot(
            'estate-a',
            'Alpha snapshot',
            array(
                'provider' => 'openai',
                'openai_api_key' => 'sk-live-should-never-render',
            )
        );

        $this->actingAsOperator();

        Livewire::test(SecurityOverview::class)
            ->call('open', $id)
            ->assertSet('detail.snapshot_id', $id)
            ->assertSet('detail.estate', 'estate-a')
            ->assertSee('openai_api_key')
            ->assertSee('masked')
            ->assertDontSee('sk-live-should-never-render');
    }

    public function test_a_missing_snapshot_renders_not_found_rather_than_failing(): void
    {
        $this->actingAsOperator();

        Livewire::test(SecurityOverview::class)
            ->call('open', 'does-not-exist')
            ->assertSet('detail.error', 'Snapshot not found.');
    }

    public function test_the_page_writes_nothing(): void
    {
        $id = $this->snapshot('estate-a', 'Alpha snapshot');
        $before = DB::table('nvoos_security_snapshots')->orderBy('id')->get()->toArray();

        $this->actingAsOperator();

        Livewire::test(SecurityOverview::class)
            ->call('open', $id)
            ->call('close');

        $after = DB::table('nvoos_security_snapshots')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'Opening and closing a snapshot must not change the store.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function snapshot(string $estate, string $label, array $payload = array('provider' => 'openai')): string
    {
        $id = 'snap-'.uniqid();

        DB::table('nvoos_security_snapshots')->insert(array(
            'id' => $id,
            'tenant_id' => $estate,
            'label' => $label,
            'payload' => (string) json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ));

        return $id;
    }

    private function actingAsOperator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        return $user;
    }
}
