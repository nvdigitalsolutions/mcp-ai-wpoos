<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The media library, for one estate — Proposal 149 §109.
 *
 * One projection, two readers: `GET /api/v1/pro/media-cc/health` (what the
 * console's media page renders) and `/admin/{estate}/media` (the ported panel
 * page). The counts lived in `MediaCcController` while it was the only reader;
 * a second reader is how two definitions of "alt coverage" start to disagree
 * (§105 found exactly that between a console and an endpoint). So the numbers
 * live here.
 *
 * `$tenantId` is required, not defaulted (§152 §3 D5): the library is an
 * estate's, and there is no operator variant of this surface.
 *
 * **What is deliberately not scoped here:**
 *
 *  - **Job counts** (`nvoos_jobs`). The durable job store has no estate, and the
 *    queue is one worker pool shared by every estate — the same position §106
 *    records for the command centre's `active_tasks`. A per-estate job count
 *    would be an invented number rather than a scoped one.
 *  - **Derivative savings** *are* scoped, but by joining to the file rather than
 *    by adding a column: `nvoos_media_derivatives.file_id` already names the
 *    estate's file, and a second copy of the estate on the derivative would be a
 *    second truth to keep in step (§152 §9.3). See {@see self::derivativeBytes()}.
 *
 * A missing table is a measured zero, not an error: the runtime tables are
 * created by guarded migrations, and a load order that has not run them yet must
 * not blank the page.
 */
final class MediaLibrary
{
    /**
     * Filename prefixes WordPress treats as generic (`IMG_1234`, `screenshot-…`).
     *
     * Moved here from `MediaCcController` so the panel and the API apply the same
     * heuristic to the same library.
     */
    public const GENERIC_PREFIXES = [
        'img_',
        'IMG_',
        'screenshot-',
        'Screenshot_',
        'image-',
        'Image_',
        'photo-',
        'Photo_',
        'dsc_',
        'DSC_',
    ];

    /** Rows per page on the panel. */
    public const PER_PAGE = 25;

    /**
     * Library totals for one estate.
     *
     * @return array{total: int, image_count: int, with_alt: int, descriptive: int,
     *               storage: int, nextgen_pct: int, alt_pct: int}
     */
    public function summary(string $tenantId): array
    {
        $empty = array(
            'total' => 0,
            'image_count' => 0,
            'with_alt' => 0,
            'descriptive' => 0,
            'storage' => 0,
            'nextgen_pct' => 0,
            'alt_pct' => 100,
        );

        if (! Schema::hasTable('nvoos_files')) {
            return $empty;
        }

        $images = fn () => $this->files($tenantId)->whereIn('mime_type', MediaSweeper::IMAGE_MIMES);

        $imageCount = (int) $images()->count();
        $withAlt = (int) $images()
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->count();

        $descriptive = (int) $images()
            ->where(static function ($query): void {
                foreach (self::GENERIC_PREFIXES as $prefix) {
                    $query->where('filename', 'not like', $prefix.'%');
                }
            })
            ->count();

        $nextgen = (int) $images()
            ->whereIn('mime_type', array('image/webp', 'image/avif'))
            ->count();

        return array(
            'total' => (int) $this->files($tenantId)->count(),
            'image_count' => $imageCount,
            'with_alt' => $withAlt,
            'descriptive' => $descriptive,
            'storage' => (int) $this->files($tenantId)->sum('size_bytes'),
            'nextgen_pct' => $imageCount > 0 ? (int) round($nextgen / $imageCount * 100) : 0,
            // WordPress reports 100 when there is nothing to measure; mirror it
            // rather than dividing by zero, and say so on the panel.
            'alt_pct' => $imageCount < 1 ? 100 : (int) round($withAlt / $imageCount * 100),
        );
    }

    /**
     * One page of the estate's files, newest first.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function page(string $tenantId, int $page = 1, string $search = '', string $type = ''): array
    {
        $page = max(1, $page);
        $search = trim($search);
        $type = trim($type);

        if (! Schema::hasTable('nvoos_files')) {
            return array('rows' => array(), 'total' => 0, 'page' => $page, 'pages' => 0, 'per_page' => self::PER_PAGE);
        }

        $query = $this->files($tenantId);

        if ('' !== $search) {
            $query->where('filename', 'like', '%'.$search.'%');
        }

        if ('' !== $type) {
            // `image` matches the family; `image/png` narrows it — the same
            // contract `MediaController::index` serves the console.
            $query->where('mime_type', 'like', $type.'%');
        }

        $total = (int) (clone $query)->count();

        $rows = $query->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get(array('id', 'filename', 'mime_type', 'size_bytes', 'alt_text', 'created_at'));

        return array(
            'rows' => array_map(
                static fn (object $row): array => array(
                    'id' => (int) $row->id,
                    'filename' => (string) $row->filename,
                    'mime_type' => (string) ($row->mime_type ?? ''),
                    'size_bytes' => (int) ($row->size_bytes ?? 0),
                    'alt_text' => (string) ($row->alt_text ?? ''),
                    'created_at' => (string) ($row->created_at ?? ''),
                ),
                $rows->all(),
            ),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'per_page' => self::PER_PAGE,
        );
    }

    /**
     * Recorded derivative savings for one estate, in bytes.
     *
     * Scoped through the file rather than through a column on the derivative:
     * `nvoos_media_derivatives.file_id` already names an estate's file, so a
     * `tenant_id` copy there would be a second truth to keep in step. Only
     * positive savings count — a file that came out larger is a miss, not a
     * negative saving that offsets the wins.
     */
    public function derivativeBytes(string $tenantId): int
    {
        if (! Schema::hasTable('nvoos_media_derivatives') || ! Schema::hasTable('nvoos_files')) {
            return 0;
        }

        return (int) DB::table('nvoos_media_derivatives as d')
            ->join('nvoos_files as f', 'f.id', '=', 'd.file_id')
            ->where('f.tenant_id', $tenantId)
            ->where('d.savings_bytes', '>', 0)
            ->sum('d.savings_bytes');
    }

    /**
     * The estate's files, and nothing else.
     *
     * The estate filter is applied here rather than at each call site so a new
     * reader cannot forget it — which is the failure `SurfaceOwnershipTest` and
     * §41 both exist to prevent.
     */
    private function files(string $tenantId): \Illuminate\Database\Query\Builder
    {
        return DB::table('nvoos_files')->where('tenant_id', $tenantId);
    }
}
