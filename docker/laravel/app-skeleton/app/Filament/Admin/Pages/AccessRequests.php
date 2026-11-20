<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Auth\EstateDomains;
use App\Support\Auth\JitProvisioner;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Who is trying to join this estate — Proposal 149 §110, decision 158 D2.
 *
 * JIT provisioning is off by default, which is right and which has a failure
 * mode: the person authenticates, gets a 403, and reads it as *broken*. Support
 * pressure then produces "just turn it on for everyone", which is exactly the
 * outcome deny-by-default exists to prevent. So the refusal is **recorded and
 * listable**, and this page is where a person resolves it.
 *
 * Two tables, one page:
 *
 *  - **Pending requests** — a subject that authenticated and was not provisioned,
 *    with the gate that stopped it. Approving creates the account and a `viewer`
 *    membership; dismissing closes it without one.
 *  - **Allowed domains** — the trust boundary JIT checks. A row added here is
 *    **unverified** until someone confirms it, so a proposer cannot provision
 *    themselves. `EstateDomains::allows()` ignores unverified rows.
 *
 * Every write on this page is audited. Livewire traffic is excluded from the
 * request middleware (plan §25), so a panel action that changes who is a member —
 * or which domains may create members — has no trace at all without an explicit
 * `recordResource()` call.
 *
 * No impersonation and no role choice: approving grants `viewer` (158 D4). A
 * different role is a separate action elsewhere, taken by someone accountable
 * for it.
 */
class AccessRequests extends Page
{
    protected static ?string $slug = 'access-requests';

    protected static ?string $navigationLabel = 'Access requests';

    protected static ?string $title = 'Access requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Workspace;

    // After Teams (30): the people who are here, then the people asking to be.
    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.admin.access-requests';

    /** The add-domain input. */
    public string $newDomain = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * Pending requests for this estate, newest attempt first.
     *
     * @return list<array{id: int, subject: string, email: string, reason: string, attempted_at: string}>
     */
    public function requests(): array
    {
        return app(JitProvisioner::class)->pendingRows($this->estateId());
    }

    /**
     * @return list<array{id: int, domain: string, verified: bool, created_at: string}>
     */
    public function domains(): array
    {
        return app(EstateDomains::class)->rows($this->estateId());
    }

    public function jitEnabled(): bool
    {
        return app(JitProvisioner::class)->enabled($this->estateId());
    }

    /**
     * Approve a request: create the account, grant `viewer`, close the row.
     */
    public function approve(int $id): void
    {
        $result = app(JitProvisioner::class)->approve($this->estateId(), $id, auth()->id());

        if (! $result['ok']) {
            $this->notify(false, (string) $result['error']);

            return;
        }

        $this->audit('access-requests', (string) $id, 'approve', array('status' => 'pending'), array('status' => 'approved', 'role' => JitProvisioner::ROLE));
        $this->notify(true, 'Approved. They can sign in on their next attempt, with viewer access.');
    }

    /**
     * Dismiss a request without provisioning.
     */
    public function dismiss(int $id): void
    {
        $done = app(JitProvisioner::class)->reject($this->estateId(), $id, auth()->id());

        if (! $done) {
            $this->notify(false, 'That request is no longer pending.');

            return;
        }

        $this->audit('access-requests', (string) $id, 'reject', array('status' => 'pending'), array('status' => 'rejected'));
        $this->notify(true, 'Dismissed. Nothing was granted.');
    }

    /**
     * Add a domain, unverified.
     */
    public function addDomain(): void
    {
        $result = app(EstateDomains::class)->add($this->estateId(), $this->newDomain, auth()->id());

        if (! $result['ok']) {
            $this->notify(false, (string) $result['error']);

            return;
        }

        $this->audit('estate-domains', (string) ($result['domain'] ?? ''), 'create', array(), array('domain' => $result['domain'], 'verified' => false));
        $this->newDomain = '';
        $this->notify(true, 'Added '.$result['domain'].'. It provisions nobody until it is verified.');
    }

    /**
     * Confirm a domain, which is what makes it able to provision.
     */
    public function verifyDomain(int $id): void
    {
        $this->setVerified($id, true);
    }

    /**
     * Withdraw confirmation. Existing members are unaffected; future logins stop
     * matching — that is the point of being able to do it.
     */
    public function unverifyDomain(int $id): void
    {
        $this->setVerified($id, false);
    }

    public function removeDomain(int $id): void
    {
        $removed = app(EstateDomains::class)->remove($this->estateId(), $id);

        if (! $removed) {
            $this->notify(false, 'That domain is not on this estate\'s list.');

            return;
        }

        $this->audit('estate-domains', (string) $id, 'delete', array('id' => $id), array());
        $this->notify(true, 'Removed. It can no longer provision anyone.');
    }

    /**
     * The reason in words, for the table. Delegates to the provisioner so the
     * page and the digest (§111) cannot disagree about what a gate means.
     */
    public function reasonLabel(string $reason): string
    {
        return JitProvisioner::reasonLabel($reason);
    }

    private function setVerified(int $id, bool $verified): void
    {
        $changed = app(EstateDomains::class)->setVerified($this->estateId(), $id, $verified);

        if (! $changed) {
            $this->notify(false, 'That domain is not on this estate\'s list.');

            return;
        }

        $this->audit(
            'estate-domains',
            (string) $id,
            $verified ? 'verify' : 'unverify',
            array('verified' => ! $verified),
            array('verified' => $verified)
        );

        $this->notify(true, $verified
            ? 'Verified. Addresses on this domain can now be provisioned on first login.'
            : 'Unverified. It provisions nobody until it is confirmed again.');
    }

    private function notify(bool $ok, string $message): void
    {
        Notification::make()
            ->title($message)
            ->status($ok ? 'success' : 'warning')
            ->send();
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function audit(string $resource, string $recordId, string $action, array $before, array $after): void
    {
        app(AdminAuditLogger::class)->recordResource($resource, $recordId, $action, $before, $after);
    }

    private function estateId(): string
    {
        return TenantContext::current();
    }
}
