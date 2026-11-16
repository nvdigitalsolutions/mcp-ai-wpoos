<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\BuildsSettingsForm;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Settings\SettingsService;
use BackedEnum;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Estate settings — Proposal 149 §17, the `/admin` half of the settings surface.
 *
 * This is the full form: every catalogue field is rendered, and the fields the
 * platform owns are shown disabled with the S-1 hint instead of being omitted.
 * The reason to render rather than hide them is that an administrator who cannot
 * see `enable_logging` at all cannot tell whether logging exists, is broken, or
 * is hidden from them — all three are worse than "NV Digital manages this".
 *
 * What the page will not do is accept them. `SettingsService::write()` filters
 * by `SettingsSchema::writableFor('admin')`, which excludes operator scope, so
 * the disabled state is a courtesy rather than the control.
 *
 * Access is estate membership (`accessTenantPanel`), matching every other
 * `/admin` resource. `manage_options` would be the wrong predicate here: the
 * estate boundary is the membership, and a site role is shared across estates.
 */
class Settings extends Page implements HasForms
{
    use BuildsSettingsForm;

    protected static ?string $slug = 'settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    // After the resource pages: configuration is the last thing an operator
    // looks for, not the first.
    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.settings-form';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    protected function settingsSurface(): string
    {
        return SettingsService::SURFACE_ADMIN;
    }
}
