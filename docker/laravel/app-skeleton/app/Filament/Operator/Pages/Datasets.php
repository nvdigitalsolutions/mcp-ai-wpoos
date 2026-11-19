<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Datasets\DatasetCatalogue;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;

/**
 * Dataset browser — Proposal 149 §95, `/ops/datasets`.
 *
 * The surface §85.1 left `planned` and §91 unblocked. It is a **catalogue**, not
 * a console: it lists what the runtime can ask `datasets-server.huggingface.co`
 * for, the curated datasets the runtime already knows about, and where a token
 * goes when a dataset is gated.
 *
 * READ-ONLY by having no action: browsing never writes, which is why the
 * contract row's `writer` is `none`. There is deliberately no "run this tool"
 * button — that surfaces every argument's validation and every provider error on
 * a page whose job is to describe the catalogue, and the tools are already
 * callable through chat and `POST /api/v1/tools`.
 *
 * Every row is derived: the endpoints from the tools' own `ENDPOINT` constants
 * and the starting points from the tool that answers them, so the page cannot
 * describe a route or a dataset that no longer exists.
 */
class Datasets extends Page
{
    protected static ?string $slug = 'datasets';

    protected static ?string $navigationLabel = 'Datasets';

    protected static ?string $title = 'Datasets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    // Beside the budget page (17): both are reference surfaces about what the
    // runtime may spend — one money, one data.
    protected static ?int $navigationSort = 18;

    protected string $view = 'filament.operator.datasets';

    /** Free-text filter over slug, name, description and endpoint. */
    public string $search = '';

    /** Category filter for the curated list; empty means every category. */
    public string $category = '';

    /**
     * Memoised for the request: the blade calls `rows()` twice (the table and
     * the summary).
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $rows = null;

    /**
     * The panel already refuses non-operators; this repeats the predicate at
     * the page so the check cannot be lost if the panel config changes.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * Every HuggingFace tool the registry serves.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows ??= DatasetCatalogue::rows(
            app(ToolRegistry::class),
            app(ToolReadiness::class)
        );
    }

    /**
     * The rows the search leaves.
     *
     * @return list<array<string, mixed>>
     */
    public function filtered(): array
    {
        return DatasetCatalogue::filter($this->rows(), $this->search);
    }

    /**
     * Totals for the heading — of the whole catalogue, not the filtered view, so
     * "10 tools" does not shrink to "3" because someone typed in the box.
     *
     * @return array{total: int, api: int, static: int}
     */
    public function summary(): array
    {
        return DatasetCatalogue::summary($this->rows());
    }

    /**
     * The curated starting points, narrowed by the category control.
     *
     * @return list<array{dataset: string, category: string, description: string, rows: int}>
     */
    public function recommendations(): array
    {
        return DatasetCatalogue::recommendations($this->category);
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return DatasetCatalogue::categories();
    }

    public function apiBase(): string
    {
        return DatasetCatalogue::apiBase();
    }

    /**
     * Deployment-level token presence only — see the reader's docblock for why
     * the page does not report a per-estate token.
     */
    public function tokenConfigured(): bool
    {
        return DatasetCatalogue::deploymentTokenConfigured();
    }
}
