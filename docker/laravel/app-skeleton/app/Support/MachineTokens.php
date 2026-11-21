<?php

namespace App\Support;

use App\Support\PersonalTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The machine credentials on this deployment — Proposal 149 §129.
 *
 * A machine token is a Sanctum personal access token, but it is not a *person's*
 * token: it is issued by an operator (159 slice 3) to act for one assistant. The
 * three token families are separated by their stored name, which is the only
 * field that distinguishes them:
 *
 *  - `oos-personal:` — a user's own token, revoked by the user on `/admin`;
 *  - `oos-assistant-{id}` — an assistant credential, rotated on `/ops`;
 *  - `oos-machine:` — this one, minted by `nvoos:machine-token`.
 *
 * Before the prefix existed, a machine token carried an operator-chosen label
 * with no owner, so no surface could list it — and the mint command told the
 * operator to revoke it "in Token inventory", where it never appeared. The
 * prefix is what makes the promise keepable; tokens minted before it are not
 * listed and remain a console concern (rotate them to adopt the prefix).
 */
final class MachineTokens
{
    /** The stored-name prefix every machine credential carries. */
    public const NAME_PREFIX = 'oos-machine:';

    /**
     * Every machine credential, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::rows(static fn (string $name): bool => str_starts_with($name, self::NAME_PREFIX));
    }

    /**
     * The credentials the expiry report covers: machine tokens plus the legacy
     * names that predate the prefix, and **not** a person's own token.
     *
     * The rule matches `ListExpiringMachineTokens` §131 exactly; keeping it here
     * means the command and the acknowledgement action cannot disagree about
     * which set they are talking about.
     *
     * @return list<array<string, mixed>>
     */
    public static function reportable(): array
    {
        return self::rows(static function (string $name, array $abilities): bool {
            if (str_starts_with($name, self::NAME_PREFIX)) {
                return true;
            }

            if (str_starts_with($name, PersonalTokens::NAME_PREFIX)) {
                return false;
            }

            return array() !== $abilities && ! in_array('*', $abilities, true);
        });
    }

    /**
     * The reportable credentials inside the window, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function expiring(int $days): array
    {
        $window = now()->addDays($days);

        return array_values(array_filter(
            self::reportable(),
            static fn (array $row): bool => null !== $row['expires_at']
                && Carbon::parse((string) $row['expires_at'])->lessThanOrEqualTo($window)
        ));
    }

    /**
     * Rows for the tokens this selector accepts.
     *
     * @param callable(string, array<int, string>): bool $include
     * @return list<array<string, mixed>>
     */
    private static function rows(callable $include): array
    {
        $rows = DB::table('personal_access_tokens as t')
            ->leftJoin('users', 'users.id', '=', 't.tokenable_id')
            ->orderBy('t.expires_at')
            ->get(array(
                't.id',
                't.name',
                't.abilities',
                't.expires_at',
                't.last_used_at',
                't.created_at',
                't.tokenable_id',
                'users.name as holder',
                'users.email as holder_email',
            ));

        $tokens = array();

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->abilities, true);
            $abilities = is_array($decoded) ? array_values(array_map('strval', $decoded)) : array();
            $name = (string) $row->name;

            if (! $include($name, $abilities)) {
                continue;
            }

            $expires = null === $row->expires_at ? null : (string) $row->expires_at;

            $tokens[] = array(
                'id' => (int) $row->id,
                'label' => self::label($name),
                'holder' => (string) ($row->holder ?? '') !== ''
                    ? (string) $row->holder
                    : (string) ($row->holder_email ?? '—'),
                'scopes' => $abilities,
                'expires' => null === $expires ? 'Never' : substr($expires, 0, 10),
                'expires_at' => $expires,
                'last_used' => null === $row->last_used_at ? null : (string) $row->last_used_at,
                'created_at' => null === $row->created_at ? null : (string) $row->created_at,
                'age_days' => null === $row->created_at
                    ? null
                    : (int) Carbon::parse((string) $row->created_at)->diffInDays(now()),
                'status' => null !== $expires && strtotime($expires) <= now()->getTimestamp()
                    ? 'expired'
                    : 'active',
            );
        }

        return $tokens;
    }

    /**
     * The operator-facing label: the stored name without the prefix.
     */
    public static function label(string $name): string
    {
        return str_starts_with($name, self::NAME_PREFIX)
            ? substr($name, strlen(self::NAME_PREFIX))
            : $name;
    }

    /**
     * Delete one machine credential, refusing anything that is not one.
     *
     * The `like` guard is defence in depth: the action is given an id from the
     * table, and a hand-crafted id must not delete a user's own token.
     *
     * @return array<string, mixed>|null the deleted row, or null when not a machine token
     */
    public static function revoke(int $id): ?array
    {
        $row = DB::table('personal_access_tokens')
            ->where('id', $id)
            ->where('name', 'like', self::NAME_PREFIX.'%')
            ->first();

        if (null === $row) {
            return null;
        }

        DB::table('personal_access_tokens')->where('id', $id)->delete();

        return array(
            'id' => $id,
            'name' => (string) $row->name,
            'tokenable_id' => (int) $row->tokenable_id,
            'expires_at' => null === $row->expires_at ? null : (string) $row->expires_at,
        );
    }
}
