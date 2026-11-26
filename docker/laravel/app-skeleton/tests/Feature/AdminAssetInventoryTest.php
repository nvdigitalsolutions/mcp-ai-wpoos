<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\AssetInventory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Assets\AssetRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §33 — the ISO 27001 information-asset register.
 *
 * The register is derived, so the tests pin the derivation rules rather than a
 * fixture: every runtime table that exists becomes an asset, classification
 * follows content, and a credential asset records presence without ever
 * carrying the value.
 */
class AdminAssetInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_the_register(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/assets')
            ->assertOk()
            ->assertSee('Information-asset register')
            ->assertSee('Assistants')
            ->assertSee('Admin audit trail')
            ->assertSee('Derived on every load');
    }

    public function test_every_asset_uses_the_closed_vocabularies(): void
    {
        $assets = $this->register();

        $this->assertNotEmpty($assets);

        foreach ($assets as $asset) {
            $this->assertArrayHasKey(
                $asset['classification'],
                AssetRegister::CLASSIFICATIONS,
                $asset['id'].' has an unknown classification.'
            );

            $this->assertArrayHasKey(
                $asset['type'],
                AssetRegister::TYPES,
                $asset['id'].' has an unknown type.'
            );
        }
    }

    /**
     * Classification follows content: credential material is restricted,
     * conversation and configuration data is confidential, the rest internal.
     */
    public function test_classification_follows_content(): void
    {
        $byId = array_column($this->register(), null, 'id');

        $this->assertSame('restricted', $byId['data_table_nvoos_settings']['classification']);
        $this->assertSame('restricted', $byId['data_table_personal_access_tokens']['classification']);
        $this->assertSame('confidential', $byId['data_table_assistants']['classification']);
        $this->assertSame('internal', $byId['data_table_teams']['classification']);
    }

    /**
     * The security property: presence is recorded, the value is not.
     */
    public function test_a_configured_credential_is_recorded_without_its_value(): void
    {
        $secret = 'sk-test-SECRETVALUE-42';

        $this->app->make(SettingsStoreInterface::class)->set('openai_api_key', $secret);

        $assets = $this->register();
        $ids = array_column($assets, 'id');

        $this->assertContains('api_key_openai', $ids);

        $apiKey = array_values(array_filter(
            $assets,
            static fn (array $asset): bool => 'api_key_openai' === $asset['id']
        ))[0];

        $this->assertSame('api_key', $apiKey['type']);
        $this->assertSame('restricted', $apiKey['classification']);
        $this->assertStringContainsString('not recorded', (string) $apiKey['description']);

        // The whole register, serialised, must not contain the secret.
        $this->assertStringNotContainsString($secret, json_encode($assets));

        $this->actingAs($this->operator())
            ->get('/ops/assets')
            ->assertOk()
            ->assertDontSee($secret);
    }

    public function test_an_unset_credential_produces_no_asset(): void
    {
        $ids = array_column($this->register(), 'id');

        $this->assertNotContains('api_key_openai', $ids);
    }

    public function test_tables_are_reported_with_row_counts(): void
    {
        $byId = array_column($this->register(), null, 'id');

        $this->assertArrayHasKey('data_table_users', $byId);
        $this->assertStringContainsString('row(s)', (string) $byId['data_table_users']['description']);
    }

    public function test_statistics_count_by_classification_and_report_the_highest(): void
    {
        $stats = AssetRegister::statistics($this->register());

        $this->assertSame(
            $stats['total'],
            array_sum($stats['by_classification']),
            'Every asset must land in exactly one classification bucket.'
        );

        $this->assertSame(
            $stats['total'],
            array_sum($stats['by_type']),
            'Every asset must land in exactly one type bucket.'
        );

        // Restricted always exists: `nvoos_settings` and the token table do.
        $this->assertSame('restricted', $stats['highest']);
    }

    public function test_the_register_sorts_highest_classification_first(): void
    {
        $order = array_keys(AssetRegister::CLASSIFICATIONS);
        $rank = array_flip($order);

        $previous = PHP_INT_MAX;

        foreach ($this->register() as $asset) {
            $current = $rank[$asset['classification']];

            $this->assertLessThanOrEqual(
                $previous,
                $current,
                'The register must not descend to a lower classification then climb again.'
            );

            $previous = $current;
        }
    }

    public function test_the_classification_filter_narrows_the_register(): void
    {
        $this->actingAs($this->operator());
        Filament::setTenant(null);

        Livewire::test(AssetInventory::class)
            ->set('classification', 'internal')
            ->assertSee('Teams')
            ->assertDontSee('Operator settings');
    }

    public function test_the_search_narrows_the_register(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(AssetInventory::class)
            ->set('search', 'audit trail')
            ->assertSee('Admin audit trail')
            ->assertDontSee('User accounts');
    }

    public function test_clearing_the_filters_restores_the_register(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(AssetInventory::class)
            ->set('search', 'audit')
            ->set('classification', 'confidential')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('classification', '');
    }

    public function test_an_empty_filter_result_shows_the_empty_state(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(AssetInventory::class)
            ->set('search', 'zzz-not-an-asset')
            ->assertSee('No asset matches that filter');
    }

    public function test_the_page_offers_no_edit_control(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/assets')
            ->assertOk()
            ->assertDontSee('New asset')
            ->assertDontSee('Delete');
    }

    public function test_a_customer_admin_is_refused_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/assets')
            ->assertForbidden();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function register(): array
    {
        return AssetRegister::assets($this->app->make(SettingsStoreInterface::class));
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}
