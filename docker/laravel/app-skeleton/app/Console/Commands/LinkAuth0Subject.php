<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Link an Auth0 subject to a user — Proposal 149 §102.
 *
 * The WordPress plugin resolves a subject to a user through its GitHub bridge,
 * which Proposal 156 D5 put out of scope here. Without a bridge there is still
 * one question the runtime cannot answer for itself: *which* user is `auth0|abc`?
 * Guessing is the dangerous option — matching on email is an account takeover,
 * because an address is mutable and a subject is not.
 *
 * So the link is explicit and operator-made. This is deliberately not a UI: it
 * runs on the operator's side of the boundary (an `auth0_subject` is estate
 * configuration, like a licence key), it is auditable in shell history and CI
 * logs, and a mis-link is undone by the same command with `--forget`.
 *
 * Deny by default: a subject with no link is rejected at authentication time, so
 * forgetting to run this denies access rather than granting it to the wrong
 * person.
 */
class LinkAuth0Subject extends Command
{
    protected $signature = 'nvoos:auth0-link
                            {email : The user to link}
                            {subject? : The Auth0 `sub` claim, e.g. auth0|64f...}
                            {--forget : Remove the existing link instead of setting one}';

    protected $description = 'Link (or unlink) an Auth0 subject to a user, for bearer-token authentication.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error(sprintf('No user with the email "%s".', $email));

            return self::FAILURE;
        }

        if ((bool) $this->option('forget')) {
            $user->forceFill(array('auth0_subject' => null))->save();

            $this->info(sprintf('Unlinked %s from its Auth0 subject.', $user->email));

            return self::SUCCESS;
        }

        $subject = trim((string) $this->argument('subject'));

        if ('' === $subject) {
            $this->error('Provide a subject, or pass --forget to remove the existing link.');

            return self::FAILURE;
        }

        $existing = User::query()
            ->where('auth0_subject', $subject)
            ->whereKeyNot($user->getKey())
            ->first();

        if ($existing instanceof User) {
            // The column is unique, so this would fail at the database anyway.
            // Saying so here names the owner instead of surfacing a constraint
            // violation, which on SQLite reads as a bare "UNIQUE constraint".
            $this->error(sprintf('Subject "%s" is already linked to %s.', $subject, $existing->email));

            return self::FAILURE;
        }

        $user->forceFill(array('auth0_subject' => $subject))->save();

        $this->info(sprintf('Linked %s to "%s".', $user->email, $subject));

        $estates = $user->tenants()->get();

        if ($estates->isEmpty()) {
            // Authentication would succeed and then be refused by
            // `ResolveApiTenant` with a 403, which looks like a token problem.
            $this->warn('This user belongs to no estate, so every bearer request will be refused.');

            return self::SUCCESS;
        }

        $this->line('Bearer requests will work for these estates (send the id in X-Tenant-Id):');

        foreach ($estates as $estate) {
            $this->line(sprintf('  - %s (%s)', $estate->getKey(), $estate->name ?? '—'));
        }

        return self::SUCCESS;
    }
}
