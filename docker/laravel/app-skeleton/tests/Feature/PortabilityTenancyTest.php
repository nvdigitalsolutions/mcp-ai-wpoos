<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proposal 149 §54 — assistant portability is estate data.
 *
 * The controller was unscoped in three separate ways, and each one gets a test
 * here because they failed independently:
 *
 *  1. `export()` listed **every estate's** assistants — a bulk disclosure of
 *     system prompts and `meta`, not just a scoping bug.
 *  2. `import()` resolved its slug/title conflict unscoped, so `mode=overwrite`
 *     could `fill()->save()` another estate's row.
 *  3. `Assistant::create()` never set `tenant_id`, so an import in estate B
 *     wrote into the column default; the `is_default` reset ran table-wide.
 *
 * A fourth case is about shape rather than leakage: an `ids[]` entry that is not
 * in the caller's estate is **refused**, not silently dropped, because a bundle
 * that quietly contains less than was asked for is how a restore lands
 * incomplete.
 */
final class PortabilityTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_export_contains_only_the_current_estate(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot');
        $this->assistantIn('estate-b', 'Foreign bot');

        $bundle = $this->postJson('/api/v1/assistants/export', array())
            ->assertOk()
            ->json('data');

        $titles = array_column($bundle['assistants'], 'title');

        $this->assertSame(array('Own bot'), $titles);
    }

    public function test_exporting_a_foreign_id_is_refused(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot');
        $foreign = $this->assistantIn('estate-b', 'Foreign bot');

        $response = $this->postJson('/api/v1/assistants/export', array('ids' => array($foreign->id)))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString((string) $foreign->id, $response->json('message'));
        $this->assertStringNotContainsString('Foreign bot', $response->json('message'));
    }

    public function test_exporting_an_unknown_id_is_refused_rather_than_dropped(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot');

        $this->postJson('/api/v1/assistants/export', array('ids' => array(999999)))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_an_import_lands_in_the_calling_estate(): void
    {
        // The estate must exist before the factory provisions the membership:
        // `UserFactory::provision()` skips an unknown estate silently, and a
        // user with no membership is refused 403 by ResolveApiTenant.
        $this->estate('estate-b');

        Sanctum::actingAs(User::factory()->admin()->inEstate('estate-b')->create());

        $this->postJson('/api/v1/assistants/import', array(
            'json' => $this->bundle(array($this->entry('Imported bot'))),
        ))->assertOk()->assertJsonPath('data.imported', 1);

        // The scope is estate-bound (Proposal 153), so the assertion pins the
        // estate it is asking about rather than reading whatever context the
        // ended request left behind — which is `default`.
        TenantContext::set('estate-b');
        $assistant = Assistant::query()->where('title', 'Imported bot')->sole();
        TenantContext::forget();

        $this->assertSame('estate-b', $assistant->tenant_id);
    }

    public function test_an_overwrite_cannot_reach_another_estates_assistant(): void
    {
        $foreign = $this->assistantIn('estate-b', 'Support bot', array('system_prompt' => 'Foreign prompt.'));

        $this->postJson('/api/v1/assistants/import', array(
            'json' => $this->bundle(array($this->entry('Support bot', array('content' => 'Local prompt.')))),
            'mode' => 'overwrite',
        ))->assertOk()->assertJsonPath('data.updated', 0)->assertJsonPath('data.imported', 1);

        $this->assertSame('Foreign prompt.', $foreign->fresh()->system_prompt);
        $this->assertSame('estate-b', $foreign->fresh()->tenant_id);

        $local = Assistant::query()
            ->where('tenant_id', Tenant::DEFAULT_ID)
            ->where('title', 'Support bot')
            ->sole();

        $this->assertSame('Local prompt.', $local->system_prompt);
    }

    public function test_an_import_does_not_clear_another_estates_default(): void
    {
        $foreign = $this->assistantIn('estate-b', 'Foreign default', array('is_default' => true));

        // `is_default` lives inside `meta` in the bundle — that is where
        // `Assistant::toBundleEntry()` puts it, and the importer reads it back
        // from there.
        $this->postJson('/api/v1/assistants/import', array(
            'json' => $this->bundle(array($this->entry('Local default', array(
                'meta' => array('is_default' => true),
            )))),
        ))->assertOk();

        $this->assertTrue((bool) $foreign->fresh()->is_default);

        $local = Assistant::query()
            ->where('tenant_id', Tenant::DEFAULT_ID)
            ->where('title', 'Local default')
            ->sole();

        $this->assertTrue((bool) $local->is_default);
    }

    /**
     * The slug suffix is computed inside the estate. A same-slug assistant in
     * another estate must not push this estate's first import to `-2`, which is
     * what an unscoped `uniqueSlug()` did.
     */
    public function test_duplicate_mode_suffixes_within_the_estate_only(): void
    {
        $this->assistantIn('estate-b', 'Bundle Bot');

        $this->postJson('/api/v1/assistants/import', array(
            'json' => $this->bundle(array($this->entry('Bundle Bot'))),
            'mode' => 'duplicate',
        ))->assertOk();

        $this->postJson('/api/v1/assistants/import', array(
            'json' => $this->bundle(array($this->entry('Bundle Bot'))),
            'mode' => 'duplicate',
        ))->assertOk();

        $slugs = Assistant::query()
            ->where('tenant_id', Tenant::DEFAULT_ID)
            ->orderBy('id')
            ->pluck('slug')
            ->all();

        $this->assertSame(array('bundle-bot', 'bundle-bot-2'), $slugs);
    }

    /**
     * A bundle document cannot choose its estate. Even with a `tenant_id` in the
     * entry, the row lands in the caller's estate — the field is not read.
     */
    public function test_a_bundle_cannot_choose_its_estate(): void
    {
        $entry = $this->entry('Smuggled bot');
        $entry['tenant_id'] = 'estate-b';

        $this->postJson('/api/v1/assistants/import', array(
            'json' => $this->bundle(array($entry)),
        ))->assertOk();

        $this->assertSame(
            Tenant::DEFAULT_ID,
            Assistant::query()->where('title', 'Smuggled bot')->sole()->tenant_id
        );
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
     * Create an assistant in a specific estate through the writer, so the
     * fixture obeys the same rules the import does.
     *
     * @param array<string, mixed> $data
     */
    private function assistantIn(string $tenantId, string $title, array $data = array()): Assistant
    {
        $this->estate($tenantId);

        TenantContext::set($tenantId);

        $assistant = app(AssistantWriter::class)->create($data + array('title' => $title));

        TenantContext::forget();

        return $assistant;
    }

    private function estate(string $id): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $id),
            array('name' => ucfirst($id), 'slug' => $id, 'is_operator' => false)
        );
    }
}
