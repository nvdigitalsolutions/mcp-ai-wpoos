<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\DiagnosticsPage;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Diagnostics\ProbeResult;
use App\Support\Diagnostics\ProbeRunner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\ValueObject\ProviderConnectivity;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Tests\TestCase;

/**
 * Proposal 149 §48 — `/ops/diagnostics`, the probe renderer.
 *
 * The row exists to close the last WordPress screens this runtime did not have
 * (Tools → Provider Test, MCP Server Diagnostic, REST Context Diagnostic) and
 * to give an operator a real answer where `/ops/site-health` can only report
 * "a client is registered". These tests pin the properties that make it
 * trustworthy:
 *
 *  - a rejected key is `critical`, not "0 models" — `ProviderConnectivity`
 *    exists for exactly that distinction;
 *  - the page renders no credential, and the projection has nowhere to put one;
 *  - the assistant probe is estate-scoped even though `/ops` has no tenant
 *    middleware;
 *  - the REST/MCP probes report what the router actually mounts, not a config
 *    value that claims it should be there;
 *  - an unknown probe key is a validation error, not a silent no-op.
 */
final class AdminDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');

        // `Livewire::test()` renders through the panel's middleware, so the
        // acting user must exist before the first snapshot or `canAccess()`
        // answers the initial request with a redirect and every later `call()`
        // has no component to update. The refusal test replaces this user.
        $this->actingAs($this->operator());
    }

    public function test_the_page_lists_providers_without_rendering_a_credential(): void
    {
        // A stored key must never appear, in any form. `getApiKey()` is the
        // question the client asks, so this seeds the same path a deployment
        // would use for the last-resort fallback.
        app(SettingsStoreInterface::class)->set('openai_api_key', 'sk-live-should-never-render');

        $this->actingAs($this->operator())
            ->get('/ops/diagnostics')
            ->assertOk()
            ->assertSee('Operations & diagnostics')
            ->assertSee('Provider credentials')
            ->assertSee('credentials configured')
            ->assertDontSee('sk-live-should-never-render')
            ->assertDontSee('••••');
    }

    public function test_a_customer_administrator_is_refused_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/diagnostics')
            ->assertForbidden();
    }

    public function test_a_rejected_key_is_reported_as_critical(): void
    {
        $this->fakeProvider('openai', ProviderConnectivity::failed(401, 'HTTP 401: Incorrect API key provided.'));

        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'provider')
            ->set('provider', 'openai')
            ->call('runProbe')
            ->assertSet('result.status', ProbeResult::CRITICAL)
            ->assertSee('401')
            ->assertSee('Incorrect API key provided.');
    }

    public function test_a_working_provider_reports_the_model_count(): void
    {
        $this->fakeProvider('openai', ProviderConnectivity::ok(200, 3, 'api.openai.com answered 200 with 3 model(s).'));

        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'provider')
            ->set('provider', 'openai')
            ->call('runProbe')
            ->assertSet('result.status', ProbeResult::GOOD)
            ->assertSee('3 model');
    }

    public function test_a_provider_without_a_liveness_endpoint_is_recommended_not_good(): void
    {
        $this->fakeProvider(
            'custom',
            ProviderConnectivity::notSupported('This provider client has no liveness endpoint.')
        );

        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'provider')
            ->set('provider', 'custom')
            ->call('runProbe')
            // Not GOOD: nothing was observed. Not CRITICAL: nothing failed.
            ->assertSet('result.status', ProbeResult::RECOMMENDED)
            ->assertSee('no liveness endpoint');
    }

    public function test_the_rest_probe_counts_the_mounted_routes(): void
    {
        $this->fakeProvider('openai', ProviderConnectivity::ok(200, 1, 'ok'));

        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'rest')
            ->call('runProbe')
            ->assertSet('result.status', ProbeResult::GOOD)
            ->assertSee('API prefix')
            ->assertSee('/api/v1');
    }

    public function test_the_mcp_probe_reads_the_transport_route(): void
    {
        $this->fakeProvider('openai', ProviderConnectivity::ok(200, 1, 'ok'));

        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'mcp')
            ->call('runProbe')
            ->assertSee('POST /mcp')
            ->assertSee('sanctum');
    }

    public function test_the_assistant_probe_renders_the_dry_run_plan(): void
    {
        $this->fakeProvider('openai', ProviderConnectivity::ok(200, 1, 'ok'));

        $assistant = $this->assistant(Tenant::DEFAULT_ID, 'Probe subject');

        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'assistant')
            ->set('assistant', (string) $assistant->id)
            ->call('runProbe')
            ->assertSet('result.status', ProbeResult::GOOD)
            ->assertSee('Probe subject')
            ->assertSee('gpt-4o-mini');
    }

    public function test_the_assistant_probe_cannot_reach_another_estate(): void
    {
        $this->fakeProvider('openai', ProviderConnectivity::ok(200, 1, 'ok'));

        $foreign = $this->assistant('estate-b', 'Foreign subject');

        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'assistant')
            ->set('assistant', (string) $foreign->id)
            ->call('runProbe')
            ->assertSet('result.status', ProbeResult::CRITICAL)
            ->assertSee('No assistant with that id exists in this estate')
            ->assertDontSee('Foreign subject');
    }

    public function test_an_unknown_probe_key_is_rejected(): void
    {
        Livewire::test(DiagnosticsPage::class)
            ->set('probe', 'not-a-probe')
            ->call('runProbe')
            ->assertHasErrors('probe');
    }

    public function test_every_declared_probe_has_a_branch(): void
    {
        // A key with no branch would throw on submit; this makes the contract
        // between the select and the matcher explicit.
        $this->fakeProvider('openai', ProviderConnectivity::ok(200, 1, 'ok'));
        $assistant = $this->assistant(Tenant::DEFAULT_ID, 'Probe subject');

        foreach (array_keys(ProbeRunner::PROBES) as $probe) {
            $result = Livewire::test(DiagnosticsPage::class)
                ->set('probe', $probe)
                ->set('provider', 'openai')
                ->set('assistant', (string) $assistant->id)
                ->call('runProbe')
                ->assertHasNoErrors()
                ->get('result');

            $this->assertIsArray($result);
            $this->assertSame($probe, $result['probe']);
        }
    }

    /**
     * Bind a router whose single provider returns the given connectivity.
     */
    private function fakeProvider(string $slug, ProviderConnectivity $connectivity): void
    {
        /** @var AbstractProviderClient&MockInterface $client */
        $client = Mockery::mock(AbstractProviderClient::class);
        $client->shouldReceive('getProviderSlug')->andReturn($slug);
        $client->shouldReceive('connectivity')->andReturn($connectivity);

        $this->mock(ProviderRouter::class, function (MockInterface $mock) use ($slug, $client): void {
            $mock->shouldReceive('getRegisteredSlugs')->andReturn([$slug]);
            $mock->shouldReceive('get')->with($slug)->andReturn($client);
            $mock->shouldReceive('resolveForChat')->andReturn($client);
        });
    }

    private function assistant(string $tenantId, string $title): Assistant
    {
        $assistant = new Assistant([
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title),
            'status' => 'draft',
            'system_prompt' => 'Be brief.',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'tools' => ['list_ecas'],
        ]);

        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    public function test_a_clean_boot_reports_no_skipped_tool_rows(): void
    {
        $overview = Livewire::test(DiagnosticsPage::class)->instance()->overview();

        $this->assertArrayHasKey('Tool registration', $overview);

        $rows = $overview['Tool registration'];

        $this->assertSame('Servable rows', $rows[0]['label']);
        $this->assertGreaterThan(0, (int) $rows[0]['value']);
        $this->assertSame(['label' => 'Skipped at boot', 'value' => 'none'], $rows[1]);
    }

    public function test_a_tool_the_host_could_not_build_is_named_with_its_reason(): void
    {
        // The registrar binds this list only when a servable row fails to
        // build. Injecting it is the only way to exercise the rendering
        // without breaking a real binding.
        $this->app->instance('nvoos.tools.skipped', [
            'ready_but_unbuildable' => 'service not bound: App\\Contracts\\Missing',
        ]);

        Livewire::test(DiagnosticsPage::class)
            ->assertSee('Tool registration')
            ->assertSee('ready_but_unbuildable')
            ->assertSee('service not bound');

        $rows = Livewire::test(DiagnosticsPage::class)->instance()->overview()['Tool registration'];

        $this->assertSame(['label' => 'Skipped at boot', 'value' => '1'], $rows[1]);
        $this->assertSame(
            ['label' => 'ready_but_unbuildable', 'value' => 'service not bound: App\\Contracts\\Missing'],
            $rows[2]
        );
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
