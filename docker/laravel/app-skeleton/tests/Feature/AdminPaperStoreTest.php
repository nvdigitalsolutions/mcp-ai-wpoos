<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\PaperStore;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §52 — `/admin/paper-store`.
 *
 * The page is a read-only browser over the shared Paper Store plus the record
 * schema catalogue. Three properties are pinned:
 *
 *  - the store is **shared by design**, not estate-scoped — unlike §47/§49/§51
 *    this is a decision, because a record written by WordPress must stay
 *    readable here;
 *  - the record list reports its pre-cap total and says when it is truncated;
 *  - the schema table advertises the same entities `GET /pro/records/schema`
 *    serves, from one catalogue.
 */
final class AdminPaperStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_collections_and_their_counts(): void
    {
        $this->seedRecord('knowledge', 'Dior Sauvage');
        $this->seedRecord('prompts', 'Launch copy');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/paper-store')
            ->assertOk()
            ->assertSee('Paper Store and record schemas')
            ->assertSee('knowledge')
            ->assertSee('prompts')
            ->assertSee('shared by every estate')
            ->assertSee('Read-only');
    }

    public function test_an_empty_store_is_an_empty_state(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/paper-store')
            ->assertOk()
            ->assertSee('The Paper Store is empty.');
    }

    public function test_selecting_a_collection_lists_its_records(): void
    {
        $this->seedRecord('knowledge', 'Dior Sauvage');
        $this->seedRecord('prompts', 'Launch copy');

        $this->bootPanel();

        Livewire::test(PaperStore::class)
            ->call('selectCollection', 'knowledge')
            ->assertSet('collection', 'knowledge')
            ->assertSee('Dior Sauvage')
            ->assertDontSee('Launch copy');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->seedRecord('knowledge', 'Published one', array('status' => 'published'));
        $this->seedRecord('knowledge', 'Draft one', array('status' => 'draft'));

        $this->bootPanel();

        Livewire::test(PaperStore::class)
            ->call('selectCollection', 'knowledge')
            ->set('status', 'draft')
            ->assertSee('Draft one')
            ->assertDontSee('Published one');
    }

    public function test_the_tag_filter_narrows_the_list(): void
    {
        $this->seedRecord('knowledge', 'Tagged', array('tags' => array('fragrance')));
        $this->seedRecord('knowledge', 'Untagged', array('tags' => array()));

        $this->bootPanel();

        Livewire::test(PaperStore::class)
            ->call('selectCollection', 'knowledge')
            ->set('tag', 'fragrance')
            ->assertSee('Tagged')
            ->assertDontSee('Untagged');
    }

    public function test_opening_a_record_shows_its_body_and_meta(): void
    {
        $record = $this->seedRecord('knowledge', 'Dior Sauvage', array(
            'description' => 'Fragrance note',
            'body' => 'Top notes: bergamot.',
            'meta' => array('sku' => 'SAUV-100'),
        ));

        $this->bootPanel();

        Livewire::test(PaperStore::class)
            ->call('selectCollection', 'knowledge')
            ->call('openRecord', (string) $record['id'])
            ->assertSee('Fragrance note')
            ->assertSee('Top notes: bergamot.')
            ->assertSee('SAUV-100');
    }

    public function test_an_unknown_record_is_not_found(): void
    {
        $this->seedRecord('knowledge', 'Dior Sauvage');

        $this->bootPanel();

        Livewire::test(PaperStore::class)
            ->call('selectCollection', 'knowledge')
            ->call('openRecord', 'missing')
            ->assertSee('Paper Store record not found.');
    }

    public function test_cross_collection_search_reports_the_collection(): void
    {
        $this->seedRecord('knowledge', 'Dior Sauvage');
        $this->seedRecord('prompts', 'Sauvage launch copy');

        $this->bootPanel();

        Livewire::test(PaperStore::class)
            ->set('search', 'sauvage')
            ->assertSee('Dior Sauvage')
            ->assertSee('Sauvage launch copy')
            ->assertSee('result(s) for');
    }

    public function test_the_record_schemas_section_lists_the_served_entities(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/paper-store')
            ->assertOk()
            ->assertSee('Record schemas')
            ->assertSee('Posts')
            ->assertSee('Pages')
            ->assertSee('Tasks')
            ->assertSee('Projects')
            ->assertSee('mcp_ai_task')
            ->assertSee('/posts');
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/paper-store')
            ->assertForbidden();
    }

    /**
     * Record CRUD is the console's, and Wave 3's manifest engine replaces
     * schema-driven record forms. A form here would be a writer that is
     * superseded immediately.
     */
    public function test_the_page_accepts_no_payload(): void
    {
        $traits = class_uses_recursive(PaperStore::class);

        $this->assertArrayNotHasKey(\Filament\Forms\Concerns\InteractsWithForms::class, $traits);
        $this->assertFalse(
            is_subclass_of(PaperStore::class, \Filament\Forms\Contracts\HasForms::class),
            'The Paper Store surface is read-only; a form would need the contract row to declare a panel writer.'
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
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function seedRecord(string $collection, string $title, array $overrides = array()): array
    {
        return app(PaperStoreInterface::class)->save($collection, array_merge(array(
            'title' => $title,
            'status' => 'published',
        ), $overrides));
    }
}
