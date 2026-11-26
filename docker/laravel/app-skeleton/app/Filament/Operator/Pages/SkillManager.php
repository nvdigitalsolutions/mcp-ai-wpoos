<?php

namespace App\Filament\Operator\Pages;

use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use App\Filament\Support\RootKeyAction;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\SkillCatalogue;
use App\Support\Skills\SkillInstallInvalid;
use App\Support\Skills\SkillInstaller;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * Install, replace and delete Agent Skills — Proposal 149 §190.
 *
 * **Why `/ops` and not `/admin`.** A skill lives in one directory
 * (`config('oos.skills.path')`) and every estate reads it, so installing one is a
 * change to the deployment, not to an estate's content. `/admin` keeps its
 * read-only catalogue (§49) — tenant admins still see what skills exist, and only
 * an operator changes what they are.
 *
 * **Why the panel exists at all.** WordPress ships this manager; this runtime
 * shipped the reader and stopped, with a note that the writes "deserve their own
 * security pass". §190 is that pass, and it lives in `SkillInstaller` — path
 * resolution, slug derivation, size caps, atomic writes and the delete-narrowly
 * rule are all there, not here. This page is the form and the notifications.
 *
 * **All three actions are gated by the root security key.** A skill's description
 * is injected into every prompt that lists skills and its body is loaded into the
 * model's context on demand, so installing one is closer to installing a prompt
 * than to uploading an image. Replace and delete are destructive; install is an
 * instruction the whole deployment will follow. §189's rule applies — high-risk
 * operator actions ask for the key at the point of the action.
 */
class SkillManager extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'skills';

    protected static ?string $navigationLabel = 'Skills';

    protected static ?string $title = 'Agent skills';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Platform;

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.operator.skill-manager';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return array(
            RootKeyAction::guard(
                Action::make('install')
                    ->label('Install skill')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading('Install a skill')
                    ->modalDescription('Paste the whole SKILL.md, frontmatter included. '
                        .'The folder name is derived from its name, so the file never chooses its own path.')
                    ->modalSubmitActionLabel('Install')
                    ->schema(array($this->contentField('Paste the SKILL.md')))
                    ->action(fn (array $data) => $this->install((string) $data['content'], false)),
            ),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => SkillCatalogue::fromConfig()->installed())
            ->columns(array(
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('description')
                    ->label('Description')
                    ->wrap()
                    ->limit(160),
                TextColumn::make('slug')
                    ->label('Folder')
                    ->color('gray')
                    ->fontFamily('mono'),
                TextColumn::make('license')
                    ->label('Licence')
                    ->placeholder('—'),
                TextColumn::make('compatibility')
                    ->label('Compatibility')
                    ->placeholder('—')
                    ->wrap(),
            ))
            ->recordActions(array(
                RootKeyAction::guard(
                    Action::make('replace')
                        ->label('Replace')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->modalHeading(fn (array $record): string => 'Replace '.$record['name'])
                        ->modalDescription('Overwrites this skill\'s SKILL.md. The folder is kept and the old file is replaced, not merged.')
                        ->modalSubmitActionLabel('Replace')
                        ->schema(array($this->contentField('Paste the replacement SKILL.md')))
                        ->action(fn (array $record, array $data) => $this->install((string) $data['content'], true)),
                ),
                RootKeyAction::guard(
                    Action::make('delete')
                        ->label('Delete')
                        ->color('danger')
                        ->icon(Heroicon::OutlinedTrash)
                        ->requiresConfirmation()
                        ->modalDescription('Removes the skill folder. Every assistant in the deployment stops seeing it immediately.')
                        ->action(fn (array $record) => $this->delete((string) $record['slug'])),
                ),
            ))
            ->paginated(false)
            ->emptyStateHeading('No skills installed')
            ->emptyStateDescription('A skill is a folder with a SKILL.md. Install one here, or place folders in the directory shown above.')
            ->emptyStateActions(array($this->getHeaderActions()[0]));
    }

    /**
     * The directory the runtime reads, so an operator can explain an empty list.
     */
    public function skillsPath(): string
    {
        return SkillInstaller::fromConfig()->root();
    }

    /**
     * The catalogue's own count, for the header.
     */
    public function installedCount(): int
    {
        return count(SkillCatalogue::fromConfig()->installed());
    }

    public function install(string $content, bool $replace): void
    {
        try {
            $result = SkillInstaller::fromConfig()->install($content, $replace);
        } catch (SkillInstallInvalid $error) {
            Notification::make()
                ->danger()
                ->title('Skill not '.($replace ? 'replaced' : 'installed'))
                ->body($error->getMessage())
                ->send();

            return;
        } catch (RuntimeException $error) {
            Notification::make()
                ->danger()
                ->title('Skill not written')
                ->body($error->getMessage())
                ->send();

            return;
        }

        $this->resetTable();

        Notification::make()
            ->success()
            ->title($result['replaced'] ? 'Skill replaced' : 'Skill installed')
            ->body(sprintf('%s → %s', $result['name'], $result['slug']))
            ->send();
    }

    public function delete(string $slug): void
    {
        try {
            SkillInstaller::fromConfig()->delete($slug);
        } catch (SkillInstallInvalid $error) {
            Notification::make()
                ->danger()
                ->title('Skill not deleted')
                ->body($error->getMessage())
                ->send();

            return;
        } catch (RuntimeException $error) {
            Notification::make()
                ->danger()
                ->title('Skill not deleted')
                ->body($error->getMessage())
                ->send();

            return;
        }

        $this->resetTable();

        Notification::make()
            ->success()
            ->title('Skill deleted')
            ->body(sprintf('"%s" is no longer in the catalogue.', $slug))
            ->send();
    }

    /**
     * The one field both write actions use.
     */
    private function contentField(string $label): Textarea
    {
        return Textarea::make('content')
            ->label($label)
            ->rows(16)
            ->required()
            ->maxLength(SkillInstaller::MAX_BYTES)
            ->helperText('YAML frontmatter between two --- lines, with a name and a description, then the body.');
    }
}
