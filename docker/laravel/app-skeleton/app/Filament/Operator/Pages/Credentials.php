<?php

namespace App\Filament\Operator\Pages;

use App\Models\Assistant;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use App\Filament\Support\RootKeyAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Error\AuthenticationException;

/**
 * Assistant credentials — Wave 2's third `/ops` surface (Proposal 149).
 *
 * A credential here is a Sanctum token named `oos-assistant-{id}`, issued to the
 * operator and scoped to one assistant by name (the same convention the console
 * token manager reads). Rotation means: issue the replacement first, then delete
 * the tokens that existed before it. Doing it the other way round would leave
 * the platform with no credential at all if issuance failed.
 *
 * THE ONE-TIME-SECRET DECISION. Filament prefills a field from the record, and a
 * password input hides characters without removing the value from the HTML or
 * from Livewire's snapshot — the exact mistake `EstateResource` documents for
 * the estate licence key. So the new token is **never** placed in a form field.
 * It is held in a public Livewire property and rendered in a panel on the page
 * until the operator dismisses it:
 *
 *  - it is never written to the database (only Sanctum's hash is stored),
 *  - it is never written to the audit log (the row records the expiry and how
 *    many credentials were replaced),
 *  - it is never placed in a notification, which would push it through the
 *    cache-backed notification mailbox,
 *  - and it disappears when the page is left or the panel is dismissed. Losing
 *    it is not an incident: rotate again, which is the recovery path the UI
 *    states.
 *
 * The plaintext does travel in the Livewire snapshot while the panel is visible
 * — that is unavoidable for a "copy this value" flow, and it is bounded to the
 * operator's own page.
 */
class Credentials extends Page implements HasTable
{
    use InteractsWithTable;

    /** Token name prefix; the suffix is the assistant id. */
    private const NAME_PREFIX = 'oos-assistant-';

    protected static ?string $slug = 'credentials';

    protected static ?string $navigationLabel = 'Credentials';

    protected static ?string $title = 'Assistant credentials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Access;

    protected static ?int $navigationSort = 22;

    protected string $view = 'filament.operator.credentials';

    /** The newly issued token, shown once. Null when nothing was just rotated. */
    public ?string $issuedToken = null;

    /** The assistant the token belongs to, for the panel heading. */
    public ?string $issuedFor = null;

    /** ISO-8601 expiry, for the panel body. */
    public ?string $issuedExpiresAt = null;

