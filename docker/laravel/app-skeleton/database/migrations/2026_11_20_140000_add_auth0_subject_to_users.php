<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user can be linked to an Auth0 subject — Proposal 149 §102.
 *
 * Why a column rather than a bridge: the WordPress plugin resolves an Auth0
 * subject to a WordPress user through its GitHub bridge (a Management API client
 * that reads user profiles). Proposal 156 D5 put that bridge out of scope here —
 * it configures WordPress software this runtime does not have — so the link is
 * made explicitly, by an operator, with `nvoos:auth0-link`.
 *
 * That is also the safer default. The bridge's job is *provisioning*, and
 * Proposal 156 D4 denies that by default: a subject with no link is rejected,
 * and it is never matched by email, because an email address is mutable and
 * matching on it is an account takeover.
 *
 * Unique because the lookup is by subject and two users sharing one would make
 * the result arbitrary. Nullable because every existing user has no link yet,
 * and an empty string would be a second way to say "unlinked".
 *
 * Guarded so the migration replays, including on SQLite.
 */
return new class extends Migration
{
    private const TABLE = 'users';

    private const COLUMN = 'auth0_subject';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string(self::COLUMN, 191)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropUnique(array(self::COLUMN));
            $table->dropColumn(self::COLUMN);
        });
    }
};
