<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\ProfessionCatalogue;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

/**
 * The profession catalogue — Proposal 149 §23, `/admin/professions`.
 *
 * READ-ONLY on this surface, and the contract row says so. Professions are
 * code-owned reference data served from a directory of JSON documents; the
 * write path (`save_profession`) is WordPress-side and is deliberately not
 * registered on this runtime. A page with a create button would either lie
 * about persistence or invent a second writer for a read-only catalogue.
 *
 * The console already had this catalogue (`GET /api/v1/professions`) because
 * its assistant editor needs a profession selector. The panel had no view of
 * it, so a tenant administrator could not answer "which professions exist, and
 * what does this runtime think they recommend?" without opening the console.
 * This page answers that, from the same reader, so the two cannot disagree.
 *
 * The catalogue version is rendered in the header: professions are versioned
 * reference data, and "which generation is this runtime serving" is the first
 * question when a definition looks stale.
 */
class Professions extends Page
{
    protected static ?string $slug = 'professions';

    protected static ?string $navigationLabel = 'Professions';

    protected static ?string $title = 'Profession catalogue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // Last of the reference catalogues: professions classify the records the
    // other surfaces hold.
    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.admin.professions';

    /** Free-text filter over slug, title, category and description. */
    public string $search = '';

    /** Category-slug filter; empty means every category. */
    public string $category = '';

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
        return ProfessionCatalogue::filter(
            $this->catalogue(),
            $this->search,
            $this->category
        );
    }

    /**
     * The unfiltered catalogue, so the category filter does not shrink to the
     * option already chosen.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        return ProfessionCatalogue::rows(app(ProfessionRepositoryInterface::class));
    }

    /**
     * Categories present in the catalogue, as slug => label.
     *
     * @return array<string, string>
     */
    public function categories(): array
    {
        return ProfessionCatalogue::categories($this->catalogue());
    }

    /**
     * Counts for the filtered set, so the header never claims more than is shown.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, tools: int}
     */
    public function totals(array $rows): array
    {
        return ProfessionCatalogue::totals($rows);
    }

    /**
     * The catalogue generation this runtime is serving.
     */
    public function catalogueVersion(): string
    {
        return app(ProfessionRepositoryInterface::class)->catalogueVersion();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->category = '';
    }
}
