<?php

namespace App\Console\Commands;

use App\Models\Assistant;
use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Auth\PrincipalScope;
use App\Support\CapabilityMap;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Mint a scoped machine credential — Proposal 159 slice 3.
 *
 * A machine principal is a personal access token with an explicit scope
 * (159 §5). This command is the first minting path, and it is a console command
 * first for the same reason `nvoos:auth0-link` was: minting is an operator act,
 * and a panel form should earn its place after the rules are pinned.
 *
 * ## The rules this enforces
 *
 *  - **A machine credential is never unscoped.** `['*']` is refused outright:
 *    that ability means "the owner's own authority", which is the one thing a
 *    machine principal is defined not to be (159 §5 rule 3).
 *  - **Scopes are validated against the vocabulary** {@see PrincipalScope}
 *    enforces, not accepted as free text. A typo'd scope must fail at mint time,
 *    not at 3am when the integration is refused.
 *  - **An assistant scope implies its estate** (159 §6 rule 7), so
 *    `assistant:{id}` must name an assistant that exists *in the issuing estate*.
 *  - **The owner must hold `manage_options`**, or the credential is unusable: the
 *    administrative routes still run `can:manage_options`, and minting a token
 *    that cannot pass them is a trap dressed as a success.
 *  - **The mint is audited** with the scopes it granted (159 §6 rule 3).
 *  - **Expiry is the default**: 90 days, the 60–90 day baseline the PAT guidance
 *    converges on. `--days=0` removes it and says so.
 *
 * ## What it deliberately does not do
 *
 * An email is resolved to an id **once, here**. Runtime identity never consults
 * email (159 §6 rule 6) — the token stores its owner id, and every later question
 * goes through that link or through the scope.
 *
 * A console invocation has no authenticated principal, so the audit row's
 * `user_id` is null and the payload records `minted_by: console`. That is the
 * honest record: the operator is whoever ran the command on the host, which this
 * process cannot see.
 */
class MintMachineToken extends Command
{
    protected $signature = 'nvoos:machine-token
        {--name= : Inventory label, e.g. ci-assistant-42 (required)}
        {--scope=* : Scope to grant; repeat for more than one (required)}
        {--user= : Owner user id (preferred) or email}
        {--estate=default : Estate to issue the credential in}
        {--days=90 : Days until expiry; 0 means no expiry}';

    protected $description = 'Mint a scoped machine token (159 slice 3) and print it once.';

    public function handle(AdminAuditLogger $audit): int
    {
        $name = trim((string) $this->option('name'));

        if ('' === $name) {
            $this->error('--name is required: it is the inventory label the next audit will look for.');

            return self::FAILURE;
        }

        $owner = $this->owner();

        if (null === $owner) {
            return self::FAILURE;
        }

        $estate = trim((string) $this->option('estate'));

        if (! $owner->tenants()->where('tenants.id', $estate)->exists()) {
            $this->error(sprintf('User %d is not a member of estate [%s].', $owner->getKey(), $estate));

            return self::FAILURE;
        }

        if (! CapabilityMap::has($owner, 'manage_options')) {
            $this->error('The owner lacks manage_options, so this credential could not pass the routes it is for.');

            return self::FAILURE;
        }

        $scopes = $this->scopes();

        if (null === $scopes) {
            return self::FAILURE;
        }

        if (! $this->assistantsBelongTo($scopes, $estate)) {
            return self::FAILURE;
        }

        $days = (int) $this->option('days');

        if ($days < 0) {
            $this->error('--days must be zero or positive.');

            return self::FAILURE;
        }

        $expiresAt = 0 === $days ? null : Carbon::now()->addDays($days);

        return $this->mint($owner, $name, $scopes, $estate, $expiresAt, $audit);
    }

