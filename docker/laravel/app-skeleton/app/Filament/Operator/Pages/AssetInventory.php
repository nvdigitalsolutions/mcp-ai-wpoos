<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Assets\AssetRegister;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

/**
 * Information-asset register — Proposal 149 §33, `/ops/assets`.
 *
 * READ-ONLY and derived. Nothing is stored: the register is computed from what
 * the runtime actually contains on each load (tables that exist and their row
 * counts, configured credentials, registered providers, shipped packages and
 * contracts). A stored snapshot would go stale the moment a migration ran, and a
 * stale inventory in a compliance register is worse than none.
 *
 * Operator-only and cross-estate: an information-asset register describes the
 * deployment, not one estate's slice of it. A per-estate view is a filter on
 * ownership, not a different register — the `owner` column carries that.
 *
 * Credential assets record **presence only**. `SettingsStoreInterface::getApiKey()`
 * returns the value to the caller, so it is read transiently to reduce to a
 * boolean and is never stored, logged or rendered; the page cannot show a key
 * because the register never keeps one.
 */
class AssetInventory extends Page
{
    protected static ?string $slug = 'assets';

    protected static ?string $navigationLabel = 'Asset inventory';

    protected static ?string $title = 'Information-asset register';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // With the other operator diagnostics, before site health.
    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.operator.asset-inventory';

    /** Free-text filter over name, type, owner, location and classification. */
    public string $search = '';

    /** Classification filter; empty means every level. */
    public string $classification = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * The filtered register, highest classification first.
     *
     * @return list<array<string, mixed>>
     */
    public function assets(): array
    {
        return AssetRegister::filter($this->register(), $this->search, $this->classification);
    }

    /**
     * The unfiltered register, so the header can say "showing X of Y".
     *
     * @return list<array<string, mixed>>
     */
    public function register(): array
    {
        return AssetRegister::assets(app(SettingsStoreInterface::class));
    }

    /**
     * @param list<array<string, mixed>> $assets
     * @return array{total: int, by_classification: array<string, int>, by_type: array<string, int>, highest: string}
     */
    public function statistics(array $assets): array
    {
        return AssetRegister::statistics($assets);
    }

    /**
     * @return array<string, string>
     */
    public function classifications(): array
    {
        return AssetRegister::CLASSIFICATIONS;
    }

    /**
     * @return array<string, string>
     */
    public function types(): array
    {
        return AssetRegister::TYPES;
    }

    public function classificationLabel(string $classification): string
    {
        return (string) (AssetRegister::CLASSIFICATIONS[$classification] ?? $classification);
    }

    public function typeLabel(string $type): string
    {
        return (string) (AssetRegister::TYPES[$type] ?? $type);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->classification = '';
    }
}
