<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\ToolCatalogue;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;

/**
 * The tool catalogue — Proposal 149 §22, `/admin/tools`.
 *
 * READ-ONLY on both surfaces. `tools` declares `writer: none`, so this page has
 * no action of any kind: the console's tool page and this one are two renderings
 * of one reader (`App\Support\ToolCatalogue`), which is also what
 * `GET /api/v1/tools` returns.
 *
 * The catalogue is NOT reduced for the console, and that is a correction to the
 * matrix rather than an omission. The earlier note proposed projecting the
 * console down to "the assistant's enabled tools plus presets" — but
 * `ToolPicker` exists precisely so an administrator can enable a tool the
 * assistant does not have yet, and it reads this same payload. A catalogue is a
 * reference list, not a tenant-owned record set; the BOPLA concern behind
 * `projection` does not apply to it.
 *
 * Why Blade rather than a Filament table: the rows come from the registry as
 * arrays, and the page's whole job is a search box plus a readiness filter over
 * a few hundred rows. A plain table is verifiable from a test (`assertSee`) and
 * cannot silently lose its data source. Search and filtering are server-side so
 * the page reports what is actually servable rather than what the client chose
 * to render.
 */
class Tools extends Page
{
    protected static ?string $slug = 'tools';

    protected static ?string $navigationLabel = 'Tools';

    protected static ?string $title = 'Tool catalogue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    // Below the resources, above the run log and settings: a reference page.
    protected static ?int $navigationSort = 70;

    protected string $view = 'filament.admin.tools';

    /** Free-text filter over slug, name and description. */
    public string $search = '';

    /** Readiness-class filter; empty means every class. */
    public string $readiness = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * The filtered catalogue.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return ToolCatalogue::filter(
            ToolCatalogue::rows(app(ToolRegistry::class), app(ToolReadiness::class)),
            $this->search,
            $this->readiness
        );
    }

    /**
     * Counts for the filtered set, so the header never claims more than is shown.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, servable: int}
     */
    public function summary(array $rows): array
    {
        return ToolCatalogue::summary($rows);
    }

    /**
     * @return array<string, string>
     */
    public function readinessOptions(): array
    {
        return ToolCatalogue::readinessOptions();
    }

    /**
     * Whether a class may actually be served by this runtime.
     */
    public function isServable(string $readiness): bool
    {
        return ToolReadiness::isServableClass($readiness);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->readiness = '';
    }
}
