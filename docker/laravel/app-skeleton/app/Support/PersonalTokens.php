<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * User-scoped personal access tokens — Proposal 149 §26.
 *
 * The third token row, and the one that is genuinely a *personal* credential:
 * `credentials.assistant` (`/ops`, rotation) and `credentials.estate`
 * (`/admin`, list + revoke) both operate on tokens named `oos-assistant-{id}`,
 * which belong to an assistant. A personal token belongs to a user and is the
 * only kind a user may mint for themselves.
 *
 * The prefix is the separation mechanism. `EstateCredentials` and the console's
 * token manager both match `oos-assistant-*` names exactly, so a token named
 * `oos-personal:*` can never appear in an estate's credential list, and the
 * reverse holds here. One record set per surface, enforced by the name rather
 * than by a shared boolean column.
 *
 * ## Security model, stated plainly
 *
 * - **Abilities are not the authority.** Nothing in this runtime calls
 *   `tokenCan()` or applies `abilities:` middleware; authorization comes from
 *   the user's *current* role through `CapabilityMap`. A token is therefore a
 *   way to present your own authority over HTTP, not to hold a frozen subset of
 *   it — and a demotion or promotion takes effect on the next request.
 *   The stored ability is `['*']` because that is the truth; writing
 *   `['edit_posts']` would assert a restriction nothing enforces.
 * - **The plaintext is returned exactly once and never stored.** Only the
 *   metadata columns are ever selected, so the hash cannot leak through a list.
 * - **Revocation deletes the row.** Sanctum re-reads `personal_access_tokens`
 *   on every request, so deletion stops the token immediately. There is no
 *   "revoked but still valid" state.
 * - **Ownership is checked in the query.** Every read and the revoke path filter
 *   on `tokenable_id`, so a guessed id answers 404 rather than disclosing that
 *   the row exists.
 * - **An expiry is required.** A personal token with no expiry is a permanent
 *   credential pasted into a chat window; this surface refuses to create one.
 */
final class PersonalTokens
{
    /** Name prefix that separates personal tokens from assistant credentials. */
    public const NAME_PREFIX = 'oos-personal:';

    /** Shortest and longest lifetime this surface will mint. */
    public const MIN_DAYS = 1;

    public const MAX_DAYS = 365;

    /** Longest label that survives sanitisation, in characters. */
    public const MAX_LABEL = 60;

    /**
     * The expiry choices the UI offers, in days => label.
     *
     * 30 days is the default: long enough not to be a nuisance, short enough
     * that a forgotten token stops working on its own.
     *
     * @return array<int, string>
     */
    public static function expiryOptions(): array
    {
        return array(
            30 => '30 days',
            90 => '90 days',
            180 => '180 days',
            365 => '365 days',
        );
    }

    /**
     * The user's own tokens, newest first.
     *
     * Metadata only — `token` is never selected, so the hash cannot reach a
     * caller even by accident.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(int $userId): array
    {
        if ($userId <= 0) {
            return array();
        }

        $rows = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->where('name', 'like', self::NAME_PREFIX.'%')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(array(
                'id',
                'name',
                'abilities',
                'last_used_at',
                'expires_at',
                'created_at',
            ));

        $now = now();

        return array_values($rows->map(
            static fn ($row): array => self::normalise($row, $now)
        )->all());
    }

    /**
     * Mint a token for a user.
     *
     * Returns the plaintext once. Nothing audits, logs or stores it: an audit
     * row carrying a live credential would defeat the point of hashing it.
     *
     * @param string $label Human label; sanitised to `[A-Za-z0-9 ._-]`.
     * @param int    $days  Lifetime in days, clamped to MIN_DAYS..MAX_DAYS.
     * @return array{id: int, name: string, label: string, plaintext: string, abilities: list<string>, expires_at: string}|null
     *         Null when the user cannot be resolved.
     */
    public static function mint(int $userId, string $label, int $days): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        if (null === $user) {
            return null;
        }

        $label = self::sanitiseLabel($label);
        $days = max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
        $expiresAt = Carbon::now()->addDays($days);

        $abilities = array('*');

        $token = $user->createToken(self::NAME_PREFIX.$label, $abilities, $expiresAt);

        return array(
            'id' => (int) $token->accessToken->getKey(),
            'name' => (string) $token->accessToken->name,
            'label' => $label,
            'plaintext' => (string) $token->plainTextToken,
            'abilities' => $abilities,
            'expires_at' => $expiresAt->toIso8601String(),
        );
    }

    /**
     * Delete one of the user's own tokens.
     *
     * Returns false when the id does not exist, belongs to another user, or
     * names an assistant credential — all three answer the same way so the
     * route cannot be used to probe which ids exist.
     */
    public static function revoke(int $userId, int $tokenId): bool
    {
        if ($userId <= 0 || $tokenId <= 0) {
            return false;
        }

        $deleted = DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->where('name', 'like', self::NAME_PREFIX.'%')
            ->delete();

        return $deleted > 0;
    }

    /**
     * Counts a header can show without the view walking the rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, active: int, expired: int, unused: int}
     */
    public static function totals(array $rows): array
    {
        $active = 0;
        $expired = 0;
        $unused = 0;

        foreach ($rows as $row) {
            if ('expired' === $row['status']) {
                ++$expired;
            } else {
                ++$active;
            }

            if ('' === $row['last_used_at']) {
                ++$unused;
            }
        }

        return array(
            'total' => count($rows),
            'active' => $active,
            'expired' => $expired,
            'unused' => $unused,
        );
    }

    /**
     * Reduce a label to something safe to embed in a token name.
     *
     * The name is stored verbatim and matched with a prefix, so a colon or a
     * wildcard in a user-supplied label must never reach it. Stripping rather
     * than rejecting keeps the mint a one-step action; the fallback makes an
     * all-symbol label still produce a valid token.
     */
    public static function sanitiseLabel(string $label): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ._-]+/', '', $label) ?? '';
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
        $clean = trim($clean);

        if ('' === $clean) {
            return 'token';
        }

        return substr($clean, 0, self::MAX_LABEL);
    }

    /**
     * @param object{id: mixed, name: mixed, abilities: mixed, last_used_at: mixed, expires_at: mixed, created_at: mixed} $row
     * @return array<string, mixed>
     */
    private static function normalise(object $row, Carbon $now): array
    {
        $name = (string) $row->name;
        $expiresAt = null === $row->expires_at ? '' : (string) $row->expires_at;
        $expired = '' !== $expiresAt && strtotime($expiresAt) <= $now->getTimestamp();

        return array(
            'id' => (int) $row->id,
            'name' => $name,
            'label' => str_starts_with($name, self::NAME_PREFIX)
                ? substr($name, strlen(self::NAME_PREFIX))
                : $name,
            'abilities' => self::decodeAbilities((string) ($row->abilities ?? '')),
            'created_at' => self::iso((string) $row->created_at),
            'last_used_at' => null === $row->last_used_at ? '' : self::iso((string) $row->last_used_at),
            'expires_at' => '' === $expiresAt ? '' : self::iso($expiresAt),
            // Sanctum rejects an expired token, so reporting it as expired is
            // what the runtime actually does rather than a UI nicety.
            'status' => $expired ? 'expired' : 'active',
        );
    }

    /**
     * @return list<string>
     */
    private static function decodeAbilities(string $raw): array
    {
        if ('' === $raw) {
            return array();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return array();
        }

        return array_values(array_map('strval', $decoded));
    }

    private static function iso(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        $timestamp = strtotime($value);

        return false === $timestamp ? '' : gmdate('c', $timestamp);
    }
}
