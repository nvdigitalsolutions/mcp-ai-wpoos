<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ToolkitPage;
use App\Filament\Admin\Pages\ToolkitsPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Pro\ToolkitEngine;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Proposal 149 Wave 3 — the manifest renderer on `/admin` (plan §56).
 *
 * The renderer exists so a toolkit is a manifest commit, not a page. These
 * tests pin what makes that safe rather than how it looks:
 *
 *  - the index and the detail page read the SAME engine the console and the
 *    REST API use, so the panel cannot become a second writer;
 *  - an unlicensed toolkit is absent from the catalogue and 404s on direct
 *    access — indistinguishable from an unknown one (R4);
 *  - every declared screen the panel serves is reachable, and `research` /
 *    `consolidate` 404 only when the family has no entities to fill them
 *    (Wave 4), so a manifest cannot produce a blank shell;
 *  - a research draft is reviewed before it is written, and the save goes
 *    through `createRecord` so the manifest's column validation still governs
 *    the write;
 *  - a duplicate scan groups the whole entity server-side, and deleting a
 *    collision re-scans instead of leaving a stale group on screen;
 *  - settings render from `tab_labels`/`tab`, and a save is audited with
 *    **keys only** — a secret never reaches the audit payload, the page, or
 *    the Livewire snapshot;
 *  - a foreign record cannot be deleted through the panel, and a successful
 *    delete leaves one audited record row.
 */
