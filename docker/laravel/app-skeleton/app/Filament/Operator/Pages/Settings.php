<?php

namespace App\Filament\Operator\Pages;

use App\Filament\Concerns\BuildsSettingsForm;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Security\RootKey;
use App\Support\Security\RootKeyGate;
use App\Support\Settings\SettingsService;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

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
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Settings;

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

    /**
     * Step-up unlock for the root key — Proposal 149 §188.
     *
     * Livewire actions cannot set an `X-Root-Key` header on their own request, so
     * the panel exchanges the key for the short session grant the gate accepts.
     * The action is hidden when the gate is off, so a deployment that does not use
     * the key never sees it.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return array(
            Action::make('unlockRootKey')
                ->label('Unlock root key')
                ->icon(Heroicon::OutlinedKey)
                ->visible(static fn (): bool => app(RootKey::class)->enabled())
                ->modalDescription(sprintf(
                    'Operator changes are refused until the root security key is verified. Unlocking lasts %d minutes.',
                    RootKeyGate::TTL_MINUTES
                ))
                ->form(array(
                    TextInput::make('key')
                        ->label('Root security key')
                        ->password()
                        ->required(),
                ))
                ->action(function (array $data): void {
                    $accepted = app(RootKeyGate::class)->attempt(
                        request(),
                        (string) $data['key']
                    );

                    $notification = Notification::make()
                        ->title($accepted ? 'Root key accepted' : 'Root key rejected');

                    if ($accepted) {
                        $notification->success()
                            ->body('Operator changes are unlocked for this session.')
                            ->send();

                        return;
                    }

                    $notification->danger()
                        ->body('The key was not correct. The attempt has been recorded.')
                        ->send();
                }),
        );
    }
}
