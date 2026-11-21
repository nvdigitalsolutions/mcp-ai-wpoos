<?php

namespace App\Console\Commands;

use App\Support\Admin\AdminAuditLogger;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revoke a machine credential immediately — Proposal 159 slice 3.
 *
 * ## Rotation is not revocation
 *
 * The two are deliberately separate commands. Rotation keeps the old credential
 * valid for a grace window so a client can adopt the new one without downtime —
 * which is exactly what you do **not** want after a leak: most rotation patterns
 * keep the old secret working, so rotating a leaked token does not stop the
 * attacker. Revocation is deletion, and Sanctum re-reads the table per request,
 * so it takes effect on the next call (159 §6 rule 4).
 *
 * Use `nvoos:machine-token:rotate` for a scheduled change and this for a leak.
 */
class RevokeMachineToken extends Command
{
    protected $signature = 'nvoos:machine-token:revoke
        {id : The token id from nvoos:machine-token}
        {--estate=default : Estate the audit row belongs to}';

    protected $description = 'Delete a machine token immediately (revocation, not rotation).';

    public function handle(AdminAuditLogger $audit): int
    {
        $id = (int) $this->argument('id');
        $estate = trim((string) $this->option('estate'));

        $token = PersonalAccessToken::query()->find($id);

        if (null === $token) {
            $this->error(sprintf('No token #%d.', $id));

            return self::FAILURE;
        }

        $before = array(
            'name' => $token->name,
            'owner_id' => $token->tokenable_id,
            'scopes' => $token->abilities,
            'expires_at' => $token->expires_at?->toIso8601String(),
        );

        // The row is written before the delete: `recordResource` reads the
        // token's own id, and after the delete there is nothing left to name.
        TenantContext::runAs($estate, static function () use ($audit, $id, $before): void {
            $audit->recordResource('machine_token', (string) $id, 'revoked', $before, array(), 'console');
        });

        $token->delete();

        $this->info(sprintf(
            'Revoked machine token #%d [%s]. It stops working on the next request.',
            $id,
            $before['name']
        ));

        return self::SUCCESS;
    }
}