final class AdminToolkitsTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->fixtureDir = sys_get_temp_dir().'/nvoos-toolkit-'.bin2hex(random_bytes(6));
        mkdir($this->fixtureDir, 0777, true);

        file_put_contents(
            $this->fixtureDir.'/workshop.json',
            json_encode($this->fixtureManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // A settings-only family: the Wave 4 screens address an entity, so this
        // is the fixture that proves they 404 rather than render empty.
        file_put_contents(
            $this->fixtureDir.'/garage.json',
            json_encode($this->settingsOnlyManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        config(array('oos.toolkit_manifests_path' => $this->fixtureDir));
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        foreach (glob($this->fixtureDir.'/*') ?: array() as $file) {
            unlink($file);
        }

        if (is_dir($this->fixtureDir)) {
            rmdir($this->fixtureDir);
        }

        parent::tearDown();
    }

    public function test_the_catalogue_lists_every_manifest_the_estate_may_render(): void
    {
        $this->asAdmin();

        $this->get('/admin/default/toolkits')
            ->assertOk()
            ->assertSee('Workshop')
            ->assertSee('Bench operations.')
            ->assertSee('/admin/default/toolkits/workshop/settings');
    }

    public function test_the_catalogue_requires_manage_options(): void
    {
        // A panel member without the capability: the estate boundary is the
        // membership, but a toolkit settings screen writes estate configuration.
        $member = $this->estateMember();

        $this->actingAs($member)
            ->get('/admin/default/toolkits')
            ->assertForbidden();
    }

    public function test_an_unlicensed_toolkit_is_absent_from_the_index_and_404s_on_direct_access(): void
    {
        $this->app->instance(ProEntitlementInterface::class, new class implements ProEntitlementInterface
        {
            public function allows(string $feature): bool
            {
                return false;
            }

            public function plan(): string
            {
                return 'none';
            }

            public function source(): string
            {
                return 'test';
            }

            public function features(): array
            {
                return array();
            }
        });

        $this->asAdmin();

        $this->get('/admin/default/toolkits')
            ->assertOk()
            ->assertDontSee('Workshop');

        $this->get('/admin/default/toolkits/workshop/settings')->assertNotFound();
    }

    public function test_unknown_toolkits_are_404(): void
    {
        $this->asAdmin();

        $this->get('/admin/default/toolkits/nope/settings')->assertNotFound();
    }

    /**
     * Wave 4 routed both screens, so a manifest that declares them and carries
     * entities must render them rather than 404.
     */
    public function test_research_and_consolidate_render_for_a_family_with_entities(): void
    {
        $this->asAdmin();

        $this->get('/admin/default/toolkits/workshop/research')
            ->assertOk()
            ->assertSee('Research & add')
            ->assertSee('Draft with AI');

        $this->get('/admin/default/toolkits/workshop/consolidate')
            ->assertOk()
            ->assertSee('Group by')
            ->assertSee('Find duplicates');
    }

    /**
     * Both Wave 4 screens address an entity. A settings-only family would render
     * an empty wizard, so it 404s — the same "declared but unservable" rule the
     * settings screen applies when a manifest carries no fields.
     */
    public function test_research_and_consolidate_404_for_a_settings_only_family(): void
    {
        $this->asAdmin();

        $this->get('/admin/default/toolkits/garage/research')->assertNotFound();
        $this->get('/admin/default/toolkits/garage/consolidate')->assertNotFound();
    }

    /**
     * The estate boundary is membership, but a Wave 4 screen reads and writes
     * records, so it also needs the capability the catalogue requires.
     */
    public function test_the_wave_four_screens_require_manage_options(): void
    {
        $member = $this->estateMember();

        $this->actingAs($member)
            ->get('/admin/default/toolkits/workshop/research')
            ->assertForbidden();

        $this->actingAs($member)
            ->get('/admin/default/toolkits/workshop/consolidate')
            ->assertForbidden();
    }

    public function test_research_drafts_the_declared_columns_then_saves_through_the_engine(): void
    {
        $this->asAdmin();

        // The model invents `ghost`, which the service must drop before the
        // draft ever reaches the form.
        $this->mock(ProviderRouter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->andReturn(array(
                'model' => 'gpt-test',
                'choices' => array(
                    array('message' => array('content' => '{"code":"W-1","name":"Widget","ghost":"drop me"}')),
                ),
            ));
        });

        $component = Livewire::test(ToolkitPage::class, array('toolkit' => 'workshop', 'screen' => 'research'))
            ->set('query', 'Add the W-1 widget')
            ->call('draftWithAi')
            ->assertSet('provider', 'gpt-test')
            ->assertSet('draft.code', 'W-1')
            ->assertSet('draft.name', 'Widget');

        $this->assertArrayNotHasKey('ghost', $component->get('draft'));

        $component->call('saveDraft')->assertSet('draft', array());

        $this->assertSame(
            1,
            DB::table('nvoos_toolkit_records')->where('toolkit', 'workshop')->where('entity', 'item')->count()
        );
    }

    public function test_research_refuses_an_empty_query_without_calling_the_provider(): void
    {
        $this->asAdmin();

        $this->mock(ProviderRouter::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test(ToolkitPage::class, array('toolkit' => 'workshop', 'screen' => 'research'))
            ->set('query', '   ')
            ->call('draftWithAi')
            ->assertSet('statusTone', 'danger')
            ->assertSet('draft', array());
    }

    public function test_research_reports_an_unavailable_provider_instead_of_throwing(): void
    {
        $this->asAdmin();

        // The router returns the failure rather than throwing it; the service
        // turns that into an `ai_unavailable` envelope.
        $this->mock(ProviderRouter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->andReturn(new RuntimeException('No provider is configured.'));
        });

        Livewire::test(ToolkitPage::class, array('toolkit' => 'workshop', 'screen' => 'research'))
            ->set('query', 'Anything')
            ->call('draftWithAi')
            ->assertSet('statusTone', 'danger')
            ->assertSet('draft', array());
    }

    public function test_consolidate_groups_duplicates_and_deleting_one_rescans(): void
    {
        $this->asAdmin();
        TenantContext::set(Tenant::DEFAULT_ID);

        $engine = app(ToolkitEngine::class);
        $manifest = $engine->find('workshop');

        $this->assertNotNull($manifest);

        $engine->createRecord($manifest, 'item', array('code' => 'A', 'name' => 'Same'));
        $engine->createRecord($manifest, 'item', array('code' => 'B', 'name' => 'Same'));
        $engine->createRecord($manifest, 'item', array('code' => 'C', 'name' => 'Other'));

        $component = Livewire::test(ToolkitPage::class, array('toolkit' => 'workshop', 'screen' => 'consolidate'))
            ->set('duplicateColumn', 'name')
            ->call('scanDuplicates')
            ->assertSet('duplicateScanned', 3);

        $this->assertCount(1, $component->get('duplicateGroups'));

        // Delete the second copy by its manifest id, then confirm the list was
        // rebuilt rather than left showing a group that no longer collides.
        $component->call('armDelete', 'B')->call('deleteRecord')
            ->assertSet('pendingDelete', '')
            ->assertSet('duplicateScanned', 2)
            ->assertSet('duplicateGroups', array());

        $this->assertSame(2, DB::table('nvoos_toolkit_records')->where('toolkit', 'workshop')->count());
    }

    public function test_the_settings_screen_renders_the_manifest_tabs(): void
    {
        $this->asAdmin();

        $this->get('/admin/default/toolkits/workshop/settings')
            ->assertOk()
            ->assertSee('Basics')
            ->assertSee('Access')
            ->assertSee('Display currency')
            ->assertSee('Internal notes')
            ->assertSee('API token')
            ->assertSee('<textarea', false);
    }

    public function test_saving_settings_goes_through_the_engine_and_audits_keys_only(): void
    {
        $this->asAdmin();

        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        Livewire::test(ToolkitPage::class, array('toolkit' => 'workshop', 'screen' => 'settings'))
            ->set('data.currency', 'EUR')
            ->set('data.notes', "Line one\nLine two")
            ->set('data.api_token', 'super-secret-token')
            ->call('save')
            ->assertSee('3 setting(s) saved.');

        $stored = $engine->settingsValues($manifest);

        $this->assertSame('EUR', $stored['currency']);
        $this->assertSame("Line one\nLine two", $stored['notes']);
        // The engine masks secrets on read; the value is present, not echoed.
        $this->assertSame('**************', $stored['api_token']);

        $audit = DB::table('nvoos_admin_audit')
            ->where('resource', 'toolkits')
            ->where('record_id', 'workshop:settings')
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($audit);
        // All three keys changed: two values, and a secret that went from
        // "unset" to "set". A changed key is not a changed value — the secret's
        // content is never in the payload.
        $this->assertSame(
            array('currency', 'notes', 'api_token'),
            json_decode((string) $audit->after, true)['fields']
        );
        $this->assertStringNotContainsString('super-secret-token', (string) $audit->after);
        $this->assertStringNotContainsString('Line two', (string) $audit->after);
        $this->assertStringNotContainsString('super-secret-token', (string) $audit->before);
    }

    public function test_a_blank_secret_keeps_the_stored_value(): void
    {
        $this->asAdmin();

        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        $engine->writeSettings($manifest, array('api_token' => 'first-token'));

        // The form hydrates secrets empty; submitting it untouched must not
        // erase what is stored.
        Livewire::test(ToolkitPage::class, array('toolkit' => 'workshop', 'screen' => 'settings'))
            ->set('data.currency', 'GBP')
            ->call('save')
            // Count-agnostic: the fixture also carries a textarea, and its blank
            // value is a legitimate change (null → '').
            ->assertSee('setting(s) saved.');

        TenantContext::set(Tenant::DEFAULT_ID);
        $stored = $this->app->make(ToolkitEngine::class)->settingsValues($manifest);

        $this->assertSame('GBP', $stored['currency']);
        $this->assertSame('**************', $stored['api_token']);
    }

    public function test_a_blank_text_setting_saves_as_an_empty_string(): void
    {
        $this->asAdmin();

        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        // A generated form hydrates an unset text field as null. Submitting it
        // unchanged must not produce a validation error on the page…
        Livewire::test(ToolkitPage::class, array('toolkit' => 'workshop', 'screen' => 'settings'))
            ->call('save')
            ->assertDontSee('Must be a string')
            ->assertDontSee('One or more settings are invalid');

        // …and the engine's own contract treats null as the empty string, which
        // is what a client that does send the field blank expects.
        $engine->writeSettings($manifest, array('notes' => null));

        $this->assertSame('', $engine->settingsValues($manifest)['notes']);
    }

    public function test_the_secret_value_is_never_rendered(): void
    {
        $this->asAdmin();

        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        $engine->writeSettings($manifest, array('api_token' => 'do-not-render-me'));

        $this->get('/admin/default/toolkits/workshop/settings')
            ->assertOk()
            ->assertDontSee('do-not-render-me');
    }

    public function test_the_entity_screen_lists_searches_and_pages_records(): void
    {
        $this->asAdmin();

        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        foreach (range(1, 30) as $index) {
            $engine->createRecord($manifest, 'item', array(
                'code' => 'SKU-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'name' => 0 === $index % 2 ? 'Even Widget' : 'Odd Widget',
            ));
        }

        $this->get('/admin/default/toolkits/workshop/entities?entity=item')
            ->assertOk()
            ->assertSee('Showing 1–25 of 30.')
            ->assertSee('SKU-30');

        // Page two carries the rest.
        $this->get('/admin/default/toolkits/workshop/entities?entity=item&page=2')
            ->assertOk()
            ->assertSee('Showing 26–30 of 30.');

        // Search is server-side, so the total reflects the match, not the page.
        $this->get('/admin/default/toolkits/workshop/entities?entity=item&search=Even')
            ->assertOk()
            ->assertSee('Showing 1–15 of 15.')
            ->assertSee('Even Widget')
            ->assertDontSee('Odd Widget');
    }

    public function test_deleting_an_own_record_is_armed_and_audited(): void
    {
        $this->asAdmin();

        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        $engine->createRecord($manifest, 'item', array('code' => 'SKU-1', 'name' => 'Widget'));

        Livewire::test(ToolkitPage::class, array(
            'toolkit' => 'workshop',
            'screen' => 'entities',
        ))
            ->call('armDelete', 'SKU-1')
            ->assertSee('Confirm delete')
            ->call('deleteRecord')
            ->assertSee('Record deleted.');

        $this->assertSame(
            0,
            DB::table('nvoos_toolkit_records')->where('external_id', 'SKU-1')->count()
        );

        $audit = DB::table('nvoos_admin_audit')
            ->where('resource', 'toolkits')
            ->where('record_id', 'workshop:item:SKU-1')
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($audit, 'A destructive panel action must leave an audit row.');
    }

    public function test_a_foreign_record_cannot_be_deleted_through_the_panel(): void
    {
        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        TenantContext::set('estate-b');
        $engine->createRecord($manifest, 'item', array('code' => 'FOREIGN-1', 'name' => 'Foreign'));

        TenantContext::set(Tenant::DEFAULT_ID);
        $this->asAdmin();

        Livewire::test(ToolkitPage::class, array(
            'toolkit' => 'workshop',
            'screen' => 'entities',
        ))
            ->call('armDelete', 'FOREIGN-1')
            ->call('deleteRecord')
            ->assertSee('not in this estate');

        TenantContext::set('estate-b');
        $this->assertNotNull($engine->findRecord($manifest, 'item', 'FOREIGN-1'));
    }

    public function test_the_dashboard_renders_the_manifest_cards(): void
    {
        $this->asAdmin();

        $engine = $this->app->make(ToolkitEngine::class);
        $manifest = $engine->find('workshop');
        $this->assertNotNull($manifest);

        $engine->createRecord($manifest, 'item', array('code' => 'SKU-1', 'name' => 'Widget'));
        $engine->createRecord($manifest, 'item', array('code' => 'SKU-2', 'name' => 'Widget'));

        $this->get('/admin/default/toolkits/workshop/dashboard')
            ->assertOk()
            ->assertSee('Items')
            ->assertSee('2');
    }

    /**
     * A manifest with the shapes the real ones do carry: tabs, a secret, the
     * entity workbench, and both Wave 4 screens.
     *
     * @return array<string, mixed>
     */
    private function fixtureManifest(): array
    {
        return array(
            'slug' => 'workshop',
            'name' => 'Workshop',
            'version' => '1.0.0',
            'description' => 'Bench operations.',
            'icon' => '🛠️',
            'feature' => 'workshop',
            'screens' => array('settings', 'entities', 'research', 'consolidate', 'dashboard'),
            'tab_labels' => array(
                'basics' => 'Basics',
                'access' => 'Access',
            ),
            'settings' => array(
                array(
                    'key' => 'currency',
                    'label' => 'Display currency',
                    'type' => 'text',
                    'tab' => 'basics',
                    'default' => 'USD',
                ),
                array(
                    'key' => 'notes',
                    'label' => 'Internal notes',
                    'type' => 'textarea',
                    'tab' => 'basics',
                    'rows' => 4,
                ),
                array(
                    'key' => 'api_token',
                    'label' => 'API token',
                    'type' => 'secret',
                    'tab' => 'access',
                ),
            ),
            'entities' => array(
                array(
                    'key' => 'item',
                    'label' => 'Items',
                    'singular' => 'Item',
                    'icon' => '📦',
                    'id_field' => 'code',
                    'columns' => array(
                        array('key' => 'code', 'label' => 'Code', 'type' => 'string', 'required' => true),
                        array('key' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true),
                    ),
                ),
            ),
            'dashboard' => array(
                'cards' => array(
                    array(
                        'key' => 'total_items',
                        'label' => 'Items',
                        'source' => 'entity:item',
                        'aggregate' => 'count',
                    ),
                ),
            ),
        );
    }

    /**
     * A settings-only manifest: no `entities`, so the Wave 4 screens cannot fill
     * themselves from it.
     *
     * @return array<string, mixed>
     */
    private function settingsOnlyManifest(): array
    {
        return array(
            'slug' => 'garage',
            'name' => 'Garage',
            'version' => '1.0.0',
            'description' => 'Parking defaults.',
            'icon' => '🚗',
            'feature' => 'garage',
            'screens' => array('settings'),
            'settings' => array(
                array(
                    'key' => 'bays',
                    'label' => 'Bays',
                    'type' => 'number',
                    'default' => 2,
                ),
            ),
        );
    }

    /**
     * Sign in an estate administrator and pin the panel tenant.
     *
     * Order matters: Filament's TenantSet event carries the authenticated
     * user, so `setTenant()` throws when nobody is signed in.
     */
    private function asAdmin(): User
    {
        $user = $this->estateAdmin();

        $this->actingAs($user);
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));

        return $user;
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function estateMember(): User
    {
        $user = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'member')));

        return $user;
    }
}
