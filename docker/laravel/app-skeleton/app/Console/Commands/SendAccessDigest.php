<?php

namespace App\Console\Commands;

use App\Mail\AccessDigest;
use App\Models\Tenant;
use App\Support\Auth\JitProvisioner;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Email each estate's administrators the access requests it has not answered —
 * Proposal 149 §111, closing §110.7 item 3.
 *
 * §110 recorded the refusal in a table and put a page in front of it. That is a
 * pull surface, and the failure mode it was built to prevent — a person reads
 * the 403 as *broken*, support hears about it, and provisioning gets switched on
 * for everyone — happens on a timescale of hours, not "whenever an administrator
 * next opens a page they have no reason to open". So the list is pushed.
 *
 * ## Why daily, at a fixed hour, and only when there is something to say
 *
 * Published guidance on notification digests converges on three rules, and each
 * is a property of this command rather than a style preference:
 *
 *  - **Digests are for useful-but-not-urgent.** A join attempt is exactly that:
 *    the person is already locked out and nothing degrades while the mail sits.
 *    Real-time channels are for what cannot wait, and spending one on this
 *    trains people to treat the urgent one as noise.
 *  - **A fixed time builds the habit that makes the mail get read.** An 08:00
 *    daily is a thing an administrator learns to expect. `schedule` is a cron
 *    expression so the hour moves without a code change.
 *  - **Nothing to report ⇒ send nothing.** An estate with zero pending requests
 *    is skipped silently. An empty digest is the mail that teaches the recipient
 *    that this channel can be ignored — and it is the mail that gets filtered,
 *    taking the non-empty one with it.
 *
 * ## Why it is one mail per estate
 *
 * The recipients are one estate's administrators and the content is that
 * estate's join attempts. A single combined mail to an operator would disclose
 * one customer's refused addresses to the vendor for a decision the customer
 * makes. `--tenant` narrows the run for debugging; it never broadens it.
 *
 * ## Fresh installs
 *
 * Both tables are optional in the sense that matters here: `tenant_user` arrives
 * with the tenancy migration and `nvoos_jit_provisioning_requests` with §110, so
 * a deployment that has not run that migration must find this command inert
 * rather than failing the schedule every morning.
 */
class SendAccessDigest extends Command
{
    private const REQUESTS = 'nvoos_jit_provisioning_requests';

    private const MEMBERSHIPS = 'tenant_user';

    /**
     * The roles an estate's decision-makers hold. `owner` is the WordPress
     * administrator (the membership backfill maps `administrator` → `owner`).
     */
    private const DECISION_ROLES = array('owner', 'admin');

    /**
     * Fallback when an estate has no owner or admin: everyone else the page would
     * let act. `editor` maps to `manager`, so an estate whose only members are
     * editors is a real configuration — and it is the one where "nobody was
     * told" would otherwise be silent.
     */
    private const ACTING_ROLES = array('manager', 'member');

    protected $signature = 'nvoos:access-digest
                            {--tenant= : Only this estate}
                            {--dry-run : Report what would be sent without sending}';

    protected $description = 'Email estate administrators the access requests still awaiting a decision.';

