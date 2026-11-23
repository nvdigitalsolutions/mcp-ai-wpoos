<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Orchestration\TurnBudgetPresets;
use App\Support\Settings\SettingsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Orchestration presets — Proposal 149 §181, `/admin/presets`.
 *
 * The WordPress `orchestration` → `presets` view ships eleven named bundles over
 * a bag of orchestrator flags. This runtime reads three of those flags' worth of
 * behaviour, so the page is a projection over the **turn budget** and says so
 * rather than rendering rows that would write nothing (§178.1's rule).
 *
 * **A preset is a macro, not a mode.** It writes the same keys the advanced tab
 * of `/admin/settings` writes, through the same `SettingsService`, and then gets
 * out of the way: the button does not latch, and editing a ceiling afterwards
 * simply makes the estate "Custom". No `active_preset` option exists, because a
 * stored label beside stored values is a second truth that drifts on the first
 * manual edit.
 *
 * **Applying confirms first.** The research on preset UIs is consistent: the
 * dangerous moment is silently overwriting values someone tuned by hand, so the
 * row expands into a "from → to" sentence before anything is written.
 *
 * The audit records the preset key plus the before/after sets. The logger
 * redacts any key containing "token", so both token ceilings read as
 * `[redacted]` while the warn band keeps its value — over-redaction is that
 * class's stated trade. The preset key is the durable answer regardless: the
 * values it writes are code-owned, so "frugal was applied" identifies them
 * exactly, which is what an auditor asking "who raised the ceiling" needs.
 */
class OrchestrationPresets extends Page
{
    protected static ?string $slug = 'presets';

    protected static ?string $navigationLabel = 'Presets';

    protected static ?string $title = 'Orchestration presets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    // Beside Settings, because these are macros over a section on that page.
    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Settings;

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.orchestration-presets';

    /** The preset awaiting confirmation, or `''`. */
    public string $pending = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * @return array<string, array{label: string, description: string, rationale: string, values: array<string, int|float>}>
     */
    public function presets(): array
    {
        return app(TurnBudgetPresets::class)->all();
    }

    /**
     * @return array<string, int|float>
     */
    public function currentValues(): array
    {
        return app(TurnBudgetPresets::class)->current(app(SettingsService::class));
    }

    public function activeKey(): ?string
    {
        return app(TurnBudgetPresets::class)->activeKey($this->currentValues());
    }

    public function confirm(string $key): void
    {
        if (! app(TurnBudgetPresets::class)->has($key)) {
            Notification::make()
                ->title('Unknown preset.')
                ->warning()
                ->send();

            return;
        }

        $this->pending = $key;
    }

    public function cancel(): void
    {
        $this->pending = '';
    }

    /**
     * Write the pending preset, then audit it.
     *
     * The writer filters by the admin surface, so a key that stopped being
     * writable is dropped rather than trusted — which is why the notification
     * counts what was applied instead of assuming three.
     */
    public function apply(): void
    {
        $presets = app(TurnBudgetPresets::class);

        if (! $presets->has($this->pending)) {
            Notification::make()
                ->title('Unknown preset.')
                ->warning()
                ->send();

            $this->pending = '';

            return;
        }

        $before = $this->currentValues();
        $applied = $presets->apply($this->pending, app(SettingsService::class));

        if (array() === $applied) {
            Notification::make()
                ->title('Nothing was written.')
                ->body('The settings service refused every key in this preset.')
                ->danger()
                ->send();

            $this->pending = '';

            return;
        }

        $preset = $presets->all()[$this->pending];

        app(AdminAuditLogger::class)->recordResource(
            'orchestration-presets',
            $this->pending,
            'applied',
            $before,
            $preset['values']
        );

        $this->pending = '';

        Notification::make()
            ->success()
            ->title(sprintf('%s applied', $preset['label']))
            ->body(sprintf('%d ceiling(s) updated.', count($applied)))
            ->send();
    }

    /**
     * Tokens, with zero reading as off rather than as a number.
     */
    public function humanTokens(int|float $value): string
    {
        return (int) $value < 1 ? 'Off' : number_format((int) $value);
    }

    /**
     * The warn band is stored as a fraction and read as a percentage.
     */
    public function humanWarn(float $value): string
    {
        return number_format($value * 100, 0).'%';
    }
}
