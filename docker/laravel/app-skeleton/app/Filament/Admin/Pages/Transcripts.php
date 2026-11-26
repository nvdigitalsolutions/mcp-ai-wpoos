<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\Assistant;
use App\Models\Transcript;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\TenantContext;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Chat transcripts — Proposal 149 §39.
 *
 * The console owns every write: `POST`/`DELETE /api/v1/chat-transcripts` from the
 * chat client, and the bounded importer on `/ops`. What an estate administrator
 * cannot do anywhere is *read* the record — "what did this assistant actually say
 * to my staff last week" is a diagnostic question, and it is the reason this row
 * existed as `admin: planned` with the note "retention stays server-owned".
 *
 * READ-ONLY, enforced by having no mutating action: no delete, no retention
 * control, no export. Deletion is the store's `prune()` (a server-owned job) and
 * the console's `DELETE`. A panel delete button would be a second writer over a
 * table whose retention rule is deliberately centralised.
 *
 * The estate scope comes from `Transcript`'s global scope, so this page and the
 * console endpoints cannot disagree about which sessions exist.
 */
class Transcripts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'transcripts';

    protected static ?string $navigationLabel = 'Transcripts';

    protected static ?string $title = 'Transcripts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Activity;

    // Content surfaces sit above the operational logs and below configuration:
    // transcripts are what the estate's users produced, not how the runtime runs.
    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.transcripts';

    /**
     * Assistant titles, memoised per request.
     *
     * A transcript stores `assistant_id`, and the assistant may be renamed or
     * deleted after the conversation. One bounded query resolves the page's
     * labels; the same map feeds the filter options, so the two cannot disagree.
     *
     * @var array<string, string>|null
     */
    private ?array $assistants = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Transcript::query())
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('session_id')
                    ->label('Session')
                    ->limit(28)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('assistant_id')
                    ->label('Assistant')
                    ->getStateUsing(fn (Transcript $record): string => $this->assistantLabel($record->assistant_id))
                    ->wrap(),
                TextColumn::make('turn_count')
                    ->label('Turns')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('preview')
                    ->label('First message')
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last turn')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('assistant_id')
                    ->label('Assistant')
                    ->options(fn (): array => $this->assistantOptions()),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->modalHeading(fn (Transcript $record): string => 'Session '.$record->session_id)
                    ->modalContent(fn (Transcript $record) => view('filament.admin.transcript', [
                        'transcript' => $record,
                    ]))
                    // Info-only: there is nothing to submit, and a modal offering
                    // "Submit" over a read-only conversation implies a write.
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No transcripts yet')
            ->emptyStateDescription('A session appears here after a chat is saved. Retention is server-owned.');
    }

    /**
     * The estate's assistants, as filter options.
     *
     * `Assistant` has no tenant global scope (the tenant is never request-supplied),
     * so the estate filter is explicit here rather than inherited.
     *
     * @return array<string, string>
     */
    private function assistantOptions(): array
    {
        if (null !== $this->assistants) {
            return $this->assistants;
        }

        return $this->assistants = Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->orderBy('title')
            ->pluck('title', 'id')
            ->map(static fn ($title): string => (string) $title)
            ->all();
    }

    /**
     * An assistant title, or a stable fallback.
     *
     * A deleted assistant must not blank the row: the transcript is still a real
     * record of a real conversation.
     */
    private function assistantLabel(?string $assistantId): string
    {
        $assistantId = (string) $assistantId;

        if ('' === $assistantId) {
            return '—';
        }

        return $this->assistantOptions()[$assistantId] ?? 'Assistant '.$assistantId;
    }
}
