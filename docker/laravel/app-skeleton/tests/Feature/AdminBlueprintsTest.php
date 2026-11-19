<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Blueprints;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Services\BlueprintInstaller;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §53 — `/admin/blueprints`.
 *
 * This row is one of the few that is genuinely **full** on this panel, so the
 * properties pinned here are the opposite of §52's: the page is expected to
 * write, and the tests prove the write lands in the caller's estate, records
 * itself, and is not a second install path.
 *
 * The tenancy finding is the reason this file exists in this shape. §47, §49
 * and §51 each shipped with a defect fix because the store behind the surface
 * was unscoped; `BlueprintInstaller` and `BlueprintCatalogue::installedMap()`
 * were already scoped, so the cross-estate test here is a **regression guard**
 * rather than a repair — it must keep passing without any production change.
 *
 * Access parity is pinned too: the console's install route sits inside its
 * `can:manage_options` group, so a non-viewer member without that capability
 * must be refused the page rather than offered a write the API would reject.
 */
final class AdminBlueprintsTest extends TestCase
{
    use RefreshDatabase;

    /** A shipped blueprint that exercises every normalised detail field. */
    private const TOOLKIT = 'crm';

    private const SLUG = 'agency-account-manager';

    private const NAME = 'Agency Account Manager';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_the_catalogue_grouped_by_toolkit(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/blueprints')
            ->assertOk()
            ->assertSee('Assistant blueprints')
            ->assertSee('CRM & Email Marketing')
            ->assertSee(self::NAME)
            ->assertSee('Health & Wellness')
            ->assertSee('Radiology Review Assistant');
    }

    public function test_the_toolkit_filter_narrows_the_list(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->set('toolkit', 'healthcare')
            ->assertSee('Radiology Review Assistant')
            ->assertDontSee(self::NAME);
    }

    public function test_the_search_filter_matches_the_name_and_the_toolkit(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->set('search', 'radiology')
            ->assertSee('Radiology Review Assistant')
            ->assertDontSee(self::NAME);

        // The toolkit label and slug are searched too, so a query for the
        // discipline finds its blueprints even when no name contains it.
        Livewire::test(Blueprints::class)
            ->set('search', 'health & wellness')
            ->assertSee('Personal Health Tracker')
            ->assertDontSee(self::NAME);
    }

    public function test_the_detail_panel_shows_instructions_tools_and_defaults(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->call('open', self::TOOLKIT, self::SLUG)
            ->assertSee('You are an Agency Account Manager')
            ->assertSee('search_upwork_jobs')
            ->assertSee('account_executive')
            ->assertSee('meddic')
            ->assertSee('linkedin_dm')
            // Nothing is installed yet, so the panel must offer Install and not
            // the confirmed overwrite.
            ->assertSee('Install as an assistant')
            ->assertDontSee('Reinstall assistant');
    }

    public function test_an_unknown_blueprint_is_reported(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->call('open', self::TOOLKIT, 'not-a-blueprint')
            ->assertSee('That blueprint does not exist.');
    }

    public function test_installing_creates_a_published_assistant_in_the_estate(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->call('install', self::TOOLKIT, self::SLUG)
            ->assertSee('Installed');

        $assistant = $this->installedAssistant();

        $this->assertSame(self::NAME, $assistant->title);
        $this->assertSame('publish', $assistant->status);
        $this->assertSame(self::SLUG, $assistant->meta['_blueprint_source']);
        $this->assertCount(19, $assistant->tools);
        $this->assertNotEmpty($assistant->system_prompt);
    }

    public function test_the_catalogue_reports_the_new_install(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->call('install', self::TOOLKIT, self::SLUG)
            ->assertSee('installed · #')
            ->assertDontSee('Reinstall assistant');
    }

    public function test_a_second_install_without_overwrite_is_refused_and_duplicates_nothing(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->call('install', self::TOOLKIT, self::SLUG)
            ->call('install', self::TOOLKIT, self::SLUG)
            ->assertSee('is already installed in this estate. Use Reinstall to update it in place.');

        $this->assertSame(1, $this->estateAssistants()->count());
    }

