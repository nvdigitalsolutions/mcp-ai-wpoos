<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\AgentMemory;
use App\Models\Memory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §40 — the read-only agent-memory browser.
 *
 * The reason this slice touched the schema: `nvoos_memories` had no tenant, and
 * `MemoryStore` named the table in all six methods. `search()` therefore spanned
 * every estate, and `get`/`update`/`delete` worked on any id. `listByAgent` looked
 * like a boundary but is not — the agent id is a query parameter.
 *
 * Four layers, because any one alone leaves a hole: the store (read scope and
 * write estate), the model's global scope, the console routes, and the `/admin`
 * table.
 */
class AdminMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_memory_list_lists_only_the_current_estate(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $own = $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', 'Our refund window is 30 days');
        $foreign = $this->seedMemory('estate-b', 'agent-1', 'Their refund window is 14 days');

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(AgentMemory::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertSee('Our refund window is 30 days')
            ->assertDontSee('Their refund window is 14 days');
    }

    public function test_the_memory_page_is_reachable_from_the_panel(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/memory')
            ->assertOk();
    }

    public function test_the_console_recall_is_estate_scoped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        $own = $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', 'own memory');
        $this->seedMemory('estate-b', 'agent-1', 'foreign memory');

        TenantContext::set(Tenant::DEFAULT_ID);

        $rows = $this->getJson('/api/v1/chat-memory/recall?agent_id=agent-1')
            ->assertOk()
            ->json('contexts');

        $this->assertSame([$own->getKey()], array_column($rows, 'id'));
    }

    public function test_the_console_search_is_estate_scoped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        // Same agent id in both estates, so the only thing that can filter the
        // foreign row out is the estate scope.
        $own = $this->seedMemory(Tenant::DEFAULT_ID, 'agent-2', 'the needle is here');
        $this->seedMemory('estate-b', 'agent-2', 'the needle is also here');

        TenantContext::set(Tenant::DEFAULT_ID);

        $rows = $this->getJson('/api/v1/chat-memory/recall?query=needle&agent_id=agent-2')
            ->assertOk()
            ->json('contexts');

        $this->assertSame([$own->getKey()], array_column($rows, 'id'));
    }

    public function test_a_foreign_memory_cannot_be_read_updated_or_deleted(): void
    {
        $this->estate('estate-b');

        $foreign = $this->seedMemory('estate-b', 'agent-1', 'do not touch');

        $store = app(MemoryStoreInterface::class);

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->assertFalse($store->get($foreign->getKey())['found'], 'get() must not see another estate.');

        $this->assertFalse(
            $store->update($foreign->getKey(), ['content' => 'rewritten'])['success'],
            'update() must not reach another estate.'
        );

        $this->assertFalse(
            $store->delete($foreign->getKey())['success'],
            'delete() must not reach another estate.'
        );

        // The row is untouched and still owned by estate-b.
        $row = DB::table('nvoos_memories')->where('id', $foreign->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('do not touch', $row->content);
        $this->assertSame('estate-b', $row->tenant_id);
    }

    public function test_storing_a_memory_records_the_current_estate(): void
    {
        $this->estate('estate-b');

        $store = app(MemoryStoreInterface::class);

        TenantContext::set('estate-b');

        $result = $store->store([
            'agent_id' => 'agent-b',
            'content' => 'remember this',
            'title' => 'Estate b note',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'estate-b',
            DB::table('nvoos_memories')->where('id', $result['memory_id'])->value('tenant_id')
        );
    }

    public function test_the_model_scope_hides_another_estates_memory(): void
    {
        $this->estate('estate-b');

        $own = $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', 'own');
        $foreign = $this->seedMemory('estate-b', 'agent-1', 'foreign');

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->assertNotNull(Memory::query()->find($own->getKey()));
        $this->assertNull(
            Memory::query()->find($foreign->getKey()),
            'The global scope is the single isolation rule for the panel; if it fails, the page leaks.'
        );
    }

    public function test_the_page_is_read_only(): void
    {
        $own = $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', 'own');

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(AgentMemory::class)
            ->assertSee('Inspect')
            ->assertDontSee('Delete');

        // A page that accepts no payload is the structural half of read-only:
        // there is no form to submit, so no write path exists to forget about.
        $this->assertFalse(
            is_subclass_of(AgentMemory::class, HasForms::class),
            'The memory page must not expose a form.'
        );

        $this->assertNotNull(Memory::query()->find($own->getKey()));
    }

    public function test_an_expired_memory_is_still_listed_and_flagged(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        $expired = $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', 'stale fact', now()->subDay());

        Livewire::test(AgentMemory::class)->assertCanSeeTableRecords([$expired]);

        $this->assertTrue($expired->is_expired);

        // Recall hides it, which is exactly why the browser must not.
        $this->assertSame(
            [],
            app(MemoryStoreInterface::class)->search('stale', ['agent_id' => 'agent-1'])
        );
    }

    private function seedMemory(
        string $tenantId,
        string $agentId,
        string $content,
        mixed $expiresAt = null
    ): Memory {
        $id = (string) Str::uuid();

        DB::table('nvoos_memories')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'title' => mb_substr($content, 0, 40),
            'content' => $content,
            'tags' => json_encode(['test'], JSON_UNESCAPED_UNICODE),
            'tier' => 'recall',
            'importance' => 0.7,
            'wing' => null,
            'room' => null,
            'ttl' => null,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Memory::withoutGlobalScope('tenant')->findOrFail($id);
    }

    private function estate(string $tenantId): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['id' => $tenantId],
            [
                'name' => 'Estate '.$tenantId,
                'slug' => $tenantId,
                'is_operator' => false,
            ]
        );
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}
