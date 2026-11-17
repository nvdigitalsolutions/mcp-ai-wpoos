<?php

namespace App\Support;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one reader (and the one revoke primitive) for estate assistant
 * credentials — Proposal 149 §24.
 *
 * Two presentations read it: `GET /api/v1/token-manager/overview` for the
 * console's `tokenManager` surface, and `/admin/credentials` for the panel.
 * The payload is a published console contract (the SPA's token-manager page
 * reads `stats`, `credentials` and `restrictions`), so the mapping lives here
 * once and both callers delegate (149 R1).
 *
 * Security rules, unchanged from the controller this was extracted from:
 *
 * - **The token hash is never read or returned.** Only metadata columns are
 *   selected; the `token` column never leaves the database.
 * - **Revocation deletes the row.** Sanctum resolves the bearer token from
 *   `personal_access_tokens` on every request, so a deleted row stops working
 *   immediately. A soft-revoked row would keep authenticating — a credential
 *   that *looks* revoked but still works is the failure mode worth avoiding.
 * - **Expired tokens are reported as revoked.** Sanctum rejects them, so the
 *   status is truthful; `revoked_at` carries the expiry timestamp.
 * - **Every read and every revoke is scoped to an explicit estate.** The
 *   tenant id is a parameter, not ambient state: `personal_access_tokens` has
 *   no tenant column and its `tokenable` is the user who issued the credential,
 *   not the estate that owns the assistant, so the only reliable link is the
 *   assistant id in the token name. A caller that passed `TenantContext` here
 *   would break the panel, which resolves tenancy through Filament and does not
 *   populate that context on every request.
 *
 * Only tokens named `oos-assistant-*` are listed. Other Sanctum tokens in the
 * table belong to unrelated features and are none of this surface's business —
 * which is also why `tokens.personal` is a separate row in the ownership
 * contract and is still unbuilt.
 *
 * Restrictions (`TokenRestriction`) have no store on this runtime: the rate
 * limiter is window-based and keeps no persisted block, so the list is always
 * empty. That is honest rather than invented.
 */
final class EstateCredentials
{
    private const NAME_PATTERN = '/^oos-assistant-(\d+)$/';

    /**
     * Credentials for the estate's own assistants, newest first.
     *
     * The shape is the console contract: `stats` (`total`, `active`,
     * `revoked`, `assistants`), `credentials` (`id`, `status`, `assistant_id`,
     * `assistant_title`, `created_at`, `created_by`, `created_by_name`,
     * `revoked_at`, `revoked_by`, `expires_at`) and an always-empty
     * `restrictions` list. Do not add, rename or reorder keys without changing
     * the SPA's token-manager page in the same commit.
     *
     * @param string $tenantId Estate whose assistants' credentials to read.
     * @return array{stats: array{total: int, active: int, revoked: int, assistants: int}, credentials: list<array<string, mixed>>, restrictions: list<mixed>}
     */
    public static function overview(string $tenantId): array
    {
        $estateAssistants = self::estateAssistantIds($tenantId);

        if (array() === $estateAssistants) {
            return array(
                'stats' => array(
                    'total' => 0,
                    'active' => 0,
                    'revoked' => 0,
                    'assistants' => 0,
                ),
                'credentials' => array(),
                'restrictions' => array(),
            );
        }

        $rows = DB::table('personal_access_tokens')
            ->whereIn('name', self::credentialNames($estateAssistants))
            ->orderByDesc('created_at')
            ->get(array(
                'id',
                'tokenable_id',
                'name',
                'last_used_at',
                'expires_at',
                'created_at',
            ));

        $assistantIds = array();
        $userIds = array();

        foreach ($rows as $row) {
            $assistantId = self::assistantIdFrom((string) $row->name);

            if ($assistantId > 0) {
                $assistantIds[$assistantId] = true;
            }

            $userIds[(int) $row->tokenable_id] = true;
        }

        $titles = array() === $assistantIds
            ? array()
            : Assistant::query()->whereIn('id', array_keys($assistantIds))->pluck('title', 'id')->all();

        $names = array() === $userIds
            ? array()
            : User::query()->whereIn('id', array_keys($userIds))->pluck('name', 'id')->all();

        $now = now();
        $credentials = array();
        $active = 0;
        $revoked = 0;
        $assistants = array();

        foreach ($rows as $row) {
            $assistantId = self::assistantIdFrom((string) $row->name);
            $expiresAt = $row->expires_at ? (string) $row->expires_at : '';
            $expired = '' !== $expiresAt && strtotime($expiresAt) <= $now->getTimestamp();

            if ($expired) {
                ++$revoked;
            } else {
                ++$active;
            }

            if ($assistantId > 0) {
                $assistants[$assistantId] = true;
            }

            $credentials[] = array(
                'id' => (string) $row->id,
                'status' => $expired ? 'revoked' : 'active',
                'assistant_id' => $assistantId,
                'assistant_title' => (string) ($titles[$assistantId] ?? ''),
                // ISO-8601, as the console's other timestamps are.
                'created_at' => self::iso((string) $row->created_at),
                'created_by' => (int) $row->tokenable_id,
                'created_by_name' => (string) ($names[(int) $row->tokenable_id] ?? ''),
                // No revocation state exists: revocation is deletion.
                'revoked_at' => $expired ? self::iso($expiresAt) : '',
                'revoked_by' => 0,
                'expires_at' => self::iso($expiresAt),
            );
        }

        return array(
            'stats' => array(
                'total' => count($credentials),
                'active' => $active,
                'revoked' => $revoked,
                'assistants' => count($assistants),
            ),
            'credentials' => $credentials,
            'restrictions' => array(),
        );
    }

