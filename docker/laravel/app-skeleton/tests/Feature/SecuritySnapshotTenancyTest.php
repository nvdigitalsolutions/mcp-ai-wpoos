<?php

namespace Tests\Feature;

use App\Support\Security\SecuritySnapshotStore;
use App\Support\SettingsSchema;
use App\Support\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §51 — security snapshots are estate data.
 *
 * The payload is the **settings** blob, so the consequence of the missing
 * estate column was worse than the toolkit-record case: `POST /security/restore`
 * would copy another estate's configuration into the caller's settings, and the
 * caller could then read it back from the settings surface.
 *
 * These tests pin the three statements that matter:
 *
 *  - a saved snapshot records the writing estate;
 *  - the list is scoped;
 *  - another estate's id cannot be restored — 404, not a copy.
 */
final class SecuritySnapshotTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_a_saved_snapshot_records_the_writing_estate(): void
    {
        $id = $this->postJson('/api/v1/security/snapshot', ['label' => 'Own'])
            ->assertCreated()
            ->json('snapshot_id');

        $this->assertSame(
            'default',
            (string) DB::table('nvoos_security_snapshots')->find($id)->tenant_id
        );
    }

    public function test_the_list_is_scoped_to_the_current_estate(): void
    {
        $this->seedSnapshot('default', 'Own snapshot');
        $this->seedSnapshot('estate-b', 'Foreign snapshot');

        $labels = array_column(
            $this->getJson('/api/v1/security/snapshots')->assertOk()->json('snapshots'),
            'label'
        );

        $this->assertSame(array('Own snapshot'), $labels);
    }

    public function test_another_estates_snapshot_cannot_be_restored(): void
    {
        $store = app(SettingsStoreInterface::class);
        $store->set('default_model', 'model-own');

        $foreign = $this->seedSnapshot('estate-b', 'Foreign snapshot', array(
            'default_model' => 'model-hijacked',
        ));

        $this->postJson('/api/v1/security/restore', ['snapshot_id' => $foreign])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        // The foreign payload did not land in this estate's settings.
        $this->assertSame('model-own', $store->get('default_model'));
    }

    public function test_an_own_snapshot_still_restores(): void
    {
        $store = app(SettingsStoreInterface::class);
        $store->set('default_model', 'model-before');

        $id = $this->postJson('/api/v1/security/snapshot', ['label' => 'Mine'])
            ->assertCreated()
            ->json('snapshot_id');

        $store->set('default_model', 'model-after');

        $this->postJson('/api/v1/security/restore', ['snapshot_id' => $id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('model-before', $store->get('default_model'));
    }

    public function test_the_store_answers_not_found_for_a_foreign_id(): void
    {
        TenantContext::set('default');

        $foreign = $this->seedSnapshot('estate-b', 'Foreign snapshot');

        $store = app(SecuritySnapshotStore::class);

        $this->assertNull($store->find($foreign));
        $this->assertNull($store->detail($foreign));
        $this->assertSame(0, $store->total());
    }

    public function test_the_payload_is_still_masked_at_write_time(): void
    {
        app(SettingsStoreInterface::class)->set('openai_api_key', 'sk-live-secret');

        $id = $this->postJson('/api/v1/security/snapshot', ['label' => 'Secrets'])
            ->assertCreated()
            ->json('snapshot_id');

        $payload = json_decode(
            (string) DB::table('nvoos_security_snapshots')->find($id)->payload,
            true
        );

        $this->assertSame(SettingsSchema::MASKED_PLACEHOLDER, $payload['openai_api_key']);
        $this->assertStringNotContainsString('sk-live-secret', (string) json_encode($payload));
    }

    /**
     * Seed a snapshot owned by an arbitrary estate.
     *
     * @param array<string, mixed> $payload
     */
    private function seedSnapshot(string $tenantId, string $label, array $payload = array()): string
    {
        $id = 'snap-'.bin2hex(random_bytes(6));

        DB::table('nvoos_security_snapshots')->insert(array(
            'id' => $id,
            'tenant_id' => $tenantId,
            'label' => $label,
            'payload' => json_encode($payload === array() ? array('default_model' => 'x') : $payload),
            'created_at' => now(),
            'updated_at' => now(),
        ));

        return $id;
    }
}
