<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\EstateCredentials;
use BackedEnum;
use UnitEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Estate assistant credentials — Proposal 149 §24, `/admin/credentials`.
 *
 * The console has had this surface (`tokenManager`) since 090-g; the panel had
 * nothing, so a tenant administrator who does not use the console could not see
 * which credentials their assistants are holding, or revoke a leaked one.
 *
 * SUBSET, not full, and the contract row says so. Listing and revoking are
 * here; issuing is not. Issuing is *rotation* — `/ops/credentials` creates the
 * replacement before deleting the old ids — and rotation is an operator action
 * (`credentials.assistant`, `writer: ops`). A customer-facing "issue" button
 * would either duplicate that flow with different semantics or hand a
 * plaintext credential to a page that is not built to burn it after one render.
 *
 * Revoking is a delete, not a state change: Sanctum resolves the bearer token
 * from `personal_access_tokens` on every request, so a deleted row stops
 * working immediately. That is why the action is two-step (armed, then
 * confirmed) rather than undoable — there is nothing to undo to.
 *
 * Scoping is by the panel's tenant, not by ambient request state.
 * `EstateCredentials` takes an explicit estate id because the panel resolves
 * tenancy through Filament and does not populate `TenantContext` on every
 * request; passing ambient state here would either read the wrong estate or
 * silently read the default one.
 */
class EstateCredentialsPage extends Page
{
    protected static ?string $slug = 'credentials';

    protected static ?string $navigationLabel = 'Credentials';

    protected static ?string $title = 'Estate credentials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Connections;

    // After the endpoints: a connection names the URL, a credential names the key.
    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.admin.estate-credentials';

    /**
     * Credential id armed for revocation; empty when nothing is armed.
     *
     * Two-step on purpose: the button becomes "Confirm revoke" and only the
     * second click deletes. A single click on a destructive, irreversible
     * action next to a list of look-alike rows is how the wrong row dies.
     */
    public string $pendingRevoke = '';

    /** Assistant id that the armed credential belongs to. */
    public int $pendingAssistant = 0;

    /** Post-action status line; empty when nothing has happened this request. */
    public string $status = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * The estate's credential overview.
     *
     * @return array{stats: array{total: int, active: int, revoked: int, assistants: int}, credentials: list<array<string, mixed>>, restrictions: list<mixed>}
     */
    public function overview(): array
    {
        return EstateCredentials::overview($this->estateId());
    }

    /**
     * Arm a credential for revocation. A second call with the same credential
     * is a no-op — the confirm button is the only way through.
     */
    public function confirmRevoke(string $credentialId, int $assistantId): void
    {
        $this->pendingRevoke = $credentialId;
        $this->pendingAssistant = $assistantId;
        $this->status = '';
    }

    public function cancelRevoke(): void
    {
        $this->pendingRevoke = '';
        $this->pendingAssistant = 0;
    }

    /**
     * Delete the armed credential.
     *
     * The id and the assistant both come from the armed state, and
     * `EstateCredentials::revoke()` re-checks that the credential's assistant
     * belongs to this estate — so a stale page (or a tampered Livewire payload)
     * cannot revoke across estates. A credential that has already gone is
     * reported as such rather than as an error.
     *
     * A successful revoke writes a record row to the audit trail. Panel actions
     * travel over Livewire's `/livewire/update`, which `AuditAdminRequests`
     * deliberately skips as UI mechanics — so without this call a destructive
     * action would leave no trace at all. The row records the assistant and the
     * outcome, never the token: the token is never read here.
     */
    public function revoke(): void
    {
        if ('' === $this->pendingRevoke) {
            return;
        }

        $credentialId = (int) $this->pendingRevoke;
        $assistantId = $this->pendingAssistant;

        $revoked = EstateCredentials::revoke($this->estateId(), $assistantId, $credentialId);

        if ($revoked) {
            app(AdminAuditLogger::class)->recordResource(
                'credentials',
                (string) $credentialId,
                'revoke',
                array('assistant_id' => $assistantId, 'present' => true),
                array('assistant_id' => $assistantId, 'present' => false)
            );
        }

        $this->status = $revoked
            ? 'Credential revoked. The token stopped working immediately.'
            : 'That credential was not found in this estate; nothing was changed.';

        $this->cancelRevoke();
    }

    /**
     * The estate this page is scoped to.
     *
     * Returns an empty string when the panel has no tenant. Every route into
     * this page is tenant-scoped, so that is a misconfiguration rather than a
     * normal state — and an empty estate id yields an empty overview, which is
     * the fail-closed answer. Falling back to `TenantContext` would silently
     * read the default estate instead.
     */
    private function estateId(): string
    {
        $tenant = Filament::getTenant();

        return null === $tenant ? '' : (string) $tenant->getKey();
    }
}
