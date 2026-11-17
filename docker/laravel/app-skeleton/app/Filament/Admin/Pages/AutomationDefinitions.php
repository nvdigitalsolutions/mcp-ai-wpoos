<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Automation\AutomationCatalogue;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;

/**
 * Automation definitions — Proposal 149 §30, `/admin/automations`.
 *
 * READ-ONLY, and the contract row says `writer: console`. Definitions are CRUD
 * in the console by ruling (§13.1): rebuilding the editor in Filament would
 * maintain one page twice, and the graph editor belongs with the canvas. What
 * the panel could not answer was "what automations exist on this estate, and
 * what runs them?" — this page answers that from the same stores the console
 * writes.
 *
 * Both record sets are shown because they answer different halves: a workflow
 * with no trigger runs only when someone fires it, and a trigger names the
 * workflow it runs. A page showing one and not the other would leave the
 * question half-answered.
 *
 * Estate scope comes from `TenantContext`, which `SetTenantContext` pins to this
 * panel's tenant on every request. The reader takes no estate parameter for that
 * reason — there is no second consumer to disagree with.
 */
class AutomationDefinitions extends Page
{
    protected static ?string $slug = 'automations';

    protected static ?string $navigationLabel = 'Automations';

    protected static ?string $title = 'Automation definitions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    // Definitions before the run log, both after the reference catalogues.
    protected static ?int $navigationSort = 75;

    protected string $view = 'filament.admin.automation-definitions';

    /** Free-text filter over workflow titles/tags and trigger names/types/schedules. */
    public string $search = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * The filtered definitions.
     *
     * @return array{workflows: list<array<string, mixed>>, triggers: list<array<string, mixed>>}
     */
    public function rows(): array
    {
        return AutomationCatalogue::filter($this->catalogue(), $this->search);
    }

    /**
     * The unfiltered definitions, so the header can say "showing X of Y".
     *
     * @return array{workflows: list<array<string, mixed>>, triggers: list<array<string, mixed>>}
     */
    public function catalogue(): array
    {
        return AutomationCatalogue::rows(app(ContentStoreInterface::class));
    }

    /**
     * @param array{workflows: list<array<string, mixed>>, triggers: list<array<string, mixed>>} $rows
     * @return array{workflows: int, triggers: int, enabled: int, disabled: int}
     */
    public function totals(array $rows): array
    {
        return AutomationCatalogue::totals($rows);
    }

    /**
     * The per-render workflow ceiling, so the header does not imply "all".
     */
    public function workflowLimit(): int
    {
        return AutomationCatalogue::WORKFLOW_LIMIT;
    }

    /**
     * A trigger's last-fired time as a readable string, or an empty string.
     */
    public function lastFired(int $timestamp): string
    {
        return 0 === $timestamp ? '' : gmdate('Y-m-d H:i:s', $timestamp).' UTC';
    }

    public function clearFilters(): void
    {
        $this->search = '';
    }
}
