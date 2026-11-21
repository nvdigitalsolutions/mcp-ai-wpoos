<?php

namespace App\Console\Commands;

use App\Mail\MachineTokensExpiring;
use App\Support\ActivityRecorder;
use App\Support\MachineTokens;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    /** How long an acknowledgement silences the same set. */
    private const ACK_DAYS = 7;

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $tokens = MachineTokens::reportable();

        if ($this->option('all')) {
            $this->line(sprintf(
                '%d machine token(s) in total; %d never expire and can never lapse.',
                count($tokens),
                count(array_filter(
                    $tokens,
                    static fn (array $row): bool => null === $row['expires_at']
                ))
            ));
        }

        $rows = MachineTokens::expiring($days);

        // Before anything else: an acknowledged set whose credentials have all
        // gone (rotated or revoked) is resolved, including on a run that has
        // nothing left to report.
        $this->resolveAcknowledgedSets(array_column($rows, 'id'));

        if (array() === $rows) {
            $this->info(sprintf('No machine tokens expire within %d days.', $days));

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d machine token(s) expire within %d days. Rotate each before it lapses: '
            . 'nvoos:machine-token:rotate {id}.',
            count($rows),
            $days
        ));

        $this->table(
            array('id', 'name', 'owner', 'scopes', 'expires', 'days left'),
            array_map(
                static fn (array $row): array => array(
                    $row['id'],
                    $row['label'],
                    $row['holder'],
                    implode(', ', $row['scopes']),
                    $row['expires'],
                    self::daysLeft($row),
                ),
                $rows
            )
        );

        $this->publishFinding($rows, $days);

        return self::FAILURE;
    }

    /**
     * Record that an acknowledged set is no longer expiring.
     *
     * The incident-response guidance is blunt about why this exists: measuring
     * only time-to-acknowledge "trains people to ack fast and do nothing". The
     * resolution event is what makes ack-to-resolution queryable — the ack says
     * someone saw it, this says the credentials actually went.
     *
     * @param list<int|string> $currentIds
     */
    private function resolveAcknowledgedSets(array $currentIds): void
    {
        $ack = DB::table('nvoos_activity_events')
            ->where('type', 'machine_token.expiry_acknowledged')
            ->latest('id')
            ->first();

        if (null === $ack) {
            return;
        }

        $context = json_decode((string) $ack->context, true);

        if (! is_array($context)) {
            return;
        }

        $signature = (string) ($context['signature'] ?? '');
        $ackedIds = array_map('intval', (array) ($context['ids'] ?? array()));

        if ('' === $signature || array() === $ackedIds) {
            return;
        }

        $resolved = DB::table('nvoos_activity_events')
            ->where('type', 'machine_token.expiry_resolved')
            ->get()
            ->contains(static function (object $row) use ($signature): bool {
                $decoded = json_decode((string) $row->context, true);

                return is_array($decoded) && ($decoded['signature'] ?? null) === $signature;
            });

        if ($resolved) {
            return;
        }

        $current = array_map('intval', $currentIds);

        if (array() !== array_intersect($ackedIds, $current)) {
            return;
        }

        app(ActivityRecorder::class)->record(
            'machine_token.expiry_resolved',
            sprintf('%d acknowledged credential(s) are no longer expiring.', count($ackedIds)),
            null,
            array(
                'signature' => $signature,
                'ids' => $ackedIds,
                'acknowledged_at' => (string) $ack->created_at,
            )
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function daysLeft(array $row): string
    {
        if (null === $row['expires_at']) {
            return '-';
        }

        return (string) (int) ceil(Carbon::now()->diffInDays(Carbon::parse((string) $row['expires_at']), false));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function soonestExpiry(array $rows): ?Carbon
    {
        foreach ($rows as $row) {
            if (null !== $row['expires_at']) {
                return Carbon::parse((string) $row['expires_at']);
            }
        }

        return null;
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
    private function publishFinding(array $rows, int $days): void
    {
        $criticalDays = $this->criticalDays($days);
        $critical = array_values(array_filter(
            $rows,
            static fn (array $row): bool => null !== $row['expires_at']
                && (int) self::daysLeft($row) <= $criticalDays
        ));

        // The critical band ignores an acknowledgement on purpose. An acked alert
        // whose condition persists must re-page; otherwise ack is a way to silence
        // the safety net (§134).
        if (array() !== $critical) {
            $this->escalate($critical, $criticalDays);

            return;
        }

        $signature = sha1(implode(',', array_column($rows, 'id')));

        if (! $this->shouldAlert($signature, $rows)) {
            $this->line('  (alert suppressed: acknowledged, or the same set was reported within 24 hours)');

            return;
        }

        app(ActivityRecorder::class)->record(
            'machine_token.expiring',
            sprintf('%d machine credential(s) expire within %d days.', count($rows), $days),
            null,
            array(
                'signature' => $signature,
                'ids' => array_column($rows, 'id'),
                'days' => $days,
            )
        );

        $this->deliver($rows, $days);
    }

    /**
     * @param list<array<string, mixed>> $critical
     */
    private function escalate(array $critical, int $criticalDays): void
    {
        $signature = 'critical:'.sha1(implode(',', array_column($critical, 'id')));

        if ($this->recentEvent('machine_token.expiring_critical', $signature)) {
            $this->line('  (critical alert suppressed: the same set was escalated within 24 hours)');

            return;
        }

        app(ActivityRecorder::class)->record(
            'machine_token.expiring_critical',
            sprintf('%d machine credential(s) expire within %d days.', count($critical), $criticalDays),
            null,
            array(
                'signature' => $signature,
                'ids' => array_column($critical, 'id'),
                'days' => $criticalDays,
            )
        );

        $this->deliver($critical, $criticalDays, true);
    }

    private function criticalDays(int $days): int
    {
        $configured = (int) config('oos.machine_alerts.critical_days', 3);

        return max(0, min($configured, $days));
    }

    private function recentEvent(string $type, string $signature): bool
    {
        $last = DB::table('nvoos_activity_events')
            ->where('type', $type)
            ->latest('id')
            ->first();

        if (null === $last) {
            return false;
        }

        $context = json_decode((string) $last->context, true);

        if (! is_array($context) || ($context['signature'] ?? null) !== $signature) {
            return false;
        }

        return Carbon::parse((string) $last->created_at)->greaterThan(Carbon::now()->subDay());
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function shouldAlert(string $signature, array $rows): bool
    {
        $now = Carbon::now();

        // An acknowledgement is a NEW event (activity rows are append-only). It
        // silences this exact set for ACK_DAYS, but never past the soonest
        // expiry: a credential about to lapse must still be shouted about.
        $ack = DB::table('nvoos_activity_events')
            ->where('type', 'machine_token.expiry_acknowledged')
            ->latest('id')
            ->first();

        if (null !== $ack) {
            $context = json_decode((string) $ack->context, true);

            if (is_array($context) && ($context['signature'] ?? null) === $signature) {
                $until = Carbon::parse((string) $ack->created_at)->addDays(self::ACK_DAYS);
                $soonest = self::soonestExpiry($rows);

                if (null !== $soonest && $soonest->lessThan($until)) {
                    $until = $soonest;
                }

                if ($now->lessThan($until)) {
                    return false;
                }
            }
        }
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

        return Carbon::parse((string) $last->created_at)->lessThan($now->copy()->subDay());
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function deliver(array $rows, int $days, bool $escalated = false): void
    {
        $email = $escalated
            ? trim((string) config('oos.machine_alerts.escalation_email', ''))
            : '';
        $key = 'oos.machine_alerts.escalation_email or NVOOS_MACHINE_ESCALATION_EMAIL';

        if ('' === $email) {
            $email = trim((string) config('oos.machine_alerts.email', ''));
            $key = 'oos.machine_alerts.email or NVOOS_MACHINE_ALERT_EMAIL';
        }

        $mailRows = array_map(
            static fn (array $row): array => array(
                'id' => $row['id'],
                'name' => $row['label'],
                'owner' => $row['holder'],
                'scopes' => implode(', ', $row['scopes']),
                'expires' => $row['expires'],
                'days_left' => self::daysLeft($row),
            ),
            $rows
        );

        if ('' === $email) {
            $this->line('  (no alert address: set '.$key.')');

            return;
        }

        try {
            Mail::to($email)->send(new MachineTokensExpiring($mailRows, $days, $escalated));
        } catch (Throwable $exception) {
            // A delivery failure must not hide the finding or change the exit code.
            $this->warn('  alert not delivered: '.$exception->getMessage());

            return;
        }

        $this->line(sprintf('  alert emailed to %s', $email));
    }
}
