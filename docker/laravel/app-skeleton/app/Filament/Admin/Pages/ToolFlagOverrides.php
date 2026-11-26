<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\ToolFlagOverride;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\ToolCatalogue;
use App\Support\ToolFlags\ToolFlagOverrideStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagResolution;
use UnitEnum;

/**
 * Per-tool capability-flag overrides — Proposal 149 §177, `/admin/tool-overrides`.
 *
 * The WordPress `orchestration` → `tools` view, minus the part that has no
 * reader here (`force_sync`: this runtime has no asynchronous tool path, so the
 * toggle would render and change nothing).
 *
 * **Addition-only, and the page cannot express anything else.** The checkbox
 * list offers the eight flags the read-only profile already refuses on, and the
 * store filters to that same list. There is no "remove flag" control because
 * there is no remove API — a stored removal would let an estate clear the flag
 * its own profile refuses on, which is an authority grant wearing a settings
 * page's clothes.
 *
 * **A tool that declares nothing cannot be overridden.** It is already refused
 * by `blockUndeclared`, and adding a non-gated flag would flip it from "unknown"
 * to "known and safe" — a widen. The store refuses the save and says why rather
 * than silently dropping the row.
 *
 * The effective flag set is computed by `CapabilityFlagResolution`, the same
 * function the guards call, so the "effective" column here and the decision a
 * tool call gets cannot disagree.
 */
class ToolFlagOverrides extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'tool-overrides';

    protected static ?string $navigationLabel = 'Tool flags';

    protected static ?string $title = 'Tool flag overrides';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // Beside the catalogue and its quotas: the three pages that describe a tool.
    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.admin.tool-flag-overrides';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ToolFlagOverride::query())
            ->defaultSort('tool_slug')
            ->columns([
                TextColumn::make('tool_slug')
                    ->label('Tool')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ToolFlagOverride $record): string => $this->toolName($record->tool_slug))
                    ->fontFamily('mono'),
                TextColumn::make('flags')
                    ->label('Added flags')
                    ->badge()
                    ->getStateUsing(fn (ToolFlagOverride $record): array => array_values((array) $record->flags))
                    ->color('warning'),
                TextColumn::make('effective')
                    ->label('Effective after override')
                    ->getStateUsing(fn (ToolFlagOverride $record): string => $this->effectiveLabel($record))
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (ToolFlagOverride $record): string => 'Flags: '.$record->tool_slug)
                    ->modalDescription('Overrides can only add restrictions. Removing one returns the tool to what it declares.')
                    ->fillForm(fn (ToolFlagOverride $record): array => array(
                        'flags' => array_values((array) $record->flags),
                    ))
                    ->schema($this->flagSchema(withIdentity: false))
                    ->action(fn (ToolFlagOverride $record, array $data) => $this->save((string) $record->tool_slug, $data)),
                Action::make('delete')
                    ->label('Remove')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->modalDescription('The tool returns to the flags it declares in code. Nothing else about it changes.')
                    ->action(fn (ToolFlagOverride $record) => app(ToolFlagOverrideStore::class)->remove((int) $record->getKey())),
            ])
            ->emptyStateHeading('No overrides')
            ->emptyStateDescription('An override marks a tool as more dangerous than it declares, so the read-only profile and the necessity gate refuse it. Nothing is overridden here by default.')
            ->emptyStateActions([
                $this->createAction(),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return array(
            $this->createAction(),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function flagSchema(bool $withIdentity): array
    {
        $fields = array();

        if ($withIdentity) {
            $fields[] = Select::make('tool_slug')
                ->label('Tool')
                ->options(fn (): array => $this->overridableTools())
                ->searchable()
                ->required()
                ->helperText('Only tools that declare at least one capability flag are listed. A tool that declares none is already refused, so there is nothing to tighten.');
        }

        $fields[] = CheckboxList::make('flags')
            ->label('Add these flags')
            ->options(ToolFlagOverrideStore::flagOptions())
            ->required()
            ->columns(2)
            ->helperText('Ordered from most to least specific. Each one makes the tool subject to a refusal or an approval it could otherwise have avoided.');

        return $fields;
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('Add override')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add a flag override')
            ->modalDescription('This only tightens. It cannot make a blocked tool runnable.')
            ->schema($this->flagSchema(withIdentity: true))
            ->action(fn (array $data) => $this->save((string) ($data['tool_slug'] ?? ''), $data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(string $slug, array $data): void
    {
        $slug = trim($slug);

        if ('' === $slug) {
            return;
        }

        // Refuse an override on a tool that declares nothing: it is already
        // blocked, and adding a flag would turn "unknown" into "known".
        if (null === $this->declaredFlags($slug)) {
            Notification::make()
                ->title('Nothing to override')
                ->body(sprintf(
                    '"%s" declares no capability flags, so it is already refused under a read-only profile. An override cannot make it safer.',
                    $slug
                ))
                ->danger()
                ->send();

            return;
        }

        app(ToolFlagOverrideStore::class)->put($slug, (array) ($data['flags'] ?? array()));

        Notification::make()
            ->title('Override saved')
            ->body('The read-only profile, the chat profile and the necessity gate all see it on the next call.')
            ->success()
            ->send();
    }

    // ── Presentation helpers ────────────────────────────────────

    /**
     * The flags a tool declares today, or null when it declares none.
     *
     * Uses the same resolution the guards use, so this page cannot claim an
     * override applies where the guard would ignore it.
     *
     * @return list<string>|null
     */
    private function declaredFlags(string $slug): ?array
    {
        $tool = $this->registry()->get($slug);

        // Resolve WITHOUT this estate's overrides: the question is what the tool
        // declares, not what it declares plus what we already added.
        return CapabilityFlagResolution::resolve($slug, $tool, null);
    }

    private function effectiveLabel(ToolFlagOverride $record): string
    {
        $slug = (string) $record->tool_slug;
        $flags = CapabilityFlagResolution::resolve($slug, $this->registry()->get($slug), app(ToolFlagOverrideStore::class));

        return null === $flags || array() === $flags ? '—' : implode(', ', $flags);
    }

    /**
     * @var array<string, string>|null
     */
    private ?array $toolNames = null;

    private function toolName(string $slug): string
    {
        $this->toolNames ??= $this->catalogue();

        return $this->toolNames[$slug] ?? 'not registered on this runtime';
    }

    /**
     * The selector's options: only tools that declare something.
     *
     * @return array<string, string>
     */
    private function overridableTools(): array
    {
        $options = array();

        foreach ($this->catalogue() as $slug => $name) {
            if (null === $this->declaredFlags($slug)) {
                continue;
            }

            $options[$slug] = $slug.' — '.$name;
        }

        asort($options);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(): array
    {
        $names = array();

        foreach (ToolCatalogue::rows($this->registry(), app(ToolReadiness::class)) as $row) {
            $names[(string) $row['slug']] = (string) $row['name'];
        }

        return $names;
    }

    private function registry(): ToolRegistry
    {
        return app(ToolRegistry::class);
    }
}
