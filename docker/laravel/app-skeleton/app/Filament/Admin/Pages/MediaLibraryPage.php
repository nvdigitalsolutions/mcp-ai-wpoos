<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Media\MediaLibrary;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;
use UnitEnum;

/**
 * The estate's media library — Proposal 149 §109.
 *
 * **Reads are the reason it exists; writes are the reason it is now usable.**
 * The panel is this runtime's replacement for the WordPress media library, and
 * without upload, alt editing and delete an estate admin had no way to add or
 * correct an image: the console's Media Studio is a WordPress product whose
 * endpoint is blank on this profile (§109).
 *
 * **Every mutating action audits itself.** Livewire traffic is excluded from the
 * request middleware (plan §25), so `AdminAuditLogger::recordResource()` is
 * called here rather than expected from a middleware pass — the obligation the
 * read-only version avoided by not writing.
 *
 * **One writer.** An upload goes through `FileUploadServiceInterface`, never
 * through `Storage` directly. It sniffs the content and derives the extension
 * from the sniff rather than from the client's filename (OWASP file-upload
 * guidance), so a `.png` that is really a script is stored as what it is or
 * refused. An upload that bypassed it would be a second, weaker gate.
 *
 * The numbers are not computed here. `MediaLibrary` serves the same projection
 * to `GET /api/v1/pro/media-cc/health`, so the panel and the console cannot
 * disagree about how many images have alt text — the failure §105 found between
 * a console and an endpoint, and §106 avoided by extracting the report.
 *
 * What this page deliberately does **not** show:
 *
 *  - **Queue counts.** `nvoos_jobs` carries no estate and the queue is one
 *    worker pool, so a per-estate count would be invented rather than scoped.
 *    The operator's `/ops/queue` is the place for it.
 *  - **Compression savings.** Available and scoped (`MediaLibrary::derivativeBytes`),
 *    but it belongs with the worker health the console already renders; two
 *    surfaces showing the same byte count is how they drift.
 */
class MediaLibraryPage extends Page
{
    use WithFileUploads;
    protected static ?string $slug = 'media';

    protected static ?string $navigationLabel = 'Media';

    protected static ?string $title = 'Media';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // Between Paper Store (30) and Memory (40): content an estate owns, before
    // the things that read it.
    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.admin.media-library';

    /** Filename search. */
    public string $search = '';

    /** Mime prefix — `image`, or a precise type. */
    public string $type = '';

    /** 1-based page of the library. Not `$page`: Livewire reserves that name. */
    public int $pageNumber = 1;

    /**
     * Pending uploads from the file input.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $uploads = array();

    /**
     * Per-file failures from the last upload attempt, already prefixed with the
     * filename the operator chose.
     *
     * @var list<string>
     */
    public array $uploadErrors = array();

    /** The row whose alt text is being edited, or `''`. */
    public string $editingId = '';

    /** The draft alt text for `$editingId`. */
    public string $editingAlt = '';

    /** The row awaiting delete confirmation, or `''`. */
    public string $deletingId = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * A filter changed: the old page number is meaningless.
     */
    public function updatedSearch(): void
    {
        $this->pageNumber = 1;
    }

