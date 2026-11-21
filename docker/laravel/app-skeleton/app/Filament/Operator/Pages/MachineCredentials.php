<?php

namespace App\Filament\Operator\Pages;

use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\ActivityRecorder;
use App\Support\Admin\AdminAuditLogger;
use App\Support\MachineTokens;
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
use UnitEnum;

/**
 * Machine credentials — Proposal 149 §129.
 *
 * The mint command has always printed "revoke it in /ops", and the inventory it
 * pointed at only lists `oos-personal:` tokens, so the promise was never kept.
 * This is the surface that keeps it: machine tokens only, with the one action
 * an operator needs when a credential leaks.
 *
 * Why this is not the token inventory: that page deliberately leaves revocation
 * to the owner, "so that the audit trail names the owner as the actor". That rule
 * is right for a person's token and wrong for a machine credential, which the
 * operator issued in the first place. The two families are separated by name
 * ({@see MachineTokens}), and the revoke guard here refuses anything that is not
 * one of ours.
 *
 * A revoked credential stops authenticating immediately: Sanctum resolves the
 * token row on every request, so deleting it is the whole revocation.
 */
final class MachineCredentials extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'machine-credentials';

    protected static ?string $navigationLabel = 'Machine credentials';

    protected static ?string $title = 'Machine credentials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Access;

    protected static ?int $navigationSort = 26;

    protected string $view = 'filament.operator.machine-credentials';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * Silences the daily expiry alert for the set it is currently about.
     *
     * The acknowledgement is a NEW activity event, because activity rows are
     * append-only — the feed records that someone said "handling it", with a
     * timestamp and an actor, rather than mutating the finding.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return array(
            Action::make('acknowledgeExpiry')
                ->label('Acknowledge expiry alerts')
                ->icon(Heroicon::OutlinedBellSlash)
                ->requiresConfirmation()
                ->modalDescription('Silences the daily expiry alert for this exact set '
                    .'until it changes, for up to 7 days, or until the soonest credential expires. '
                    .'The acknowledgement is recorded.')
                ->schema(array(
                    Textarea::make('note')
                        ->label('What are you doing?')
                        ->maxLength(280)
                        ->rows(2)
                        ->helperText('Optional. Recorded with the acknowledgement; "rotating Thursday" is the point.'),
                ))
                ->action(fn (array $data) => $this->acknowledgeExpiryAlerts(14, (string) ($data['note'] ?? ''))),
        );
    }

    /**
     * Record the acknowledgement and name what it covers.
     */
    public function acknowledgeExpiryAlerts(int $days = 14, string $note = ''): void
    {
        $rows = MachineTokens::expiring($days);
        $ids = array_column($rows, 'id');

        if (array() === $ids) {
            Notification::make()
                ->title('Nothing to acknowledge')
                ->body(sprintf('No machine credential expires within %d days.', $days))
                ->send();

            return;
        }

        $signature = sha1(implode(',', $ids));

        app(ActivityRecorder::class)->record(
            'machine_token.expiry_acknowledged',
            sprintf('Expiry alert acknowledged for %d machine credential(s).', count($ids)),
            null,
            array(
                'signature' => $signature,
                'ids' => $ids,
                'note' => mb_substr(trim($note), 0, 280),
            )
        );

        app(AdminAuditLogger::class)->recordResource(
            'machine_token',
            'expiry-alert',
            'acknowledged',
            array(),
            array('ids' => $ids)
        );

        Notification::make()
            ->success()
            ->title('Expiry alert acknowledged')
            ->body('Silenced for this set for up to 7 days, or until the soonest credential expires.')
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => MachineTokens::all())
            ->columns(array(
                TextColumn::make('label')
                    ->label('Label')
                    ->wrap(),
                TextColumn::make('holder')
                    ->label('Owner'),
                TextColumn::make('age_days')
                    ->label('Age')
                    ->state(fn (array $record): string => null === $record['age_days'] ? '—' : $record['age_days'].' d')
                    ->color(fn (array $record): string => null !== $record['age_days']
                        && $record['age_days'] > 90 ? 'warning' : 'gray'),
                TextColumn::make('scopes')
                    ->label('Scopes')
                    ->state(fn (array $record): string => array() === $record['scopes']
                        ? '—'
                        : implode(', ', $record['scopes']))
                    ->wrap(),
                TextColumn::make('last_used')
                    ->label('Last used')
                    ->state(fn (array $record): string => null === $record['last_used']
                        ? 'Never used'
                        : substr((string) $record['last_used'], 0, 16))
                    ->color(fn (array $record): string => null === $record['last_used'] ? 'warning' : 'gray'),
                TextColumn::make('expires')
                    ->label('Expires')
                    ->state(fn (array $record): string => null === $record['expires']
                        ? 'Never'
                        : substr((string) $record['expires'], 0, 10)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => 'expired' === $state ? 'danger' : 'success'),
            ))
            ->recordActions(array(
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->modalDescription('Deletes the credential. A machine using it stops '
                        .'authenticating immediately, and the token cannot be recovered.')
                    ->action(fn (array $record) => $this->revoke((int) $record['id'])),
            ))
            ->paginated(false)
            ->emptyStateHeading('No machine credentials')
            ->emptyStateDescription('Machine tokens are minted with nvoos:machine-token. '
                .'Tokens minted before the oos-machine: prefix existed are not listed here.');
    }

    /**
     * Revoke one machine credential.
     *
     * `MachineTokens::revoke()` refuses an id whose name is not a machine token,
     * so a hand-crafted call cannot delete a person's own credential through
     * this page.
     */
    public function revoke(int $id): void
    {
        $revoked = MachineTokens::revoke($id);

        if (null === $revoked) {
            Notification::make()
                ->danger()
                ->title('Not a machine credential')
                ->body('That token is not one this page may revoke.')
                ->send();

            return;
        }

        app(AdminAuditLogger::class)->recordResource(
            'machine_token',
            (string) $id,
            'revoked',
            array(
                'name' => $revoked['name'],
                'owner_id' => $revoked['tokenable_id'],
            ),
            array()
        );

        $this->resetTable();

        Notification::make()
            ->success()
            ->title('Machine credential revoked')
            ->body('The token stops authenticating immediately.')
            ->send();
    }
}
