<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot tokens for magic-link sign-in — Proposal 149 §103.
 *
 * The WordPress controller keeps these in transients (its own docblock calls the
 * choice deliberate: the raw token never reaches the database). This runtime
 * keeps them in a table instead, for three reasons the plugin's single-site
 * model does not have:
 *
 *  - **Single use has to be atomic.** Two simultaneous confirms must not both
 *    succeed. The table makes that a conditional `UPDATE ... WHERE consumed_at IS
 *    NULL` with an affected-row check, rather than a read-then-delete race.
 *  - **The link is per estate.** `tenant_id` records whose configuration issued
 *    it, so the app origin in the email and the audit trail agree.
 *  - **A cache flush must not resurrect a used link.** With transients, an
 *    evicted entry simply means "no link"; with a table, `consumed_at` is the
 *    durable record.
 *
 * Only the SHA-256 hash is stored. The raw token exists in the email and in the
 * response to the client that requested it, never here — the same rule the
 * plugin documents, and the same rule `tokens.personal` follows in this runtime.
 *
 * Guarded so the migration replays, including on SQLite.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_magic_login_tokens';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('tenant_id', 64);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // Revoking a user's outstanding link is the common write, and the
            // consume path starts with the unique hash index.
            $table->index(array('user_id', 'consumed_at'));
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