    /**
     * Delete one credential row, refusing anything outside the estate pair.
     *
     * Both the assistant and the credential must belong together: the token's
     * name must decode to the same assistant id in the URL, and that assistant
     * must belong to the estate. Otherwise a route (or a page action) could
     * revoke another estate's credential by guessing an id.
     *
     * Returns false rather than throwing so the HTTP caller can answer 404 and
     * the panel can report "no longer there" without a stack trace.
     *
     * @param string $tenantId     Estate that must own the assistant.
     * @param int    $assistantId  Assistant the credential is said to belong to.
     * @param int    $credentialId `personal_access_tokens.id`.
     */
    public static function revoke(string $tenantId, int $assistantId, int $credentialId): bool
    {
        if (! self::ownsAssistant($tenantId, $assistantId)) {
            return false;
        }

        $row = DB::table('personal_access_tokens')
            ->where('id', $credentialId)
            ->where('name', 'like', 'oos-assistant-%')
            ->first(array('id', 'name'));

        if (null === $row || self::assistantIdFrom((string) $row->name) !== $assistantId) {
            return false;
        }

        DB::table('personal_access_tokens')->where('id', (int) $row->id)->delete();

        return true;
    }

    /**
     * Assistant ids belonging to an estate.
     *
     * @return list<int>
     */
    public static function estateAssistantIds(string $tenantId): array
    {
        return Assistant::query()
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Whether the assistant belongs to the estate.
     */
    public static function ownsAssistant(string $tenantId, int $assistantId): bool
    {
        return Assistant::query()
            ->where('id', $assistantId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Token names for a set of assistant ids.
     *
     * Scoping by the literal names keeps the query indexed by `name` and avoids
     * a `like` scan over every estate's credentials.
     *
     * @param list<int> $assistantIds
     * @return list<string>
     */
    private static function credentialNames(array $assistantIds): array
    {
        return array_map(
            static fn (int $id): string => 'oos-assistant-'.$id,
            $assistantIds
        );
    }

    private static function assistantIdFrom(string $name): int
    {
        return 1 === preg_match(self::NAME_PATTERN, $name, $matches)
            ? (int) $matches[1]
            : 0;
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