    /**
     * The installer identifies an existing assistant by TITLE (as WordPress
     * does), while the catalogue's `installed` flag is provenance
     * (`_blueprint_source`). An assistant created by hand with a blueprint's
     * title is therefore a conflict without being an install: the row offers
     * Install, not Reinstall, so the 409 notice must not name Reinstall.
     */
    public function test_a_hand_made_assistant_with_a_blueprint_title_is_a_conflict_not_an_install(): void
    {
        app(AssistantWriter::class)->create(array('title' => self::NAME));

        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->assertDontSee('installed · #')
            ->call('install', self::TOOLKIT, self::SLUG)
            ->assertSee('was not installed from a blueprint')
            ->assertDontSee('Use Reinstall to update it in place.');

        $this->assertSame(1, $this->estateAssistants()->count());
        $this->assertNull($this->installedAssistant()->meta['_blueprint_source'] ?? null);
    }

    public function test_reinstall_updates_in_place_and_keeps_operator_keys(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)->call('install', self::TOOLKIT, self::SLUG);

        $assistant = $this->installedAssistant();

        // A key the operator added after installing. The confirm dialog
        // promises it survives a reinstall, so pin the promise.
        $assistant->meta = array_merge($assistant->meta, array('operator_note' => 'kept'));
        $assistant->save();

        Livewire::test(Blueprints::class)
            ->call('install', self::TOOLKIT, self::SLUG, true)
            ->assertSee('Reinstalled');

        $fresh = $assistant->fresh();

        $this->assertSame($assistant->id, $fresh->id, 'Reinstall must update in place, not duplicate.');
        $this->assertSame('kept', $fresh->meta['operator_note']);
        $this->assertSame(1, $this->estateAssistants()->count());
    }

    /**
     * `AuditAdminRequests` skips `/livewire/update`, so an install from the
     * panel is invisible unless the page writes its own record row.
     */
    public function test_each_install_writes_a_record_level_audit_row(): void
    {
        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->call('install', self::TOOLKIT, self::SLUG)
            ->call('install', self::TOOLKIT, self::SLUG, true);

        $rows = DB::table('nvoos_admin_audit')
            ->where('resource', 'blueprint')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);

        $this->assertSame('install', $rows[0]->action);
        $this->assertSame('reinstall', $rows[1]->action);
        $this->assertSame(self::TOOLKIT.'/'.self::SLUG, $rows[0]->record_id);
        $this->assertSame(Tenant::DEFAULT_ID, $rows[0]->tenant_id);
        $this->assertSame(Tenant::DEFAULT_ID, $rows[1]->tenant_id);

        $id = (int) $this->installedAssistant()->getKey();

        $this->assertSame($id, json_decode((string) $rows[0]->after, true)['assistant_id']);
        $this->assertTrue(json_decode((string) $rows[1]->after, true)['overwrite']);
    }

    /**
     * The estate boundary, in both directions: another estate's install is not
     * reported here, and it does not make this estate's install a duplicate.
     */
    public function test_another_estates_install_neither_shows_nor_blocks(): void
    {
        $this->estate('estate-b');

        TenantContext::set('estate-b');
        $foreign = app(BlueprintInstaller::class)->install(self::TOOLKIT, self::SLUG);
        TenantContext::forget();

        $this->bootPanel();

        Livewire::test(Blueprints::class)
            ->assertDontSee('installed · #')
            ->call('install', self::TOOLKIT, self::SLUG)
            ->assertSee('Installed')
            ->assertDontSee('already exists');

        // The scope is estate-bound (Proposal 153), so an assertion that spans
        // two estates opts out explicitly — which is what this test is about.
        $this->assertSame(2, Assistant::query()->withoutGlobalScopes()->count());

        $mine = $this->installedAssistant();
        $foreignRow = Assistant::query()->withoutGlobalScopes()->findOrFail($foreign['assistant_id']);

        $this->assertNotSame($foreign['assistant_id'], (int) $mine->getKey());
        $this->assertSame('estate-b', $foreignRow->tenant_id);
        $this->assertSame(self::NAME, $foreignRow->title);
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/blueprints')
            ->assertForbidden();
    }

    /**
     * Non-viewer membership is not enough: the console's install route needs
     * `manage_options`, so the page must not offer the write without it.
     */
    public function test_a_member_without_manage_options_is_refused(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->get('/admin/default/blueprints')
            ->assertForbidden();
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
        return User::factory()->admin()->create();
    }

    private function estate(string $id): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $id),
            array('name' => ucfirst($id), 'slug' => $id, 'is_operator' => false)
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Assistant>
     */
    private function estateAssistants()
    {
        return Assistant::query()->where('tenant_id', Tenant::DEFAULT_ID);
    }

    private function installedAssistant(): Assistant
    {
        return $this->estateAssistants()->where('title', self::NAME)->sole();
    }
}
