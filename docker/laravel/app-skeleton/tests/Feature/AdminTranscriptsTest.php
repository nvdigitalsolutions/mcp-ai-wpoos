<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Transcripts;
use App\Models\Tenant;
use App\Models\Transcript;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\Transcripts\TranscriptPreview;
use Filament\Facades\Filament;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §39 — the read-only transcript list.
 *
 * The reason this slice touched the schema: `nvoos_transcripts` had no tenant,
 * and `session_id` was globally unique. A list over it would have shown one
 * estate another estate's conversations, including full message bodies, and the
 * unique key meant two estates that generated the same session id silently
 * overwrote each other. Both are fixed here, so both are pinned here.
 *
 * Four layers are tested because any one alone leaves a hole:
 *
 *  - the store (both directions: scope on read, estate on write);
 *  - the model's global scope (the rule the panel inherits);
 *  - the console endpoints (index and show);
 *  - the `/admin` table itself.
 */
class AdminTranscriptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_transcript_list_lists_only_the_current_estate(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $own = $this->seedTranscript(Tenant::DEFAULT_ID, 'session-own', [
            ['role' => 'user', 'content' => 'what is the refund policy'],
        ]);
        $foreign = $this->seedTranscript('estate-b', 'session-foreign');

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Transcripts::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign])
            // The preview travels column → accessor → escaped cell; asserting
            // only the model accessor would leave the table half untested.
            ->assertSee('what is the refund policy');
    }

    public function test_the_transcripts_page_is_reachable_from_the_panel(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/transcripts')
            ->assertOk();
    }

    public function test_the_console_index_is_estate_scoped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        $this->seedTranscript(Tenant::DEFAULT_ID, 'session-own');
        $this->seedTranscript('estate-b', 'session-foreign');

        TenantContext::set(Tenant::DEFAULT_ID);

        $rows = $this->getJson('/api/v1/chat-transcripts')->assertOk()->json();

        $this->assertSame(['session-own'], array_column($rows['sessions'], 'session_key'));
        $this->assertSame(1, $rows['total']);
    }

    public function test_the_console_show_does_not_disclose_another_estates_session(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        $this->seedTranscript('estate-b', 'session-foreign', [
            ['role' => 'user', 'content' => 'another estate secret'],
        ]);

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->getJson('/api/v1/chat-transcripts/session-foreign')
            ->assertOk()
            ->assertJsonPath('session', null);
    }

    public function test_the_same_session_key_in_two_estates_does_not_collide(): void
    {
        $this->estate('estate-b');

        $store = app(TranscriptStoreInterface::class);

        TenantContext::set(Tenant::DEFAULT_ID);
        $store->save([
            'session_id' => 'shared-key',
            'messages' => [['role' => 'user', 'content' => 'from default']],
        ]);

        TenantContext::set('estate-b');
        $store->save([
            'session_id' => 'shared-key',
            'messages' => [['role' => 'user', 'content' => 'from estate b']],
        ]);

        $rows = DB::table('nvoos_transcripts')->where('session_id', 'shared-key')->get();

        $this->assertCount(
            2,
            $rows,
            'The session key is per estate; a global unique would overwrite one estate or fail the insert.'
        );
        $this->assertEqualsCanonicalizing(
            [Tenant::DEFAULT_ID, 'estate-b'],
            $rows->pluck('tenant_id')->all()
        );

        // And each estate reads its own copy, not the newest write.
        TenantContext::set(Tenant::DEFAULT_ID);
        $own = $store->list(['session_id' => 'shared-key'])['transcripts'];

        $this->assertCount(1, $own);
        $this->assertSame('from default', $own[0]['messages'][0]['content']);
    }

    public function test_saving_a_transcript_records_the_current_estate(): void
    {
        $this->estate('estate-b');

        $store = app(TranscriptStoreInterface::class);

        TenantContext::set('estate-b');
        $store->save([
            'session_id' => 'session-estate-b',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]);

        $this->assertSame(
            'estate-b',
            DB::table('nvoos_transcripts')->where('session_id', 'session-estate-b')->value('tenant_id')
        );
    }

    public function test_prune_spans_estates(): void
    {
        $this->estate('estate-b');

        $this->seedTranscript(Tenant::DEFAULT_ID, 'old-default', [], null, now()->subDays(2));
        $this->seedTranscript('estate-b', 'old-estate-b', [], null, now()->subDays(2));

        TenantContext::set(Tenant::DEFAULT_ID);

        $result = app(TranscriptStoreInterface::class)->prune(86400);

        $this->assertSame(2, $result['deleted_count']);
        $this->assertSame(
            0,
            DB::table('nvoos_transcripts')->count(),
            'Retention is a server-owned job with no request estate, so it must not be estate-scoped.'
        );
    }

    public function test_the_model_scope_hides_another_estates_transcript(): void
    {
        $this->estate('estate-b');

        $own = $this->seedTranscript(Tenant::DEFAULT_ID, 'session-own');
        $foreign = $this->seedTranscript('estate-b', 'session-foreign');

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->assertNotNull(Transcript::query()->find($own->getKey()));
        $this->assertNull(
            Transcript::query()->find($foreign->getKey()),
            'The global scope is the single isolation rule for the panel; if it fails, the page leaks.'
        );
    }

    public function test_the_preview_reduces_multimodal_content_to_text(): void
    {
        $this->assertSame(
            'hello world',
            TranscriptPreview::firstUserMessage([
                ['role' => 'system', 'content' => 'ignored'],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'hello'],
                    ['type' => 'image', 'url' => 'https://example.test/x.png'],
                    ['type' => 'text', 'text' => 'world'],
                ]],
            ])
        );

        // An image-only opening turn is skipped rather than ending the search.
        $this->assertSame(
            'the real question',
            TranscriptPreview::firstUserMessage([
                ['role' => 'user', 'content' => [['type' => 'image', 'url' => 'https://example.test/x.png']]],
                ['role' => 'user', 'content' => 'the real question'],
            ])
        );

        $this->assertSame('', TranscriptPreview::firstUserMessage([['role' => 'assistant', 'content' => 'hi']]));

        // The defect this replaced: `(string)` on an array previewed "Array".
        $row = $this->seedTranscript(Tenant::DEFAULT_ID, 'session-multimodal', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'multimodal opening']]],
        ]);

        $this->assertSame('multimodal opening', $row->preview);
    }

    public function test_the_page_is_read_only(): void
    {
        $own = $this->seedTranscript(Tenant::DEFAULT_ID, 'session-own');

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Transcripts::class)
            ->assertSee('Inspect')
            ->assertDontSee('Delete')
            ->assertDontSee('Prune');

        // A page that accepts no payload is the structural half of read-only:
        // there is no form to submit, so no write path exists to forget about.
        $this->assertFalse(
            is_subclass_of(Transcripts::class, HasForms::class),
            'The transcript page must not expose a form.'
        );

        // Inspecting is a read; the row survives it.
        $this->assertNotNull(Transcript::query()->find($own->getKey()));
    }

    /**
     * Insert a transcript directly. The store is the production writer; this
     * fixture exists so the isolation tests are about scope rather than about
     * JSON encoding, which the store's own tests cover.
     *
     * @param  array<int, mixed>  $messages
     */
    private function seedTranscript(
        string $tenantId,
        string $sessionId,
        array $messages = [],
        ?string $assistantId = null,
        mixed $updatedAt = null
    ): Transcript {
        $id = (string) Str::uuid();

        DB::table('nvoos_transcripts')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'assistant_id' => $assistantId,
            'messages' => json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'metadata' => json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => $updatedAt ?? now(),
        ]);

        return Transcript::withoutGlobalScope('tenant')->findOrFail($id);
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
