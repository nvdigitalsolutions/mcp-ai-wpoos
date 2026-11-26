<?php

namespace App\Filament\Operator\Pages;

use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\PersonalTokens;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Personal access tokens, across estates — Proposal 149 §114, closing the last
 * `planned` row on `/ops`.
 *
 * `/admin/personal-tokens` (§26) is the owner's view: a user mints, lists and
 * revokes their own tokens. This is the operator's: **who holds a token, how long
 * it lives, and how wide it reaches.** Read-only, deliberately — a cross-estate
 * revoke is a different decision, and `writer` on this row stays `admin`.
 *
 * ## What is shown, and what can never be
 *
 * Published guidance on token management converges on one rule: there must be
 * **no code path, for a user or an administrator**, that can display a token after
 * creation (GitLab: "after you leave or refresh the page, you cannot view it
 * again"; the convention is a visible prefix as a *handle* and nothing more).
 * {@see PersonalTokens::query()} never selects the `token` column, so the hash
 * cannot reach this page even by accident — the same guarantee the user-scoped
 * reader makes.
 *
 * What is left is the metadata an auditor actually needs: the label, the holder,
 * when it was created, **when it was last used**, and when it expires. "Never used"
 * is rendered as words rather than an empty cell and coloured as a warning, because
 * a long-lived credential that has never been presented is the finding, not the
 * absence of one.
 *
 * ## The estate column is reach, not scope
 *
 * A personal token belongs to a *person* and carries their authority in every
 * estate they belong to; its routes sit outside `ResolveApiTenant` for exactly that
 * reason. So the estates beside a token are **the holder's memberships**, and the
 * column heading and the page description say so. Presenting them as the token's
 * scope would describe a narrowing that nothing in this runtime enforces — the same
 * mistake the `abilities` column avoids by never being offered as a restriction.
 *
 * ## Why this read is audited when most `/ops` pages are not
 *
 * OWASP's multi-tenant guidance is that a cross-tenant administrative path must be
 * "explicit, separately authorized, and auditable". This page is explicit (its own
 * route), separately authorized (operator estate + `manage_options`, like every
 * `/ops` page), and audited — one row per visit, naming the operator and which
 * estate they narrowed to. A credential inventory is the kind of read whose
 * *occurrence* matters even when it changes nothing.
 *
 * The estate filter is a **URL parameter**, not Livewire state: `?tenant=acme`.
 * That makes "the tokens that can reach Acme" a link an operator can share, and it
 * makes each narrowing a page load — so the audit row records *which estate was
 * looked at*, not merely that the page was opened.
 */
class TokenInventory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'personal-tokens';

    protected static ?string $navigationLabel = 'Personal tokens';

    protected static ?string $title = 'Personal access tokens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Access;

    // After the scheduler (20), before Settings (90): the credential inventory
    // closes the operational run.
    protected static ?int $navigationSort = 23;

    protected string $view = 'filament.operator.personal-tokens';

    /** The estate slug the view is narrowed to; empty means every estate. */
    public string $estate = '';

    /** Memoised rows for the header counts and the audit payload. */
    private ?array $rowsCache = null;

    /** Holder id to membership list, built once from {@see self::rows()}. */
    private ?array $reachCache = null;

    public function mount(): void
    {
        $requested = trim((string) request()->query('tenant', ''));

        // Only a slug that actually holds tokens is accepted. An unknown slug is
        // ignored rather than honoured, so a hand-edited URL cannot produce an
        // empty page that looks like "this estate has no tokens".
        if ('' !== $requested) {
            foreach (PersonalTokens::estatesWithHolders() as $candidate) {
                if ($candidate['slug'] === $requested) {
                    $this->estate = $requested;

                    break;
                }
            }
        }

        $this->audit();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * The tokens in scope, with holder and reach attached.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if (null === $this->rowsCache) {
            $this->rowsCache = PersonalTokens::acrossEstates('' !== $this->estate ? $this->estate : null);
        }

        return $this->rowsCache;
    }

    /**
     * @return list<array{slug: string, name: string, holders: int, tokens: int}>
     */
    public function estates(): array
    {
        return PersonalTokens::estatesWithHolders();
    }

    /**
     * @return array{total: int, active: int, expired: int, unused: int}
     */
    public function totals(): array
    {
        return PersonalTokens::totals($this->rows());
    }

    /**
     * The estate currently in scope, for the heading; null when unscoped.
     *
     * @return array{slug: string, name: string, holders: int, tokens: int}|null
     */
    public function scopedEstate(): ?array
    {
        foreach ($this->estates() as $estate) {
            if ($estate['slug'] === $this->estate) {
                return $estate;
            }
        }

        return null;
    }

    /**
     * The table.
     *
     * **`->query()` and not `->records()`.** A records-backed table renders fine
     * and silently turns every `searchable()` and `sortable()` into a no-op,
     * because both are SQL-level. The scoping therefore lives in
     * {@see PersonalTokens::query()} — one place that decides what is in scope —
     * and the search and sort behave.
     *
     * **Every SQL-level column is qualified, and the joined ones need a closure.**
     * The query joins `users` to get the holder, so a bare `name`, `created_at` or
     * `id` matches two tables and the database rejects it as ambiguous. But
     * passing `users.name` to `searchable()`/`sortable()` does **not** work
     * either: Filament treats any dotted name that does not start with the
     * model's own table as a **JSON path**, and emits
     * `json_extract("users", '$."name"')` — a malformed-JSON error at runtime.
     * Columns on `personal_access_tokens` can be named (that prefix is
     * recognised); columns on `users` must go through the `query:` closure, which
     * bypasses the name resolution entirely.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(PersonalTokens::query('' !== $this->estate ? $this->estate : null))
            ->columns([
                TextColumn::make('holder_name')
                    ->label('Holder')
                    ->searchable(query: static fn (Builder $query, string $search): Builder => self::searchColumn($query, 'users.name', $search))
                    ->sortable(query: static fn (Builder $query, string $direction): Builder => $query->orderBy('users.name', $direction)),
                TextColumn::make('holder_email')
                    ->label('Email')
                    // Hidden by default, not dropped: seven columns overflowed the
                    // viewport and clipped the Status badge. The address is one
                    // toggle away and still matches the search box and the sort — a
                    // hidden-by-default column is not a `hidden()` column, so
                    // Filament still applies its search constraint.
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(query: static fn (Builder $query, string $search): Builder => self::searchColumn($query, 'users.email', $search))
                    ->sortable(query: static fn (Builder $query, string $direction): Builder => $query->orderBy('users.email', $direction)),
                TextColumn::make('name')
                    ->label('Label')
                    // The stored name carries the `oos-personal:` prefix, which is
                    // the separation mechanism and not something an operator reads.
                    ->formatStateUsing(
                        static fn (string $state): string => str_starts_with($state, PersonalTokens::NAME_PREFIX)
                            ? substr($state, strlen(PersonalTokens::NAME_PREFIX))
                            : $state
                    )
                    ->searchable(['personal_access_tokens.name']),
                TextColumn::make('reach')
                    ->label('Reaches')
                    ->state(fn (object $record): array => $this->reachLines((int) $record->tokenable_id))
                    // One estate per line. A straight comma-joined phrase wrapped to
                    // four lines in a narrow column and made the roles harder to
                    // read than the estates, which is backwards: the role is the
                    // risk.
                    ->listWithLineBreaks()
                    ->bulleted(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d')
                    // The least load-bearing column on a page whose job is "who,
                    // how long, how wide": `Expires` and `Last used` answer the
                    // questions, and hiding this by default is what keeps the table
                    // from scrolling sideways. Still sortable, and one toggle away.
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(['personal_access_tokens.created_at']),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    // The finding, not the absence of one: a credential minted six
                    // months ago and never presented is the reason to look.
                    ->state(static fn (object $record): string => null === $record->last_used_at
                        ? 'Never used'
                        : substr((string) $record->last_used_at, 0, 16))
                    ->color(static fn (object $record): string => null === $record->last_used_at ? 'warning' : 'gray')
                    ->sortable(['personal_access_tokens.last_used_at']),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->state(static fn (object $record): string => null === $record->expires_at
                        ? '—'
                        : substr((string) $record->expires_at, 0, 10))
                    ->sortable(['personal_access_tokens.expires_at']),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(static fn (object $record): string => null !== $record->expires_at
                        && strtotime((string) $record->expires_at) <= now()->getTimestamp()
                            ? 'expired'
                            : 'active')
                    ->badge()
                    ->color(static fn (string $state): string => 'expired' === $state ? 'danger' : 'success'),
            ])
            ->defaultSort('personal_access_tokens.created_at', 'desc')
            ->searchPlaceholder('Holder, email or label')
            ->emptyStateHeading('No personal tokens')
            ->emptyStateDescription(
                '' === $this->estate
                    ? 'Nobody on this deployment has minted a personal access token. They are created by each user under Connections → Personal tokens.'
                    : 'Nobody in this estate holds a personal token. A token belongs to a person, so one minted by someone who later left the estate does not appear here.'
            )
            ->paginated(false);
    }

    /**
     * A `like` constraint with the search box's own wildcards escaped.
     *
     * Filament does this for its own columns with helpers marked `@internal`
     * ("please do not use this function in your own code"), so the rule is ported
     * rather than imported: `!` is the escape character and `!`, `[`, `%` and `_`
     * are escaped. `ESCAPE '!'` is the portable spelling — SQLite has no default
     * escape character for `like`, so the MySQL backslash convention would not
     * survive the test suite. The value is bound, and the identifier is wrapped by
     * the connection's own grammar.
     */
    private static function searchColumn(Builder $query, string $column, string $search): Builder
    {
        $pattern = '%'.str_replace(
            array('!', '[', '%', '_'),
            array('!!', '![', '!%', '!_'),
            $search
        ).'%';

        return $query->whereRaw(
            $query->getQuery()->getGrammar()->wrap($column)." like ? escape '!'",
            array($pattern)
        );
    }

    /**
     * The holder's memberships as one line per estate.
     *
     * The role travels with each estate because a long-lived token held by a
     * `viewer` is a different fact from one held by an `owner`: a token presents
     * whatever the role grants *at request time*.
     *
     * @return list<string>
     */
    public function reachLines(int $holderId): array
    {
        if (null === $this->reachCache) {
            $this->reachCache = array();

            // Built from the rows the page already has, so the column costs no
            // extra query. `rows()` is memoised, so this is one read of one array.
            foreach ($this->rows() as $row) {
                $this->reachCache[(int) $row['holder_id']] = $row['estates'];
            }
        }

        $estates = $this->reachCache[$holderId] ?? array();

        if (array() === $estates) {
            return array('No estate');
        }

        return array_map(
            static fn (array $estate): string => $estate['name'].' · '.$estate['role'],
            $estates
        );
    }

    /**
     * Record that the inventory was read.
     *
     * One row per page load. The record id carries the scope so the trail answers
     * "which estate did they look at" — the question this page exists to make
     * answerable about *itself* as well as about tokens.
     */
    private function audit(): void
    {
        app(AdminAuditLogger::class)->recordResource(
            'personal-tokens-inventory',
            '' === $this->estate ? 'all-estates' : $this->estate,
            'view',
            array(),
            array(
                'scope' => '' === $this->estate ? 'all-estates' : $this->estate,
                // Named `count`, not `tokens`: the audit redactor
                // substring-matches "token" (deliberately over-redacting) and
                // would store `[redacted]` in place of the figure. The number
                // is not the secret — the rows are, and none are in here.
                'count' => count($this->rows()),
            )
        );
    }
}
