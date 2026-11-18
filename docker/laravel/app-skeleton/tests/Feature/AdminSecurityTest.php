<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\SecuritySnapshots;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsSchema;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §51 — `/admin/security`.
 *
 * The contract row's note is the design: "Security snapshots are a dedicated
 * store; the admin read-only view is a projection, never a scan at request
 * time." These tests pin that reading, plus the two properties that make it
 * safe:
 *
 *  - the list and the detail are estate-scoped, so a foreign snapshot id is
 *    "not found" rather than a configuration disclosure;
 *  - no stored secret reaches the screen. Snapshots are masked at write time;
 *    the page masks against `sensitiveKeys()` again for rows that predate that
 *    rule.
 */
final class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_the_estates_snapshots(): void
    {
        $this->seedSnapshot(Tenant::DEFAULT_ID, 'Before upgrade');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/security')
            ->assertOk()
            ->assertSee('Security snapshots')
            ->assertSee('Before upgrade')
            ->assertSee('Read-only');
    }

    public function test_an_empty_store_is_an_empty_state(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/security')
            ->assertOk()
            ->assertSee('No snapshot has been saved for this estate yet.');
    }

    public function test_a_foreign_snapshot_is_not_listed(): void
    {
        $this->seedSnapshot(Tenant::DEFAULT_ID, 'Own snapshot');
        $this->seedSnapshot('estate-b', 'Foreign snapshot');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/security')
            ->assertOk()
            ->assertSee('Own snapshot')
            ->assertDontSee('Foreign snapshot');
    }

    public function test_a_foreign_snapshot_id_is_not_found(): void
    {
        $this->seedSnapshot(Tenant::DEFAULT_ID, 'Own snapshot');
        $foreign = $this->seedSnapshot('estate-b', 'Foreign snapshot');

        $this->bootPanel();

        Livewire::test(SecuritySnapshots::class)
            ->call('open', $foreign)
            ->assertSee('Snapshot not found.')
            ->assertDontSee('Foreign snapshot');
    }

    public function test_opening_a_snapshot_renders_its_settings(): void
    {
        $id = $this->seedSnapshot(Tenant::DEFAULT_ID, 'Before upgrade', array(
            'default_model' => 'gpt-4o',
            'enable_rate_limiting' => true,
        ));

        $this->bootPanel();

        Livewire::test(SecuritySnapshots::class)
            ->call('open', $id)
            ->assertSee('default_model')
            ->assertSee('gpt-4o')
            ->assertSee('Before upgrade');
    }

    public function test_a_sensitive_key_is_masked_even_if_the_row_stored_it(): void
    {
        // A row that predates the write-path mask, or was inserted by hand.
        $id = $this->seedSnapshot(Tenant::DEFAULT_ID, 'Legacy snapshot', array(
            'openai_api_key' => 'sk-should-never-render',
            'default_model' => 'gpt-4o',
        ));

        $this->bootPanel();

        Livewire::test(SecuritySnapshots::class)
            ->call('open', $id)
            ->assertSee(SettingsSchema::MASKED_PLACEHOLDER)
            ->assertSee('masked')
            ->assertDontSee('sk-should-never-render');
    }

    public function test_closing_the_detail_clears_it(): void
    {
        $id = $this->seedSnapshot(Tenant::DEFAULT_ID, 'Before upgrade');

        $this->bootPanel();

        Livewire::test(SecuritySnapshots::class)
            ->call('open', $id)
            ->assertSet('detail.label', 'Before upgrade')
            ->call('close')
            ->assertSet('detail', null);
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/security')
            ->assertForbidden();
    }

    /**
     * Restoring a snapshot is a settings write and stays in the console (§16's
     * one-writer rule). A form here would need the contract row to declare a
     * panel writer.
     */
    public function test_the_page_accepts_no_payload(): void
    {
        $traits = class_uses_recursive(SecuritySnapshots::class);

        $this->assertArrayNotHasKey(\Filament\Forms\Concerns\InteractsWithForms::class, $traits);
        $this->assertFalse(
            is_subclass_of(SecuritySnapshots::class, \Filament\Forms\Contracts\HasForms::class),
            'The security projection is read-only; a form would need the contract row to declare a writer.'
        );
    }

    private function bootPanel(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedSnapshot(string $tenantId, string $label, array $payload = array()): string
    {
        $id = 'snap-'.bin2hex(random_bytes(6));

        DB::table('nvoos_security_snapshots')->insert(array(
            'id' => $id,
            'tenant_id' => $tenantId,
            'label' => $label,
            'payload' => json_encode($payload === array() ? array('default_model' => 'gpt-4o') : $payload),
            'created_at' => now(),
            'updated_at' => now(),
        ));

        return $id;
    }
}
