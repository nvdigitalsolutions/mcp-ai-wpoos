<?php

namespace Tests\Feature;

use App\Jobs\OptimizeMediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Tests\TestCase;

/**
 * 090-j — the media command centre and its processing pipeline.
 *
 * The contract that matters is what a number *means*:
 *
 *  - Library numbers are real, and the image list and filename heuristic mirror
 *    WordPress's own gatherers so the same library reports the same percentages.
 *  - `compression` is the sum of **recorded derivative savings** — a saving is
 *    only ever reported once the optimised bytes exist.
 *  - A worker that is down does not throw: the local numbers still render
 *    beside an honest `available: false`.
 *  - With no worker configured the surface is hidden entirely; the route still
 *    answers 503, because a stale bookmark deserves a reason.
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

    private function addFile(string $filename, string $mime, int $bytes, ?string $alt = null): int
    {
        return (int) DB::table('nvoos_files')->insertGetId([
            'path' => '2026/11/'.$filename,
            'disk' => 'public',
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => $bytes,
            'alt_text' => $alt,
            'owner_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addFiles(): void
    {
        $this->addFile('a.webp', 'image/webp', 1000);
        $this->addFile('b.png', 'image/png', 2000);
        $this->addFile('c.pdf', 'application/pdf', 500);
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

    public function test_the_surface_requires_manage_options(): void
    {
        $this->configureWorker();
        $this->fakeHealthyWorker();

        Sanctum::actingAs(User::factory()->create(['role' => 'editor']));

        $this->getJson('/api/v1/pro/media-cc/health')->assertForbidden();
        $this->postJson('/api/v1/pro/media-cc/compression-sweep')->assertForbidden();
    }

    public function test_it_reports_real_library_numbers(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        $this->addFiles();

        $snapshot = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json();

        $this->assertSame(3, $snapshot['attachments']['total']);
        $this->assertSame(2, $snapshot['attachments']['image_count']);
        // No image has alt text yet.
        $this->assertSame(0, $snapshot['attachments']['with_alt']);
        $this->assertSame(0, $snapshot['alt_pct']);
        // Both filenames are descriptive; `IMG_0001.png` would not be.
        $this->assertSame(2, $snapshot['attachments']['descriptive_filename_count']);
        $this->assertSame(3500, $snapshot['storage']);
        // One of two images is next-gen.
        $this->assertSame(50, $snapshot['nextgen_pct']);
        $this->assertSame(0, $snapshot['compression']);

        $this->assertSame(0, $snapshot['queue']['pending']);
        $this->assertSame(0, $snapshot['queue']['failed']);
        $this->assertSame([], $snapshot['queue']['recent_failures']);
        $this->assertSame('', $snapshot['schedule']['next_run']);

        // The sweep exists now, so the console offers it.
        $this->assertTrue($snapshot['capabilities']['sweep']);

        $this->assertTrue($snapshot['worker']['configured']);
        $this->assertTrue($snapshot['worker']['available']);
        $this->assertSame('3.4.0', $snapshot['worker']['version']);
        $this->assertSame(12.5, $snapshot['worker']['uptime']);
    }

    public function test_alt_text_moves_the_coverage_metric(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();

        $this->addFile('a.webp', 'image/webp', 1000, 'A described image');
        $this->addFile('b.png', 'image/png', 2000);

        $snapshot = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json();

        $this->assertSame(1, $snapshot['attachments']['with_alt']);
        $this->assertSame(50, $snapshot['alt_pct']);
    }

    public function test_alt_coverage_is_100_when_there_are_no_images(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();

        $this->addFile('report.pdf', 'application/pdf', 500);

        // WordPress reports 100 when there is nothing to measure; mirror it.
        $this->assertSame(100, $this->getJson('/api/v1/pro/media-cc/health')->json('alt_pct'));
    }

    public function test_compression_is_the_sum_of_positive_recorded_savings(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();

        $first = $this->addFile('a.webp', 'image/webp', 1000);
        $second = $this->addFile('b.png', 'image/png', 2000);

        DB::table('nvoos_media_derivatives')->insert([
            [
                'file_id' => $first,
                'format' => 'webp',
                'path' => 'derived/'.$first.'.webp',
                'size_bytes' => 300,
                'savings_bytes' => 700,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                // A miss: the "optimised" file came out larger. It must not
                // offset the win above.
                'file_id' => $second,
                'format' => 'webp',
                'path' => 'derived/'.$second.'.webp',
                'size_bytes' => 2100,
                'savings_bytes' => -100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(700, $this->getJson('/api/v1/pro/media-cc/health')->json('compression'));
    }

    public function test_a_sweep_queues_each_unprocessed_image(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        Queue::fake();
        $this->addFiles();

        $this->postJson('/api/v1/pro/media-cc/compression-sweep')
            ->assertOk()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('message', '2 files queued for optimisation.');

        Queue::assertPushed(OptimizeMediaFile::class, 2);

        $this->assertSame(2, DB::table('nvoos_jobs')->where('handler', 'media.optimize')->count());
        $this->assertSame(2, DB::table('nvoos_jobs')->where('status', 'queued')->count());

        // The queue is visible immediately.
        $this->assertSame(
            2,
            $this->getJson('/api/v1/pro/media-cc/health')->json('queue.pending'),
        );
    }

    public function test_a_sweep_skips_files_that_already_have_a_derivative(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        Queue::fake();

        $done = $this->addFile('a.webp', 'image/webp', 1000);
        $this->addFile('b.png', 'image/png', 2000);

        DB::table('nvoos_media_derivatives')->insert([
            'file_id' => $done,
            'format' => 'webp',
            'path' => 'derived/'.$done.'.webp',
            'size_bytes' => 300,
            'savings_bytes' => 700,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/pro/media-cc/compression-sweep')
            ->assertOk()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('message', '1 file queued for optimisation.');

        Queue::assertPushed(OptimizeMediaFile::class, 1);
    }

    public function test_a_sweep_is_refused_while_one_is_in_flight(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        Queue::fake();
        $this->addFiles();

        $this->app->make(JobStatusStore::class)->queue('media.optimize:1:webp', 'media.optimize', []);
        $this->app->make(JobStatusStore::class)->markRunning('media.optimize:1:webp');

        $this->postJson('/api/v1/pro/media-cc/compression-sweep')
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('message', 'A compression sweep is already running.');

        Queue::assertNothingPushed();
    }

    public function test_a_sweep_with_nothing_to_do_says_so(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        Queue::fake();

        $this->postJson('/api/v1/pro/media-cc/compression-sweep')
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('message', 'Nothing to optimise.');
    }

    public function test_the_job_writes_a_derivative_and_reports_the_saving(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        Storage::fake('public');

        $id = $this->addFile('hero.png', 'image/png', 1000);
        Storage::disk('public')->put('2026/11/hero.png', 'original-bytes');

        Http::fake([
            '*/api/image/optimize' => Http::response([
                'success' => true,
                'original_size' => 1000,
                'optimized_size' => 300,
                'savings_bytes' => 700,
                'format' => 'webp',
                'b64' => base64_encode('optimized-bytes'),
            ]),
        ]);

        $jobs = $this->app->make(JobStatusStore::class);
        $jobs->queue(OptimizeMediaFile::jobId($id), 'media.optimize', ['file_id' => $id]);

        OptimizeMediaFile::dispatchSync($id);

        $derivative = DB::table('nvoos_media_derivatives')->where('file_id', $id)->first();

        $this->assertNotNull($derivative);
        $this->assertSame('webp', $derivative->format);
        $this->assertSame(300, (int) $derivative->size_bytes);
        $this->assertSame(700, (int) $derivative->savings_bytes);
        $this->assertTrue(Storage::disk('public')->exists('derived/'.$id.'.webp'));
        $this->assertSame('optimized-bytes', Storage::disk('public')->get('derived/'.$id.'.webp'));

        $this->assertSame('completed', $jobs->get(OptimizeMediaFile::jobId($id))->status);

        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/image/optimize'));

        // And the snapshot now reports the saving.
        Http::fake(['*/api/health' => Http::response(['status' => 'ok', 'version' => '3.4.0'])]);
        $this->assertSame(700, $this->getJson('/api/v1/pro/media-cc/health')->json('compression'));
    }

    public function test_the_job_skips_non_image_files(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        Storage::fake('public');

        $id = $this->addFile('report.pdf', 'application/pdf', 500);
        Storage::disk('public')->put('2026/11/report.pdf', 'pdf');

        Http::fake();

        $jobs = $this->app->make(JobStatusStore::class);
        $jobs->queue(OptimizeMediaFile::jobId($id), 'media.optimize', ['file_id' => $id]);

        OptimizeMediaFile::dispatchSync($id);

        $this->assertSame(0, DB::table('nvoos_media_derivatives')->count());
        $this->assertSame('cancelled', $jobs->get(OptimizeMediaFile::jobId($id))->status);
        Http::assertNothingSent();
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

    public function test_the_schedule_is_inactive_without_a_cron_expression(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();

        $schedule = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json('schedule');

        $this->assertSame(0, $schedule['active']);
        $this->assertSame('', $schedule['next_run']);
        $this->assertSame(0, $schedule['ran_today']);
    }

    public function test_the_schedule_reports_the_configured_expression(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        config(['oos.media_worker.sweep_schedule' => '0 3 * * *']);

        $schedule = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json('schedule');

        $this->assertSame(1, $schedule['active']);
        $this->assertStringEndsWith(' 03:00:00', $schedule['next_run']);
    }

    public function test_ran_today_counts_completed_scheduled_sweeps_only(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        config(['oos.media_worker.sweep_schedule' => '0 3 * * *']);

        DB::table('nvoos_jobs')->insert([
            [
                'id' => 'media.sweep:today',
                'handler' => 'media.sweep',
                'payload' => null,
                'status' => 'completed',
                'attempts' => 1,
                'queued_at' => now(),
                'completed_at' => now(),
            ],
            [
                // A cancelled slot (another sweep was running) is not a run.
                'id' => 'media.sweep:cancelled',
                'handler' => 'media.sweep',
                'payload' => null,
                'status' => 'cancelled',
                'attempts' => 1,
                'queued_at' => now(),
                'completed_at' => now(),
            ],
        ]);

        $this->assertSame(
            1,
            $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json('schedule.ran_today'),
        );
    }

    public function test_a_missed_slot_is_reported_as_overdue(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        config(['oos.media_worker.sweep_schedule' => '* * * * *']);

        DB::table('nvoos_jobs')->insert([
            'id' => 'media.sweep:old',
            'handler' => 'media.sweep',
            'payload' => null,
            'status' => 'completed',
            'attempts' => 1,
            'queued_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
        ]);

        $schedule = $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json('schedule');

        // The most recent slot was a minute ago; the last run was two hours ago.
        $this->assertSame(1, $schedule['overdue']);
    }

    public function test_a_first_run_is_not_overdue(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        config(['oos.media_worker.sweep_schedule' => '* * * * *']);

        // Nothing has run yet, so no slot has been missed.
        $this->assertSame(
            0,
            $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json('schedule.overdue'),
        );
    }

    public function test_the_command_queues_a_sweep_and_records_its_run(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        config(['oos.media_worker.sweep_schedule' => '0 3 * * *']);
        Queue::fake();
        $this->addFiles();

        $this->artisan('nvoos:sweep-media')->assertSuccessful();

        Queue::assertPushed(OptimizeMediaFile::class, 2);

        $record = DB::table('nvoos_jobs')->where('handler', 'media.sweep')->first();

        $this->assertNotNull($record);
        $this->assertSame('completed', $record->status);

        $this->assertSame(
            1,
            $this->getJson('/api/v1/pro/media-cc/health')->assertOk()->json('schedule.ran_today'),
        );
    }

    public function test_the_command_records_a_refused_sweep_as_cancelled(): void
    {
        $this->actAsAdmin();
        $this->configureWorker();
        $this->fakeHealthyWorker();
        config(['oos.media_worker.sweep_schedule' => '0 3 * * *']);
        Queue::fake();
        $this->addFiles();

        $jobs = $this->app->make(JobStatusStore::class);
        $jobs->queue('media.optimize:1:webp', 'media.optimize', []);
        $jobs->markRunning('media.optimize:1:webp');

        $this->artisan('nvoos:sweep-media')->assertSuccessful();

        Queue::assertNothingPushed();

        $this->assertSame(
            1,
            DB::table('nvoos_jobs')->where('handler', 'media.sweep')->where('status', 'cancelled')->count(),
        );
    }
}
