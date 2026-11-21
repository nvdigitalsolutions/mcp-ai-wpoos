<?php

namespace App\Support;

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
        $rows = DB::table('personal_access_tokens as t')
            ->leftJoin('users', 'users.id', '=', 't.tokenable_id')
            ->where('t.name', 'like', self::NAME_PREFIX.'%')
            ->orderByDesc('t.created_at')
            ->get(array(
                't.id',
                't.name',
                't.abilities',
                't.expires_at',
                't.last_used_at',
                't.created_at',
                'users.name as holder',
                'users.email as holder_email',
            ));

        $tokens = array();

        foreach ($rows as $row) {
            $abilities = json_decode((string) $row->abilities, true);
            $expires = null === $row->expires_at ? null : (string) $row->expires_at;

            $tokens[] = array(
                'id' => (int) $row->id,
                'label' => self::label((string) $row->name),
                'holder' => (string) ($row->holder ?? '') !== ''
                    ? (string) $row->holder
                    : (string) ($row->holder_email ?? '—'),
                'scopes' => is_array($abilities) ? array_values(array_map('strval', $abilities)) : array(),
                'expires' => $expires,
                'last_used' => null === $row->last_used_at ? null : (string) $row->last_used_at,
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
