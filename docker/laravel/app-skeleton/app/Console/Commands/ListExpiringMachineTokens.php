<?php

namespace App\Console\Commands;

use App\Mail\MachineTokensExpiring;
use App\Support\ActivityRecorder;
use App\Support\MachineTokens;
use App\Support\PersonalTokens;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Report machine credentials that are about to expire — Proposal 159 slice 3.
 *
 * ## Why a report and not automatic rotation
 *
 * A personal access token is shown once, at mint time, and stored hashed. Nothing
 * can therefore deliver a rotated credential to a client — automatic rotation
 * would invalidate the working token and hand the replacement to nobody. The
 * guidance prefers short-lived credentials precisely because they remove the need
 * to schedule rotation; the honest remaining job is to say what is about to lapse
 * while an operator can still act.
 *
 * Exit status is part of the contract: **1 means "there is something to rotate"**,
 * so a scheduler or a health check can flag it without parsing the table. An empty
 * window exits 0.
 */
class ListExpiringMachineTokens extends Command
{
    protected $signature = 'nvoos:machine-token:expiring
        {--days=14 : How far ahead to look}
        {--all : Also report credentials that never expire}';

    protected $description = 'List machine tokens expiring soon (exit 1 when there are any).';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $window = Carbon::now()->addDays($days);
        $tokens = $this->machineTokens();

        if ($this->option('all')) {
            $this->line(sprintf(
                '%d machine token(s) in total; %d never expire and can never lapse.',
                $tokens->count(),
                $tokens->filter(static fn (PersonalAccessToken $token): bool => null === $token->expires_at)->count()
            ));
        }

        $expiring = $tokens->filter(
            static fn (PersonalAccessToken $token): bool => null !== $token->expires_at
                && $token->expires_at->lessThanOrEqualTo($window)
        );

        if ($expiring->isEmpty()) {
            $this->info(sprintf('No machine tokens expire within %d days.', $days));

            return self::SUCCESS;
        }

        $rows = $expiring->map(static function (PersonalAccessToken $token): array {
            $left = null === $token->expires_at
                ? null
                : (int) ceil(Carbon::now()->diffInDays($token->expires_at, false));

            return array(
                'id' => (int) $token->getKey(),
                'name' => (string) $token->name,
                'owner' => (string) ($token->tokenable?->email ?? $token->tokenable_id),
                'scopes' => implode(', ', (array) $token->abilities),
                'expires' => (string) $token->expires_at?->toDateTimeString(),
                'days_left' => null === $left ? '-' : (string) $left,
            );
        })->values()->all();

        $this->warn(sprintf(
            '%d machine token(s) expire within %d days. Rotate each before it lapses: '
            . 'nvoos:machine-token:rotate {id}.',
            $expiring->count(),
            $days
        ));

        $this->table(
            array('id', 'name', 'owner', 'scopes', 'expires', 'days left'),
            array_map(
                static fn (array $row): array => array(
                    $row['id'],
                    $row['name'],
                    $row['owner'],
                    $row['scopes'],
                    $row['expires'],
                    $row['days_left'],
                ),
                $rows
            )
        );

        $this->publishFinding($rows, $days, $expiring->count());

        return self::FAILURE;
    }

    /**
     * Record the finding and, when configured, mail it once.
     *
     * Two properties make this an alert rather than a log line:
     *
     *  - it is **auditable** — the same finding lands in the activity feed, so a
     *    missed mail is recoverable and a delivered one leaves a timestamp;
     *  - it is **deduplicated** — the same set of credentials does not re-alert
     *    for 24 hours. An alert that fires every morning about the same token is
     *    one people learn to ignore, which is worse than no alert at all.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function publishFinding(array $rows, int $days, int $count): void
    {
        $signature = sha1(implode(',', array_column($rows, 'id')));

        if (! $this->shouldAlert($signature)) {
            $this->line('  (alert suppressed: the same set was reported within 24 hours)');

            return;
        }

        app(ActivityRecorder::class)->record(
            'machine_token.expiring',
            sprintf('%d machine credential(s) expire within %d days.', $count, $days),
            null,
            array(
                'signature' => $signature,
                'ids' => array_column($rows, 'id'),
                'days' => $days,
            )
        );

        $this->deliver($rows, $days);
    }

    private function shouldAlert(string $signature): bool
    {
        $last = DB::table('nvoos_activity_events')
            ->where('type', 'machine_token.expiring')
            ->latest('id')
            ->first();

        if (null === $last) {
            return true;
        }

        $context = json_decode((string) $last->context, true);

        if (! is_array($context) || ($context['signature'] ?? null) !== $signature) {
            return true;
        }

        return Carbon::parse((string) $last->created_at)->lessThan(Carbon::now()->subDay());
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function deliver(array $rows, int $days): void
    {
        $email = trim((string) config('oos.machine_alerts.email', ''));

        if ('' === $email) {
            $this->line('  (no alert address: set oos.machine_alerts.email or NVOOS_MACHINE_ALERT_EMAIL)');

            return;
        }

        try {
            Mail::to($email)->send(new MachineTokensExpiring($rows, $days));
        } catch (Throwable $exception) {
            // A delivery failure must not hide the finding or change the exit code.
            $this->warn('  alert not delivered: '.$exception->getMessage());

            return;
        }

        $this->line(sprintf('  alert emailed to %s', $email));
    }

    /**
     * Credentials that are machine principals: scoped, and not the `*` that means
     * the owner's own authority.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    private function machineTokens(): Collection
    {
        return PersonalAccessToken::query()
            ->with('tokenable')
            ->orderBy('expires_at')
            ->get()
            ->filter(static function (PersonalAccessToken $token): bool {
                $name = (string) $token->name;

                // A machine credential is identified by its prefix (§129).
                if (str_starts_with($name, MachineTokens::NAME_PREFIX)) {
                    return true;
                }

                // A person's own token is the inventory's and the owner's concern;
                // it is not a machine credential however narrowly it is scoped.
                if (str_starts_with($name, PersonalTokens::NAME_PREFIX)) {
                    return false;
                }

                // Everything else predates the prefix: keep the old heuristic so a
                // machine token minted before it still appears in the report.
                $abilities = is_array($token->abilities) ? $token->abilities : array();

                return array() !== $abilities && ! in_array('*', $abilities, true);
            })
            ->values();
    }
}