    public function updatedType(): void
    {
        $this->pageNumber = 1;
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return app(MediaLibrary::class)->summary(TenantContext::current());
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function library(): array
    {
        return app(MediaLibrary::class)->page(
            TenantContext::current(),
            $this->pageNumber,
            $this->search,
            $this->type,
        );
    }

    /**
     * The mime prefixes worth offering. `image` covers the family; the precise
     * types are the ones the alt-coverage and next-gen figures are about.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        return array(
            '' => 'Everything',
            'image' => 'All images',
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WebP',
            'image/avif' => 'AVIF',
            'application/pdf' => 'PDF',
        );
    }

    public function goToPage(int $page): void
    {
        $total = $this->library()['pages'];

        $this->pageNumber = max(1, min(max(1, $total), $page));
    }

    /**
     * The per-file ceiling, for the upload guidance.
     *
     * Read from the same config the upload service is constructed with, so the
     * sentence beside the input cannot promise a size the service refuses.
     */
    public function maxBytes(): int
    {
        return (int) config('oos.upload_max_bytes', FileUploadServiceInterface::DEFAULT_MAX_FILE_SIZE);
    }

    /**
     * Public URL for a stored file, or `''` when the row carries no path.
     *
     * @param array<string, mixed> $row
     */
    public function urlFor(array $row): string
    {
        $path = (string) ($row['path'] ?? '');

        if ('' === $path) {
            return '';
        }

        $disk = (string) ($row['disk'] ?? 'public');

        return Storage::disk('' !== $disk ? $disk : 'public')->url($path);
    }

    /**
     * Store the chosen files in this estate's library.
     *
     * The upload service owns validation (sniffed type, allow-list, size) and the
     * stored filename, so the panel cannot become a weaker second gate. A file it
     * refuses is reported by name and the rest of the batch still goes through —
     * one bad file should not discard four good ones.
     */
    public function upload(): void
    {
        abort_unless(Gate::allows('upload_files'), 403);

        $this->uploadErrors = array();

        if (array() === $this->uploads) {
            Notification::make()
                ->title('Choose at least one file first.')
                ->warning()
                ->send();

            return;
        }

        $service = app(FileUploadServiceInterface::class);
        $tenant = TenantContext::current();
        $stored = 0;

        foreach ($this->uploads as $file) {
            if (! is_object($file) || ! method_exists($file, 'getRealPath')) {
                continue;
            }

            $original = (string) $file->getClientOriginalName();
            $size = (int) $file->getSize();

            $result = $service->upload(
                array(
                    'name' => $original,
                    'type' => (string) $file->getMimeType(),
                    'size' => $size,
                    'tmp_name' => (string) $file->getRealPath(),
                    'error' => UPLOAD_ERR_OK,
                ),
                array(
                    'user_id' => auth()->id(),
                    'tenant_id' => $tenant,
                ),
            );

            if (! ($result['success'] ?? false)) {
                $this->uploadErrors[] = $original.': '.trim((string) ($result['error'] ?? 'Upload failed.'));

                continue;
            }

            ++$stored;

            $this->audit(
                (string) (int) $result['attachment_id'],
                'upload',
                array(),
                array(
                    'filename' => $original,
                    'path' => (string) ($result['path'] ?? ''),
                    'size_bytes' => $size,
                )
            );
        }

        $this->uploads = array();

        if ($stored > 0) {
            $this->pageNumber = 1;
        }

        Notification::make()
            ->title(
                array() === $this->uploadErrors
                    ? sprintf('%d file%s added.', $stored, 1 === $stored ? '' : 's')
                    : sprintf('%d stored, %d refused.', $stored, count($this->uploadErrors))
            )
            ->status(array() === $this->uploadErrors ? 'success' : 'warning')
            ->send();
    }

    public function startEdit(int $id): void
    {
        $row = $this->rowToChange((string) $id);

        if (null === $row) {
            return;
        }

        $this->deletingId = '';
        $this->editingId = (string) $row->id;
        $this->editingAlt = (string) ($row->alt_text ?? '');
    }

    public function cancelEdit(): void
    {
        $this->editingId = '';
        $this->editingAlt = '';
    }

    /**
     * Save the draft alt text.
     *
     * An empty string is a real value: it clears the description, which is what a
     * decorative image wants. WordPress conflates "not set" and "decorative"; the
     * guidance beside the field is how the difference is made usable without a
     * second column to keep in step.
     */
    public function saveAlt(): void
    {
        abort_unless(Gate::allows('edit_posts'), 403);

        $row = $this->rowToChange($this->editingId);

        if (null === $row) {
            return;
        }

        $before = (string) ($row->alt_text ?? '');
        $alt = mb_substr(trim($this->editingAlt), 0, 500);

        if ($alt === $before) {
            $this->cancelEdit();

            return;
        }

        DB::table('nvoos_files')
            ->where('tenant_id', TenantContext::current())
            ->where('id', (int) $row->id)
            ->update(array('alt_text' => $alt, 'updated_at' => now()));

        $this->audit((string) $row->id, 'alt_text', array('alt_text' => $before), array('alt_text' => $alt));

        $this->cancelEdit();

        Notification::make()
            ->title('' === $alt ? 'Alt text cleared.' : 'Alt text saved.')
            ->success()
            ->send();
    }

    public function confirmDelete(int $id): void
    {
        $row = $this->rowToChange((string) $id);

        if (null === $row) {
            return;
        }

        $this->editingId = '';
        $this->deletingId = (string) $row->id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = '';
    }

    /**
     * Remove the row, its derivatives and the stored object.
     *
     * Order matters: the object goes before the row, because an orphaned object is
     * a storage leak while an orphaned row is a broken entry the library keeps
     * offering. If the disk refuses, the row stays so the action can be retried
     * rather than the failure being recorded as success.
     */
    public function delete(): void
    {
        abort_unless(Gate::allows('delete_posts'), 403);

        $row = $this->rowToChange($this->deletingId);

        if (null === $row) {
            return;
        }

        $before = array(
            'filename' => (string) $row->filename,
            'path' => (string) $row->path,
            'mime_type' => (string) ($row->mime_type ?? ''),
            'size_bytes' => (int) ($row->size_bytes ?? 0),
        );

        // Derivatives name the file and nothing cascades them.
        if (Schema::hasTable('nvoos_media_derivatives')) {
            DB::table('nvoos_media_derivatives')->where('file_id', (int) $row->id)->delete();
        }

        $disk = Storage::disk('' !== (string) $row->disk ? (string) $row->disk : 'public');
        $path = (string) $row->path;

        if ('' !== $path && $disk->exists($path) && ! $disk->delete($path)) {
            Notification::make()
                ->title('The file could not be removed from storage; the entry was kept.')
                ->danger()
                ->send();

            return;
        }

        DB::table('nvoos_files')
            ->where('tenant_id', TenantContext::current())
            ->where('id', (int) $row->id)
            ->delete();

        $this->audit((string) $row->id, 'delete', $before, array());

        $this->deletingId = '';
        $this->pageNumber = min($this->pageNumber, max(1, $this->library()['pages']));

        Notification::make()
            ->title('File deleted.')
            ->success()
            ->send();
    }

    /**
     * Resolve the row an action is about, refusing anything outside the estate.
     */
    private function rowToChange(string $id): ?object
    {
        $row = '' === $id
            ? null
            : app(MediaLibrary::class)->find(TenantContext::current(), (int) $id);

        if (null === $row) {
            Notification::make()
                ->title('That file is not in this estate\'s library.')
                ->warning()
                ->send();
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function audit(string $recordId, string $action, array $before, array $after): void
    {
        app(AdminAuditLogger::class)->recordResource('media', $recordId, $action, $before, $after);
    }

    /**
     * Bytes, in the unit a reader can act on.
     */
    public function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * Whether the alt figure is a measurement or a placeholder.
     *
     * WordPress reports 100 when there are no images to measure. Showing that as
     * "100% coverage" would be a true number saying something false.
     */
    public function altIsMeasured(): bool
    {
        return ((int) $this->summary()['image_count']) > 0;
    }
}
