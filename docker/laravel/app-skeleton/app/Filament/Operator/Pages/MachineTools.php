<?php

namespace App\Filament\Operator\Pages;

use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Settings\SettingsService;
use App\Support\ToolCatalogue;
use App\Support\Tools\MachineToolAllowlist;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use UnitEnum;

/**
 * Machine-executable tools — Proposal 149 §128.
 *
 * §127 made the allowlist editable by typing slugs into a settings field. That
 * was the wiring; this is the picker. The rows are the servable tools the
 * registry reports, and the badges answer the two questions an operator
 * actually has: *what would this tool do unattended* (its annotations) and
 * *who allowlisted it* (the deploy's config or this page).
 *
 * Three limits are deliberate:
 *
 *  - **Only a servable tool is listed.** A package that is registered but
 *    cannot run would be a toggle that does nothing.
 *  - **A config entry is not removable here.** The remove action appears only
 *    for `setting`, so a panel click cannot narrow a decision made with shell
 *    access (the union §127.2 states).
 *  - **Allowlisting is permission, not safety.** An allowed tool still needs
 *    the assistant to assign it, and its annotations still veto unattended use.
 */
final class MachineTools extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'machine-tools';

    protected static ?string $navigationLabel = 'Machine tools';

    protected static ?string $title = 'Machine-executable tools';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Access;

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.operator.machine-tools';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns(array(
                TextColumn::make('name')
                    ->label('Tool')
                    ->wrap(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->color('gray'),
                TextColumn::make('annotations')
                    ->label('If a machine ran it')
                    ->badge()
                    ->color(fn (array $record): string => match ($record['annotation_key']) {
                        'destructive' => 'danger',
                        'state-changing' => 'warning',
                        'read-only' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('source')
                    ->label('Allowlisted by')
                    ->badge()
                    ->placeholder('—'),
            ))
            ->recordActions(array(
                Action::make('allow')
                    ->label('Allow')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (array $record): bool => ! $record['allowlisted'])
                    ->action(fn (array $record) => $this->allowTool((string) $record['slug'])),
                Action::make('deny')
                    ->label('Remove')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedMinus)
                    ->requiresConfirmation()
                    ->modalDescription('Removes the panel entry. A tool named in config/oos.php is not removable here.')
                    ->visible(fn (array $record): bool => 'setting' === $record['source'])
                    ->action(fn (array $record) => $this->denyTool((string) $record['slug'])),
            ))
            ->paginated(array(25, 50, 100))
            ->emptyStateHeading('No servable tools')
            ->emptyStateDescription('The registry reports no tool that can run in this runtime.');
    }

    /**
     * The servable catalogue, with the two facts the table shows.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $config = $this->configSlugs();
        $stored = $this->storedSlugs();
        $rows = array();

        foreach ($this->catalogue() as $row) {
            $slug = (string) $row['slug'];

            if (! ToolReadiness::isServableClass((string) $row['readiness'])) {
                continue;
            }

            $inConfig = in_array($slug, $config, true);
            $inSetting = in_array($slug, $stored, true);
            $annotations = (array) ($row['annotations'] ?? array());

            $rows[] = array(
                'id' => $slug,
                'slug' => $slug,
                'name' => (string) $row['name'],
                'annotation_key' => $this->annotationKey($annotations),
                'annotations' => $this->annotationLabel($annotations),
                'source' => $inConfig ? 'config' : ($inSetting ? 'setting' : ''),
                'allowlisted' => $inConfig || $inSetting,
            );
        }

        return $rows;
    }

    public function allowTool(string $slug): void
    {
        if (! $this->isServable($slug)) {
            return;
        }

        $slugs = $this->storedSlugs();

        if (! in_array($slug, $slugs, true)) {
            $slugs[] = $slug;
        }

        $this->persist($slugs);
        $this->record($slug, 'allowed');
        $this->resetTable();

        Notification::make()->title('Tool allowlisted')->success()->send();
    }

    public function denyTool(string $slug): void
    {
        $slugs = array_values(array_filter(
            $this->storedSlugs(),
            static fn (string $candidate): bool => $candidate !== $slug,
        ));

        $this->persist($slugs);
        $this->record($slug, 'removed');
        $this->resetTable();

        Notification::make()->title('Tool removed from the panel list')->success()->send();
    }

    /**
     * The annotation claim, read in the same order MachineToolSafety reads it:
     * destructive first, then read-only, and no claim means no claim.
     *
     * @param array<string, mixed> $annotations
     */
    private function annotationKey(array $annotations): string
    {
        if (true === ($annotations['destructiveHint'] ?? null)) {
            return 'destructive';
        }

        if (false === ($annotations['readOnlyHint'] ?? null)) {
            return 'state-changing';
        }

        if (true === ($annotations['readOnlyHint'] ?? null)) {
            return 'read-only';
        }

        return 'unannotated';
    }

    /**
     * @param array<string, mixed> $annotations
     */
    private function annotationLabel(array $annotations): string
    {
        return match ($this->annotationKey($annotations)) {
            'destructive' => 'Destroys data',
            'state-changing' => 'Changes state (approval)',
            'read-only' => 'Read-only',
            default => 'Not annotated',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogue(): array
    {
        return ToolCatalogue::rows(app(ToolRegistry::class), app(ToolReadiness::class));
    }

    private function isServable(string $slug): bool
    {
        foreach ($this->catalogue() as $row) {
            if ($slug === (string) $row['slug']) {
                return ToolReadiness::isServableClass((string) $row['readiness']);
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function configSlugs(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $slug): string => is_string($slug) ? trim($slug) : '',
            (array) config('oos.machine_tools', array()),
        )));
    }

    /**
     * The panel's own list — not the union. Removing a config entry must not be
     * possible by writing an empty string, so this reads the setting directly.
     *
     * @return list<string>
     */
    private function storedSlugs(): array
    {
        return MachineToolAllowlist::parse(app(SettingsService::class)->value('machine_tools'));
    }

    /**
     * @param list<string> $slugs
     */
    private function persist(array $slugs): void
    {
        app(SettingsService::class)->write(
            array('machine_tools' => implode(', ', array_values(array_unique($slugs)))),
            SettingsService::SURFACE_OPS,
        );
    }

    private function record(string $slug, string $action): void
    {
        app(AdminAuditLogger::class)->recordResource(
            'machine_tools',
            $slug,
            $action,
            array(),
            array('allowlisted' => 'allowed' === $action),
        );
    }
}
