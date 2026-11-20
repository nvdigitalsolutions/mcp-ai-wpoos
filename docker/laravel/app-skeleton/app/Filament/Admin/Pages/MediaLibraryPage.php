<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Media\MediaLibrary;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The estate's media library — Proposal 149 §109.
 *
 * **Read-only, deliberately.** The console owns upload and alt editing; a panel
 * write would need its own `AdminAuditLogger::recordResource()` call first,
 * because Livewire traffic is excluded from the request middleware (plan §25).
 * Reading the library has no such obligation, and the alt-coverage number is
 * most useful *before* someone starts editing.
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
