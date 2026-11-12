<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SettingsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Tests\TestCase;

/**
 * Phase 3b — approvals, triggers, security center, paper store.
 *
 * Every assertion is pinned to the shape the Pro SPA clients consume
 * (`src/api/approvals.ts`, `triggers.ts`, `security.ts`, `paper-store.ts`),
 * not to an arbitrary server-side envelope.
 */
class Phase3bTest extends TestCase
{
    use RefreshDatabase;

    private function actAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // ---------------------------------------------------------------- approvals

    public function test_approvals_default_to_the_pending_queue_with_a_real_total(): void
    {
        $this->actAsUser();

        $approve = $this->postJson('/api/v1/approvals', ['tool' => 'run_sql', 'arguments' => ['q' => 'select 1']]);
        $approve->assertCreated()->assertJsonPath('tool', 'run_sql');

        $keep = $this->postJson('/api/v1/approvals', ['tool' => 'deploy', 'reason' => 'needs review']);
        $keep->assertCreated();

        // Resolve one so it leaves the pending queue.
        $this->postJson('/api/v1/approvals/'.$approve->json('id').'/approve', [])->assertOk();

        $this->getJson('/api/v1/approvals')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'approvals')
            ->assertJsonPath('approvals.0.tool', 'deploy')
            ->assertJsonStructure([
                'approvals' => [[
                    'id', 'status', 'tool', 'arguments', 'assistant_id',
                    'requester_id', 'session_id', 'reason', 'created_at', 'expires_at',
                ]],
                'total',
            ]);
    }

    public function test_approve_then_second_resolution_conflicts(): void
    {
        $this->actAsUser();
        $id = $this->postJson('/api/v1/approvals', ['tool' => 'run_sql'])->json('id');

        $this->postJson('/api/v1/approvals/'.$id.'/approve', ['note' => 'looks fine'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/v1/approvals/'.$id.'/deny', ['note' => 'changed my mind'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'conflict');

        $this->assertSame('approved', DB::table('nvoos_approvals')->find($id)->status);
        $this->assertSame('looks fine', DB::table('nvoos_approvals')->find($id)->resolution_note);
    }

    public function test_deny_marks_the_approval_denied(): void
    {
        $this->actAsUser();
        $id = $this->postJson('/api/v1/approvals', ['tool' => 'delete_post'])->json('id');

        $this->postJson('/api/v1/approvals/'.$id.'/deny', ['note' => 'not allowed'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('denied', DB::table('nvoos_approvals')->find($id)->status);
    }

    public function test_resolving_an_unknown_approval_returns_404(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/approvals/999999/approve')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_approvals_require_authentication(): void
    {
        $this->getJson('/api/v1/approvals')->assertUnauthorized();
    }

    // ----------------------------------------------------------------- triggers

    public function test_trigger_crud_round_trip(): void
    {
        $this->actAsUser();

        $created = $this->postJson('/api/v1/triggers', [
            'name' => 'Nightly digest',
            'type' => 'schedule',
            'config' => ['cron' => '0 2 * * *'],
            'webhook_url' => 'https://example.com/hook',
        ])->assertCreated()->assertJsonPath('enabled', true);

        $id = $created->json('id');

        $this->getJson('/api/v1/triggers')
            ->assertOk()
            ->assertJsonCount(1, 'triggers')
            ->assertJsonPath('triggers.0.name', 'Nightly digest')
            ->assertJsonStructure([
                'triggers' => [[
                    'id', 'name', 'type', 'config', 'workflow_id',
                    'enabled', 'last_fired_at', 'webhook_url',
                ]],
            ]);

        $this->putJson('/api/v1/triggers/'.$id, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->deleteJson('/api/v1/triggers/'.$id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->getJson('/api/v1/triggers')->assertJsonCount(0, 'triggers');
    }

    public function test_trigger_update_and_delete_404_for_unknown_ids(): void
    {
        $this->actAsUser();

        $this->putJson('/api/v1/triggers/424242', ['enabled' => true])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->deleteJson('/api/v1/triggers/424242')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    // ----------------------------------------------------------------- security

    public function test_posture_report_is_internally_consistent(): void
    {
        $this->actAsUser();

        $response = $this->getJson('/api/v1/security/posture')
            ->assertOk()
            ->assertJsonStructure([
                'score', 'grade', 'signals', 'quick_wins', 'computed_at',
            ]);

        $signals = $response->json('signals');
        $score = $response->json('score');
        $grade = $response->json('grade');

        $this->assertSame(100, array_sum(array_column($signals, 'weight')));

        foreach ($signals as $signal) {
            $this->assertContains($signal['subtab'], ['network', 'access', 'audit', 'ai_safety']);
            $this->assertIsBool($signal['passed']);
        }

        $failed = array_values(array_filter($signals, static fn (array $s): bool => ! $s['passed']));
        $this->assertCount(count($failed), $response->json('quick_wins'));

        $expectedGrade = match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
        $this->assertSame($expectedGrade, $grade);

        // Durable stores exist under RefreshDatabase, so this signal must pass.
        $audit = array_values(array_filter($signals, static fn (array $s): bool => $s['id'] === 'audit'));
        $this->assertTrue($audit[0]['passed']);
    }

    public function test_preview_headers_returns_the_baseline_header_set(): void
    {
        $this->actAsUser();

        $headers = $this->getJson('/api/v1/security/preview-headers')
            ->assertOk()
            ->assertJsonPath('security_headers_enabled', true)
            ->json('headers');

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
    }

    public function test_snapshot_save_list_restore_round_trip(): void
    {
        $this->actAsUser();
        $store = app(SettingsStoreInterface::class);
        $store->set('default_model', 'model-alpha');

        $snapshot = $this->postJson('/api/v1/security/snapshot', ['label' => 'before upgrade'])
            ->assertCreated()
            ->assertJsonPath('label', 'before upgrade');

        $snapshotId = $snapshot->json('snapshot_id');
        $this->assertNotEmpty($snapshotId);

        $this->getJson('/api/v1/security/snapshots')
            ->assertOk()
            ->assertJsonPath('snapshots.0.snapshot_id', $snapshotId);

        $store->set('default_model', 'model-beta');
        $this->assertSame('model-beta', $store->get('default_model'));

        $restore = $this->postJson('/api/v1/security/restore', ['snapshot_id' => $snapshotId])
            ->assertOk()
            ->assertJsonPath('success', true);

        // A snapshot captures the whole settings surface, so the restore
        // rewrites every non-secret key rather than only the one we changed.
        $this->assertGreaterThanOrEqual(1, $restore->json('restored'));

        $this->assertSame('model-alpha', $store->get('default_model'));
    }

    public function test_snapshots_never_persist_plaintext_secrets(): void
    {
        $this->actAsUser();
        app(SettingsStoreInterface::class)->set('openai_api_key', 'sk-live-secret');

        $id = $this->postJson('/api/v1/security/snapshot', ['label' => 'secrets'])
            ->assertCreated()
            ->json('snapshot_id');

        $payload = json_decode(DB::table('nvoos_security_snapshots')->find($id)->payload, true);

        $this->assertSame(SettingsSchema::MASKED_PLACEHOLDER, $payload['openai_api_key']);
        $this->assertStringNotContainsString('sk-live-secret', (string) json_encode($payload));
    }

    public function test_restoring_an_unknown_snapshot_returns_404(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/security/restore', ['snapshot_id' => 'missing'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_self_test_reports_every_check(): void
    {
        $this->actAsUser();

        $response = $this->postJson('/api/v1/security/self-test')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = array_column($response->json('checks'), 'id');

        $this->assertSame(['bindings', 'database', 'cache', 'storage'], $ids);

        foreach ($response->json('checks') as $check) {
            $this->assertTrue($check['passed'], $check['label'].': '.$check['message']);
        }
    }

    // -------------------------------------------------------------- paper store

    public function test_paper_store_collection_lifecycle(): void
    {
        $this->actAsUser();

        $created = $this->postJson('/api/v1/paper-store/notes', [
            'title' => 'Hello World',
            'description' => 'First note',
            'tags' => ['intro'],
            'status' => 'published',
        ])->assertCreated()->assertJsonPath('id', 'hello-world');

        $this->getJson('/api/v1/paper-store')
            ->assertOk()
            ->assertJsonPath('collections', ['notes']);

        $this->getJson('/api/v1/paper-store/notes')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('records.0.title', 'Hello World');

        $this->getJson('/api/v1/paper-store/search?q=hello')
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.id', 'hello-world');

        $this->putJson('/api/v1/paper-store/notes/hello-world', ['title' => 'Hello There'])
            ->assertOk()
            ->assertJsonPath('title', 'Hello There');

        $this->deleteJson('/api/v1/paper-store/notes/hello-world')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson('/api/v1/paper-store/notes/hello-world')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_paper_store_reports_total_before_the_page_slice(): void
    {
        $this->actAsUser();

        foreach (['One', 'Two', 'Three'] as $title) {
            $this->postJson('/api/v1/paper-store/briefs', ['title' => $title])->assertCreated();
        }

        $this->getJson('/api/v1/paper-store/briefs?limit=1&offset=0')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonCount(1, 'records');

        $this->getJson('/api/v1/paper-store/briefs?status=published')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_paper_store_ids_are_uniquified_within_a_collection(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/paper-store/docs', ['title' => 'Same Title'])
            ->assertCreated()
            ->assertJsonPath('id', 'same-title');

        $this->postJson('/api/v1/paper-store/docs', ['title' => 'Same Title'])
            ->assertCreated()
            ->assertJsonPath('id', 'same-title-2');
    }

    public function test_paper_store_requires_authentication(): void
    {
        $this->getJson('/api/v1/paper-store')->assertUnauthorized();
        $this->postJson('/api/v1/security/self-test')->assertUnauthorized();
        $this->getJson('/api/v1/triggers')->assertUnauthorized();
    }
}
