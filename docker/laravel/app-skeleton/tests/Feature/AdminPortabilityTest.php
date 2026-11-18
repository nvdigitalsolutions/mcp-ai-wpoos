<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Portability;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §54 — `/admin/portability`.
 *
 * The page is the second caller of `App\Services\AssistantPortability`; the
 * estate boundary itself is pinned in `PortabilityTenancyTest`. What this file
 * adds is the panel-specific half:
 *
 *  - the list is the estate's, not the table's;
 *  - **Preview writes nothing** while the write path does (the two calls differ
 *    only by a flag, so both are exercised);
 *  - the import records a record-level audit row, because `AuditAdminRequests`
 *    skips `/livewire/update`;
 *  - the page is refused without `manage_options`, matching the console's
 *    `can:manage_options` group.
 */
final class AdminPortabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(array('sanctum.stateful' => array('localhost', '127.0.0.1')));
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_the_estates_assistants(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/portability')
            ->assertOk()
            ->assertSee('Assistant export / import')
            ->assertSee('Own bot')
            ->assertSee('scoped to');
    }

    public function test_a_foreign_assistant_is_not_listed(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot');
        $this->assistantIn('estate-b', 'Foreign bot');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/portability')
            ->assertOk()
            ->assertSee('Own bot')
            ->assertDontSee('Foreign bot');
    }

    public function test_export_renders_a_bundle_for_the_whole_estate(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot');
        $this->assistantIn('estate-b', 'Foreign bot');

        $this->bootPanel();

        $bundle = $this->exportedBundle(
            Livewire::test(Portability::class)->call('export')
        );

        $this->assertSame('nvoos-assistant', $bundle['format']);
        $this->assertSame(1, $bundle['format_version']);
        $this->assertSame(array('Own bot'), array_column($bundle['assistants'], 'title'));
    }

    public function test_export_of_a_selection_is_limited_to_that_selection(): void
    {
        $first = $this->assistantIn(Tenant::DEFAULT_ID, 'First bot');
        $this->assistantIn(Tenant::DEFAULT_ID, 'Second bot');

        $this->bootPanel();

        $bundle = $this->exportedBundle(
            Livewire::test(Portability::class)
                ->set('selected', array((string) $first->id))
                ->call('export')
        );

        $this->assertSame(array('First bot'), array_column($bundle['assistants'], 'title'));
    }

    public function test_a_foreign_selection_is_refused(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot');
        $foreign = $this->assistantIn('estate-b', 'Foreign bot');

        $this->bootPanel();

        $component = Livewire::test(Portability::class)
            ->set('selected', array((string) $foreign->id))
            ->call('export');

        $this->assertSame('', $component->get('bundle'));

        $notice = $component->get('notice');

        $this->assertSame('error', $notice['tone']);
        $this->assertStringContainsString((string) $foreign->id, $notice['message']);
    }

    public function test_preview_reports_without_writing(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Support bot');

        $this->bootPanel();

        $component = Livewire::test(Portability::class)
            ->set('importJson', $this->bundle(array($this->entry('Support bot'))))
            ->set('mode', 'overwrite')
            ->set('dryRun', true)
            ->call('preview');

        $report = $component->get('report');

        $this->assertTrue($report['dry_run']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame('updated', $report['assistants'][0]['action']);
        $this->assertSame(1, Assistant::query()->count());
        $this->assertSame(0, DB::table('nvoos_admin_audit')->where('resource', 'portability')->count());
    }

    public function test_import_creates_the_assistant_and_writes_an_audit_row(): void
    {
        $this->bootPanel();

        $component = Livewire::test(Portability::class)
            ->set('importJson', $this->bundle(array(
                $this->entry('Imported bot', array('content' => 'Imported prompt.')),
                $this->entry('Second import'),
            )))
            ->set('mode', 'skip')
            ->set('dryRun', false)
            ->call('import');

        $report = $component->get('report');

        $this->assertFalse($report['dry_run']);
        $this->assertSame(2, $report['imported']);

        $assistant = Assistant::query()->where('title', 'Imported bot')->sole();

        $this->assertSame('Imported prompt.', $assistant->system_prompt);
        $this->assertSame(Tenant::DEFAULT_ID, $assistant->tenant_id);

        $row = DB::table('nvoos_admin_audit')->where('resource', 'portability')->first();

        $this->assertNotNull($row, 'An import must produce a record-level audit row.');
        $this->assertSame('import', $row->action);
        $this->assertSame('bundle', $row->record_id);
        $this->assertSame(Tenant::DEFAULT_ID, $row->tenant_id);

        $after = json_decode((string) $row->after, true);

        $this->assertSame(2, $after['imported']);
        $this->assertSame('skip', $after['mode']);
    }

    /**
     * The page path must be as scoped as the route: an overwrite here may not
     * reach the other estate's row either.
     */
    public function test_an_overwrite_from_the_page_cannot_reach_another_estate(): void
    {
        $foreign = $this->assistantIn('estate-b', 'Support bot', array('system_prompt' => 'Foreign prompt.'));

        $this->bootPanel();

        Livewire::test(Portability::class)
            ->set('importJson', $this->bundle(array($this->entry('Support bot', array('content' => 'Local prompt.')))))
            ->set('mode', 'overwrite')
            ->set('dryRun', false)
            ->call('import');

        $this->assertSame('Foreign prompt.', $foreign->fresh()->system_prompt);
        $this->assertSame('estate-b', $foreign->fresh()->tenant_id);
    }

    public function test_a_non_bundle_is_refused(): void
    {
        $this->bootPanel();

        $component = Livewire::test(Portability::class)
            ->set('importJson', '{"format":"other"}')
            ->call('preview');

        $this->assertNull($component->get('report'));

        $notice = $component->get('notice');

        $this->assertSame('error', $notice['tone']);
        $this->assertStringContainsString('not an nvoos-assistant bundle', $notice['message']);
    }

    public function test_an_empty_payload_is_reported_before_parsing(): void
    {
        $this->bootPanel();

        $component = Livewire::test(Portability::class)->call('preview');

        $notice = $component->get('notice');

        $this->assertSame('error', $notice['tone']);
        $this->assertSame('Paste a bundle first.', $notice['message']);
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/portability')
            ->assertForbidden();
    }

    public function test_a_member_without_manage_options_is_refused(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->get('/admin/default/portability')
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

    /**
     * @return array<string, mixed>
     */
    private function exportedBundle(mixed $component): array
    {
        $decoded = json_decode((string) $component->get('bundle'), true);

        $this->assertIsArray($decoded, 'Export must render a decodable bundle.');

        return $decoded;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function entry(string $title, array $overrides = array()): array
    {
        return array_merge(array(
            'title' => $title,
            'slug' => '',
            'status' => 'draft',
            'content' => 'Prompt.',
            'excerpt' => null,
            'meta' => array(),
        ), $overrides);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function bundle(array $entries): string
    {
        return (string) json_encode(array(
            'format' => 'nvoos-assistant',
            'format_version' => 1,
            'assistants' => $entries,
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assistantIn(string $tenantId, string $title, array $data = array()): Assistant
    {
        Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array('name' => ucfirst($tenantId), 'slug' => $tenantId, 'is_operator' => false)
        );

        TenantContext::set($tenantId);

        $assistant = app(AssistantWriter::class)->create($data + array('title' => $title));

        TenantContext::forget();

        return $assistant;
    }
}
