<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\MediaLibraryPage;
use App\Http\Controllers\MediaController;
use App\Jobs\OptimizeMediaFile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaSweeper;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §109 — the media library acquires an estate.
 *
 * The slice exists because `nvoos_files` had no tenant while its only readers
 * ran one-estate-per-deployment: `/api/v1` pins one estate, so listing the table
 * listed one estate's files. Putting the library on `/admin` — and scoping the
 * compression sweep, which selects across the whole table — turns that from
 * invisible into a leak.
 *
 * Four surfaces, four tests, because any one alone leaves a hole: the panel, the
 * library projection, the REST controller (read *and* write), and the two job
 * paths that used to record on `default`.
 */
final class AdminMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_it_requires_the_tenant_panel(): void
    {
        $this->actingAs(User::factory()->create(array('role' => 'subscriber')));

        $this->assertFalse(MediaLibraryPage::canAccess());
    }

    public function test_the_page_lists_only_the_current_estates_files(): void
    {
        $this->estate('estate-b');

        $this->addFile(Tenant::DEFAULT_ID, 'own.png', 'image/png', 1000);
        $this->addFile('estate-b', 'foreign.png', 'image/png', 1000);

        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(MediaLibraryPage::class)
            ->assertSee('own.png')
            ->assertDontSee('foreign.png');
    }

    public function test_alt_coverage_is_measured_per_estate(): void
    {
        $this->estate('estate-b');

        $this->addFile(Tenant::DEFAULT_ID, 'no-alt.png', 'image/png', 1000);
        $this->addFile('estate-b', 'with-alt.png', 'image/png', 1000, 'A described image');

        $own = app(MediaLibrary::class)->summary(Tenant::DEFAULT_ID);
        $foreign = app(MediaLibrary::class)->summary('estate-b');

        // The foreign estate's described image must not raise this estate's
        // coverage — the figure is the reason the column exists.
        $this->assertSame(1, $own['image_count']);
        $this->assertSame(0, $own['with_alt']);
        $this->assertSame(0, $own['alt_pct']);

        $this->assertSame(1, $foreign['with_alt']);
        $this->assertSame(100, $foreign['alt_pct']);
    }

    public function test_an_empty_library_reports_a_dash_rather_than_100_percent(): void
    {
        $this->actingAsEstate(Tenant::DEFAULT_ID);

        // WordPress reports 100 when there is nothing to divide by. The page
        // keeps that for the API's benefit but labels it, rather than showing a
        // clean bill of health.
        Livewire::test(MediaLibraryPage::class)
            ->assertSee('No images to measure')
            ->assertSee('nothing to measure');
    }

    public function test_the_library_pagination_is_scoped(): void
    {
        $this->estate('estate-b');

        $this->addFile(Tenant::DEFAULT_ID, 'own.png', 'image/png', 1000);
        $this->addFile('estate-b', 'foreign.png', 'image/png', 1000);

        $page = app(MediaLibrary::class)->page('estate-b');

        $this->assertSame(1, $page['total']);
        $this->assertSame('foreign.png', $page['rows'][0]['filename']);
    }

    public function test_a_foreign_file_id_answers_404_on_read_and_write(): void
    {
        $this->estate('estate-b');

        $foreignId = $this->addFile('estate-b', 'foreign.png', 'image/png', 1000, 'theirs');

        TenantContext::set(Tenant::DEFAULT_ID);

        $controller = app(MediaController::class);

        $read = $controller->show($foreignId);
        $this->assertSame(404, $read->getStatusCode());
        $this->assertSame('rest_post_invalid_id', $read->getData(true)['code']);

        $write = $controller->update($foreignId, Request::create('/', 'PATCH', array('alt_text' => 'mine now')));
        $this->assertSame(404, $write->getStatusCode());

        // And the 404 is not a lie: the row is untouched.
        $this->assertSame(
            'theirs',
            DB::table('nvoos_files')->where('id', $foreignId)->value('alt_text'),
        );
    }

    public function test_the_sweep_queues_only_the_current_estates_files(): void
    {
        $this->estate('estate-b');

        Queue::fake();

        $ownId = $this->addFile(Tenant::DEFAULT_ID, 'own.png', 'image/png', 1000);
        $this->addFile('estate-b', 'foreign.png', 'image/png', 1000);

        TenantContext::set(Tenant::DEFAULT_ID);

        $result = app(MediaSweeper::class)->sweep();

        $this->assertSame(1, $result['queued']);
        Queue::assertPushed(OptimizeMediaFile::class, 1);
        Queue::assertPushed(
            OptimizeMediaFile::class,
            static fn (OptimizeMediaFile $job): bool => $job->fileId === $ownId,
        );
    }

    public function test_the_optimiser_records_the_files_estate_and_restores_the_context(): void
    {
        $this->estate('estate-b');

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.token' => 'test-token',
        ]);

        Storage::fake('public');
        Storage::disk('public')->put('media/2026/11/foreign.png', 'bytes');

        $id = (int) DB::table('nvoos_files')->insertGetId(array(
            'path' => 'media/2026/11/foreign.png',
            'disk' => 'public',
            'filename' => 'foreign.png',
            'mime_type' => 'image/png',
            'size_bytes' => 5,
            'tenant_id' => 'estate-b',
            'created_at' => now(),
            'updated_at' => now(),
        ));

        Http::fake([
            '*/api/image/optimize' => Http::response(array(
                'success' => true,
                'original_size' => 5,
                'optimized_size' => 2,
                'savings_bytes' => 3,
                'format' => 'webp',
                'b64' => base64_encode('ok'),
            )),
        ]);

        // The worker runs outside a request, so the ambient estate is the default
        // one. The event must still be attributed to the file's estate (§106.2
        // recorded the opposite behaviour as a limitation; this closes it).
        TenantContext::set(Tenant::DEFAULT_ID);

        OptimizeMediaFile::dispatchSync($id);

        $this->assertSame(
            'estate-b',
            DB::table('nvoos_activity_events')
                ->where('type', 'media.optimized')
                ->value('tenant_id'),
        );

        // And the pin did not escape: `QUEUE_CONNECTION=sync` runs this inside
        // the caller's request, so a leaked pin would move that request into
        // another estate's scope.
        $this->assertSame(Tenant::DEFAULT_ID, TenantContext::current());
    }

    private function actingAsEstate(string $tenantId): void
    {
        $tenant = $this->estate($tenantId);

        $this->actingAs($this->estateAdmin($tenantId));
        Filament::setTenant($tenant);
        TenantContext::set($tenantId);
    }

    private function estate(string $tenantId): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array(
                'name' => 'Estate '.$tenantId,
                'slug' => $tenantId,
                'is_operator' => false,
            )
        );
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function addFile(string $tenantId, string $filename, string $mime, int $bytes, ?string $alt = null): int
    {
        return (int) DB::table('nvoos_files')->insertGetId(array(
            'path' => 'media/2026/11/'.$filename,
            'disk' => 'public',
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => $bytes,
            'alt_text' => $alt,
            'owner_id' => 1,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ));
    }
}
