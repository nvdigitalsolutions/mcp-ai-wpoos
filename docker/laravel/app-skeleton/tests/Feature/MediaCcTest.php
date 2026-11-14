<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Tests\TestCase;

/**
 * 090-i — the media command centre.
 *
 * The contract that matters is what a number *means*:
 *
 *  - Four sections are real (counts, storage, next-gen coverage, queue).
 *  - Three are **null**, not zero — this runtime has no alt-text column, no
 *    original-size column and no scheduler, and a `0%` would read as a failing
 *    pipeline rather than an absent one.
 *  - A worker that is down does not throw: the local numbers still render
 *    beside an honest `available: false`.
 *  - With no worker configured the surface is hidden entirely — the route
 *    still answers 503, because a stale bookmark deserves a reason.
 */
final class MediaCcTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function configureWorker(): void
    {
        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.token' => 'test-token',
        ]);
    }

    private function fakeHealthyWorker(): void
    {
        Http::fake([
            '*/api/health' => Http::response([
                'status' => 'ok',
                'service' => 'design-media-worker',
                'version' => '3.4.0',
                'uptime' => 12.5,
            ]),
        ]);
    }

    private function addFiles(): void
    {
        DB::table('nvoos_files')->insert([
            [
                'path' => '2026/11/a.webp',
                'disk' => 'public',
                'filename' => 'a.webp',
                'mime_type' => 'image/webp',
                'size_bytes' => 1000,
                'owner_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'path' => '2026/11/b.png',
                'disk' => 'public',
                'filename' => 'b.png',
                'mime_type' => 'image/png',
                'size_bytes' => 2000,
                'owner_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'path' => '2026/11/c.pdf',
                'disk' => 'public',
                'filename' => 'c.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 500,
                'owner_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_an_unconfigured_runtime_hides_the_surface_and_refuses_the_route(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.mediaCc', false)
            ->assertJsonPath('data.endpoint_reasons.mediaCc', 'connected-service')
            ->assertJsonPath('data.endpoint_gaps', []);

        $this->getJson('/api/v1/pro/media-cc/health')
            ->assertStatus(503)
            ->assertJsonPath('code', 'media_cc_not_configured');
    }

    public function test_the_route_requires_manage_options(): void
    {
        $this->configureWorker();
        $this->fakeHealthyWorker();

        Sanctum::actingAs(User::factory()->create(['role' => 'editor']));

        $this->getJson('/api/v1/pro/media-cc/health')->assertForbidden();
    }

    public function test_it_reports_real_library_numbers_and_unknowns_as_null(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        $this->addFiles();

        $snapshot = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json();

        $this->assertSame(3, $snapshot['attachments']['total']);
        $this->assertSame(2, $snapshot['attachments']['image_count']);
        // No alt-text column: unknown, not zero.
        $this->assertNull($snapshot['attachments']['with_alt']);
        $this->assertNull($snapshot['attachments']['descriptive_filename_count']);
        $this->assertSame(3500, $snapshot['storage']);
        $this->assertNull($snapshot['alt_pct']);
        // One of two images is next-gen.
        $this->assertSame(50, $snapshot['nextgen_pct']);
        // No original-size column: savings cannot be measured.
        $this->assertNull($snapshot['compression']);

        $this->assertSame(0, $snapshot['queue']['pending']);
        $this->assertSame(0, $snapshot['queue']['failed']);
        $this->assertSame([], $snapshot['queue']['recent_failures']);
        $this->assertSame('', $snapshot['schedule']['next_run']);

        // The sweep does not exist yet, so the console must not offer it.
        $this->assertFalse($snapshot['capabilities']['sweep']);

        $this->assertTrue($snapshot['worker']['configured']);
        $this->assertTrue($snapshot['worker']['available']);
        $this->assertSame('3.4.0', $snapshot['worker']['version']);
        $this->assertSame(12.5, $snapshot['worker']['uptime']);
    }

    public function test_an_unreachable_worker_is_reported_not_thrown(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        Http::fake(['*/api/health' => Http::response('', 500)]);

        $snapshot = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json();

        $this->assertFalse($snapshot['worker']['available']);
        $this->assertNull($snapshot['worker']['version']);
        // Local numbers still render beside the failure.
        $this->assertSame(0, $snapshot['attachments']['total']);
    }

    public function test_media_jobs_appear_in_the_queue_and_other_handlers_do_not(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();

        $jobs = $this->app->make(JobStatusStore::class);
        $jobs->queue('media-1', 'media.optimize', []);
        $jobs->markFailed('media-1', new \RuntimeException('sharp failed'));
        $jobs->queue('tool-1', 'tools.execute', []);

        $queue = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json('queue');

        $this->assertSame(1, $queue['failed']);
        $this->assertCount(1, $queue['recent_failures']);
        $this->assertSame('media.optimize', $queue['recent_failures'][0]['handler']);
        $this->assertSame('sharp failed', $queue['recent_failures'][0]['error']);
    }

    public function test_the_manifest_reports_the_surface_once_configured(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.mediaCc', true)
            ->assertJsonPath('data.endpoint_reasons.mediaCc', 'connected-service')
            ->assertJsonPath('data.endpoint_gaps', []);
    }
}
