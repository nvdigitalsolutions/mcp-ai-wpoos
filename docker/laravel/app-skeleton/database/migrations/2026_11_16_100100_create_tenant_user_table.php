<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estate membership — Proposal 149 T-0.3.
 *
 * `tenant_user` (alphabetical singular, Laravel's pivot convention) answers
 * "which estates may this user enter, and as what". It is deliberately
 * separate from `team_members`: a team is a working group inside one estate,
 * while membership is the estate boundary itself. An estate can exist with no
 * teams, and a user can belong to two estates.
 *
 * `role` reuses `CapabilityMap::TEAM_ROLES` rather than inventing a second
 * vocabulary — the same five words mean the same thing at both scopes, and
 * Filament's panel tenancy reads this column directly.
 *
 * Backfill: every existing user joins the default estate, with their
 * membership role derived from their WordPress site role. A blanket `owner`
 * would grant `/admin` to subscribers, because the panel predicate is
 * "not a viewer".
 */
return new class extends Migration
{
    private const DEFAULT_TENANT = 'default';

    /**
     * WordPress site role → estate membership role.
     *
     * Fails closed: `subscriber` becomes `viewer`, and a viewer is refused the
     * customer panel outright (AdminAccessPolicy). `editor` maps to `manager`
     * rather than `admin` because `delete_others_posts` is what separates the
     * two on WordPress, and an estate may hold many editors.
     */
    private const ROLE_MAP = array(
        'administrator' => 'owner',
        'admin' => 'owner',          // legacy label; normalised at read time too
        'editor' => 'manager',
        'author' => 'member',
        'contributor' => 'member',
        'subscriber' => 'viewer',
    );

    public function up(): void
    {
        if (! Schema::hasTable('tenant_user')) {
            Schema::create('tenant_user', function (Blueprint $table): void {
                $table->string('tenant_id', 64);
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('role', array('owner', 'admin', 'manager', 'member', 'viewer'))
                    ->default('member');
                $table->timestamps();

                $table->primary(array('tenant_id', 'user_id'));
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index(array('user_id'));
            });
        }

        // Backfill only when the estate exists; a partial deploy must not fail
        // the migration because a previous one bailed.
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('users')) {
            return;
        }

        $defaultExists = DB::table('tenants')->where('id', self::DEFAULT_TENANT)->exists();

        if (! $defaultExists) {
            return;
        }

        $now = now();

        DB::table('users')
            ->select(array('id', 'role'))
            ->orderBy('id')
            ->chunk(200, function ($users) use ($now): void {
                foreach ($users as $user) {
                    $siteRole = (string) ($user->role ?? 'subscriber');
                    $role = self::ROLE_MAP[$siteRole] ?? 'viewer';

                    DB::table('tenant_user')->updateOrInsert(
                        array(
                            'tenant_id' => self::DEFAULT_TENANT,
                            'user_id' => (int) $user->id,
                        ),
                        array(
                            'role' => $role,
                            'created_at' => $now,
                            'updated_at' => $now,
                        )
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
