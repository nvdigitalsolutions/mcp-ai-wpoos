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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    /**
     * A real 1x1 PNG. `UploadedFile::fake()->image()` needs GD, which this
     * runtime deliberately does not install, and the upload service sniffs the
     * bytes rather than the extension — so a fake header would be refused.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_an_upload_lands_in_the_current_estate_and_audits_itself(): void
    {
        Storage::fake('public');
        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(MediaLibraryPage::class)
            ->set('uploads', [
                UploadedFile::fake()->createWithContent('Pixel.png', (string) base64_decode(self::PNG, true)),
            ])
            ->call('upload')
            ->assertSet('uploadErrors', []);

        $row = DB::table('nvoos_files')->where('tenant_id', Tenant::DEFAULT_ID)->first();

        $this->assertNotNull($row, 'The upload did not create a row.');
        $this->assertSame('image/png', $row->mime_type);
        $this->assertSame('public', $row->disk);
        // The service names the stored file from the sniffed type, so the
        // client's filename cannot decide the extension.
        //
        // §162.7 delegates the write to `FileStore`, which changed two things:
        // the operator's name is now kept as the **display** name (the media
        // library reads better for it), and the object key is a ULID under
        // `{tenant}/public/…` rather than a slug of the upload. What the old
        // assertion was protecting — the client cannot choose the path or the
        // extension — is asserted directly below and is now stronger.
        $this->assertStringEndsWith('.png', $row->filename);
        $this->assertStringEndsWith('.png', $row->path);
        $this->assertStringNotContainsString('Pixel', (string) $row->path);
        $this->assertStringStartsWith(Tenant::DEFAULT_ID.'/public/', (string) $row->path);
        $this->assertSame('public', $row->visibility);
        $this->assertNotNull($row->checksum, 'The registry records a checksum.');
        Storage::disk('public')->assertExists($row->path);

        $this->assertDatabaseHas('nvoos_admin_audit', [
            'resource' => 'media',
            'record_id' => (string) $row->id,
            'action' => 'upload',
            'tenant_id' => Tenant::DEFAULT_ID,
        ]);
    }

    public function test_a_refused_type_is_named_and_nothing_is_stored(): void
    {
        Storage::fake('public');
        $this->actingAsEstate(Tenant::DEFAULT_ID);

        $component = Livewire::test(MediaLibraryPage::class)
            ->set('uploads', [
                // A ZIP magic number under an image name: the sniff decides.
                UploadedFile::fake()->createWithContent('archive.png', "PK\x03\x04".str_repeat('x', 64)),
            ])
            ->call('upload');

        $errors = $component->get('uploadErrors');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('archive.png', $errors[0]);
        $this->assertSame(0, DB::table('nvoos_files')->count());
    }

    public function test_alt_text_edits_are_audited_with_before_and_after(): void
    {
        $id = $this->addFile(Tenant::DEFAULT_ID, 'shot.png', 'image/png', 1000, 'old');
        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(MediaLibraryPage::class)
            ->call('startEdit', $id)
            ->assertSet('editingId', (string) $id)
            ->assertSet('editingAlt', 'old')
            ->set('editingAlt', 'A red bicycle leaning on a wall')
            ->call('saveAlt')
            ->assertSet('editingId', '');

        $this->assertDatabaseHas('nvoos_files', [
            'id' => $id,
            'alt_text' => 'A red bicycle leaning on a wall',
        ]);

        $audit = DB::table('nvoos_admin_audit')
            ->where('resource', 'media')
            ->where('record_id', (string) $id)
            ->where('action', 'alt_text')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('old', (string) $audit->before);
        $this->assertStringContainsString('red bicycle', (string) $audit->after);
    }

    public function test_alt_text_and_delete_refuse_a_foreign_file(): void
    {
        $this->estate('estate-b');
        $foreignId = $this->addFile('estate-b', 'foreign.png', 'image/png', 1000, 'theirs');

        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(MediaLibraryPage::class)
            ->call('startEdit', $foreignId)
            ->assertSet('editingId', '')
            ->call('confirmDelete', $foreignId)
            ->assertSet('deletingId', '');

        $this->assertDatabaseHas('nvoos_files', [
            'id' => $foreignId,
            'alt_text' => 'theirs',
        ]);
        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    public function test_deleting_removes_the_row_the_object_and_its_derivatives(): void
    {
        Storage::fake('public');

        $id = $this->addFile(Tenant::DEFAULT_ID, 'gone.png', 'image/png', 5);
        Storage::disk('public')->put('media/2026/11/gone.png', 'bytes');

        DB::table('nvoos_media_derivatives')->insert([
            'file_id' => $id,
            'format' => 'webp',
            'path' => 'media/2026/11/gone.webp',
            'size_bytes' => 3,
            'savings_bytes' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(MediaLibraryPage::class)
            ->call('confirmDelete', $id)
            ->assertSet('deletingId', (string) $id)
            ->call('delete')
            ->assertSet('deletingId', '');

        $this->assertDatabaseMissing('nvoos_files', ['id' => $id]);
        $this->assertDatabaseMissing('nvoos_media_derivatives', ['file_id' => $id]);
        Storage::disk('public')->assertMissing('media/2026/11/gone.png');

        $this->assertDatabaseHas('nvoos_admin_audit', [
            'resource' => 'media',
            'record_id' => (string) $id,
            'action' => 'delete',
        ]);
    }

    public function test_a_member_without_upload_files_is_refused(): void
    {
        Storage::fake('public');

        $tenant = $this->estate(Tenant::DEFAULT_ID);
        // A non-viewer membership opens the panel (the policy is membership, not
        // `manage_options`), so the capability gate is the only thing between
        // this user and the writer. A contributor has `edit_posts` but not
        // `upload_files`.
        $user = User::factory()->create(array('role' => 'contributor'));
        $tenant->users()->syncWithoutDetaching(array($user->id => array('role' => 'member')));

        $this->actingAs($user);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        // Precondition: the role, not the panel policy, is what has to refuse.
        $this->assertFalse(Gate::allows('upload_files'), 'A contributor must not hold upload_files.');

        $component = Livewire::test(MediaLibraryPage::class)
            ->set('uploads', [
                UploadedFile::fake()->createWithContent('Pixel.png', (string) base64_decode(self::PNG, true)),
            ]);

        // Invoked on the instance rather than through `call()`: Livewire's test
        // harness turns the abort into a response, and the point here is the
        // guard, not the plumbing.
        $caught = null;

        try {
            $component->instance()->upload();
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(
            \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface::class,
            $caught,
            'Expected a 403, got ' . (null === $caught ? 'no exception' : $caught::class)
        );
        $this->assertSame(403, $caught->getStatusCode());
        $this->assertSame(0, DB::table('nvoos_files')->count());
        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
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