    /**
     * The owner, by id (preferred) or email resolved once.
     */
    private function owner(): ?User
    {
        $value = trim((string) $this->option('user'));

        if ('' === $value) {
            $this->error('--user is required: a machine credential is owned by a person.');

            return null;
        }

        $user = ctype_digit($value)
            ? User::query()->find((int) $value)
            : User::query()->where('email', $value)->first();

        if (null === $user) {
            $this->error(sprintf('No user matches [%s].', $value));

            return null;
        }

        return $user;
    }

    /**
     * The scopes, validated against the vocabulary the route guard enforces.
     *
     * @return list<string>|null
     */
    private function scopes(): ?array
    {
        $raw = (array) $this->option('scope');
        $scopes = array();

        foreach ($raw as $scope) {
            $scope = trim((string) $scope);

            if ('' === $scope) {
                continue;
            }

            if (! $this->isMintable($scope)) {
                $this->error(sprintf(
                    '[%s] is not a scope this runtime understands. Use assistant:{id} or estate:{name}.',
                    $scope
                ));

                return null;
            }

            $scopes[] = $scope;
        }

        if (array() === $scopes) {
            $this->error('--scope is required: a machine principal is never unscoped.');

            return null;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * `*` and `any` are deliberately not mintable: they are the two entries that
     * would make a "machine" credential behave like a user's.
     */
    private function isMintable(string $scope): bool
    {
        if (PrincipalScope::UNRESTRICTED === $scope || PrincipalScope::ANY === $scope) {
            return false;
        }

        if (1 === preg_match('/^' . PrincipalScope::ASSISTANT . ':[1-9]\d*$/', $scope)) {
            return true;
        }

        return 1 === preg_match('/^' . PrincipalScope::ESTATE . ':[a-z0-9][a-z0-9._-]*$/', $scope);
    }

    /**
     * An assistant scope implies its estate (159 §6 rule 7), so the assistant has
     * to exist in the estate the credential is issued in.
     *
     * The model filters by the current estate, so the lookup runs *as* that
     * estate: "exists" and "exists here" are the same question here.
     *
     * @param list<string> $scopes
     */
    private function assistantsBelongTo(array $scopes, string $estate): bool
    {
        foreach ($scopes as $scope) {
            if (! str_starts_with($scope, PrincipalScope::ASSISTANT . ':')) {
                continue;
            }

            $id = (int) substr($scope, strlen(PrincipalScope::ASSISTANT) + 1);

            $assistant = TenantContext::runAs($estate, static fn () => Assistant::query()->find($id));

            if (null === $assistant) {
                $this->error(sprintf('No assistant [%d] in estate [%s].', $id, $estate));

                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $scopes
     */
    private function mint(
        User $owner,
        string $name,
        array $scopes,
        string $estate,
        ?Carbon $expiresAt,
        AdminAuditLogger $audit
    ): int {
        $token = $owner->createToken($name, $scopes, $expiresAt);
        $id = (int) $token->accessToken->getKey();

        TenantContext::runAs($estate, static function () use ($audit, $id, $owner, $name, $scopes, $expiresAt): void {
            $audit->recordResource(
                'machine_token',
                (string) $id,
                'created',
                array(),
                array(
                    'name' => $name,
                    'owner_id' => $owner->getKey(),
                    'scopes' => $scopes,
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'minted_by' => 'console',
                ),
                'console'
            );
        });

        $this->newLine();
        $this->info(sprintf('Minted machine token #%d [%s]', $id, $name));
        $this->line(sprintf('  owner:   %d', $owner->getKey()));
        $this->line(sprintf('  estate:  %s', $estate));
        $this->line(sprintf('  scopes:  %s', implode(', ', $scopes)));
        $this->line(sprintf(
            '  expires: %s',
            null === $expiresAt ? 'never (--days=0)' : $expiresAt->toDateTimeString()
        ));
        $this->newLine();
        $this->warn('Shown once. It is stored hashed and cannot be recovered.');
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line(sprintf('Revoke it in /ops -> Token inventory (row %d).', $id));

        return self::SUCCESS;
    }
}
