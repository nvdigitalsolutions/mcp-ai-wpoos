<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\McpAppsPage;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\McpAppsStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §43 — assistant MCP apps on `/admin`.
 *
 * This is a `full` surface: the panel replaces the whole per-assistant list,
 * exactly like the console. The properties pinned here are the ones a bulk
 * editor can break:
 *
 *  - a stored token is never rendered — not the value, not the mask;
 *  - a blank token keeps the sealed one (the store's carry-over by identity);
 *  - the read projection cannot be saved back as a token (the store's mask
 *    guard), which is the failure a read → write round-trip would cause;
 *  - the cap and the http(s) rule are the store's, and a refusal writes nothing;
 *  - the assistant is resolved through `tenant_id` on every request, so a
 *    tampered URL selector reads and writes nothing;
 *  - `manage_options` is required, matching the REST routes.
 */
final class AdminMcpAppsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_page_lists_the_estates_assistants(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/mcp-apps')
            ->assertOk()
            ->assertSee('MCP apps')
            ->assertSee('Estate Agent')
            ->assertSee('Save MCP apps');
    }

    public function test_a_stored_token_is_never_rendered(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $this->storeApps($assistant, array(array(
            'label' => 'Weather',
            'server_url' => 'https://mcp.example.com/mcp',
            'token' => 'super-secret-token',
        )));

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/mcp-apps')
            ->assertOk()
            ->assertSee('Weather')
            ->assertDontSee('super-secret-token')
            // Not the mask either: a mask on screen invites a "show" affordance.
            ->assertDontSee("\u{2022}\u{2022}\u{2022}\u{2022}");
    }

    /**
     * The form must not carry the mask into its state. If it did, a later save
     * would seal it (the store guard is the second line of defence).
     */
    public function test_the_form_state_carries_no_token(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $this->storeApps($assistant, array(array(
            'server_url' => 'https://mcp.example.com/mcp',
            'token' => 'super-secret-token',
        )));

        $this->bootPanel();

        Livewire::test(McpAppsPage::class)
            ->assertSet('data.apps', static function (array $apps): bool {
                $rows = array_values($apps);

                // The key may exist (Filament hydrates every field) but its
                // value must be empty — never the mask, never the plaintext.
                return 1 === count($rows) && '' === (string) ($rows[0]['token'] ?? '');
            });
    }

    public function test_saving_adds_an_app(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $this->bootPanel();

        // The full shape a real form submit carries. Sending a partial array
        // here would exercise the dehydrated-false path of an untouched Toggle,
        // which the browser never produces — the defaults are covered by
        // `McpAppsTest::test_null_boolean_fields_fall_back_to_defaults`.
        Livewire::test(McpAppsPage::class)
            ->set('data.apps', array(array(
                'label' => 'Weather',
                'server_url' => 'https://mcp.example.com/mcp',
                'auth_type' => 'none',
                'enabled' => true,
                'timeout' => 30,
                'verify_ssl' => true,
            )))
            ->call('save')
            ->assertHasNoErrors();

        $stored = $this->stored($assistant);

        $this->assertCount(1, $stored);
        $this->assertSame('https://mcp.example.com/mcp', $stored[0]['server_url']);
        $this->assertTrue($stored[0]['enabled']);
        $this->assertTrue($stored[0]['verify_ssl']);
        $this->assertSame(30, $stored[0]['timeout']);
    }

    public function test_saving_without_a_token_keeps_the_sealed_one(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $url = 'https://mcp.example.com/mcp';

        $this->storeApps($assistant, array(array(
            'label' => 'Weather',
            'server_url' => $url,
            'token' => 'first-token',
        )));

        $sealed = $this->stored($assistant)[0]['token_sealed'];

        $this->bootPanel();

        Livewire::test(McpAppsPage::class)
            ->set('data.apps', array(array('label' => 'Renamed', 'server_url' => $url)))
            ->call('save')
            ->assertHasNoErrors();

        $stored = $this->stored($assistant);

        $this->assertSame('Renamed', $stored[0]['label']);
        $this->assertSame(
            $sealed,
            $stored[0]['token_sealed'],
            'A blank token must keep the sealed value, not delete it.'
        );
    }

    public function test_a_typed_token_is_sealed(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $this->bootPanel();

        Livewire::test(McpAppsPage::class)
            ->set('data.apps', array(array(
                'server_url' => 'https://mcp.example.com/mcp',
                'token' => 'new-token',
            )))
            ->call('save')
            ->assertHasNoErrors();

        $stored = $this->stored($assistant);

        $this->assertArrayHasKey('token_sealed', $stored[0]);
        $this->assertArrayNotHasKey('token', $stored[0]);
        $this->assertStringNotContainsString(
            'new-token',
            (string) json_encode($stored),
            'The plaintext token must never be stored in assistant meta.'
        );
        $this->assertSame(
            'new-token',
            app(\App\Support\CredentialStore::class)->decrypt($stored[0]['token_sealed'])['token']
        );
    }

    public function test_more_than_ten_apps_is_refused_and_nothing_is_written(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $apps = array();

        for ($i = 0; $i < 11; ++$i) {
            $apps[] = array('server_url' => 'https://mcp'.$i.'.example.com/mcp');
        }

        $this->bootPanel();

        Livewire::test(McpAppsPage::class)
            ->set('data.apps', $apps)
            ->call('save')
            // The cap is a form rule as well as a store rule, so the submit is
            // refused before the writer is reached.
            ->assertHasErrors();

        $this->assertArrayNotHasKey(McpAppsStore::META_KEY, (array) $assistant->fresh()->meta);
    }

    public function test_a_non_http_server_url_is_refused_and_nothing_is_written(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $this->bootPanel();

        Livewire::test(McpAppsPage::class)
            ->set('data.apps', array(array('server_url' => 'ftp://mcp.example.com/mcp')))
            ->call('save')
            ->assertNotified();

        $this->assertArrayNotHasKey(McpAppsStore::META_KEY, (array) $assistant->fresh()->meta);
    }

    /**
     * A selector is not an authorization. `Assistant` has no global tenant
     * scope, so this is the check that stops a crafted URL from reading another
     * estate's app list.
     */
    public function test_a_foreign_assistant_is_not_selectable_or_writable(): void
    {
        $foreign = $this->assistantIn('estate-b', 'Foreign Agent');

        $this->storeApps($foreign, array(array(
            'label' => 'Foreign app',
            'server_url' => 'https://foreign.example.com/mcp',
        )));

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/mcp-apps?assistant='.$foreign->id)
            ->assertOk()
            ->assertDontSee('Foreign Agent')
            ->assertDontSee('Foreign app');

        $this->bootPanel();

        Livewire::test(McpAppsPage::class)
            ->set('assistantId', (string) $foreign->id)
            ->assertSet('assistantId', '')
            ->call('save')
            ->assertNotified();

        $this->assertCount(
            1,
            $this->stored($foreign),
            'A refused save must not touch another estate\'s assistant.'
        );
    }

    public function test_saving_writes_an_audit_row_without_the_secret_or_the_path(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate Agent');

        $this->bootPanel();

        Livewire::test(McpAppsPage::class)
            ->set('data.apps', array(array(
                'label' => 'Weather',
                'server_url' => 'https://mcp.example.com/secret-path',
                'token' => 'super-secret-token',
            )))
            ->call('save');

        $row = DB::table('nvoos_admin_audit')
            ->where('resource', 'mcp-apps')
            ->where('record_id', (string) $assistant->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($row, 'A panel write must leave a record row.');

        $after = (string) $row->after;

        $this->assertStringNotContainsString('super-secret-token', $after);
        // Host yes, path no: a provider can put a token in the path (§30 rule).
        $this->assertStringContainsString('mcp.example.com', $after);
        $this->assertStringNotContainsString('secret-path', $after);

        // `sealed_count` survives redaction on purpose; a `with_token` key would
        // have been replaced by `[redacted]` and recorded nothing.
        $decoded = json_decode($after, true);

        $this->assertSame(1, $decoded['sealed_count']);
        $this->assertSame(array('mcp.example.com'), $decoded['hosts']);
    }

    public function test_a_member_without_manage_options_is_refused(): void
    {
        $member = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($member->id => array('role' => 'member')));

        $this->actingAs($member)
            ->get('/admin/default/mcp-apps')
            ->assertForbidden();
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/mcp-apps')
            ->assertForbidden();
    }

    private function bootPanel(): void
    {
        $this->actingAs($this->estateAdmin());
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function assistantIn(string $tenantId, string $title): Assistant
    {
        if (Assistant::DEFAULT_TENANT !== $tenantId) {
            Tenant::query()->firstOrCreate(
                array('id' => $tenantId),
                array('name' => ucfirst($tenantId), 'slug' => $tenantId, 'is_operator' => false)
            );
        }

        $assistant = Assistant::query()->create(array(
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'publish',
        ));

        // `tenant_id` is not fillable by design; assigning it after create keeps
        // this fixture honest rather than silently landing in the default estate.
        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    /**
     * Write a list through the store, so tokens are sealed the way production
     * seals them.
     *
     * @param list<array<string, mixed>> $apps
     */
    private function storeApps(Assistant $assistant, array $apps): void
    {
        app(McpAppsStore::class)->replace($assistant, $apps);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(Assistant $assistant): array
    {
        $meta = (array) $assistant->fresh()->meta;

        return array_values((array) ($meta[McpAppsStore::META_KEY] ?? array()));
    }
}
