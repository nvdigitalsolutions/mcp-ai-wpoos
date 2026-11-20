<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Replace a machine credential, keeping the old one valid for a grace window —
 * Proposal 159 slice 3.
 *
 * ## Why a grace window exists
 *
 * The guidance on credential rotation converges on overlapping validity: two
 * credentials are valid at once so a client can adopt the new one without
 * downtime. A hard cutover from a single command would lock out every client
 * still holding the old token, and the operator running this command cannot
 * update those clients from here.
 *
 * `--grace=0` is therefore an explicit choice, and it is the **revocation**
 * shape — it deletes the old token on the spot. Use it for a leak; use the
 * default window for a scheduled change. Rotation that keeps the old secret
 * alive does not stop an attacker who already has it, which is why
 * `nvoos:machine-token:revoke` also exists.
 *
 * The replacement keeps the owner, name and scopes. Rotation is not a privilege
 * change; widening a credential is a mint, not a rotation.
 */
class RotateMachineToken extends Command
{
    protected $signature = 'nvoos:machine-token:rotate
        {id : The token id to replace}
        {--days=90 : Days until the NEW token expires (must be at least 1)}
        {--grace=1440 : Minutes the OLD token keeps working; 0 deletes it now}
        {--estate=default : Estate the audit row belongs to}';

    protected $description = 'Replace a machine token, keeping the old one valid for a grace window.';

    public function handle(AdminAuditLogger $audit): int
    {
        $id = (int) $this->argument('id');
        $estate = trim((string) $this->option('estate'));
        $days = (int) $this->option('days');
        $grace = (int) $this->option('grace');

        if ($days < 1) {
            $this->error('--days must be at least 1: a rotating credential is one that expires.');

            return self::FAILURE;
        }

        if ($grace < 0) {
            $this->error('--grace must be zero or positive.');

            return self::FAILURE;
        }

        $old = PersonalAccessToken::query()->find($id);

        if (null === $old) {
            $this->error(sprintf('No token #%d.', $id));

            return self::FAILURE;
        }

        $abilities = is_array($old->abilities) ? $old->abilities : array();

        if (array() === $abilities || in_array('*', $abilities, true)) {
            $this->error(
                'That token is not a machine credential. Rotating a user credential would issue '
                . 'a narrowed replacement for it.'
            );

            return self::FAILURE;
        }

        $owner = $old->tokenable;

        if (! $owner instanceof User) {
            $this->error('That token is not owned by a user.');

            return self::FAILURE;
        }

        // Snapshot before mutating: after the save, `expires_at` is the deadline
        // this rotation just imposed, and an audit row that recorded it as the
        // "before" state would be wrong.
        $before = array(
            'token_id' => $id,
            'name' => $old->name,
            'scopes' => $abilities,
            'expires_at' => $old->expires_at?->toIso8601String(),
        );

        $name = (string) $old->name;
        $token = $owner->createToken($name, $abilities, Carbon::now()->addDays($days));
        $newId = (int) $token->accessToken->getKey();

        $oldDeadline = Carbon::now()->addMinutes($grace);

        if (0 === $grace) {
            $old->delete();
        } else {
            $old->forceFill(array('expires_at' => $oldDeadline))->save();
        }

        TenantContext::runAs($estate, static function () use (
            $audit,
            $newId,
            $id,
            $before,
            $grace,
            $oldDeadline
        ): void {
            $audit->recordResource(
                'machine_token',
                (string) $newId,
                'rotated',
                $before,
                array(
                    'replaced_token_id' => $id,
                    'grace_minutes' => $grace,
                    'old_expires_at' => 0 === $grace ? null : $oldDeadline->toIso8601String(),
                ),
                'console'
            );
        });

        $this->newLine();
        $this->info(sprintf('Rotated machine token #%d -> #%d [%s]', $id, $newId, $name));
        $this->line(sprintf('  scopes:   %s', implode(', ', $abilities)));
        $this->line(sprintf('  expires:  %s', Carbon::now()->addDays($days)->toDateTimeString()));
        $this->line(sprintf(
            '  old:      %s',
            0 === $grace ? 'revoked immediately' : 'keeps working until ' . $oldDeadline->toDateTimeString()
        ));
        $this->newLine();
        $this->warn('This token is shown once. Update the client before the old one stops working.');
        $this->line($token->plainTextToken);
        $this->newLine();

        return self::SUCCESS;
    }
}
