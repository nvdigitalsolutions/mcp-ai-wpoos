<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\PersonalTokens;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Personal access tokens — Proposal 149 §26, `/admin/personal-tokens`.
 *
 * The third and last token row. Unlike the two credential surfaces, this one is
 * **user-scoped**: a token belongs to a person, not to an assistant or an
 * estate, so the page lists the signed-in user's tokens only and there is no
 * estate filter. Ownership is the authorization, not a capability — a token
 * carries exactly the authority the user has at request time.
 *
 * `full` on this surface: mint, list, revoke. There is no operator cross-estate
 * view yet (that stays `planned` on `/ops`); a half-built view would be worse
 * than an honestly-planned one, because "whose tokens can I see" is the whole
 * question on a cross-estate surface.
 *
 * Two things are deliberately absent:
 *
 * - **No ability selector.** Nothing in this runtime enforces Sanctum abilities;
 *   authorization comes from the user's current role. Offering a "read-only
 *   token" checkbox would be a promise the server does not keep.
 * - **No "never expires" option.** A personal token without an expiry is a
 *   permanent credential pasted into a chat window.
 *
 * The plaintext appears once, in `$plaintext`, and is cleared by every action
 * that could replace or invalidate it. It is never written to the audit trail:
 * a record row carrying a live credential would defeat hashing it.
 */
class PersonalTokensPage extends Page
{
    protected static ?string $slug = 'personal-tokens';

    protected static ?string $navigationLabel = 'Personal tokens';

    protected static ?string $title = 'Personal access tokens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    // With the credentials surface: both are token management.
    protected static ?int $navigationSort = 55;

    protected string $view = 'filament.admin.personal-tokens';

    /** Label for the token about to be minted. */
    public string $label = '';

    /** Lifetime in days for the token about to be minted. */
    public int $days = 30;

    /** The plaintext of the token just minted; null once dismissed. */
    public ?string $plaintext = null;

    /** Label of the token the plaintext belongs to. */
    public string $plaintextFor = '';

    /** Expiry of the token the plaintext belongs to. */
    public string $plaintextExpiresAt = '';

    /** Token id armed for revocation; empty when nothing is armed. */
    public string $pendingRevoke = '';

    /** Post-action status line; empty when nothing has happened this request. */
    public string $status = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * The signed-in user's tokens.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return PersonalTokens::rows($this->userId());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, active: int, expired: int, unused: int}
     */
    public function totals(array $rows): array
    {
        return PersonalTokens::totals($rows);
    }

    /**
     * @return array<int, string>
     */
    public function expiryOptions(): array
    {
        return PersonalTokens::expiryOptions();
    }

    /**
     * Mint a token and hold its plaintext for one render.
     *
     * The label is sanitised inside the reader, so a label that is all symbols
     * still produces a valid token rather than an error the user has to decode.
     */
    public function mint(): void
    {
        $minted = PersonalTokens::mint($this->userId(), $this->label, $this->days);

        if (null === $minted) {
            $this->status = 'Could not mint a token: the signed-in user could not be resolved.';
            $this->plaintext = null;

            return;
        }

        $this->plaintext = (string) $minted['plaintext'];
        $this->plaintextFor = (string) $minted['label'];
        $this->plaintextExpiresAt = (string) $minted['expires_at'];
        $this->label = '';
        $this->status = '';

        // The plaintext is deliberately absent from the audit row.
        app(AdminAuditLogger::class)->recordResource(
            'personal-tokens',
            (string) $minted['id'],
            'mint',
            array(),
            array(
                'label' => (string) $minted['label'],
                'expires_at' => (string) $minted['expires_at'],
                'abilities' => $minted['abilities'],
            )
        );
    }

    /**
     * Discard the plaintext. It cannot be shown again — only re-minted.
     */
    public function dismissPlaintext(): void
    {
        $this->plaintext = null;
        $this->plaintextFor = '';
        $this->plaintextExpiresAt = '';
    }

    /**
     * Arm a token for revocation.
     */
    public function confirmRevoke(string $tokenId): void
    {
        $this->pendingRevoke = $tokenId;
        $this->status = '';
    }

    public function cancelRevoke(): void
    {
        $this->pendingRevoke = '';
    }

    /**
     * Delete the armed token.
     *
     * `PersonalTokens::revoke()` re-checks ownership in the query, so a stale
     * page cannot delete another user's token. Dismissing the plaintext here is
     * not cosmetic: if the armed token is the one whose secret is on screen,
     * leaving it visible after it stopped working would be misleading.
     */
    public function revoke(): void
    {
        if ('' === $this->pendingRevoke) {
            return;
        }

        $tokenId = (int) $this->pendingRevoke;

        $revoked = PersonalTokens::revoke($this->userId(), $tokenId);

        if ($revoked) {
            app(AdminAuditLogger::class)->recordResource(
                'personal-tokens',
                (string) $tokenId,
                'revoke',
                array('present' => true),
                array('present' => false)
            );

            $this->dismissPlaintext();
        }

        $this->status = $revoked
            ? 'Token revoked. It stopped working immediately.'
            : 'That token was not found on your account; nothing was changed.';

        $this->cancelRevoke();
    }

    /**
     * The signed-in user's id, or 0 when there is none.
     *
     * A zero id makes every reader return empty, which is the fail-closed
     * answer — the same choice `/admin/credentials` makes when the panel has no
     * tenant.
     */
    private function userId(): int
    {
        $user = auth()->user();

        return $user instanceof User ? (int) $user->getKey() : 0;
    }
}
