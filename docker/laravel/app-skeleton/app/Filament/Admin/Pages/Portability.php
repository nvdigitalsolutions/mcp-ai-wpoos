<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\Assistant;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Services\AssistantPortability;
use App\Support\Admin\AdminAuditLogger;
use App\Support\BundleException;
use App\Support\CapabilityMap;
use App\Support\TenantContext;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Assistant export / import — Proposal 149 §54, `/admin/portability`.
 *
 * **Estate-scoped, and that is the slice.** The console's route pair was
 * unscoped in three separate ways (export listed every estate, an import
 * overwrite could resolve another estate's assistant, and a created assistant
 * ignored the caller's estate). The repair moves the whole surface into
 * {@see AssistantPortability}, which reads through an estate-scoped query and
 * writes through {@see \App\Services\AssistantWriter}. This page is the second
 * caller of that service, so there is no second import path to keep in step —
 * the same rule §46 applies to every write surface.
 *
 * Two consequences worth stating on the page itself:
 *
 * - **A bundle is a document, not authority.** The estate comes from the
 *   runtime, never from the JSON, so a pasted bundle claiming another
 *   `tenant_id` imports into *this* estate.
 * - **Export is a read that moves data out.** It renders the bundle in a
 *   textarea rather than handing the browser a download, so the operator can see
 *   exactly what is leaving; nothing is stored server-side.
 *
 * Import is the only mutation, and it is recorded as a record-level audit row
 * because `AuditAdminRequests` skips `/livewire/update`. The audit row carries
 * counts and the conflict mode, never the bundle.
 */
class Portability extends Page
{
    protected static ?string $slug = 'portability';

    protected static ?string $navigationLabel = 'Portability';

    protected static ?string $title = 'Assistant export / import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Workspace;

    // Beside blueprints: both install or replace assistants.
    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.admin.portability';

    /**
     * Assistant ids ticked for export, as strings because a checkbox binding
     * submits strings. Empty means "every assistant in this estate".
     *
     * @var list<string>
     */
    public array $selected = array();

    /** The rendered bundle, or '' before the first export. */
    public string $bundle = '';

    /** The pasted bundle document. */
    public string $importJson = '';

    /** Conflict mode: skip | overwrite | duplicate. */
    public string $mode = 'skip';

    /** Preview first: the default, because Import replaces assistants. */
    public bool $dryRun = true;

    /** Last import report, dry or real: `['dry_run','imported',…,'assistants']`. */
    public ?array $report = null;

    /** Last outcome: `['tone' => 'ok'|'error', 'message' => string]`. */
    public ?array $notice = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(AdminAccessPolicy::class)->accessTenantPanel($user)) {
            return false;
        }

        // Same gate as the console's `can:manage_options` group around
        // `POST /assistants/export` and `/import`. A bulk export of every
        // prompt in an estate is not a viewer action.
        return CapabilityMap::has($user, 'manage_options');
    }

    /**
     * The estate's assistants, newest first by title.
     *
     * @return list<array{id:int, title:string, slug:string, status:string, is_default:bool}>
     */
    public function assistants(): array
    {
        return Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->orderBy('title')
            ->get(array('id', 'title', 'slug', 'status', 'is_default'))
            ->map(static fn (Assistant $assistant): array => array(
                'id' => (int) $assistant->id,
                'title' => (string) $assistant->title,
                'slug' => (string) $assistant->slug,
                'status' => (string) $assistant->status,
                'is_default' => (bool) $assistant->is_default,
            ))
            ->all();
    }

    /**
     * Render a bundle for the ticked assistants, or for the whole estate.
     */
    public function export(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));

        try {
            $bundle = app(AssistantPortability::class)->export([] === $ids ? null : $ids);
        } catch (BundleException $exception) {
            $this->bundle = '';
            $this->notice = array('tone' => 'error', 'message' => $exception->getMessage());

            return;
        }

        $this->bundle = (string) json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->notice = array(
            'tone' => 'ok',
            'message' => sprintf(
                'Exported %d assistant(s) from this estate. The bundle is below; nothing was stored on the server.',
                count($bundle['assistants'])
            ),
        );
    }

    public function clearBundle(): void
    {
        $this->bundle = '';
    }

    /**
     * Dry run: report what an import would do, without writing.
     */
    public function preview(): void
    {
        $this->runImport(true);
    }

    /**
     * Import, replacing or duplicating as the mode says.
     */
    public function import(): void
    {
        $this->runImport(false);
    }

    public function resetImport(): void
    {
        $this->importJson = '';
        $this->report = null;
        $this->notice = null;
    }

    /**
     * @param bool $dryRun True for a preview, false for the write.
     */
    private function runImport(bool $dryRun): void
    {
        if ('' === trim($this->importJson)) {
            $this->report = null;
            $this->notice = array('tone' => 'error', 'message' => 'Paste a bundle first.');

            return;
        }

        try {
            $report = app(AssistantPortability::class)->importJson(
                $this->importJson,
                $this->mode,
                $dryRun,
                null
            );
        } catch (BundleException $exception) {
            $this->report = null;
            $this->notice = array('tone' => 'error', 'message' => $exception->getMessage());

            return;
        }

        $this->report = $report;

        if (! $dryRun) {
            $this->audit($report);
        }

        $this->notice = array(
            'tone' => 'ok',
            'message' => sprintf(
                '%s: %d imported, %d updated, %d skipped.',
                $dryRun ? 'Preview (nothing written)' : 'Imported',
                $report['imported'],
                $report['updated'],
                $report['skipped']
            ),
        );
    }

    /**
     * Record the import. Counts and mode only — never the bundle, which can
     * hold prompts and arbitrary meta.
     *
     * @param array<string, mixed> $report
     */
    private function audit(array $report): void
    {
        app(AdminAuditLogger::class)->recordResource(
            'portability',
            'bundle',
            'import',
            array(),
            array(
                'mode' => $this->mode,
                'imported' => (int) $report['imported'],
                'updated' => (int) $report['updated'],
                'skipped' => (int) $report['skipped'],
            )
        );
    }
}
