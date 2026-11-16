<?php

namespace App\Filament\Operator\Pages;

use App\Filament\Concerns\BuildsSettingsForm;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Settings\SettingsService;
use BackedEnum;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Platform settings — Proposal 149 §17, the `/ops` half of the settings surface.
 *
 * Operator-scope fields only: `enable_logging` and the server-fetched
 * `ollama_endpoint`. `SettingsSchema::forSurface('ops')` enforces that, and
 * `writableFor('ops')` accepts exactly the same set.
 *
 * Why not render the estate fields here too: `/ops` is deliberately NOT
 * tenant-scoped (an operator inspects every estate from one panel), so
 * `TenantContext` falls back to `default`. A `default_model` field on this page
 * would therefore edit one estate while looking like a platform setting — the
 * most confusing possible failure, because it would appear to work.
 *
 * Writes go to the platform layer (`SettingsService` routes by scope), so one
 * save applies to every estate.
 */
class Settings extends Page implements HasForms
{
    use BuildsSettingsForm;

    protected static ?string $slug = 'settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Platform settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.settings-form';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    protected function settingsSurface(): string
    {
        return SettingsService::SURFACE_OPS;
    }
}