    /**
     * Credential aggregates per assistant, memoised for this request.
     *
     * One query for the whole table rather than one per row.
     *
     * @var array<int, array{total: int, active: int, last_used: ?string, expires_at: ?string}>|null
     */
    private ?array $stats = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            // Operated cross-estate by design: `/ops` is the vendor's view of
            // every estate, and the Estate column below is what makes that
            // visible. `withoutGlobalScopes()` is the documented opt-out
            // (Proposal 153); removing it would silently reduce this table to
            // the default estate.
            ->query(Assistant::query()->withoutGlobalScopes()->with('tenant'))
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tenant.name')
                    ->label('Estate')
                    ->placeholder('—'),
                TextColumn::make('credentials')
                    ->label('Credentials')
                    ->badge()
                    ->getStateUsing(fn (Assistant $record): string => $this->credentialState((int) $record->getKey())),
                TextColumn::make('last_used')
                    ->label('Last used')
                    ->getStateUsing(fn (Assistant $record): ?string => $this->statsFor((int) $record->getKey())['last_used'])
                    ->dateTime()
                    ->placeholder('Never'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->getStateUsing(fn (Assistant $record): ?string => $this->statsFor((int) $record->getKey())['expires_at'])
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                RootKeyAction::guard(
                    Action::make('rotate')
                        ->label('Rotate')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->modalHeading(fn (Assistant $record): string => 'Rotate credential: '.$record->title)
                        ->modalDescription('Issues a replacement and revokes every existing credential for this assistant. The new value is shown once.')
                        ->schema($this->rotationFields())
                        ->action(fn (Assistant $record, array $data) => $this->rotate($record, (int) $data['days'])),
                    $this->rotationFields(),
                ),
                RootKeyAction::guard(
                    Action::make('revoke')
                        ->label('Revoke')
                        ->color('danger')
                        ->icon(Heroicon::OutlinedTrash)
                        ->requiresConfirmation()
                        ->modalDescription('Deletes every credential for this assistant. Anyone using one loses access immediately.')
                        ->visible(fn (Assistant $record): bool => $this->statsFor((int) $record->getKey())['total'] > 0)
                        ->action(fn (Assistant $record) => $this->revoke($record)),
                ),
            ])
            ->emptyStateHeading('No assistants')
            ->emptyStateDescription('A credential is issued for an assistant; create one first.');
    }

    /**
     * Hide the one-time panel without touching the credential itself.
     */
    public function dismissIssued(): void
    {
        $this->issuedToken = null;
        $this->issuedFor = null;
        $this->issuedExpiresAt = null;
    }

    /**
     * Issue the replacement first, then revoke what came before it.
     *
     * Reversing the order would leave the assistant with no credential whenever
     * issuance throws, which is the failure mode that matters.
     */
    /**
     * The rotation modal's own field, shared by the action and its guard.
     *
     * `RootKeyAction::guard()` replaces an action's modal schema when the gate is
     * armed, so the field list has to be the same array in both places. Two
     * copies would drift, and the failure would only appear on deployments that
     * turned the gate on — the rotation modal would lose its "Valid for" and
     * quietly fall back to whatever the store did with a missing key.
     *
     * @return array<int, Select>
     */
    private function rotationFields(): array
    {
        return array(
            Select::make('days')
                ->label('Valid for')
                ->options(array(
                    30 => '30 days',
                    90 => '90 days',
                    180 => '180 days',
                    365 => '365 days',
                ))
                ->default(90)
                ->required(),
        );
    }

    private function rotate(Assistant $assistant, int $days): void
    {
        $assistantId = (int) $assistant->getKey();
        $name = self::NAME_PREFIX.$assistantId;

        $existingIds = DB::table('personal_access_tokens')
            ->where('name', $name)
            ->pluck('id')
            ->all();

        try {
            $credential = app(AuthProviderInterface::class)->issueCredential(
                $assistantId,
                array('expires_in_seconds' => $days * 86400)
            );
        } catch (AuthenticationException $exception) {
            Notification::make()
                ->danger()
                ->title('Credential not issued')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        if (array() !== $existingIds) {
            DB::table('personal_access_tokens')->whereIn('id', $existingIds)->delete();
        }

        $expiresAt = $credential->expiresAt?->format(DATE_ATOM);

        $this->issuedToken = $credential->token;
        $this->issuedFor = (string) $assistant->title;
        $this->issuedExpiresAt = $expiresAt;

        // The plaintext is deliberately absent from the audit row.
        $this->audit('rotated', $assistantId, array(
            'revoked' => count($existingIds),
            'expires_at' => $expiresAt,
        ));

        $this->forgetStats();

        Notification::make()
            ->success()
            ->title('Credential rotated')
            ->body('The new value is shown on this page until you hide it.')
            ->send();
    }

    private function revoke(Assistant $assistant): void
    {
        $assistantId = (int) $assistant->getKey();

        $deleted = DB::table('personal_access_tokens')
            ->where('name', self::NAME_PREFIX.$assistantId)
            ->delete();

        if ($deleted > 0) {
            $this->audit('revoked', $assistantId, array('revoked' => $deleted));
        }

        $this->forgetStats();

        Notification::make()
            ->success()
            ->title($deleted > 0 ? "Revoked {$deleted} credential(s)" : 'No credentials to revoke')
            ->send();
    }

    /**
     * The badge shown in the table.
     */
    private function credentialState(int $assistantId): string
    {
        $stats = $this->statsFor($assistantId);

        if ($stats['total'] === 0) {
            return 'None';
        }

        if ($stats['active'] === 0) {
            return 'Expired';
        }

        return $stats['active'] > 1 ? "{$stats['active']} active" : 'Active';
    }

    /**
     * @return array{total: int, active: int, last_used: ?string, expires_at: ?string}
     */
    private function statsFor(int $assistantId): array
    {
        if (null === $this->stats) {
            $this->stats = $this->buildStats();
        }

        return $this->stats[$assistantId]
            ?? array('total' => 0, 'active' => 0, 'last_used' => null, 'expires_at' => null);
    }

    /**
     * @return array<int, array{total: int, active: int, last_used: ?string, expires_at: ?string}>
     */
    private function buildStats(): array
    {
        // Only the metadata columns are read: the token hash is never selected,
        // for the same reason TokenManagerController does not select it.
        $rows = DB::table('personal_access_tokens')
            ->where('name', 'like', self::NAME_PREFIX.'%')
            ->get(array('name', 'last_used_at', 'expires_at'));

        $now = time();
        $stats = array();

        foreach ($rows as $row) {
            $assistantId = $this->assistantIdFrom((string) $row->name);

            if ($assistantId <= 0) {
                continue;
            }

            $stats[$assistantId] ??= array('total' => 0, 'active' => 0, 'last_used' => null, 'expires_at' => null);

            ++$stats[$assistantId]['total'];

            $expiresAt = null !== $row->expires_at ? (string) $row->expires_at : '';
            $expired = '' !== $expiresAt && strtotime($expiresAt) <= $now;

            if ($expired) {
                continue;
            }

            ++$stats[$assistantId]['active'];

            $lastUsed = null !== $row->last_used_at ? (string) $row->last_used_at : null;

            if (null !== $lastUsed && (null === $stats[$assistantId]['last_used'] || $lastUsed > $stats[$assistantId]['last_used'])) {
                $stats[$assistantId]['last_used'] = $lastUsed;
            }

            // The soonest expiry is the one worth showing: it is what will
            // break first.
            if ('' !== $expiresAt && (null === $stats[$assistantId]['expires_at'] || $expiresAt < $stats[$assistantId]['expires_at'])) {
                $stats[$assistantId]['expires_at'] = $expiresAt;
            }
        }

        return $stats;
    }

    private function forgetStats(): void
    {
        $this->stats = null;
        $this->resetTable();
    }

    private function assistantIdFrom(string $name): int
    {
        if (1 !== preg_match('/^'.preg_quote(self::NAME_PREFIX, '/').'(\d+)$/', $name, $matches)) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * @param array<string, mixed> $after
     */
    private function audit(string $action, int $assistantId, array $after): void
    {
        app(AdminAuditLogger::class)->recordResource(
            'credential',
            (string) $assistantId,
            $action,
            array(),
            $after
        );
    }
}