    public function handle(): int
    {
        if (! Schema::hasTable(self::REQUESTS) || ! Schema::hasTable(self::MEMBERSHIPS)) {
            $this->info('Access requests are not installed here; nothing to send.');

            return self::SUCCESS;
        }

        $tenantIds = $this->estates();

        if (array() === $tenantIds) {
            $this->info('No access request is waiting; no digest sent.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tenantIds as $tenantId) {
            try {
                $outcome = $this->digestEstate($tenantId, $dryRun);
            } catch (Throwable $e) {
                // One estate's bad transport must not cost every other estate its
                // reminder, so the loop continues and the exit code still admits
                // the failure.
                ++$failed;
                $this->error(sprintf('%s: %s', $tenantId, $e->getMessage()));

                continue;
            }

            if (null === $outcome) {
                ++$skipped;

                continue;
            }

            ++$sent;
            $this->line($outcome);
        }

        $this->info(sprintf(
            '%d digest(s) %s, %d estate(s) skipped, %d failed.',
            $sent,
            $dryRun ? 'would be sent' : 'sent',
            $skipped,
            $failed
        ));

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The estates with at least one pending request, in a stable order.
     *
     * Read from the requests table rather than from `tenants`: the only estates
     * that need a digest are the ones with something in it, and `tenants` is not
     * guaranteed small on a vendor deployment.
     *
     * @return list<string>
     */
    private function estates(): array
    {
        $query = DB::table(self::REQUESTS)
            ->where('status', 'pending')
            ->distinct()
            ->orderBy('tenant_id');

        $only = trim((string) $this->option('tenant'));

        if ('' !== $only) {
            $query->where('tenant_id', $only);
        }

        return $query->pluck('tenant_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Build and send one estate's digest, or explain why it was skipped.
     *
     * Returns the line to print on success, or null when there was nothing worth
     * sending.
     */
    private function digestEstate(string $tenantId, bool $dryRun): ?string
    {
        if (! $this->enabledFor($tenantId)) {
            return null;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            // No membership row and no FK to cascade: a request can outlive the
            // estate it was made against. There is nobody to address and no page
            // to link, so this is a skip rather than a failure.
            return null;
        }

        $total = app(JitProvisioner::class)->pendingCount($tenantId);

        if ($total <= 0) {
            return null;
        }

        $recipients = $this->recipientsFor($tenantId);

        if (array() === $recipients) {
            $this->warn(sprintf('%s: %d pending, but no administrator to tell.', $tenantId, $total));

            return null;
        }

        $preview = max(1, (int) config('oos.access_digest.preview_limit', 10));
        $rows = $this->preview($tenantId, $preview);
        $url = $this->urlFor($tenant);

        foreach ($recipients as $recipient) {
            if ($dryRun) {
                continue;
            }

            Mail::to($recipient['email'], $recipient['name'])
                ->send(new AccessDigest(
                    estateName: (string) $tenant->name,
                    recipientName: $recipient['name'],
                    total: $total,
                    rows: $rows,
                    url: $url,
                ));
        }

        return sprintf(
            '%s: %d request(s) %s to %d administrator(s).',
            $tenantId,
            $total,
            $dryRun ? 'would be sent' : 'sent',
            count($recipients)
        );
    }

    /**
     * Whether this estate asked for the reminder.
     *
     * Read through `runAs` for the same reason `JitProvisioner::enabled()` does:
     * a scheduler run has no request and therefore no pinned estate, and reading
     * the estate-scoped store unpinned would answer with the default estate's
     * value for every estate.
     */
    private function enabledFor(string $tenantId): bool
    {
        return (bool) TenantContext::runAs(
            $tenantId,
            fn (): mixed => app(SettingsService::class)->value('enable_access_digest'),
        );
    }

    /**
     * The people who can act on the request list, decision-makers first.
     *
     * Deliberately the same boundary the page enforces and no wider: a `viewer`
     * is refused the panel outright (AdminAccessPolicy), so mailing a viewer
     * would tell somebody about a decision they cannot take part in.
     *
     * @return list<array{email: string, name: string}>
     */
    private function recipientsFor(string $tenantId): array
    {
        $fetch = static function (array $roles) use ($tenantId): array {
            $rows = DB::table('users')
                ->join(self::MEMBERSHIPS, 'users.id', '=', self::MEMBERSHIPS.'.user_id')
                ->where(self::MEMBERSHIPS.'.tenant_id', $tenantId)
                ->whereIn(self::MEMBERSHIPS.'.role', $roles)
                ->orderBy('users.id')
                ->get(array('users.name', 'users.email'));

            $recipients = array();

            foreach ($rows as $row) {
                $email = trim((string) $row->email);

                // An account with no address cannot be mailed, and a malformed
                // one is the transport's problem, not ours to guess at.
                if ('' === $email || ! str_contains($email, '@')) {
                    continue;
                }

                $recipients[] = array(
                    'email' => $email,
                    'name' => (string) ($row->name ?? ''),
                );
            }

            return $recipients;
        };

        $recipients = $fetch(self::DECISION_ROLES);

        return array() === $recipients ? $fetch(self::ACTING_ROLES) : $recipients;
    }

    /**
     * The first N pending requests, with the gate named in words.
     *
     * @return list<array{email: string, reason: string, attempted_at: string}>
     */
    private function preview(string $tenantId, int $limit): array
    {
        $rows = app(JitProvisioner::class)->pendingRows($tenantId, $limit);

        return array_map(
            static fn (array $row): array => array(
                // An unverified address is often empty; say so rather than
                // printing a blank line and looking like a template bug.
                'email' => '' !== $row['email'] ? $row['email'] : '(no address asserted)',
                'reason' => JitProvisioner::reasonLabel($row['reason']),
                'attempted_at' => self::when($row['attempted_at']),
            ),
            $rows,
        );
    }

    /**
     * A readable attempt time, or a plain admission that there isn't one.
     */
    private static function when(string $value): string
    {
        if ('' === trim($value)) {
            return 'at an unrecorded time';
        }

        try {
            return CarbonImmutable::parse($value)->toDayDateTimeString();
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * The deep link to the estate's page.
     *
     * Built from `app.url`, never from a request: this runs on a schedule, where
     * there is no request to trust, and a link pointed at an attacker-chosen
     * host is a phishing mail sent from our own domain (the same rule as §103).
     */
    private function urlFor(Tenant $tenant): string
    {
        return sprintf(
            '%s/admin/%s/access-requests',
            rtrim((string) config('app.url'), '/'),
            rawurlencode((string) $tenant->slug)
        );
    }
}
