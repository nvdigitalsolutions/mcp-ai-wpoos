<?php

namespace App\Console\Commands;

use App\Support\Security\RootKey;
use App\Support\Security\RootKeyInvalid;
use Illuminate\Console\Command;

/**
 * Break-glass management for the root security key — Proposal 149 §188.
 *
 * The gate refuses operator writes when the flag is on and no proof has been
 * given, and the key "cannot be recovered if lost" (the field's own words: only a
 * hash is stored). A control whose loss makes a deployment unrecoverable is a
 * denial-of-service waiting to happen, so this command exists outside the panel.
 *
 * It is deliberately not gated: a command that needed the key to manage the key
 * would be the circular lock the break-glass path exists to avoid. Operators are
 * the only people with shell access, which is the same trust boundary as the key
 * itself — and every use is visible in shell history and the deployment's own
 * audit trail.
 */
class RootKeyCommand extends Command
{
    protected $signature = 'nvoos:root-key
        {--set : Prompt for and store a new root security key}
        {--disable : Remove the stored key and switch the gate off}
        {--status : Report whether the key is configured and whether the gate is on}';

    protected $description = 'Manage the root security key that gates operator writes (break-glass path).';

    public function handle(RootKey $key): int
    {
        if ($this->option('status') || (! $this->option('set') && ! $this->option('disable'))) {
            return $this->status($key);
        }

        if ($this->option('disable')) {
            $key->disable();
            $this->info('Root security key cleared and the gate switched off. Operator writes are no longer refused.');

            return self::SUCCESS;
        }

        return $this->set($key);
    }

    private function status(RootKey $key): int
    {
        $this->line('Configured: '.($key->configured() ? 'yes' : 'no'));
        $this->line('Gate enabled: '.($key->enabled() ? 'yes' : 'no'));
        $this->line(sprintf('Minimum length: %d characters', RootKey::MIN_LENGTH));

        if ($key->enabled() && ! $key->configured()) {
            $this->warn('The gate is on with no key stored, so operator writes are refused.');
            $this->warn('Run `php artisan nvoos:root-key --set` to configure one.');
        }

        return self::SUCCESS;
    }

    private function set(RootKey $key): int
    {
        $secret = (string) $this->secret('New root security key (min '.RootKey::MIN_LENGTH.' chars)');

        if (strlen(trim($secret)) < RootKey::MIN_LENGTH) {
            $this->error(sprintf('The key must be at least %d characters.', RootKey::MIN_LENGTH));

            return self::FAILURE;
        }

        $confirm = (string) $this->secret('Repeat the key');

        if (! hash_equals(trim($secret), trim($confirm))) {
            $this->error('The keys do not match.');

            return self::FAILURE;
        }

        try {
            $key->set($secret);
        } catch (RootKeyInvalid $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('Root security key stored (hashed). It cannot be read back.');

        return self::SUCCESS;
    }
}
