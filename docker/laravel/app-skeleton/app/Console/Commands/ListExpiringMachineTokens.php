<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

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

        $this->warn(sprintf(
            '%d machine token(s) expire within %d days. Rotate each before it lapses: '
            . 'nvoos:machine-token:rotate {id}.',
            $expiring->count(),
            $days
        ));

        $this->table(
            array('id', 'name', 'owner', 'scopes', 'expires', 'days left'),
            $expiring->map(static function (PersonalAccessToken $token): array {
                $left = null === $token->expires_at
                    ? null
                    : (int) ceil(Carbon::now()->diffInDays($token->expires_at, false));

                return array(
                    $token->getKey(),
                    $token->name,
                    $token->tokenable?->email ?? $token->tokenable_id,
                    implode(', ', (array) $token->abilities),
                    $token->expires_at?->toDateTimeString(),
                    null === $left ? '-' : (string) $left,
                );
            })->all()
        );

        return self::FAILURE;
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
                $abilities = is_array($token->abilities) ? $token->abilities : array();

                return array() !== $abilities && ! in_array('*', $abilities, true);
            })
            ->values();
    }
}
